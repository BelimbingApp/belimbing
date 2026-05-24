<?php

namespace App\Base\Database\Exceptions;

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;

/**
 * Thrown when a dev seeder is run outside the local environment.
 */
final class DevSeederProductionEnvironmentException extends BlbConfigurationException
{
    public static function forEnvironment(string $currentEnvironment): self
    {
        return new self(
            'Dev seeders may only run when APP_ENV=local. Current: '.$currentEnvironment,
            DatabaseErrorCode::DEV_SEEDER_NON_LOCAL_ENV,
            ['environment' => $currentEnvironment],
        );
    }
}
