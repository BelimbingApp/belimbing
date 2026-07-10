<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DevelopmentSanitizationException extends RuntimeException
{
    public static function unsupportedSessionDriver(string $driver): self
    {
        return new self(__('Development sanitization cannot safely clear :driver sessions. Configure file, database, array, cookie, or null sessions and retry.', [
            'driver' => $driver,
        ]));
    }

    public static function missingTable(string $table): self
    {
        return new self(__('Development sanitization requires table :table. Run compatible migrations before sanitizing the restored database.', [
            'table' => $table,
        ]));
    }

    public static function duplicateContributor(string $key): self
    {
        return new self(__('Development sanitization contributor key :key is registered more than once.', ['key' => $key]));
    }
}
