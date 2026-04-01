<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Lifecycle states for a persistent browser session.
 *
 * Transitions:
 *   Opening → Ready → Busy → Ready (loop)
 *   Ready|Busy → Expired (idle timeout)
 *   Ready|Busy → Failed (crash/process loss)
 *   Ready|Busy → Closed (explicit closure)
 *   Opening → Failed (startup failure)
 */
enum BrowserSessionStatus: string
{
    case Opening = 'opening';
    case Ready = 'ready';
    case Busy = 'busy';
    case Expired = 'expired';
    case Failed = 'failed';
    case Closed = 'closed';

    /**
     * Whether the session is in a terminal state (cannot accept new actions).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Expired, self::Failed, self::Closed => true,
            default => false,
        };
    }

    /**
     * Whether the session can accept new browser actions.
     */
    public function isActionable(): bool
    {
        return $this === self::Ready;
    }

    public function label(): string
    {
        return match ($this) {
            self::Opening => __('Opening'),
            self::Ready => __('Ready'),
            self::Busy => __('Busy'),
            self::Expired => __('Expired'),
            self::Failed => __('Failed'),
            self::Closed => __('Closed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Opening => 'yellow',
            self::Ready => 'green',
            self::Busy => 'blue',
            self::Expired => 'gray',
            self::Failed => 'red',
            self::Closed => 'gray',
        };
    }
}
