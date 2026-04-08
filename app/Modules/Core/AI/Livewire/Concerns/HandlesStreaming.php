<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Handles streaming chat run preparation and finalization.
 *
 * Uses direct-streaming architecture: creates a ChatTurn with runtime_meta,
 * returns a stream URL so Alpine can open a persistent fetch connection.
 * The streaming controller runs the agentic runtime inline.
 */
trait HandlesStreaming
{
    /**
     * Prepare a streaming run: persist user message, create turn, return stream URL.
     *
     * Creates a ChatTurn with runtime_meta containing model override, page
     * context, and execution mode. Returns the turn ID and stream URL so
     * Alpine can open a persistent fetch connection to the streaming controller.
     *
     * @return array{turnId: string, streamUrl: string, session_id: string}|null Null when an orchestration shortcut handled the message or input was invalid
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
        } elseif ($sessionManager->get($this->employeeId, $this->selectedSessionId) === null) {
            // Recover gracefully when client-side storage points to a stale session ID.
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

        $turn = ChatTurn::query()->create([
            'employee_id' => $this->employeeId,
            'session_id' => $this->selectedSessionId,
            'acting_for_user_id' => auth()->id(),
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
            'runtime_meta' => [
                'model_override' => $this->selectedModel,
                'page_context' => $this->resolvePageContextForDispatch(),
                'execution_mode' => 'interactive',
            ],
        ]);

        return [
            'turnId' => $turn->id,
            'streamUrl' => route('ai.chat.turn.stream', ['turnId' => $turn->id]),
            'session_id' => $this->selectedSessionId,
        ];
    }

    /**
     * Resolve page context from the current request for storage in turn runtime_meta.
     *
     * The streaming controller runs in a separate HTTP request whose route is
     * not the user's page. We resolve here (on the real page request) and embed
     * the result in the turn's runtime_meta so the runner can hydrate context.
     *
     * @return array{consent: string, context: array<string, mixed>|null, snapshot: array<string, mixed>|null}|null
     */
    private function resolvePageContextForDispatch(): ?array
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

        return $payload;
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
     * Called by the UI stop button. Sets cancel_requested_at on the turn
     * so the streaming controller's runner detects it cooperatively on
     * the next event iteration.
     */
    public function cancelActiveTurn(string $turnId): void
    {
        $turn = ChatTurn::query()->find($turnId);

        if ($turn === null || $turn->isTerminal()) {
            return;
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return;
        }

        $turn->requestCancel('User pressed stop');

        $this->isLoading = false;
    }
}
