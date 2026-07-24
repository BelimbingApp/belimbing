<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use Closure;
use Throwable;

/**
 * Best-effort progress channel for a mirror run. Observability must never be
 * allowed to change the data-operation outcome, so listener failures are
 * deliberately isolated from the transfer.
 */
final class DataShareMirrorProgress
{
    private function __construct(private readonly ?Closure $listener) {}

    public static function listen(?callable $listener): self
    {
        return new self($listener === null ? null : Closure::fromCallable($listener));
    }

    public function report(string $message): void
    {
        if ($this->listener === null) {
            return;
        }

        try {
            ($this->listener)($message);
        } catch (Throwable) {
            // Progress delivery is informative; the mirror remains authoritative.
        }
    }
}
