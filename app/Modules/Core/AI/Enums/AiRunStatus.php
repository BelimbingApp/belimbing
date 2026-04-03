<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Lifecycle states for LLM run records in the ai_runs ledger.
 */
enum AiRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    /**
     * Determine whether this status represents a terminal (complete) state.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::TimedOut => 'Timed Out',
        };
    }

    /**
     * UI badge variant.
     */
    public function color(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
            self::TimedOut => 'danger',
        };
    }
}
