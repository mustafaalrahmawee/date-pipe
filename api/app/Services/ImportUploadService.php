<?php

namespace App\Services;

use App\Exceptions\FileTooLargeException;
use App\Exceptions\ImportUploadException;
use App\Exceptions\InvalidCsvException;
use App\Exceptions\InvalidFileTypeException;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImportUploadService
{
    private const DISK = 'local';

    private const DIRECTORY = 'imports';

    /**
     * Content MIME types accepted for imports, verified via finfo
     * against the actual bytes on disk.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
    ];

    public function store(UploadedFile $file): ImportUploadResult
    {
        $tempPath = $file->getRealPath();

        if ($tempPath === false) {
            throw new ImportUploadException('Uploaded file is not available on disk.');
        }

        $this->assertSize($tempPath);
        $this->assertMimeType($tempPath);
        $header = $this->readHeader($tempPath);

        $storedPath = $this->persist($file);

        return new ImportUploadResult(
            path: $storedPath,
            originalName: $file->getClientOriginalName(),
            sizeBytes: Storage::disk(self::DISK)->size($storedPath),
            header: $header,
        );
    }

    private function assertSize(string $tempPath): void
    {
        $size = filesize($tempPath);

        if ($size === false) {
            throw new ImportUploadException('Uploaded file size could not be determined.');
        }

        $maxBytes = (int) config('imports.max_upload_bytes');

        if ($size > $maxBytes) {
            throw new FileTooLargeException("File exceeds the maximum allowed size of {$maxBytes} bytes.");
        }
    }

    private function assertMimeType(string $tempPath): void
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tempPath);

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidFileTypeException("Unsupported media type: {$mime}.");
        }
    }

    /**
     * Reads the CSV header row from the raw temp stream. Runs before
     * anything is persisted: files failing here never reach the disk.
     *
     * @return list<string>
     */
    private function readHeader(string $tempPath): array
    {
        $stream = fopen($tempPath, 'rb');

        if ($stream === false) {
            throw new ImportUploadException('Uploaded file could not be opened for reading.');
        }

        try {
            // All arguments explicit: relying on fgetcsv defaults triggers
            // a deprecation on PHP 8.4+. Values match the historic defaults.
            $header = fgetcsv($stream, 0, ',', '"', '\\');

            if ($header === false || $header === []) {
                throw new InvalidCsvException('File contains no header row.');
            }

            // Blank lines make fgetcsv yield null elements, hence the cast.
            $header = array_map(
                static fn ($column) => trim((string) $column),
                $header
            );

            if (in_array('', $header, true)) {
                throw new InvalidCsvException('Header row contains an empty column name.');
            }

            if (count($header) !== count(array_unique($header))) {
                throw new InvalidCsvException('Header row contains duplicate column names.');
            }

            return $header;
        } finally {
            fclose($stream);
        }
    }

    private function persist(UploadedFile $file): string
    {
        $storedPath = $file->storeAs(
            self::DIRECTORY,
            bin2hex(random_bytes(16)) . '.csv',
            ['disk' => self::DISK, 'visibility' => 'private'],
        );

        if ($storedPath === false) {
            throw new ImportUploadException('File could not be stored.');
        }

        return $storedPath;
    }
}
