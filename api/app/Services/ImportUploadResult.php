<?php

namespace App\Services;

class ImportUploadResult
{
    /**
     * @param list<string> $header
     */
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly int $sizeBytes,
        public readonly array $header,
    ) {}
}
