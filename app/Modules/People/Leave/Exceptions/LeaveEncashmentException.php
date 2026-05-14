<?php

namespace App\Modules\People\Leave\Exceptions;

use RuntimeException;

final class LeaveEncashmentException extends RuntimeException
{
    public static function nonPositiveDays(): self
    {
        return new self('Encashment days must be positive.');
    }

    public static function insufficientBalance(float $days, float $available): self
    {
        return new self(sprintf(
            'Cannot encash %.2f days; available balance is %.2f days.',
            $days,
            $available,
        ));
    }
}
