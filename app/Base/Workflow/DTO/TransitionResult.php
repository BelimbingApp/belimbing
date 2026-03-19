<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\DTO;

use App\Base\Workflow\Models\StatusHistory;

final readonly class TransitionResult
{
    public function __construct(
        public bool $success,
        public ?string $reason = null,
        public ?StatusHistory $history = null,
    ) {}

    /**
     * Create a successful result.
     */
    public static function success(StatusHistory $history): self
    {
        return new self(success: true, history: $history);
    }

    /**
     * Create a failed result.
     *
     * @param  string  $reason  Human-readable failure reason
     */
    public static function failure(string $reason): self
    {
        return new self(success: false, reason: $reason);
    }
}
