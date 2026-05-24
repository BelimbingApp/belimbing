<?php

namespace App\Base\Database\Exceptions;

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Foundation\Exceptions\BlbDataContractException;

/**
 * Thrown when an operation requires a database driver that is not supported.
 */
final class UnsupportedDatabaseDriverException extends BlbDataContractException
{
    public static function forOperation(string $driver, string $operation): self
    {
        return new self(
            "Unsupported database driver '{$driver}' for {$operation}.",
            DatabaseErrorCode::DATABASE_DRIVER_UNSUPPORTED,
            ['driver' => $driver, 'operation' => $operation],
        );
    }
}
