<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;

/**
 * Request-scoped holder for the resolved page context and snapshot.
 *
 * Written by RunAgentChatJob (hydrated from dispatch meta) at the start of
 * a request. Read by LaraPromptFactory (for system prompt injection) and
 * ActivePageSnapshotTool (for on-demand rich inspection).
 *
 * Registered as a scoped singleton to guarantee isolation between requests.
 */
class PageContextHolder
{
    private ?PageContext $context = null;

    private ?PageSnapshot $snapshot = null;

    private string $consentLevel = 'page';

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

    public function setConsentLevel(string $level): void
    {
        if (in_array($level, ['off', 'page', 'full'], true)) {
            $this->consentLevel = $level;
        }
    }

    public function getConsentLevel(): string
    {
        return $this->consentLevel;
    }

    /**
     * Whether page context is available (not null and not consent-off).
     */
    public function hasContext(): bool
    {
        return $this->context !== null && $this->consentLevel !== 'off';
    }

    /**
     * Whether the full snapshot is available and consented.
     */
    public function hasSnapshot(): bool
    {
        return $this->snapshot !== null && $this->consentLevel === 'full';
    }
}
