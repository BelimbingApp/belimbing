<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
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
            BlbErrorCode::DATABASE_DRIVER_UNSUPPORTED,
            ['driver' => $driver, 'operation' => $operation],
        );
    }
}
