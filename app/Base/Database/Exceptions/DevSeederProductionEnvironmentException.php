<?php
namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
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
            BlbErrorCode::DEV_SEEDER_NON_LOCAL_ENV,
            ['environment' => $currentEnvironment],
        );
    }
}
