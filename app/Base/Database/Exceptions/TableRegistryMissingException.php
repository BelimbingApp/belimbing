<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class TableRegistryMissingException extends BlbConfigurationException
{
    public static function forFreshCommand(): self
    {
        return new self(
            'TableRegistry (base_database_tables) is missing. This table is created on install and must always exist. Reinstall or run the base database migration before using migrate:fresh.'
        );
    }
}
