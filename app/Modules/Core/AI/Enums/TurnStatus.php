<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Lifecycle states for a user-facing chat turn.
 *
 * A turn represents one user prompt and everything the agent does in response.
 * The user watches turns; operators inspect runs.
 *
 * State machine:
 *   queued → booting → running → completed | failed | cancelled
 *
 * `queued`:    Turn created, waiting for a worker to claim it.
 * `booting`:   Worker claimed; runtime is initializing.
 * `running`:   Agent is actively executing (thinking, tools, streaming).
 * `completed`: Turn finished successfully — terminal.
 * `failed`:    Turn ended in error — terminal.
 * `cancelled`: Turn was cancelled by the user or system — terminal.
 */
enum TurnStatus: string
{
    case Queued = 'queued';
    case Booting = 'booting';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this status represents a terminal (finished) state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Whether this status represents an active (non-terminal) state.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Valid transitions from this status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Booting, self::Failed, self::Cancelled],
            self::Booting => [self::Running, self::Failed, self::Cancelled],
            self::Running => [self::Completed, self::Failed, self::Cancelled],
            self::Completed, self::Failed, self::Cancelled => [],
        };
    }

    /**
     * Whether a transition to the given status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Booting => 'Booting',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * UI badge color variant.
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued => 'default',
            self::Booting => 'info',
            self::Running => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }
}
