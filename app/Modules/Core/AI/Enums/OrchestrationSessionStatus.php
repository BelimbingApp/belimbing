<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Lifecycle states for orchestration sessions (child agent sessions).
 *
 * Distinct from OperationStatus: orchestration sessions model bounded
 * execution contexts with lineage, not just queue dispatch records.
 */
enum OrchestrationSessionStatus: string
{
    /** Session created but child agent has not started execution. */
    case Pending = 'pending';

    /** Child agent is actively executing within this session. */
    case Running = 'running';

    /** Child session completed successfully with a result. */
    case Completed = 'completed';

    /** Child session failed during execution. */
    case Failed = 'failed';

    /** Parent or policy cancelled the child session before completion. */
    case Cancelled = 'cancelled';

    /**
     * Determine whether this status represents a terminal state.
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
            self::Pending => 'Pending',
            self::Running => 'Running',
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
            self::Pending => 'default',
            self::Running => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }
}
