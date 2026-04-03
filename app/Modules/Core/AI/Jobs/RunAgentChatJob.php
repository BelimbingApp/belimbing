<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Base\AI\DTO\AiRuntimeError;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
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

/**
 * Queue job that executes a chat message through the agentic runtime in the background.
 *
 * Unlike RunAgentTaskJob (which works with OperationDispatch), this job
 * operates directly on a chat session. It runs the agentic tool-calling
 * loop with a background execution policy and persists the assistant
 * response to the session transcript.
 *
 * Status lifecycle: queued → running → completed/failed.
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
     * @param  int  $employeeId  The AI employee running the chat
     * @param  string  $sessionId  The chat session UUID
     * @param  string  $userMessage  The user's message content
     * @param  string|null  $modelOverride  Optional model selection override
     * @param  int|null  $actingForUserId  The human user on whose behalf
     */
    public function __construct(
        public int $employeeId,
        public string $sessionId,
        public string $userMessage,
        public ?string $modelOverride = null,
        public ?int $actingForUserId = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Human-readable name shown in payloads/logs.
     */
    public function displayName(): string
    {
        return 'RunAgentChat['.$this->sessionId.']';
    }

    /**
     * Execute the background chat run.
     */
    public function handle(
        AgenticRuntime $runtime,
        MessageManager $messageManager,
        RuntimeResponseFactory $responseFactory,
    ): void {
        try {
            if ($this->actingForUserId !== null) {
                Auth::loginUsingId($this->actingForUserId);
            }

            $messages = $messageManager->read($this->employeeId, $this->sessionId);

            $systemPrompt = $this->resolveSystemPrompt();

            $result = $runtime->run(
                messages: $messages,
                employeeId: $this->employeeId,
                systemPrompt: $systemPrompt,
                modelOverride: $this->modelOverride,
                policy: ExecutionPolicy::background(),
            );

            $messageManager->appendAssistantMessage(
                $this->employeeId,
                $this->sessionId,
                $result['content'],
                $result['run_id'],
                $result['meta'],
            );
        } catch (\Throwable $e) {
            report($e);

            $runId = 'run_'.str()->random(12);
            $error = AiRuntimeError::unexpected($e->getMessage());
            $fallback = $responseFactory->error($runId, 'unknown', 'unknown', $error);

            $messageManager->appendAssistantMessage(
                $this->employeeId,
                $this->sessionId,
                $fallback['content'],
                $fallback['run_id'],
                $fallback['meta'],
            );

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
    private function resolveSystemPrompt(): ?string
    {
        if ($this->employeeId === Employee::LARA_ID) {
            $factory = app(LaraPromptFactory::class);
            $package = $factory->buildPackage($this->userMessage);

            return app(PromptRenderer::class)->render($package);
        }

        return null;
    }
}
