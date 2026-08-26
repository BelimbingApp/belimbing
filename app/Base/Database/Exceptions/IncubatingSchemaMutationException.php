<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class IncubatingSchemaMutationException extends BlbConfigurationException
{
    public static function localEnvironmentRequired(string $environment): self
    {
        return new self("Schema incubation source edits are local-only; current environment is {$environment}.");
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
