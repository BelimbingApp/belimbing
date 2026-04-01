<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\BrowserSessionStatus;

/**
 * Operator-visible snapshot of a browser session's current state.
 *
 * Assembled from the session model and page state for inspection,
 * diagnostics, and UI display. Not stored directly — constructed on demand.
 */
final readonly class BrowserSessionState
{
    /**
     * @param  list<BrowserTabState>  $tabs
     */
    public function __construct(
        public string $sessionId,
        public int $employeeId,
        public int $companyId,
        public BrowserSessionStatus $status,
        public bool $headless,
        public ?string $activeTabId,
        public ?string $currentUrl,
        public array $tabs,
        public ?string $lastSnapshotRef,
        public ?string $failureReason,
        public string $createdAt,
        public string $lastActivityAt,
        public ?string $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'employee_id' => $this->employeeId,
            'company_id' => $this->companyId,
            'status' => $this->status->value,
            'headless' => $this->headless,
            'active_tab_id' => $this->activeTabId,
            'current_url' => $this->currentUrl,
            'tabs' => array_map(
                static fn (BrowserTabState $tab): array => $tab->toArray(),
                $this->tabs,
            ),
            'last_snapshot_ref' => $this->lastSnapshotRef,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt,
            'last_activity_at' => $this->lastActivityAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
