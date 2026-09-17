<?php

namespace App\Services;

use App\Exceptions\ImportProcessingException;
use App\Exceptions\InvalidCsvException;
use App\Models\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Throwable;

class ImportProcessingService
{
    private const DISK = 'local';
    private const EXPECTED_HEADER = ['name', 'email', 'amount'];

    public function process(Import $import): ImportProcessingResult
    {
        // Guard before the delete: validate the stored file's header
        // against the fixed contract so a corrupt or missing file cannot
        // destroy the records of the last successful run.
        $stream = $this->openStream($import->path);

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

        // Re-processing replaces the previous run: a crashed partial run
        // can simply be repeated instead of blocking the import.
        $import->records()->delete();

        // Stream the stored CSV one row at a time: O(1) memory regardless
        // of file size. The header row is row 1, so the first data row is
        // 2, and the resource is closed in finally so a mid-stream error
        // cannot leak it.
        $rows = LazyCollection::make(function () use ($import) {
            $stream = $this->openStream($import->path);

            try {
                $this->readHeader($stream);
                $row = 2;

                // All fgetcsv arguments explicit, matching the upload
                // service: relying on defaults triggers a deprecation on
                // PHP 8.4+.
                while (($fields = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                    yield ['row' => $row++, 'fields' => $fields];
                }
            } finally {
                fclose($stream);
            }
        });

        $errors = [];
        $validRows = 0;
        $invalidRows = 0;
        $maxErrors = (int) config('imports.max_reported_row_errors');

        $rows
            ->map(fn (array $row) => $this->validateRow($row['row'], $row['fields']))
            ->chunk((int) config('imports.process_chunk_size'))
            ->each(function ($batch) use ($import, &$errors, &$validRows, &$invalidRows, $maxErrors) {
                // The batch is bounded by the chunk size, so splitting and
                // inserting it costs one chunk's worth of memory, not the
                // whole file.
                [$valid, $invalid] = collect($batch)->partition(
                    fn (array $row) => $row['error'] === null
                );

                if ($valid->isNotEmpty()) {
                    $insertRows = $valid
                        ->map(fn (array $row) => ['import_id' => $import->id] + $row['record'])
                        ->all();

                    DB::transaction(fn () => DB::table('records')->insert($insertRows));
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
     * @return resource
     */
    private function openStream(string $path)
    {
        try {
            $stream = Storage::disk(self::DISK)->readStream($path);
        } catch (Throwable $e) {
            throw new ImportProcessingException('Stored file could not be opened for reading.', 0, $e);
        }

        if (is_resource($stream) === false) {
            throw new ImportProcessingException('Stored file could not be opened for reading.');
        }

        return $stream;
    }

    /**
     * Consumes the first CSV row, normalized the same way as at upload
     * time, so a header stored as "name, email" compares equal to its
     * trimmed form.
     *
     * @param  resource  $stream
     * @return list<string>
     */
    private function readHeader($stream): array
    {
        $header = fgetcsv($stream, 0, ',', '"', '\\');

        if ($header === false) {
            throw new InvalidCsvException('File contains no header row.');
        }

        // Excel and many Windows tools prefix UTF-8 CSVs with a byte order
        // mark; it rides on the first column and would otherwise break the
        // strict header contract.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }

        return array_map(
            static fn ($column) => trim((string) $column),
            $header
        );
    }

    /**
     * Validates one CSV row against the fixed schema and returns either an
     * insert-ready record or the reason it is invalid.
     *
     * @param  list<string|null>  $fields
     * @return array{row: int, error: ?string, record: ?array<string, mixed>}
     */
    private function validateRow(int $row, array $fields): array
    {
        $expected = count(self::EXPECTED_HEADER);

        if (count($fields) !== $expected) {
            return ['row' => $row, 'error' => "Expected {$expected} columns, found ".count($fields).'.', 'record' => null];
        }

        $name = trim((string) $fields[0]);

        if ($name === '') {
            return ['row' => $row, 'error' => 'name is empty.', 'record' => null];
        }

        if (mb_strlen($name) > 255) {
            return ['row' => $row, 'error' => 'name exceeds 255 characters.', 'record' => null];
        }

        $email = trim((string) $fields[1]);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['row' => $row, 'error' => 'email is not a valid address.', 'record' => null];
        }

        if (mb_strlen($email) > 255) {
            return ['row' => $row, 'error' => 'email exceeds 255 characters.', 'record' => null];
        }

        $amount = trim((string) $fields[2]);

        if (! is_numeric($amount)) {
            return ['row' => $row, 'error' => 'amount is not a numeric value.', 'record' => null];
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
}
