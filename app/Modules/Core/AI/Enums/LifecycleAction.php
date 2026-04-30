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

    /** Prune retained per-run wire log files. */
    case PruneWireLogs = 'prune_wire_logs';

    /** Refresh imported AI model token pricing snapshots. */
    case RefreshPricingSnapshot = 'refresh_pricing_snapshot';

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
            self::PruneWireLogs => 'Prune Wire Logs',
            self::RefreshPricingSnapshot => 'Refresh Pricing Snapshot',
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
            self::PruneWireLogs => true,
            self::RefreshPricingSnapshot => false,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CompactMemory => __('Condense daily memory notes into durable knowledge without deleting the source sessions.'),
            self::PruneSessions => __('Delete stale session transcripts and metadata after the selected retention window.'),
            self::PruneArtifacts => __('Delete browser screenshots, PDFs, and snapshots for a specific session.'),
            self::SweepBrowserSessions => __('Mark expired browser sessions as swept so abandoned automation contexts stop accumulating.'),
            self::SweepOperations => __('Cancel or mark stale background operations that appear to be stuck.'),
            self::PruneWireLogs => __('Delete retained per-run transport wire logs older than the selected retention window.'),
            self::RefreshPricingSnapshot => __('Fetch the latest LiteLLM token pricing snapshot and update the local pricing registry.'),
        };
    }
}
