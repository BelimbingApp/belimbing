<?php

namespace App\Base\Database\Exceptions;

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

/**
 * Thrown when a data-operation ledger write targets a run that cannot accept it.
 */
final class DataOperationException extends BlbInvariantViolationException
{
    public static function missing(int $runId): self
    {
        return new self(
            "Data operation run #{$runId} does not exist.",
            DatabaseErrorCode::DATA_OPERATION_RUN_NOT_FOUND,
            ['run_id' => $runId],
        );
    }

    public static function notRunning(int $runId): self
    {
        return new self(
            "Data operation run #{$runId} is already terminal and cannot be changed.",
            DatabaseErrorCode::DATA_OPERATION_RUN_TERMINAL,
            ['run_id' => $runId],
        );
    }
}
