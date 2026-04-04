<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Base\AI\DTO\AiRuntimeError;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\RuntimeResponseFactory;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Queue job that executes a chat message through the agentic runtime in the background.
 *
 * Uses the streaming generator (`runStream`) so tool-calling activity
 * is persisted to the transcript progressively, giving the client
 * visibility into background work via polling.
 *
 * Tracks lifecycle through OperationDispatch (queued → running → succeeded/failed).
 * Checks for cancellation between streamed events so a user-initiated cancel
 * is honoured cooperatively.
 */
class RunAgentChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Use the same queue as regular agent tasks.
     */
    public const QUEUE = 'ai-agent-tasks';

    /**
     * Maximum execution time in seconds (matches background policy).
     */
    public int $timeout = 600;

    /**
     * @param  string  $dispatchId  The ai_operation_dispatches primary key
     */
    public function __construct(
        public string $dispatchId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Human-readable name shown in payloads/logs.
     */
    public function displayName(): string
    {
        return 'RunAgentChat['.$this->dispatchId.']';
    }

    /**
     * Execute the background chat run.
     *
     * Uses `runStream()` to iterate events and persists activity entries
     * (thinking, tool calls, results) as they occur. Checks for
     * cancellation between events.
     */
    public function handle(
        AgenticRuntime $runtime,
        MessageManager $messageManager,
        RuntimeResponseFactory $responseFactory,
        ChatRunPersister $persister,
    ): void {
        $dispatch = OperationDispatch::query()->find($this->dispatchId);

        if ($dispatch === null || $dispatch->isTerminal()) {
            return;
        }

        $dispatch->markRunning();

        $employeeId = (int) $dispatch->employee_id;
        $sessionId = (string) data_get($dispatch->meta, 'session_id');
        $modelOverride = data_get($dispatch->meta, 'model_override');
        $actingForUserId = $dispatch->acting_for_user_id;

        try {
            if ($actingForUserId !== null) {
                Auth::loginUsingId($actingForUserId);
            }

            $this->hydratePageContext($dispatch);

            $messages = $messageManager->read($employeeId, $sessionId);
            $systemPrompt = $this->resolveSystemPrompt($employeeId, $dispatch->task);

            $this->executeStreamingRun(
                $runtime,
                $messageManager,
                $persister,
                $dispatch,
                $employeeId,
                $sessionId,
                $messages,
                $systemPrompt,
                $modelOverride,
            );
        } catch (\Throwable $e) {
            report($e);

            $runId = 'run_'.Str::random(12);
            $error = AiRuntimeError::unexpected($e->getMessage());
            $fallback = $responseFactory->error($runId, 'unknown', 'unknown', $error);

            $messageManager->appendAssistantMessage(
                $employeeId,
                $sessionId,
                $fallback['content'],
                $fallback['run_id'],
                $fallback['meta'],
            );

            if (! $dispatch->isTerminal()) {
                $dispatch->markFailed($e->getMessage());
            }

            throw $e;
        } finally {
            Auth::logout();
        }
    }

    /**
     * Iterate the streaming generator, persisting activity and checking cancellation.
     *
     * @param  list<mixed>  $messages
     */
    private function executeStreamingRun(
        AgenticRuntime $runtime,
        MessageManager $messageManager,
        ChatRunPersister $persister,
        OperationDispatch $dispatch,
        int $employeeId,
        string $sessionId,
        array $messages,
        ?string $systemPrompt,
        ?string $modelOverride,
    ): void {
        $thinkingPersisted = false;
        $fullContent = null;
        $runId = null;
        $meta = null;
        $hadError = false;

        foreach ($runtime->runStream($messages, $employeeId, $systemPrompt, $modelOverride, ExecutionPolicy::background(), $sessionId) as $event) {
            // Cooperative cancellation check
            if ($this->isCancelled($dispatch)) {
                return;
            }

            $eventName = $event['event'];
            $data = $event['data'];

            $eventRunId = $data['run_id'] ?? $runId;
            if ($eventRunId !== null && $runId === null) {
                $runId = $eventRunId;
            }

            if ($eventName === 'status') {
                $persister->persistStatusEvent(
                    $messageManager,
                    $employeeId,
                    $sessionId,
                    $eventRunId,
                    $data,
                    $thinkingPersisted,
                );
            }

            if ($eventName === 'done') {
                $fullContent = $data['content'] ?? '';
                $runId = $data['run_id'] ?? $runId;
                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

                continue;
            }

            if ($eventName === 'error') {
                $hadError = true;
                $persister->persistError($messageManager, $employeeId, $sessionId, $data);
            }
        }

        // Final cancellation check before persisting result
        if ($this->isCancelled($dispatch)) {
            return;
        }

        if (! $hadError && $fullContent !== null && $runId !== null) {
            $persister->persistAssistantMessage(
                $messageManager,
                $employeeId,
                $sessionId,
                $fullContent,
                $runId,
                $meta ?? [],
            );

            $dispatch->markSucceeded(
                $runId,
                Str::limit($fullContent, 200),
                $meta ?? [],
            );
        } elseif (! $hadError && ! $dispatch->isTerminal()) {
            $dispatch->markFailed('Run completed without producing a response');
        } elseif ($hadError && ! $dispatch->isTerminal()) {
            $dispatch->markFailed('Run encountered an error');
        }
    }

    /**
     * Check whether the dispatch has been cancelled by the user.
     *
     * Refreshes the model from the database to pick up cancellation
     * that may have occurred while the run was in progress.
     */
    private function isCancelled(OperationDispatch $dispatch): bool
    {
        $dispatch->refresh();

        return $dispatch->isTerminal();
    }

    /**
     * Hydrate the request-scoped PageContextHolder from dispatch metadata.
     *
     * When a run is offloaded from interactive to background, the page
     * context that was resolved on the original request is stored in
     * the dispatch meta so the background worker can reconstruct it.
     */
    private function hydratePageContext(OperationDispatch $dispatch): void
    {
        $pageContext = data_get($dispatch->meta, 'page_context');

        if (! is_array($pageContext)) {
            return;
        }

        $holder = app(PageContextHolder::class);
        $holder->setConsentLevel($pageContext['consent'] ?? 'page');

        if (isset($pageContext['context']) && is_array($pageContext['context'])) {
            $holder->setContext(\App\Modules\Core\AI\DTO\PageContext::fromArray($pageContext['context']));
        }

        if (isset($pageContext['snapshot']) && is_array($pageContext['snapshot'])) {
            $holder->setSnapshot(\App\Modules\Core\AI\DTO\PageSnapshot::fromArray($pageContext['snapshot']));
        }
    }

    /**
     * Build the system prompt for the employee.
     *
     * Delegates to the appropriate prompt factory based on employee identity.
     * Returns null for employees without a dedicated prompt factory — the
     * runtime falls back to its default system prompt.
     */
    private function resolveSystemPrompt(int $employeeId, string $userMessage): ?string
    {
        if ($employeeId === Employee::LARA_ID) {
            $factory = app(LaraPromptFactory::class);
            $package = $factory->buildPackage($userMessage);

            return app(PromptRenderer::class)->render($package);
        }

        return null;
    }
}
