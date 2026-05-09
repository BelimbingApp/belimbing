<?php
namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbDataContractException;

/**
 * Thrown when a user-defined database query fails validation or execution.
 */
final class BlbQueryException extends BlbDataContractException
{
    /**
     * Create an exception for an invalid query.
     *
     * @param  string  $reason  Human-readable explanation of the failure
     */
    public static function invalidQuery(string $reason): self
    {
        return new self(
            'Invalid query: '.$reason,
            BlbErrorCode::DATABASE_QUERY_INVALID,
            ['reason' => $reason],
        );
    }

    /**
     * Create an exception for a query that failed during execution.
     *
     * @param  string  $message  The database error message
     * @param  \Throwable  $previous  The original database exception
     */
    public static function executionFailed(string $message, \Throwable $previous): self
    {
        return new self(
            'Query execution failed: '.$message,
            BlbErrorCode::DATABASE_QUERY_EXECUTION_FAILED,
            ['error' => $message],
            previous: $previous,
        );
    }
}
