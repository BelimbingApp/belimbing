<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

/**
 * Request/job-scoped holder for the active AI chat session.
 *
 * Tools can read this during an interactive or queued runtime execution
 * to attach follow-up work to the same Lara session transcript.
 */
final class RuntimeSessionContext
{
    private ?string $sessionId = null;

    public function set(?string $sessionId): void
    {
        $this->sessionId = is_string($sessionId) && $sessionId !== ''
            ? $sessionId
            : null;
    }

    public function clear(): void
    {
        $this->sessionId = null;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }
}
