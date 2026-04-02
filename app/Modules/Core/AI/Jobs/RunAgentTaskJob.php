<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Base\AI\Services\AiRuntimeLogger;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\KodiPromptFactory;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Queue job that executes an agent task via AgenticRuntime.
 *
 * Loads the dispatch record, authenticates as the acting user (so
 * capability-gated tools resolve correctly), sets the agent execution
 * context, builds the system prompt with entity context, and runs
 * the agentic tool-calling loop.
 *
 * Status lifecycle: queued -> running -> succeeded/failed/cancelled.
 */
class RunAgentTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Dedicated queue for agent task execution.
     */
    public const QUEUE = 'ai-agent-tasks';

    /**
     * The number of seconds the job can run before timing out.
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
        return 'RunAgentTask['.$this->dispatchId.']';
    }

    /**
     * Execute the agent task.
     */
    public function handle(
        AgenticRuntime $runtime,
        KodiPromptFactory $promptFactory,
        PromptRenderer $renderer,
        AgentExecutionContext $context,
    ): void {
        $dispatch = null;
        $promptMeta = null;

        try {
            $dispatch = OperationDispatch::query()->find($this->dispatchId);

            if ($dispatch === null || $dispatch->isTerminal()) {
                return;
            }

            $dispatch->markRunning();

            if ($dispatch->acting_for_user_id !== null) {
                Auth::loginUsingId($dispatch->acting_for_user_id);
            }

            $context->set(
                employeeId: $dispatch->employee_id,
                actingForUserId: $dispatch->acting_for_user_id,
                entityType: $dispatch->entity_type,
                entityId: $dispatch->entity_id,
                dispatchId: $dispatch->id,
            );

            $entity = $dispatch->entity;

            $package = $promptFactory->buildPackage($dispatch, $entity);
            $systemPrompt = $renderer->render($package);
            $promptMeta = $package->describe();

            $messages = [new Message(
                role: 'user',
                content: $dispatch->task,
                timestamp: new DateTimeImmutable,
            )];

            $result = $runtime->run(
                messages: $messages,
                employeeId: $dispatch->employee_id,
                systemPrompt: $systemPrompt,
                modelOverride: data_get($dispatch->meta, 'model_override'),
            );

            $this->recordResult($dispatch, $result, $promptMeta);
        } catch (\Throwable $e) {
            app(AiRuntimeLogger::class)->unhandledException(
                $result['run_id'] ?? 'dispatch_'.$this->dispatchId,
                $e,
                [
                    'dispatch_id' => $this->dispatchId,
                    'employee_id' => $dispatch?->employee_id,
                    'acting_for_user_id' => $dispatch?->acting_for_user_id,
                ],
            );

            if ($dispatch !== null && ! $dispatch->isTerminal()) {
                $dispatch->markFailed($e->getMessage());
            }

            throw $e;
        } finally {
            $context->clear();
            Auth::logout();
        }
    }

    /**
     * Record the runtime result on the dispatch record.
     *
     * @param  array{content: string, run_id: string, meta: array<string, mixed>}  $result
     * @param  array<string, mixed>|null  $promptMeta  Prompt package diagnostics
     */
    private function recordResult(OperationDispatch $dispatch, array $result, ?array $promptMeta): void
    {
        $hasError = isset($result['meta']['error_type']);

        if ($hasError) {
            $dispatch->markFailed((string) ($result['meta']['error'] ?? 'Unknown runtime error'));

            return;
        }

        $meta = ['runtime_meta' => $result['meta'] ?? []];

        if ($promptMeta !== null) {
            $meta['prompt_package'] = $promptMeta;
        }

        $dispatch->markSucceeded(
            $result['run_id'],
            $result['content'] ?? '',
            $meta,
        );
    }
}
