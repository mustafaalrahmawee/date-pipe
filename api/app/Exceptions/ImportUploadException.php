<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception for a failed import upload.
 *
 * Carries the HTTP status the failure maps to, so the exception
 * rendering layer can translate it without knowing each subclass.
 * The base status (400) is the catch-all for malformed uploads.
 */
class ImportUploadException extends RuntimeException
{
    public function status(): int
    {
        return 400;
    }
}
