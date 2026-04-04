<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\AI\DTO\AiRuntimeError;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\MessageManager;

/**
 * Records AI run lifecycle events to the ai_runs ledger.
 *
 * All methods are idempotent:
 * - start() is insert-only — duplicate run_id is a no-op
 * - complete() and fail() only transition from 'running' — calls on terminal rows are no-ops
 * - attachDispatch() is a nullable FK write — safe to call multiple times
 *
 * Never throws on idempotent no-ops.
 */
class RunRecorder
{
    /**
     * Record the start of a new run.
     *
     * Insert-only — if the run_id already exists, this is a no-op.
     *
     * @param  string  $runId  Unique run identifier (e.g. "run_aBcDeFgHiJkL")
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $source  Origin: 'chat', 'stream', 'delegate_task', 'orchestration', 'cron'
     * @param  string  $executionMode  'interactive' or 'background'
     * @param  string|null  $sessionId  Chat session ID (null for headless/cron runs)
     * @param  int|null  $actingForUserId  User on whose behalf (null for system-initiated)
     * @param  int|null  $timeoutSeconds  Configured timeout for this run
     */
    public function start(
        string $runId,
        int $employeeId,
        string $source,
        string $executionMode = 'interactive',
        ?string $sessionId = null,
        ?int $actingForUserId = null,
        ?int $timeoutSeconds = null,
    ): void {
        AiRun::query()->firstOrCreate(
            ['id' => $runId],
            [
                'employee_id' => $employeeId,
                'session_id' => $sessionId,
                'acting_for_user_id' => $actingForUserId,
                'source' => $source,
                'execution_mode' => $executionMode,
                'status' => AiRunStatus::Running,
                'timeout_seconds' => $timeoutSeconds,
                'started_at' => now(),
            ],
        );
    }

    /**
     * Record successful completion of a run.
     *
     * Only transitions from 'running' — calls on a terminal row are no-ops.
     *
     * @param  string  $runId  Run identifier
     * @param  array<string, mixed>  $meta  Runtime metadata (provider, model, latency, tokens, tool actions, etc.)
     */
    public function complete(string $runId, array $meta): void
    {
        $run = $this->findRunning($runId);

        if ($run === null) {
            return;
        }

        $run->update([
            'status' => AiRunStatus::Succeeded,
            'provider_name' => $meta['provider_name'] ?? $meta['llm']['provider'] ?? null,
            'model' => $meta['model'] ?? $meta['llm']['model'] ?? null,
            'latency_ms' => $meta['latency_ms'] ?? null,
            'prompt_tokens' => $meta['tokens']['prompt'] ?? null,
            'completion_tokens' => $meta['tokens']['completion'] ?? null,
            'retry_attempts' => $meta['retry_attempts'] ?? null,
            'fallback_attempts' => $meta['fallback_attempts'] ?? null,
            'tool_actions' => $meta['tool_actions'] ?? null,
            'meta' => $this->sanitizeMeta($meta),
            'finished_at' => now(),
        ]);
    }

    /**
     * Record a failed run.
     *
     * Only transitions from 'running' — calls on a terminal row are no-ops.
     *
     * @param  string  $runId  Run identifier
     * @param  AiRuntimeError  $error  Structured runtime error
     * @param  array<string, mixed>  $meta  Additional runtime metadata
     */
    public function fail(string $runId, AiRuntimeError $error, array $meta = []): void
    {
        $run = $this->findRunning($runId);

        if ($run === null) {
            return;
        }

        $run->update([
            'status' => AiRunStatus::Failed,
            'provider_name' => $meta['provider_name'] ?? $meta['llm']['provider'] ?? null,
            'model' => $meta['model'] ?? $meta['llm']['model'] ?? null,
            'latency_ms' => $error->latencyMs > 0 ? $error->latencyMs : ($meta['latency_ms'] ?? null),
            'retry_attempts' => $meta['retry_attempts'] ?? null,
            'fallback_attempts' => $meta['fallback_attempts'] ?? null,
            'tool_actions' => $meta['tool_actions'] ?? null,
            'error_type' => $error->errorType->value,
            'error_message' => $error->userMessage,
            'meta' => $this->sanitizeMetaWithDiagnostic($meta, $error),
            'finished_at' => now(),
        ]);
    }

    /**
     * Link an async dispatch to a run.
     *
     * Safe to call multiple times — last write wins (always the same dispatch).
     */
    public function attachDispatch(string $runId, string $dispatchId): void
    {
        AiRun::query()
            ->where('id', $runId)
            ->update(['dispatch_id' => $dispatchId]);
    }

    /**
     * Find a run by ID.
     */
    public function find(string $runId): ?AiRun
    {
        return AiRun::query()->find($runId);
    }

    /**
     * Reconstruct an ai_runs row from transcript entries.
     *
     * Reads the v2 transcript for the given session/run, computes tool actions,
     * timing, and outcome, then upserts the ai_runs row. This is a repair tool,
     * not the hot path — start()/complete()/fail() remain the normal write path.
     */
    public function reconstructFromTranscript(int $employeeId, string $sessionId, string $runId): void
    {
        $messageManager = app(MessageManager::class);
        $messages = $messageManager->read($employeeId, $sessionId);

        $toolActions = [];
        $tokens = ['prompt' => null, 'completion' => null];
        $hasAssistantMessage = false;
        $hasError = false;

        foreach ($messages as $message) {
            if (! $this->messageBelongsToReconstructedRun($message, $runId)) {
                continue;
            }

            if ($message->type === 'tool_result') {
                $toolActions[] = $this->reconstructedToolAction($message);

                continue;
            }

            $this->captureAssistantMessageState($message, $runId, $tokens, $hasAssistantMessage, $hasError);
        }

        AiRun::query()->updateOrCreate(
            ['id' => $runId],
            $this->reconstructedRunPayload($employeeId, $sessionId, $tokens, $toolActions, $hasAssistantMessage, $hasError),
        );
    }

    /**
     * Find a run that is still in 'running' status.
     *
     * Returns null if the run does not exist or has already reached a terminal state.
     */
    private function findRunning(string $runId): ?AiRun
    {
        return AiRun::query()
            ->where('id', $runId)
            ->where('status', AiRunStatus::Running)
            ->first();
    }

    /**
     * Extract safe metadata for persistence, excluding fields already stored as columns.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function sanitizeMeta(array $meta): ?array
    {
        $safe = array_diff_key($meta, array_flip([
            'provider_name', 'model', 'latency_ms', 'tokens',
            'retry_attempts', 'fallback_attempts', 'tool_actions',
            'error', 'error_type', 'diagnostic', 'message_type',
            'llm',
        ]));

        return $safe !== [] ? $safe : null;
    }

    /**
     * Build sanitized meta with diagnostic information from the error.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function sanitizeMetaWithDiagnostic(array $meta, AiRuntimeError $error): ?array
    {
        $safe = $this->sanitizeMeta($meta);

        if ($error->diagnostic !== null && $error->diagnostic !== '') {
            $safe ??= [];
            $safe['diagnostic'] = $error->diagnostic;
        }

        return $safe;
    }

    private function messageBelongsToReconstructedRun(Message $message, string $runId): bool
    {
        return $message->runId === $runId;
    }

    /**
     * @return array{tool: mixed, result_length: mixed}
     */
    private function reconstructedToolAction(Message $message): array
    {
        return [
            'tool' => $message->meta['tool'] ?? 'unknown',
            'result_length' => $message->meta['result_length'] ?? null,
        ];
    }

    /**
     * @param  array{prompt: int|null, completion: int|null}  $tokens
     */
    private function captureAssistantMessageState(
        Message $message,
        string $runId,
        array &$tokens,
        bool &$hasAssistantMessage,
        bool &$hasError,
    ): void {
        if ($message->type !== 'message' || $message->role !== 'assistant' || $message->runId !== $runId) {
            return;
        }

        $hasAssistantMessage = true;

        if (isset($message->meta['tokens'])) {
            $tokens = $message->meta['tokens'];
        }

        if (($message->meta['message_type'] ?? null) === 'error') {
            $hasError = true;
        }
    }

    /**
     * @param  array{prompt: int|null, completion: int|null}  $tokens
     * @param  list<array{tool: mixed, result_length: mixed}>  $toolActions
     * @return array<string, mixed>
     */
    private function reconstructedRunPayload(
        int $employeeId,
        string $sessionId,
        array $tokens,
        array $toolActions,
        bool $hasAssistantMessage,
        bool $hasError,
    ): array {
        return [
            'employee_id' => $employeeId,
            'session_id' => $sessionId,
            'source' => 'reconstructed',
            'execution_mode' => 'interactive',
            'status' => $this->reconstructedStatus($hasAssistantMessage, $hasError),
            'prompt_tokens' => $tokens['prompt'] ?? null,
            'completion_tokens' => $tokens['completion'] ?? null,
            'tool_actions' => $toolActions !== [] ? $toolActions : null,
        ];
    }

    private function reconstructedStatus(bool $hasAssistantMessage, bool $hasError): AiRunStatus
    {
        return match (true) {
            $hasError => AiRunStatus::Failed,
            $hasAssistantMessage => AiRunStatus::Succeeded,
            default => AiRunStatus::Running,
        };
    }
}
