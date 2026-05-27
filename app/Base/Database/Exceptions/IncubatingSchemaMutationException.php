<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class IncubatingSchemaMutationException extends BlbConfigurationException
{
    public static function migrationFileUnreadable(string $path): self
    {
        return new self('Unable to read migration file: '.$path);
    }

    public static function migrationFileNotMarkedIncubating(string $path): self
    {
        return new self('Unable to mark migration incubating: '.$path);
    }
}
