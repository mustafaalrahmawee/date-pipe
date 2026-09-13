<?php

namespace App\Exceptions;

class FileTooLargeException extends ImportUploadException
{
    public function status(): int
    {
        return 413;
    }
}
