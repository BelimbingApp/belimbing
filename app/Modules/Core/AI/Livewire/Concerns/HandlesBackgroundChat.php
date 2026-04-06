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
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\SessionManager;
use Illuminate\Support\Str;

/**
 * Handles background chat dispatch and progress polling.
 *
 * When the user explicitly requests background execution, this trait
 * creates an OperationDispatch record, dispatches RunAgentChatJob,
 * and provides polling for the client.
 *
 * The turn is created upfront so the client can immediately attach to
 * the turn event stream via the resume endpoint — no polling needed.
 *
 * Expects the using class to have: $employeeId, $selectedSessionId,
 * $selectedModel, $messageInput, $isLoading properties.
 */
trait HandlesBackgroundChat
{
    /**
     * Active background dispatch ID for the current session (if any).
     */
    public ?string $backgroundDispatchId = null;

    /**
     * Dispatch the current chat message to run in the background.
     *
     * Creates the ChatTurn upfront and an OperationDispatch record, then
     * queues RunAgentChatJob. The turn ID is stored in dispatch meta so
     * the job reuses it instead of creating a new one.
     */
    public function dispatchBackgroundChat(string $userMessage): void
    {
        if ($this->selectedSessionId === null) {
            $session = app(SessionManager::class)->create($this->employeeId);
            $this->selectedSessionId = $session->id;
        }

        // Create the turn upfront so the client can attach to its event stream
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
            'task' => Str::limit($userMessage, 500),
            'status' => OperationStatus::Queued,
            'meta' => [
                'session_id' => $this->selectedSessionId,
                'model_override' => $this->selectedModel,
                'page_context' => $this->capturePageContextForBackground(),
                'turn_id' => $turn->id,
            ],
        ]);

        RunAgentChatJob::dispatch($dispatch->id);

        $this->backgroundDispatchId = $dispatch->id;
        $this->isLoading = false;

        // Emit the turn's resume URL so the client can attach immediately
        $resumeUrl = route('ai.chat.turn.events', ['turnId' => $turn->id]);
        $this->dispatch('agent-chat-background-started', dispatchId: $dispatch->id, turnId: $turn->id, resumeUrl: $resumeUrl);
    }

    /**
     * Cancel an active background chat run.
     */
    public function cancelBackgroundChat(): void
    {
        if ($this->backgroundDispatchId === null) {
            return;
        }

        $dispatch = OperationDispatch::query()->find($this->backgroundDispatchId);

        if ($dispatch !== null && ! $dispatch->isTerminal()) {
            $dispatch->markCancelled();
        }

        $this->backgroundDispatchId = null;
    }

    /**
     * Capture current page context so the background job can hydrate it.
     *
     * @return array{consent: string, context: array<string, mixed>|null, snapshot: array<string, mixed>|null}|null
     */
    private function capturePageContextForBackground(): ?array
    {
        $holder = app(PageContextHolder::class);

        if ($holder->getConsentLevel() === 'off') {
            return null;
        }

        $context = $holder->getContext();

        return [
            'consent' => $holder->getConsentLevel(),
            'context' => $context?->toArray(),
            'snapshot' => $holder->getSnapshot()?->toArray(),
        ];
    }
}
