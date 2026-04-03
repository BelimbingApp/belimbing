<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Models\OrchestrationSession;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Queue job that executes a spawned child agent session.
 *
 * Analogous to RunAgentTaskJob but operates on OrchestrationSession
 * records instead of OperationDispatch. Sets orchestration lineage
 * on AgentExecutionContext so downstream tools can trace the spawn
 * chain. Authenticates as the acting user and runs the agentic loop
 * with bounded iterations from the spawn envelope.
 *
 * Status lifecycle: pending -> running -> completed/failed.
 */
class SpawnAgentSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Use the same queue as regular agent tasks.
     */
    public const QUEUE = 'ai-agent-tasks';

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 600;

    /**
     * @param  string  $orchestrationSessionId  The ai_orchestration_sessions primary key
     */
    public function __construct(
        public string $orchestrationSessionId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Human-readable name shown in payloads/logs.
     */
    public function displayName(): string
    {
        return 'SpawnAgentSession['.$this->orchestrationSessionId.']';
    }

    /**
     * Execute the spawned child agent session.
     */
    public function handle(
        AgenticRuntime $runtime,
        AgentExecutionContext $context,
    ): void {
        $session = null;

        try {
            $session = OrchestrationSession::query()->find($this->orchestrationSessionId);

            if ($session === null || $session->isTerminal()) {
                return;
            }

            $session->markRunning();

            if ($session->acting_for_user_id !== null) {
                Auth::loginUsingId($session->acting_for_user_id);
            }

            $context->set(
                employeeId: $session->child_employee_id,
                actingForUserId: $session->acting_for_user_id,
                entityType: null,
                entityId: null,
                dispatchId: $session->id,
                orchestrationSessionId: $session->id,
                parentDispatchId: $session->parent_dispatch_id,
            );

            $envelope = $session->spawn_envelope ?? [];
            $modelOverride = $envelope['model_override'] ?? null;

            $systemPrompt = $this->buildSystemPrompt($session);

            $messages = [new Message(
                role: 'user',
                content: $session->task,
                timestamp: new DateTimeImmutable,
            )];

            $result = $runtime->run(
                messages: $messages,
                employeeId: $session->child_employee_id,
                systemPrompt: $systemPrompt,
                modelOverride: $modelOverride,
                policy: ExecutionPolicy::background(),
            );

            $this->recordResult($session, $result);
        } catch (\Throwable $e) {
            report($e);

            if ($session !== null && ! $session->isTerminal()) {
                $session->markFailed($e->getMessage());
            }

            throw $e;
        } finally {
            $context->clear();
            Auth::logout();
        }
    }

    /**
     * Build the system prompt for the child session.
     */
    private function buildSystemPrompt(OrchestrationSession $session): string
    {
        $envelope = $session->spawn_envelope ?? [];
        $contextPayload = $envelope['context_payload'] ?? [];

        // Build a focused child prompt with task context
        $parts = [];
        $parts[] = 'You are executing a bounded child task spawned by another agent.';
        $parts[] = 'Task: '.$session->task;

        if ($session->task_type !== null) {
            $parts[] = 'Task type: '.$session->task_type;
        }

        if ($contextPayload !== []) {
            $parts[] = 'Context: '.json_encode($contextPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $parts[] = 'Complete this task to the best of your ability and return a clear result.';

        return implode("\n\n", $parts);
    }

    /**
     * Record the runtime result on the orchestration session.
     *
     * @param  array{content: string, run_id: string, meta: array<string, mixed>}  $result
     */
    private function recordResult(OrchestrationSession $session, array $result): void
    {
        $hasError = isset($result['meta']['error_type']);

        if ($hasError) {
            $session->markFailed((string) ($result['meta']['error'] ?? 'Unknown runtime error'));

            return;
        }

        $session->markCompleted(
            $result['content'] ?? '',
            ['run_id' => $result['run_id'], 'runtime_meta' => $result['meta'] ?? []],
        );
    }
}
