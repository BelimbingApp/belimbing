<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

use App\Modules\Core\AI\DTO\BrowserSessionState;
use App\Modules\Core\AI\DTO\BrowserTabState;
use App\Modules\Core\AI\Models\BrowserSession;

/**
 * Owns browser session creation, lookup, execution, expiration, and closure.
 *
 * This is the primary entry point for all browser lifecycle operations.
 * Tools and services should interact with the browser subsystem through
 * this manager rather than touching the repository or runtime directly.
 *
 * Enforces concurrency limits, session reuse, and idle timeout policy.
 */
class BrowserSessionManager
{
    public function __construct(
        private readonly BrowserSessionRepository $repository,
        private readonly BrowserRuntimeAdapter $runtimeAdapter,
        private readonly BrowserContextFactory $contextFactory,
    ) {}

    /**
     * Open or reuse a browser session for the given agent.
     *
     * If an active session already exists for this agent identity tuple, it is
     * reused. Otherwise a new session is created if the concurrency limit
     * allows. Returns the session on success, or throws on limit/availability.
     *
     * @param  int  $agentEmployeeId  Agent (employee) requesting the session
     * @param  int  $companyId  Company scope for isolation and limits
     * @param  int|null  $actingForUserId  Authenticated user the agent acts for, if any
     * @param  bool  $headless  Whether the session should run headless
     *
     * @throws BrowserSessionException If the browser is unavailable or limit is reached
     */
    public function open(int $agentEmployeeId, int $companyId, ?int $actingForUserId, bool $headless): BrowserSession
    {
        if (! $this->isAvailable()) {
            throw new BrowserSessionException(
                'Browser automation is not available. The tool is either disabled or Playwright is not installed.'
            );
        }

        // Reuse existing active session for same agent identity tuple.
        $existing = $this->repository->findActiveForIdentity(
            $agentEmployeeId,
            $companyId,
            $actingForUserId,
        );

        if ($existing !== null) {
            $this->repository->touchActivity($existing, $this->sessionTtl());

            return $existing;
        }

        // Check concurrency limit before creating.
        $maxContexts = (int) config('ai.tools.browser.max_contexts_per_company', 3);
        $activeCount = $this->repository->countActiveForCompany($companyId);

        if ($activeCount >= $maxContexts) {
            throw new BrowserSessionException(
                "Company has reached the maximum of {$maxContexts} concurrent browser sessions."
            );
        }

        $session = $this->repository->create(
            $agentEmployeeId,
            $companyId,
            $actingForUserId,
            $headless,
            $this->sessionTtl(),
        );
        $this->repository->markReady($session);

        return $session;
    }

    /**
     * Execute a browser action within a session.
     *
     * Delegates to the runtime adapter which handles Busy/Ready transitions.
     * Extends the session TTL after successful execution.
     *
     * @param  string  $sessionId  Session to execute within
     * @param  string  $action  The browser action name
     * @param  array<string, mixed>  $arguments  Action-specific arguments
     * @return array{ok: bool, action: string, ...}
     *
     * @throws BrowserSessionException If the session is not found or not actionable
     */
    public function executeAction(string $sessionId, string $action, array $arguments = []): array
    {
        $session = $this->repository->find($sessionId);

        if ($session === null) {
            throw new BrowserSessionException("Browser session '{$sessionId}' not found.");
        }

        if ($session->isExpired()) {
            $this->repository->markExpired($session);

            throw new BrowserSessionException("Browser session '{$sessionId}' has expired.");
        }

        $result = $this->runtimeAdapter->execute($session, $action, $arguments);

        // Extend TTL after every successful action.
        $this->repository->touchActivity($session, $this->sessionTtl());

        return $result;
    }

    /**
     * Look up a session by ID.
     */
    public function getSession(string $sessionId): ?BrowserSession
    {
        return $this->repository->find($sessionId);
    }

    /**
     * Build an operator-visible state snapshot for a session.
     */
    public function getSessionState(string $sessionId): ?BrowserSessionState
    {
        $session = $this->repository->find($sessionId);

        if ($session === null) {
            return null;
        }

        return $this->buildSessionState($session);
    }

    /**
     * Explicitly close a browser session.
     *
     * @throws BrowserSessionException If the session is not found
     */
    public function close(string $sessionId): void
    {
        $session = $this->repository->find($sessionId);

        if ($session === null) {
            throw new BrowserSessionException("Browser session '{$sessionId}' not found.");
        }

        $this->repository->markClosed($session);
    }

    /**
     * Sweep stale sessions — expire sessions past their TTL.
     *
     * @return int Number of sessions expired
     */
    public function sweepStaleSessions(): int
    {
        $stale = $this->repository->findStaleSessions();
        $count = 0;

        foreach ($stale as $session) {
            if ($this->repository->markExpired($session)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check whether browser automation is available.
     */
    public function isAvailable(): bool
    {
        return config('ai.tools.browser.enabled', false)
            && $this->contextFactory->isAvailable()
            && $this->runtimeAdapter->isAvailable();
    }

    /**
     * Get all active sessions for a company (operator visibility).
     *
     * @return list<BrowserSessionState>
     */
    public function getActiveSessionsForCompany(int $companyId): array
    {
        $sessions = $this->repository->getActiveSessionsForCompany($companyId);

        return $sessions->map(fn (BrowserSession $s): BrowserSessionState => $this->buildSessionState($s))
            ->values()
            ->all();
    }

    /**
     * Build an operator-visible state snapshot from a session model.
     */
    private function buildSessionState(BrowserSession $session): BrowserSessionState
    {
        $tabs = [];

        if (is_array($session->tabs)) {
            foreach ($session->tabs as $tabData) {
                if (is_array($tabData)) {
                    $tabs[] = BrowserTabState::fromArray($tabData);
                }
            }
        }

        $pageState = $session->page_state ?? [];

        return new BrowserSessionState(
            sessionId: $session->id,
            agentEmployeeId: $session->agent_employee_id,
            actingForUserId: $session->acting_for_user_id,
            companyId: $session->company_id,
            status: $session->status,
            headless: $session->headless,
            activeTabId: $session->active_tab_id,
            currentUrl: $session->current_url,
            tabs: $tabs,
            lastSnapshotRef: $pageState['refs_captured_at'] ?? null,
            failureReason: $session->failure_reason,
            createdAt: $session->created_at?->toIso8601String() ?? '',
            lastActivityAt: $session->last_activity_at?->toIso8601String() ?? '',
            expiresAt: $session->expires_at?->toIso8601String(),
        );
    }

    /**
     * Session time-to-live in seconds from config.
     */
    private function sessionTtl(): int
    {
        return (int) config('ai.tools.browser.context_idle_timeout_seconds', 300);
    }
}
