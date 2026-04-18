<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Enums;

enum PrincipalType: string
{
    case USER = 'user';
    case AGENT = 'agent'; // AI Agent
    case GUEST = 'guest'; // Unauthenticated or unidentified principal; for audit storage only (not used as an authorization Actor).
    case CONSOLE = 'console';
    case SCHEDULER = 'scheduler';
    case QUEUE = 'queue';

    /**
     * Human-readable label for admin filters and selects.
     */
    public function label(): string
    {
        return match ($this) {
            self::USER => __('User'),
            self::AGENT => __('Agent'),
            self::GUEST => __('Guest'),
            self::CONSOLE => __('Console'),
            self::SCHEDULER => __('Scheduler'),
            self::QUEUE => __('Queue'),
        };
    }

    /**
     * All cases in a stable order for UI filters (must include every case).
     *
     * @return list<self>
     */
    public static function orderedCases(): array
    {
        return [
            self::USER,
            self::GUEST,
            self::AGENT,
            self::CONSOLE,
            self::SCHEDULER,
            self::QUEUE,
        ];
    }

    /**
     * Whether this principal type represents a process rather than a user or agent.
     */
    public function isProcess(): bool
    {
        return match ($this) {
            self::CONSOLE, self::SCHEDULER, self::QUEUE => true,
            default => false,
        };
    }
}
