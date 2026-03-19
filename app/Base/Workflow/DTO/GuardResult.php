<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\DTO;

final readonly class GuardResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}

    /**
     * Create an allowed result.
     */
    public static function allow(): self
    {
        return new self(allowed: true);
    }

    /**
     * Create a denied result with a reason.
     *
     * @param  string  $reason  Human-readable denial reason
     */
    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }
}
