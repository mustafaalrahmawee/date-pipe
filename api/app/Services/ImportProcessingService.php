<?php

namespace App\Services;

use App\Exceptions\ImportProcessingException;
use App\Exceptions\InvalidCsvException;
use App\Models\Import;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Throwable;

class ImportProcessingService
{
    private const DISK = 'local';

    /**
     * The column contract of this round: only files with exactly this
     * header can be processed into records. Positions matter, row
     * validation reads fields by index.
     */
    private const EXPECTED_HEADER = ['name', 'email', 'amount'];

    public function process(Import $import): ImportProcessingResult
    {
        // Guards run before the delete, so a corrupt or missing file
        // cannot destroy the records of the last successful run.
        $this->assertHeaderMatches($import->path);

        // Re-processing replaces the previous run: a crashed partial
        // run can simply be repeated instead of blocking the import.
        $import->records()->delete();

        $errors = [];
        $validRows = 0;
        $invalidRows = 0;
        $maxErrors = (int) config('imports.max_reported_row_errors');

        $this->rows($import)
            ->map(fn (array $row) => $this->validateRow($row['row'], $row['fields']))
            ->chunk((int) config('imports.process_chunk_size'))
            ->each(function ($batch) use ($import, &$errors, &$validRows, &$invalidRows, $maxErrors) {
                // The batch is bounded by the chunk size, so splitting
                // it eagerly costs one batch worth of memory, not the
                // whole file.
                [$valid, $invalid] = collect($batch)->partition(
                    fn (array $row) => $row['error'] === null
                );

                if ($valid->isNotEmpty()) {
                    $this->insertBatch($import, $valid);
                }

                $validRows += $valid->count();
                $invalidRows += $invalid->count();

                foreach ($invalid as $row) {
                    if (count($errors) < $maxErrors) {
                        $errors[] = ['row' => $row['row'], 'message' => $row['error']];
                    }
                }
            });

        return new ImportProcessingResult(
            importId: $import->id,
            totalRows: $validRows + $invalidRows,
            validRows: $validRows,
            invalidRows: $invalidRows,
            errors: $errors,
        );
    }

    /**
     * Streams the stored CSV one row at a time. Call after
     * assertHeaderMatches(): the header row is consumed here, but only
     * validated there, and the resource is closed in finally so an
     * exception mid-stream cannot leak it.
     *
     * @return LazyCollection<int, array{row: int, fields: list<string|null>}>
     */
    private function rows(Import $import): LazyCollection
    {
        return LazyCollection::make(function () use ($import) {
            $stream = $this->openStream($import->path);

            try {
                $this->readHeader($stream);

                // The header row is row 1, so the first data row is 2.
                $row = 2;

                // All fgetcsv arguments explicit, matching the upload
                // service: relying on defaults triggers a deprecation
                // on PHP 8.4+.
                while (($fields = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                    yield ['row' => $row++, 'fields' => $fields];
                }
            } finally {
                fclose($stream);
            }
        });
    }

    private function assertHeaderMatches(string $path): void
    {
        $stream = $this->openStream($path);

        try {
            if ($this->readHeader($stream) !== self::EXPECTED_HEADER) {
                throw new InvalidCsvException(
                    'File header does not match the expected columns: '
                    .implode(', ', self::EXPECTED_HEADER).'.'
                );
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function openStream(string $path)
    {
        try {
            $stream = Storage::disk(self::DISK)->readStream($path);
        } catch (Throwable $e) {
            throw new ImportProcessingException('Stored file could not be opened for reading.', 0, $e);
        }

        if ($stream === false || ! is_resource($stream)) {
            throw new ImportProcessingException('Stored file could not be opened for reading.');
        }

        return $stream;
    }

    /**
     * Consumes the first CSV row, normalized the same way as at upload
     * time, so a header stored as "name, email" compares equal to its
     * trimmed form.
     *
     * @return list<string>
     */
    private function readHeader($stream): array
    {
        $header = fgetcsv($stream, 0, ',', '"', '\\');

        if ($header === false) {
            throw new InvalidCsvException('File contains no header row.');
        }

        return array_map(
            static fn ($column) => trim((string) $column),
            $header
        );
    }

    /**
     * Validates one CSV row against the fixed schema and returns either
     * an insert-ready record or the reason it is invalid.
     *
     * @param  list<string|null>  $fields
     * @return array{row: int, error: ?string, record: ?array<string, mixed>}
     */
    private function validateRow(int $row, array $fields): array
    {
        $expected = count(self::EXPECTED_HEADER);

        if (count($fields) !== $expected) {
            return $this->invalid($row, "Expected {$expected} columns, found ".count($fields).'.');
        }

        $name = trim((string) $fields[0]);

        if ($name === '') {
            return $this->invalid($row, 'name is empty.');
        }

        if (mb_strlen($name) > 255) {
            return $this->invalid($row, 'name exceeds 255 characters.');
        }

        $email = trim((string) $fields[1]);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->invalid($row, 'email is not a valid address.');
        }

        if (mb_strlen($email) > 255) {
            return $this->invalid($row, 'email exceeds 255 characters.');
        }

        $amount = trim((string) $fields[2]);

        if (! is_numeric($amount)) {
            return $this->invalid($row, 'amount is not a numeric value.');
        }

        // The numeric string flows on as-is: casting to float here would
        // lose the precision the decimal column and cast protect.
        return [
            'row' => $row,
            'error' => null,
            'record' => [
                'row_index' => $row,
                'name' => $name,
                'email' => $email,
                'amount' => $amount,
            ],
        ];
    }

    /**
     * @return array{row: int, error: string, record: null}
     */
    private function invalid(int $row, string $message): array
    {
        return ['row' => $row, 'error' => $message, 'record' => null];
    }

    /**
     * @param  Collection<int, array{row: int, error: null, record: array<string, mixed>}>  $valid
     */
    private function insertBatch(Import $import, Collection $valid): void
    {
        $rows = $valid
            ->map(fn (array $row) => ['import_id' => $import->id] + $row['record'])
            ->all();

        DB::transaction(fn () => DB::table('records')->insert($rows));
    }
}
