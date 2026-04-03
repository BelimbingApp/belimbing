<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Jobs\RunAgentChatJob;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use Illuminate\Support\Str;

/**
 * Handles background chat dispatch and progress polling.
 *
 * When an interactive chat run times out or the user explicitly requests
 * background execution, this trait creates an OperationDispatch record,
 * dispatches RunAgentChatJob, and provides polling for the client.
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
     * Creates an OperationDispatch record and queues RunAgentChatJob.
     * The user message must already be persisted to the transcript.
     */
    public function dispatchBackgroundChat(string $userMessage): void
    {
        if ($this->selectedSessionId === null) {
            $session = app(SessionManager::class)->create($this->employeeId);
            $this->selectedSessionId = $session->id;
        }

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
            ],
        ]);

        RunAgentChatJob::dispatch($dispatch->id);

        $this->backgroundDispatchId = $dispatch->id;
        $this->isLoading = false;

        $this->dispatch('agent-chat-background-started', dispatchId: $dispatch->id);
    }

    /**
     * Poll the status of an active background chat run.
     *
     * Called by the client on an interval. Returns the current status
     * and clears the dispatch ID when the run reaches a terminal state.
     *
     * @return array{status: string, label: string, result_summary: string|null, error: string|null, finished: bool}
     */
    public function pollBackgroundChat(): array
    {
        if ($this->backgroundDispatchId === null) {
            return [
                'status' => 'none',
                'label' => '',
                'result_summary' => null,
                'error' => null,
                'finished' => true,
            ];
        }

        $dispatch = OperationDispatch::query()->find($this->backgroundDispatchId);

        if ($dispatch === null) {
            $this->backgroundDispatchId = null;

            return [
                'status' => 'not_found',
                'label' => __('Background run not found'),
                'result_summary' => null,
                'error' => null,
                'finished' => true,
            ];
        }

        $finished = $dispatch->isTerminal();

        if ($finished) {
            $this->backgroundDispatchId = null;
        }

        return [
            'status' => $dispatch->status->value,
            'label' => $this->backgroundStatusLabel($dispatch->status),
            'result_summary' => $dispatch->result_summary,
            'error' => $dispatch->error_message,
            'finished' => $finished,
        ];
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
     * Handle a timeout from an interactive run by offering background execution.
     *
     * Called from sendMessage() when the result indicates a timeout error.
     * Persists a system notice to the transcript and dispatches the job.
     *
     * @param  string  $userMessage  The original user message
     */
    protected function handleTimeoutWithBackgroundOffer(string $userMessage): void
    {
        $messageManager = app(MessageManager::class);

        $notice = __('This task is taking longer than expected. Running it in the background — you\'ll see the response when it\'s ready.');
        $messageManager->appendAssistantMessage(
            $this->employeeId,
            $this->selectedSessionId,
            $notice,
            'run_bg_'.Str::random(8),
            ['source' => 'background_offload', 'original_error' => 'timeout'],
        );

        $this->dispatchBackgroundChat($userMessage);
    }

    /**
     * Human-readable label for a background run status.
     */
    private function backgroundStatusLabel(OperationStatus $status): string
    {
        return match ($status) {
            OperationStatus::Queued => __('Queued…'),
            OperationStatus::Running => __('Running in background…'),
            OperationStatus::Succeeded => __('Completed'),
            OperationStatus::Failed => __('Failed'),
            OperationStatus::Cancelled => __('Cancelled'),
        };
    }
}
