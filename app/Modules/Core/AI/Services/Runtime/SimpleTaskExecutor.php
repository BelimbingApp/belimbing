<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Runtime;

use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ConfigResolver;
use Illuminate\Support\Str;

/**
 * Executor for Lara simple tasks.
 *
 * A simple task is a single-inference LLM call with no tools. The executor:
 *   1. Resolves the task model via {@see ConfigResolver::resolveTask()}, falling
 *      back to the company default when the task has no saved configuration.
 *   2. Routes the call through {@see AgenticRuntime} with an empty tool allowlist,
 *      so the run is recorded in the Control Plane and wire-logged when enabled.
 *   3. Returns the trimmed text content on success, or null on any error so
 *      callers can degrade silently without breaking user-facing flows.
 *
 * Usage:
 *   $title = $executor->execute($employeeId, 'titling', $messages, $prompt, maxOutputTokens: 20, timeout: 15);
 */
final readonly class SimpleTaskExecutor
{
    public function __construct(
        private ConfigResolver $configResolver,
        private AgenticRuntime $agenticRuntime,
    ) {}

    /**
     * Execute a simple task and return the LLM's text response.
     *
     * @param  list<\App\Modules\Core\AI\DTO\Message>  $messages  Conversation history
     * @param  string  $systemPrompt  Task-specific system prompt
     * @param  int  $maxOutputTokens  Upper bound on the response; keep tight for simple tasks
     * @param  int  $timeout  Per-call timeout in seconds
     * @return string|null  Trimmed response text, or null on any error or empty response
     */
    public function execute(
        int $employeeId,
        string $taskKey,
        array $messages,
        string $systemPrompt,
        int $maxOutputTokens = 64,
        int $timeout = 30,
        ?string $sessionId = null,
    ): ?string {
        $config = $this->configResolver->resolveTask($employeeId, $taskKey)
            ?? $this->configResolver->resolveDefault($employeeId);

        $policy = new ExecutionPolicy(
            mode: ExecutionMode::Interactive,
            timeoutSeconds: $timeout,
        );
        $runId = (string) Str::ulid();

        AiRun::query()->create([
            'id' => $runId,
            'employee_id' => $employeeId,
            'session_id' => $sessionId,
            'acting_for_user_id' => auth()->id(),
            'source' => 'simple_task',
            'execution_mode' => $policy->mode->value,
            'status' => AiRunStatus::Queued,
        ]);

        $result = $this->agenticRuntime->run(
            messages: $messages,
            employeeId: $employeeId,
            runId: $runId,
            systemPrompt: $systemPrompt,
            policy: $policy,
            sessionId: $sessionId,
            configOverride: $config,
            allowedToolNames: [],
            executionControlsOverride: ['limits' => ['max_output_tokens' => $maxOutputTokens]],
            context: RuntimeInvocationContext::forSimpleTask($taskKey),
        );

        if (isset($result['meta']['error_type'])) {
            return null;
        }

        $content = $result['content'] ?? '';

        return $content !== '' ? $content : null;
    }
}
