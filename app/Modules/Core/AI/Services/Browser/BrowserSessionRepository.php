<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\AI\Models\BrowserSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Persistence layer for browser session records.
 *
 * Encapsulates all database interaction for browser sessions so that
 * the session manager and other services never touch Eloquent directly.
 * All state transitions are validated here to enforce lifecycle invariants.
 */
class BrowserSessionRepository
{
    /**
     * Create a new browser session record.
     *
     * @param  int  $agentEmployeeId  Agent (employee) that owns the session
     * @param  int  $companyId  Company scope for isolation
     * @param  int|null  $actingForUserId  Authenticated user the agent acts for, if any
     * @param  bool  $headless  Whether the session runs headless
     * @param  int  $ttlSeconds  Initial time-to-live in seconds
     */
    public function create(
        int $agentEmployeeId,
        int $companyId,
        ?int $actingForUserId,
        bool $headless,
        int $ttlSeconds,
    ): BrowserSession {
        return BrowserSession::query()->create([
            'id' => 'bs_'.Str::ulid()->toBase32(),
            'agent_employee_id' => $agentEmployeeId,
            'acting_for_user_id' => $actingForUserId,
            'company_id' => $companyId,
            'status' => BrowserSessionStatus::Opening,
            'headless' => $headless,
            'last_activity_at' => now(),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);
    }

    /**
     * Find a session by ID, or null if not found.
     */
    public function find(string $sessionId): ?BrowserSession
    {
        return BrowserSession::query()->find($sessionId);
    }

    /**
     * Find the most recent active session for an agent identity within a company.
     *
     * Returns the newest non-terminal session, useful for session reuse.
     */
    public function findActiveForIdentity(
        int $agentEmployeeId,
        int $companyId,
        ?int $actingForUserId,
    ): ?BrowserSession {
        return BrowserSession::query()
            ->where('agent_employee_id', $agentEmployeeId)
            ->where('acting_for_user_id', $actingForUserId)
            ->where('company_id', $companyId)
            ->active()
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Count active (non-terminal) sessions for a company.
     */
    public function countActiveForCompany(int $companyId): int
    {
        return BrowserSession::query()
            ->where('company_id', $companyId)
            ->active()
            ->count();
    }

    /**
     * Transition session to Ready state.
     *
     * Only valid from Opening status.
     */
    public function markReady(BrowserSession $session): bool
    {
        if ($session->status !== BrowserSessionStatus::Opening) {
            return false;
        }

        return $this->updateStatus($session, BrowserSessionStatus::Ready);
    }

    /**
     * Transition session to Busy state.
     *
     * Only valid from Ready status.
     */
    public function markBusy(BrowserSession $session): bool
    {
        if ($session->status !== BrowserSessionStatus::Ready) {
            return false;
        }

        return $this->updateStatus($session, BrowserSessionStatus::Busy);
    }

    /**
     * Transition session back to Ready after an action completes.
     *
     * Only valid from Busy status.
     */
    public function markIdle(BrowserSession $session): bool
    {
        if ($session->status !== BrowserSessionStatus::Busy) {
            return false;
        }

        return $this->updateStatus($session, BrowserSessionStatus::Ready);
    }

    /**
     * Transition session to Failed state with a reason.
     */
    public function markFailed(BrowserSession $session, string $reason): bool
    {
        if ($session->status->isTerminal()) {
            return false;
        }

        $session->failure_reason = $reason;

        return $this->updateStatus($session, BrowserSessionStatus::Failed);
    }

    /**
     * Transition session to Closed state (explicit closure).
     */
    public function markClosed(BrowserSession $session): bool
    {
        if ($session->status->isTerminal()) {
            return false;
        }

        return $this->updateStatus($session, BrowserSessionStatus::Closed);
    }

    /**
     * Transition session to Expired state.
     */
    public function markExpired(BrowserSession $session): bool
    {
        if ($session->status->isTerminal()) {
            return false;
        }

        return $this->updateStatus($session, BrowserSessionStatus::Expired);
    }

    /**
     * Update the page/tab state on a session.
     *
     * @param  array<int, array<string, mixed>>|null  $tabs
     * @param  array<string, mixed>|null  $pageState
     */
    public function updatePageState(
        BrowserSession $session,
        ?string $activeTabId,
        ?string $currentUrl,
        ?array $tabs,
        ?array $pageState,
    ): void {
        $session->update([
            'active_tab_id' => $activeTabId,
            'current_url' => $currentUrl,
            'tabs' => $tabs,
            'page_state' => $pageState,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Touch the last activity timestamp and extend expiry.
     */
    public function touchActivity(BrowserSession $session, int $ttlSeconds): void
    {
        $session->update([
            'last_activity_at' => now(),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);
    }

    /**
     * Find all sessions that are past their expiry time but not yet terminal.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BrowserSession>
     */
    public function findStaleSessions(): \Illuminate\Database\Eloquent\Collection
    {
        return BrowserSession::query()->stale()->get();
    }

    /**
     * Get all active sessions for a company (for operator visibility).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BrowserSession>
     */
    public function getActiveSessionsForCompany(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return BrowserSession::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /**
     * Apply a status transition and refresh the model.
     */
    private function updateStatus(BrowserSession $session, BrowserSessionStatus $status): bool
    {
        $session->status = $status;
        $session->last_activity_at = now();

        return $session->save();
    }
}
