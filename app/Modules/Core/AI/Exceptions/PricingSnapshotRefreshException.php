<?php
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
