<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class IncubatingSchemaMutationException extends BlbConfigurationException
{
    public static function deprecatedTableListMissing(string $path): self
    {
        return new self('Deprecated incubating table list script not found: '.$path);
    }

    public static function deprecatedTableListUnreadable(string $path): self
    {
        return new self('Unable to read deprecated incubating table list script: '.$path);
    }

    public static function deprecatedTableListUnwritable(string $path): self
    {
        return new self('Unable to update deprecated incubating table list script: '.$path);
    }

    public static function migrationFileUnreadable(string $path): self
    {
        return new self('Unable to read migration file: '.$path);
    }

    public static function migrationFileNotMarkedIncubating(string $path): self
    {
        return new self('Unable to mark migration incubating: '.$path);
    }
}
