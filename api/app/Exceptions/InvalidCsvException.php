<?php

namespace App\Exceptions;

class InvalidCsvException extends ImportUploadException
{
    public function status(): int
    {
        return 422;
    }
}
