<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Layered policy evaluation levels.
 *
 * Policy is evaluated top-to-bottom; the first layer that denies
 * or degrades stops further evaluation. This structure makes it
 * possible to explain exactly which layer blocked an action.
 */
enum PolicyLayer: string
{
    /** Actor capability (authz — can this user/agent perform this action?). */
    case Capability = 'capability';

    /** Tool or operation readiness (is the tool configured and available?). */
    case Readiness = 'readiness';

    /** Subsystem-specific policy (orchestration, browser, memory rules). */
    case Subsystem = 'subsystem';

    /** Data, network, or workspace policy (SSRF, file access, workspace validity). */
    case DataNetwork = 'data_network';

    /** Operator confirmation or escalation policy (requires explicit approval). */
    case Operator = 'operator';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Capability => 'Capability',
            self::Readiness => 'Readiness',
            self::Subsystem => 'Subsystem',
            self::DataNetwork => 'Data & Network',
            self::Operator => 'Operator',
        };
    }

    /**
     * Evaluation order (lower = evaluated first).
     */
    public function order(): int
    {
        return match ($this) {
            self::Capability => 1,
            self::Readiness => 2,
            self::Subsystem => 3,
            self::DataNetwork => 4,
            self::Operator => 5,
        };
    }
}
