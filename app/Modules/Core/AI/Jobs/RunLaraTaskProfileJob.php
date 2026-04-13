<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\LaraTaskExecutionProfileRegistry;
use App\Modules\Core\Employee\Models\Employee;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class RunLaraTaskProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'ai-agent-tasks';

    public int $timeout = 600;

    public function __construct(
        public string $dispatchId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function displayName(): string
    {
        return 'RunLaraTaskProfile['.$this->dispatchId.']';
    }

    public function handle(
        AgenticRuntime $runtime,
        AgentExecutionContext $context,
        ConfigResolver $configResolver,
        LaraPromptFactory $promptFactory,
        LaraTaskExecutionProfileRegistry $profileRegistry,
    ): void {
        $dispatch = null;

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
                employeeId: Employee::LARA_ID,
                actingForUserId: $dispatch->acting_for_user_id,
                entityType: $dispatch->entity_type,
                entityId: $dispatch->entity_id,
                dispatchId: $dispatch->id,
            );

            $taskProfileKey = data_get($dispatch->meta, 'task_profile');

            if (! is_string($taskProfileKey) || $taskProfileKey === '') {
                $dispatch->markFailed('No Lara task profile was specified.');

                return;
            }

            $profile = $profileRegistry->find($taskProfileKey);

            if ($profile === null) {
                $dispatch->markFailed('Unknown Lara task profile: '.$taskProfileKey);

                return;
            }

            $resolvedConfig = $configResolver->resolveTaskWithPrimaryFallback(Employee::LARA_ID, $taskProfileKey);

            if ($resolvedConfig === null) {
                $dispatch->markFailed('No LLM configuration resolved for Lara task profile: '.$taskProfileKey);

                return;
            }

            $systemPrompt = $profileRegistry->composeSystemPrompt(
                $profile,
                $promptFactory->buildForCurrentUser($dispatch->task),
            );

            $result = $runtime->run(
                messages: [new Message(
                    role: 'user',
                    content: $dispatch->task,
                    timestamp: new DateTimeImmutable,
                )],
                employeeId: Employee::LARA_ID,
                systemPrompt: $systemPrompt,
                policy: ExecutionPolicy::forMode($profile->executionMode),
                configOverride: $resolvedConfig,
                allowedToolNames: $profile->allowedToolNames,
            );

            $this->recordResult($dispatch, $result, $profile->taskKey);
        } catch (\Throwable $e) {
            report($e);

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
     * @param  array{content: string, run_id: string, meta: array<string, mixed>}  $result
     */
    private function recordResult(OperationDispatch $dispatch, array $result, string $taskProfileKey): void
    {
        $hasError = isset($result['meta']['error_type']);

        if ($hasError) {
            $dispatch->markFailed((string) ($result['meta']['error'] ?? 'Unknown runtime error'));

            return;
        }

        $dispatch->markSucceeded(
            $result['run_id'],
            $result['content'] ?? '',
            [
                'runtime_meta' => $result['meta'] ?? [],
                'task_profile' => $taskProfileKey,
            ],
        );
    }
}
