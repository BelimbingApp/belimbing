<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Classifies memory source files by trust and retention model.
 *
 * Retrieval results carry this classification so operators and agents
 * can distinguish curated knowledge from raw notes.
 */
enum MemoryFileType: string
{
    /**
     * Curated, long-term knowledge (e.g., MEMORY.md).
     * Highest trust; survives compaction.
     */
    case Durable = 'durable';

    /**
     * Raw daily notes (e.g., memory/2026-04-01.md).
     * Subject to compaction into durable memory.
     */
    case Daily = 'daily';

    /**
     * External reference docs included by explicit config.
     * Labeled separately in retrieval results.
     */
    case Reference = 'reference';
}
