<?php

namespace App\Exceptions;

class InvalidFileTypeException extends ImportUploadException
{
    public function status(): int
    {
        return 415;
    }
}
