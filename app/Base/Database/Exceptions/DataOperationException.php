<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

final class DataOperationException extends RuntimeException
{
    public static function missing(int $runId): self
    {
        return new self("Data operation run #{$runId} does not exist.");
    }

    public static function notRunning(int $runId): self
    {
        return new self("Data operation run #{$runId} is already terminal and cannot be changed.");
    }
}
