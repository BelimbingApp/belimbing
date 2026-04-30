<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Exceptions;

use RuntimeException;
use Throwable;

class PricingSnapshotRefreshException extends RuntimeException
{
    public static function noFallback(string $message, ?Throwable $previous = null): self
    {
        return new self($message, previous: $previous);
    }
}
