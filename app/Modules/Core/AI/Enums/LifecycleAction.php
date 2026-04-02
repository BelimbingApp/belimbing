<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Types of lifecycle control operations.
 *
 * Each action maps to a specific destructive or compaction operation
 * managed by the LifecycleControlService.
 */
enum LifecycleAction: string
{
    /** Compact daily memory notes into durable knowledge. */
    case CompactMemory = 'compact_memory';

    /** Prune stale chat sessions and transcripts. */
    case PruneSessions = 'prune_sessions';

    /** Prune expired browser artifacts (screenshots, snapshots, PDFs). */
    case PruneArtifacts = 'prune_artifacts';

    /** Sweep stale browser sessions past their TTL. */
    case SweepBrowserSessions = 'sweep_browser_sessions';

    /** Sweep stale operation dispatches stuck in running state. */
    case SweepOperations = 'sweep_operations';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CompactMemory => 'Compact Memory',
            self::PruneSessions => 'Prune Sessions',
            self::PruneArtifacts => 'Prune Artifacts',
            self::SweepBrowserSessions => 'Sweep Browser Sessions',
            self::SweepOperations => 'Sweep Operations',
        };
    }

    /**
     * Whether this action is destructive (deletes data vs. compacts/reorganizes).
     */
    public function isDestructive(): bool
    {
        return match ($this) {
            self::CompactMemory => false,
            self::PruneSessions, self::PruneArtifacts => true,
            self::SweepBrowserSessions, self::SweepOperations => false,
        };
    }
}
