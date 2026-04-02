<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Presence state for agents, tools, and subsystems.
 *
 * Answers "is this entity live/active/reachable right now?"
 * Distinct from readiness (can it be used?) and health (is it behaving well?).
 */
enum PresenceState: string
{
    /** No presence signal has been received or the entity is offline. */
    case Offline = 'offline';

    /** Entity was recently active but not within the "live" threshold. */
    case Idle = 'idle';

    /** Entity is currently active — recent session, run, or heartbeat. */
    case Active = 'active';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Offline',
            self::Idle => 'Idle',
            self::Active => 'Active',
        };
    }

    /**
     * UI color class.
     */
    public function color(): string
    {
        return match ($this) {
            self::Offline => 'default',
            self::Idle => 'warning',
            self::Active => 'success',
        };
    }
}
