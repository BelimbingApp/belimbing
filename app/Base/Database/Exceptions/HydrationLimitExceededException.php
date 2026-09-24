<?php

namespace App\Base\Database\Exceptions;

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

/**
 * A unit of work hydrated more Eloquent models than the hydration guard
 * allows. Thrown only in throw mode (local and testing by default); in log
 * mode the same finding is a warning. See docs/architecture/query-bounds.md.
 */
final class HydrationLimitExceededException extends BlbInvariantViolationException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function forUnitOfWork(string $description, int $limit, string $models, array $context): self
    {
        return new self(
            "Hydration guard: {$description} hydrated more than {$limit} Eloquent models ({$models}). Limit, paginate, chunk, cursor, or aggregate in the database; wrap a deliberate bulk pass in HydrationGuard::suspend().",
            DatabaseErrorCode::HYDRATION_LIMIT_EXCEEDED,
            $context,
        );
    }
}
