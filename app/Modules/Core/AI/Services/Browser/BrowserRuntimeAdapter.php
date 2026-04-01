<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

use App\Modules\Core\AI\Models\BrowserSession;
use Illuminate\Support\Carbon;

/**
 * Bridges BLB PHP services to Playwright execution with session awareness.
 *
 * Wraps PlaywrightRunner to provide session-scoped execution: the adapter
 * manages the Busy→Ready state transition around each action and updates
 * page state from runner results. Transport details remain hidden behind
 * a stable interface.
 *
 * Current implementation: delegates to PlaywrightRunner (per-command Chromium).
 * Future: may support persistent Playwright connections or WebSocket channels.
 */
class BrowserRuntimeAdapter
{
    public function __construct(
        private readonly PlaywrightRunner $runner,
        private readonly BrowserSessionRepository $repository,
    ) {}

    /**
     * Execute a browser action within the context of a persistent session.
     *
     * Transitions session to Busy before execution, back to Ready (or Failed)
     * after. Updates page state from the runner result when applicable.
     *
     * @param  BrowserSession  $session  The session to execute within
     * @param  string  $action  The browser action name
     * @param  array<string, mixed>  $arguments  Action-specific arguments
     * @return array{ok: bool, action: string, ...}
     *
     * @throws BrowserSessionException If the session is not in an actionable state
     */
    public function execute(BrowserSession $session, string $action, array $arguments = []): array
    {
        if (! $session->isActionable()) {
            throw new BrowserSessionException(
                "Cannot execute action '{$action}' on session '{$session->id}' "
                ."in status '{$session->status->value}'."
            );
        }

        if ($action === 'act') {
            $this->validateRefFreshness($session);
        }

        $this->repository->markBusy($session);

        try {
            // Inject headless mode from session state.
            $arguments['headless'] = $session->headless;

            $result = $this->runner->execute($action, $arguments);

            $this->updatePageStateFromResult($session, $action, $result);
            $this->repository->markIdle($session);

            return $result;
        } catch (\RuntimeException $e) {
            $this->repository->markFailed($session, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Check whether the Playwright runner infrastructure is available.
     */
    public function isAvailable(): bool
    {
        return $this->runner->isAvailable();
    }

    /**
     * Extract page state changes from a runner result and persist them.
     *
     * After navigate: update current URL and tab info.
     * After snapshot: update page_state with element ref namespace.
     * After tab operations: update tab list and active tab.
     *
     * @param  array<string, mixed>  $result
     */
    private function updatePageStateFromResult(BrowserSession $session, string $action, array $result): void
    {
        if (! ($result['ok'] ?? false)) {
            return;
        }

        $currentUrl = $session->current_url;
        $activeTabId = $session->active_tab_id;
        $tabs = $session->tabs;
        $pageState = $session->page_state ?? [];

        match ($action) {
            'navigate' => $currentUrl = $result['url'] ?? $currentUrl,
            'open' => $this->mergeOpenResult($result, $currentUrl, $activeTabId, $tabs),
            'close' => $this->mergeCloseResult($result, $activeTabId, $tabs),
            'snapshot' => $pageState = $this->mergeSnapshotState($result, $pageState),
            'tabs' => $tabs = $result['tabs'] ?? $tabs,
            default => null,
        };

        // For navigate and snapshot, use match's return value was consumed —
        // the variables were reassigned via match arm side effects.
        // Reassign from match results for open/close which return via helpers.
        if ($action === 'navigate') {
            $currentUrl = $result['url'] ?? $session->current_url;
            $pageState = $this->invalidateRefs($pageState);
        }

        $this->repository->updatePageState(
            session: $session,
            activeTabId: $activeTabId,
            currentUrl: $currentUrl,
            tabs: $tabs,
            pageState: $pageState,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>|null  &$tabs
     */
    private function mergeOpenResult(array $result, ?string &$currentUrl, ?string &$activeTabId, ?array &$tabs): void
    {
        if (isset($result['tab_id'])) {
            $activeTabId = $result['tab_id'];
        }

        if (isset($result['url'])) {
            $currentUrl = $result['url'];
        }

        if (isset($result['tabs'])) {
            $tabs = $result['tabs'];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>|null  &$tabs
     */
    private function mergeCloseResult(array $result, ?string &$activeTabId, ?array &$tabs): void
    {
        if (isset($result['tabs'])) {
            $tabs = $result['tabs'];
        }

        if (isset($result['active_tab_id'])) {
            $activeTabId = $result['active_tab_id'];
        }
    }

    /**
     * Merge snapshot element references into page state.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $pageState
     * @return array<string, mixed>
     */
    private function mergeSnapshotState(array $result, array $pageState): array
    {
        if (isset($result['refs'])) {
            $pageState['element_refs'] = $result['refs'];
            $pageState['refs_url'] = $result['url'] ?? null;
            $pageState['refs_captured_at'] = now()->toIso8601String();
        }

        return $pageState;
    }

    /**
     * Clear stale element refs from page state after navigation.
     *
     * Navigation changes the DOM, so refs from a prior snapshot are no
     * longer valid. The agent must take a new snapshot before acting.
     *
     * @param  array<string, mixed>  $pageState
     * @return array<string, mixed>
     */
    private function invalidateRefs(array $pageState): array
    {
        unset(
            $pageState['element_refs'],
            $pageState['refs_url'],
            $pageState['refs_captured_at'],
        );

        return $pageState;
    }

    /**
     * Validate that element refs exist, match the current URL, and are fresh.
     *
     * The `act` action operates against element refs from a prior snapshot.
     * If no snapshot has been taken, refs belong to a different page, or the
     * snapshot is older than the configured threshold, the action is rejected
     * with a clear instruction to take a new snapshot first.
     *
     * @throws BrowserSessionException If refs are missing, stale, or mismatched
     */
    private function validateRefFreshness(BrowserSession $session): void
    {
        $pageState = $session->page_state ?? [];

        // No refs at all — snapshot never taken.
        if (empty($pageState['element_refs'])) {
            throw new BrowserSessionException(
                'Cannot act: no element refs available. Take a snapshot first.'
            );
        }

        // Refs belong to a different URL than the current page.
        $refsUrl = $pageState['refs_url'] ?? null;
        $currentUrl = $session->current_url;

        if ($refsUrl !== null && $currentUrl !== null && $refsUrl !== $currentUrl) {
            throw new BrowserSessionException(
                'Cannot act: element refs are from a different page '
                ."(refs: {$refsUrl}, current: {$currentUrl}). Take a new snapshot."
            );
        }

        // Refs too old.
        $capturedAt = $pageState['refs_captured_at'] ?? null;

        if ($capturedAt !== null) {
            $refMaxAge = (int) config('ai.tools.browser.ref_stale_seconds', 300);
            $capturedTime = Carbon::parse($capturedAt);

            if ($capturedTime->diffInSeconds(now()) > $refMaxAge) {
                throw new BrowserSessionException(
                    'Cannot act: element refs are stale (captured '
                    .$capturedTime->diffForHumans().'). Take a new snapshot.'
                );
            }
        }
    }
}
