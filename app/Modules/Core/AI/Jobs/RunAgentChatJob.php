<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Base\AI\DTO\AiRuntimeError;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\RuntimeResponseFactory;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\AI\Services\TurnStreamBridge;
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
 * is persisted to the turn event stream progressively, giving the client
 * visibility into background work via the turn events SSE endpoint.
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
     * Uses `runStream()` to iterate events and publishes turn events
     * as they occur. Transcript is materialized after the stream completes.
     * Checks for cancellation between events.
     */
    public function handle(
        AgenticRuntime $runtime,
        MessageManager $messageManager,
        RuntimeResponseFactory $responseFactory,
        ChatRunPersister $persister,
        TurnStreamBridge $bridge,
        TurnEventPublisher $turnPublisher,
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

        $turn = null;

        try {
            if ($actingForUserId !== null) {
                Auth::loginUsingId($actingForUserId);
            }

            $this->hydratePageContext($dispatch);

            $messages = $messageManager->read($employeeId, $sessionId);
            [$systemPrompt, $promptMeta] = $this->resolvePromptPackage($employeeId, $messages);

            // Use pre-created turn from dispatch meta, or create one if absent
            $turnId = data_get($dispatch->meta, 'turn_id');
            $turn = $turnId !== null
                ? ChatTurn::query()->find($turnId)
                : null;

            if ($turn === null) {
                $turn = ChatTurn::query()->create([
                    'employee_id' => $employeeId,
                    'session_id' => $sessionId,
                    'acting_for_user_id' => $actingForUserId,
                    'status' => TurnStatus::Queued,
                    'current_phase' => TurnPhase::WaitingForWorker,
                ]);
            }

            $this->executeStreamingRun(
                $runtime,
                $messageManager,
                $persister,
                $bridge,
                $turnPublisher,
                $turn,
                $dispatch,
                $employeeId,
                $sessionId,
                $messages,
                $systemPrompt,
                $promptMeta,
                $modelOverride,
            );
        } catch (\Throwable $e) {
            report($e);

            if ($turn !== null && ! $turn->fresh()->isTerminal()) {
                $turnPublisher->turnFailed($turn, 'runtime_exception', $e->getMessage());
            }

            // Best-effort transcript materialization on exception
            $extraMeta = $promptMeta !== null ? ['prompt_package' => $promptMeta] : [];

            if ($turn !== null) {
                try {
                    $persister->materializeFromTurn($turn->refresh(), $messageManager, $employeeId, $sessionId, $extraMeta);
                } catch (\Throwable) {
                    // Fall back to direct error message write
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
                }
            }

            if (! $dispatch->isTerminal()) {
                $dispatch->markFailed($e->getMessage());
            }

            throw $e;
        } finally {
            Auth::logout();
        }
    }

    /**
     * Iterate the streaming generator, publishing turn events and checking cancellation.
     *
     * Transcript materialization happens after the stream completes via
     * ChatRunPersister::materializeFromTurn, which reads the turn's durable
     * event stream and writes equivalent transcript entries.
     *
     * @param  list<mixed>  $messages
     * @param  array<string, mixed>|null  $promptMeta  Prompt package metadata from resolvePromptPackage
     */
    private function executeStreamingRun(
        AgenticRuntime $runtime,
        MessageManager $messageManager,
        ChatRunPersister $persister,
        TurnStreamBridge $bridge,
        TurnEventPublisher $turnPublisher,
        ChatTurn $turn,
        OperationDispatch $dispatch,
        int $employeeId,
        string $sessionId,
        array $messages,
        ?string $systemPrompt,
        ?array $promptMeta,
        ?string $modelOverride,
    ): void {
        $policy = $this->resolveExecutionPolicy($dispatch);

        $runtimeStream = $runtime->runStream(
            $messages, $employeeId, $systemPrompt, $modelOverride,
            $policy, $sessionId, turnId: $turn->id,
        );

        $cancelled = false;

        foreach ($bridge->wrap($turn, $runtimeStream) as $event) {
            // Cooperative cancellation check
            if ($this->isCancelled($dispatch)) {
                $turn->refresh();

                if (! $turn->isTerminal()) {
                    $turnPublisher->turnCancelled($turn, 'User cancelled');
                }

                $cancelled = true;

                break;
            }
        }

        if ($cancelled) {
            return;
        }

        // Final cancellation check before materializing
        if ($this->isCancelled($dispatch)) {
            return;
        }

        // Materialize transcript from turn events
        $extraMeta = $promptMeta !== null ? ['prompt_package' => $promptMeta] : [];
        $persister->materializeFromTurn($turn, $messageManager, $employeeId, $sessionId, $extraMeta);

        // Mark dispatch based on turn outcome
        $turn->refresh();

        if ($turn->status === TurnStatus::Completed) {
            $content = $this->extractContentFromTurn($turn);
            $dispatch->markSucceeded(
                $turn->current_run_id ?? 'unknown',
                Str::limit($content, 200),
                [],
            );
        } elseif (! $dispatch->isTerminal()) {
            $dispatch->markFailed($turn->status === TurnStatus::Failed
                ? 'Turn failed'
                : 'Run completed without producing a response');
        }
    }

    /**
     * Extract the final assistant content from a completed turn's events.
     */
    private function extractContentFromTurn(ChatTurn $turn): string
    {
        $blockEvent = $turn->events()
            ->where('event_type', TurnEventType::AssistantOutputBlockCommitted->value)
            ->orderByDesc('seq')
            ->first();

        if ($blockEvent !== null) {
            return (string) ($blockEvent->payload['content'] ?? '');
        }

        return '';
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
     * When a run is dispatched from interactive to a worker, the page
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
            $holder->setContext(PageContext::fromArray($pageContext['context']));
        }

        if (isset($pageContext['snapshot']) && is_array($pageContext['snapshot'])) {
            $holder->setSnapshot(PageSnapshot::fromArray($pageContext['snapshot']));
        }
    }

    /**
     * Build the system prompt and prompt metadata from persisted messages.
     *
     * Uses the last
     * message content to build the prompt package so the job and inline
     * paths produce identical system prompts.
     *
     * Returns [systemPrompt, promptMeta] or [null, null] for employees
     * without a dedicated prompt factory.
     *
     * @param  list<mixed>  $messages  Persisted conversation messages
     * @return array{?string, ?array<string, mixed>}
     */
    private function resolvePromptPackage(int $employeeId, array $messages): array
    {
        if ($employeeId !== Employee::LARA_ID) {
            return [null, null];
        }

        $factory = app(LaraPromptFactory::class);
        $package = $factory->buildPackage($messages[count($messages) - 1]->content ?? '');

        return [
            app(PromptRenderer::class)->render($package),
            $package->describe(),
        ];
    }

    /**
     * Resolve execution policy from dispatch metadata.
     *
     * Reads `execution_mode` from the dispatch meta and converts to an
     * ExecutionPolicy. Falls back to background policy when absent.
     */
    private function resolveExecutionPolicy(OperationDispatch $dispatch): ExecutionPolicy
    {
        $modeValue = data_get($dispatch->meta, 'execution_mode');

        if ($modeValue !== null) {
            $mode = ExecutionMode::from($modeValue);

            return ExecutionPolicy::forMode($mode);
        }

        return ExecutionPolicy::background();
    }
}
