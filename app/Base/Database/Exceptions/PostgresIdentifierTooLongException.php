<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbDataContractException;

final class PostgresIdentifierTooLongException extends BlbDataContractException
{
    public static function forIdentifier(string $identifier, int $byteLength, int $maxBytes): self
    {
        return new self(
            "PostgreSQL identifier '{$identifier}' is {$byteLength} bytes; the maximum is {$maxBytes} bytes. Use a shorter explicit table, column, index, or constraint name.",
            BlbErrorCode::DATABASE_IDENTIFIER_TOO_LONG,
            [
                'identifier' => $identifier,
                'byte_length' => $byteLength,
                'max_bytes' => $maxBytes,
            ],
        );
    }
}
