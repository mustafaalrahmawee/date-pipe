<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a stored import file cannot be processed, e.g. the
 * stream cannot be opened or a batch insert fails.
 *
 * Unlike upload failures these are server-side problems, not client
 * errors: the status is 500 and the exception stays reportable.
 */
class ImportProcessingException extends RuntimeException
{
    public function status(): int
    {
        return 500;
    }
}
