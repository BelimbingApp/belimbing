<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

final class DataOperationRunNotFoundException extends BlbInvariantViolationException
{
    public static function forRun(int $runId): self
    {
        return new self("Cannot resume data operation run #{$runId}: it does not exist.");
    }
}
