<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

/**
 * Provides the background dispatch ID property for tracking active dispatches.
 *
 * All LLM chat runs now go through the dispatch-first path in HandlesStreaming
 * (prepareStreamingRun → RunAgentChatJob). This trait retains the shared
 * property used by both prepareStreamingRun (sets it) and cancelActiveTurn
 * (reads it).
 */
trait HandlesBackgroundChat
{
    /**
     * Active background dispatch ID for the current session (if any).
     */
    public ?string $backgroundDispatchId = null;
}
