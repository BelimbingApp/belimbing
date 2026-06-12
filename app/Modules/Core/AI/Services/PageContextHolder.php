<?php

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;

/**
 * Request-scoped holder for the resolved page context and snapshot.
 *
 * Written by ChatTurnRunner (hydrated from turn runtime_meta) at the start
 * of a request. Read by LaraPromptFactory (for system prompt injection) and
 * ActivePageSnapshotTool (for on-demand rich inspection).
 *
 * Registered as a scoped singleton to guarantee isolation between requests.
 */
class PageContextHolder
{
    private ?PageContext $context = null;

    private ?PageSnapshot $snapshot = null;

    public function setContext(PageContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): ?PageContext
    {
        return $this->context;
    }

    public function setSnapshot(PageSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;
    }

    public function getSnapshot(): ?PageSnapshot
    {
        return $this->snapshot;
    }

    /**
     * Whether page context is available.
     */
    public function hasContext(): bool
    {
        return $this->context !== null;
    }

    /**
     * Whether a page snapshot is available.
     */
    public function hasSnapshot(): bool
    {
        return $this->snapshot !== null;
    }
}
