<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Lifecycle states for operations tracked in the dispatch ledger.
 */
enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine whether this status represents a terminal (complete) state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * UI color class.
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued => 'default',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }
}
