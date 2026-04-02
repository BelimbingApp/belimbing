<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Outcome of a policy evaluation.
 *
 * A policy check results in one of three verdicts:
 * - Allow: proceed without restriction
 * - Deny: action is blocked
 * - Degrade: action may proceed with reduced capability or a warning
 */
enum PolicyVerdict: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Degrade = 'degrade';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allowed',
            self::Deny => 'Denied',
            self::Degrade => 'Degraded',
        };
    }

    /**
     * UI color class.
     */
    public function color(): string
    {
        return match ($this) {
            self::Allow => 'success',
            self::Deny => 'danger',
            self::Degrade => 'warning',
        };
    }
}
