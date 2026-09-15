<?php

namespace App\Services;

class ImportProcessingResult
{
    /**
     * @param  list<array{row: int, message: string}>  $errors
     */
    public function __construct(
        public readonly string $importId,
        public readonly int $totalRows,
        public readonly int $validRows,
        public readonly int $invalidRows,
        public readonly array $errors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'import_id' => $this->importId,
            'total_rows' => $this->totalRows,
            'valid_rows' => $this->validRows,
            'invalid_rows' => $this->invalidRows,
            'errors' => $this->errors,
        ];
    }
}
