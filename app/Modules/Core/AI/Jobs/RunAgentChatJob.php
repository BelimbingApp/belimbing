<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Base\AI\DTO\AiRuntimeError;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
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
 * Tracks lifecycle through OperationDispatch (queued → running → succeeded/failed).
 * Runs the agentic tool-calling loop with a background execution policy and
 * persists the assistant response to the session transcript.
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
     */
    public function handle(
        AgenticRuntime $runtime,
        MessageManager $messageManager,
        RuntimeResponseFactory $responseFactory,
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

            $messages = $messageManager->read($employeeId, $sessionId);
            $systemPrompt = $this->resolveSystemPrompt($employeeId, $dispatch->task);

            $result = $runtime->run(
                messages: $messages,
                employeeId: $employeeId,
                systemPrompt: $systemPrompt,
                modelOverride: $modelOverride,
                policy: ExecutionPolicy::background(),
            );

            $messageManager->appendAssistantMessage(
                $employeeId,
                $sessionId,
                $result['content'],
                $result['run_id'],
                $result['meta'],
            );

            $dispatch->markSucceeded(
                $result['run_id'],
                Str::limit($result['content'], 200),
                $result['meta'],
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
