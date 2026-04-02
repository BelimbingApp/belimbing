<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Status of a lifecycle control request.
 *
 * Lifecycle actions progress through: Previewed → Executing → Completed/Failed.
 * Actions may also be cancelled after preview.
 */
enum LifecycleActionStatus: string
{
    case Previewed = 'previewed';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this status represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Previewed => 'Previewed',
            self::Executing => 'Executing',
            self::Completed => 'Completed',
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
            self::Previewed => 'info',
            self::Executing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'default',
        };
    }
}
