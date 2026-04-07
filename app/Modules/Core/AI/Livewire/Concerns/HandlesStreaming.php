<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Jobs\RunAgentChatJob;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Str;

/**
 * Handles streaming chat run preparation and finalization.
 *
 * Uses dispatch-first architecture: creates a ChatTurn and OperationDispatch
 * upfront, then queues RunAgentChatJob. Page context is stored directly in
 * dispatch meta instead of a short-lived cache key.
 */
trait HandlesStreaming
{
    /**
     * Prepare a streaming run: persist user message, create turn + dispatch, queue job.
     *
     * Creates a ChatTurn and OperationDispatch with `execution_mode => 'interactive'`
     * so the job uses the interactive streaming policy. Page context is resolved
     * here (on the real page request) and stored directly in dispatch meta.
     *
     * @return array{turnId: string, session_id: string}|null Null when an orchestration shortcut handled the message or input was invalid
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

        $turn = ChatTurn::query()->create([
            'employee_id' => $this->employeeId,
            'session_id' => $this->selectedSessionId,
            'acting_for_user_id' => auth()->id(),
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
        ]);

        $dispatch = OperationDispatch::query()->create([
            'id' => OperationDispatch::ID_PREFIX.Str::random(20),
            'operation_type' => OperationType::BackgroundChat,
            'employee_id' => $this->employeeId,
            'acting_for_user_id' => auth()->id(),
            'task' => Str::limit($content, 500),
            'status' => OperationStatus::Queued,
            'meta' => [
                'session_id' => $this->selectedSessionId,
                'model_override' => $this->selectedModel,
                'page_context' => $this->resolvePageContextForDispatch(),
                'turn_id' => $turn->id,
                'execution_mode' => 'interactive',
            ],
        ]);

        RunAgentChatJob::dispatch($dispatch->id);

        $this->backgroundDispatchId = $dispatch->id;

        return [
            'turnId' => $turn->id,
            'session_id' => $this->selectedSessionId,
        ];
    }

    /**
     * Resolve page context from the current request for storage in dispatch meta.
     *
     * The job runs in a separate process whose route is not the user's page.
     * We resolve here (on the real page request) and embed the result directly
     * in the OperationDispatch meta so the job can hydrate context cheaply.
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
     * Called by the UI stop button. Marks the turn as cancelled so the
     * background job sees the terminal state on its next cooperative
     * cancellation check. Looks up the OperationDispatch from the turn
     * via dispatch meta (meta->turn_id) when $this->backgroundDispatchId
     * is not set.
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

        // Cancel the associated dispatch
        $dispatch = $this->backgroundDispatchId !== null
            ? OperationDispatch::query()->find($this->backgroundDispatchId)
            : OperationDispatch::query()
                ->where('meta->turn_id', $turnId)
                ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
                ->first();

        if ($dispatch !== null && ! $dispatch->isTerminal()) {
            $dispatch->markCancelled();
        }

        $this->backgroundDispatchId = null;
        $this->isLoading = false;
    }
}
