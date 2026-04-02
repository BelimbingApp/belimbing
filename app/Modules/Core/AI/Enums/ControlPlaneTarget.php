<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Identifies the kind of entity a control-plane operation targets.
 *
 * Used across health snapshots, telemetry events, and lifecycle requests
 * so every subsystem shares the same target vocabulary.
 */
enum ControlPlaneTarget: string
{
    case Agent = 'agent';
    case Tool = 'tool';
    case Provider = 'provider';
    case Session = 'session';
    case BrowserSession = 'browser_session';
    case Memory = 'memory';
    case Dispatch = 'dispatch';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent',
            self::Tool => 'Tool',
            self::Provider => 'Provider',
            self::Session => 'Session',
            self::BrowserSession => 'Browser Session',
            self::Memory => 'Memory',
            self::Dispatch => 'Dispatch',
        };
    }
}
