<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Handles streaming chat run preparation and finalization.
 */
trait HandlesStreaming
{
    /**
     * Prepare a streaming run: persist user message, return SSE URL.
     *
     * The client opens an EventSource to the returned URL. The SSE endpoint
     * streams the response and persists the assistant message on completion.
     * Falls back to synchronous sendMessage() if streaming is unavailable.
     *
     * @return array{url: string, session_id: string}|null Null signals fallback to sync
     */
    public function prepareStreamingRun(): ?array
    {
        $hasAttachments = $this->attachments !== [] && $this->canAttachFiles();
        $hasText = trim($this->messageInput) !== '';

        if (! $this->isAgentActivated() || (! $hasText && ! $hasAttachments)) {
            return null;
        }

        $sessionManager = app(SessionManager::class);
        if ($this->selectedSessionId === null) {
            $session = $sessionManager->create($this->employeeId);
            $this->selectedSessionId = $session->id;
        }

        $content = trim($this->messageInput);
        $this->messageInput = '';

        $attachmentMeta = $hasAttachments
            ? $this->processAttachments($this->selectedSessionId)
            : [];
        $this->attachments = [];

        $userMeta = $attachmentMeta !== [] ? ['attachments' => $attachmentMeta] : [];

        // Check for orchestration shortcuts (sync-only, no streaming)
        if ($this->employeeId === Employee::LARA_ID && ! $hasAttachments) {
            $orchestration = app(LaraOrchestrationService::class)->dispatchFromMessage($content);
            if ($orchestration !== null) {
                // Fall through to sync path — return null to signal caller
                $messageManager = app(MessageManager::class);
                $messageManager->appendUserMessage($this->employeeId, $this->selectedSessionId, $content, $userMeta);

                $messageManager->appendAssistantMessage(
                    $this->employeeId,
                    $this->selectedSessionId,
                    $orchestration['assistant_content'],
                    $orchestration['run_id'],
                    $orchestration['meta'],
                );

                $this->lastRunMeta = [
                    'run_id' => $orchestration['run_id'],
                    ...$orchestration['meta'],
                ];

                $this->dispatch('agent-chat-response-ready');
                $this->dispatch('agent-chat-focus-composer');

                $navigationUrl = $orchestration['meta']['orchestration']['navigation']['url'] ?? null;
                if (is_string($navigationUrl) && str_starts_with($navigationUrl, '/')) {
                    $this->dispatch('agent-chat-execute-js', js: "Livewire.navigate('".$navigationUrl."')");
                }

                return null;
            }
        }

        $messageManager = app(MessageManager::class);
        $messageManager->appendUserMessage($this->employeeId, $this->selectedSessionId, $content, $userMeta);

        $pageContextKey = $this->cachePageContext();

        $url = route('ai.chat.stream', array_filter([
            'employee_id' => $this->employeeId,
            'session_id' => $this->selectedSessionId,
            'model' => $this->selectedModel,
            'page_ctx' => $pageContextKey,
        ]));

        return [
            'url' => $url,
            'session_id' => $this->selectedSessionId,
        ];
    }

    /**
     * Resolve page context on the current request and cache for the SSE endpoint.
     *
     * The SSE endpoint runs in a separate HTTP request whose route is
     * `ai.chat.stream` — not the user's page. Resolving page context from that
     * route would yield nothing useful. Instead, we resolve here (on the real
     * page request), cache the result under a short-lived key, and pass the key
     * to the SSE URL so the streaming controller can hydrate context cheaply.
     *
     * @return string|null Cache key, or null when consent is off or no context resolved
     */
    private function cachePageContext(): ?string
    {
        $consentLevel = $this->pageAwareness ?? 'page';

        if ($consentLevel === 'off') {
            return null;
        }

        $resolver = app(PageContextResolver::class);
        $context = $resolver->resolveFromUrl($this->pageUrl);

        if ($context === null) {
            return null;
        }

        $payload = [
            'consent' => $consentLevel,
            'context' => $context->toArray(),
            'snapshot' => null,
        ];

        if ($consentLevel === 'full') {
            $snapshot = $resolver->resolveSnapshotFromUrl($this->pageUrl);
            $payload['snapshot'] = $snapshot?->toArray();
        }

        $key = 'page_ctx:'.Str::random(20);
        Cache::put($key, $payload, now()->addSeconds(30));

        return $key;
    }

    /**
     * Finalize a completed streaming run by refreshing component state.
     */
    public function finalizeStreamingRun(): void
    {
        $this->isLoading = false;
        $this->dispatch('agent-chat-response-ready');
        $this->dispatch('agent-chat-focus-composer');
    }

    /**
     * Cancel the active turn for the current user and session.
     *
     * Called by the UI stop button. Marks the turn as cancelled so the
     * background job (if any) sees the terminal state on its next
     * cooperative cancellation check. Also cancels the OperationDispatch
     * so the job detects it via `isCancelled()`.
     */
    public function cancelActiveTurn(string $turnId): void
    {
        $turn = ChatTurn::query()->find($turnId);

        if ($turn === null || $turn->isTerminal()) {
            return;
        }

        // Ownership guard: only the acting user can cancel
        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return;
        }

        $publisher = app(TurnEventPublisher::class);
        $publisher->turnCancelled($turn, 'User pressed stop');

        // Also cancel the background dispatch if present
        if ($this->backgroundDispatchId !== null) {
            $dispatch = OperationDispatch::query()->find($this->backgroundDispatchId);

            if ($dispatch !== null && ! $dispatch->isTerminal()) {
                $dispatch->markCancelled();
            }

            $this->backgroundDispatchId = null;
        }

        $this->isLoading = false;
    }
}
