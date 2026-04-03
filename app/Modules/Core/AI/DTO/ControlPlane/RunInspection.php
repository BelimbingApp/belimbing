<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\ControlPlane;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;

/**
 * Normalized view of a single AI runtime run.
 *
 * Assembles run facts from session metadata, dispatch records,
 * and runtime response data into one coherent operator-inspectable
 * object. Secrets and raw prompts are excluded by design.
 */
final readonly class RunInspection
{
    /**
     * @param  string  $runId  Unique run identifier
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Owning session ID
     * @param  string|null  $dispatchId  Linked operation dispatch ID (if dispatched)
     * @param  string  $provider  Provider name used for this run
     * @param  string  $model  Model identifier used for this run
     * @param  string  $outcome  Run outcome: 'success', 'error', or 'unknown'
     * @param  int|null  $latencyMs  Total run latency in milliseconds
     * @param  array{prompt: int|null, completion: int|null}  $tokens  Token usage
     * @param  list<array{tool: string, result_length: int|null}>  $toolActions  Summary of tool invocations
     * @param  list<array{provider: string, model: string, error: string}>  $fallbackAttempts  Provider fallback history
     * @param  int  $retryAttempts  Number of retry attempts
     * @param  string|null  $errorType  Error type if the run failed
     * @param  string|null  $errorMessage  User-safe error message if the run failed
     * @param  string  $recordedAt  ISO 8601 timestamp when run metadata was recorded
     */
    public function __construct(
        public string $runId,
        public int $employeeId,
        public string $sessionId,
        public ?string $dispatchId,
        public string $provider,
        public string $model,
        public string $outcome,
        public ?int $latencyMs,
        public array $tokens,
        public array $toolActions,
        public array $fallbackAttempts,
        public int $retryAttempts,
        public ?string $errorType,
        public ?string $errorMessage,
        public string $recordedAt,
    ) {}

    /**
     * Build from session run metadata and optional dispatch context.
     *
     * @param  string  $runId  Run identifier
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session ID
     * @param  array<string, mixed>  $meta  Run metadata from session storage
     * @param  string  $recordedAt  ISO 8601 timestamp
     * @param  string|null  $dispatchId  Linked dispatch ID
     */
    public static function fromRunMeta(
        string $runId,
        int $employeeId,
        string $sessionId,
        array $meta,
        string $recordedAt,
        ?string $dispatchId = null,
    ): self {
        $llm = $meta['llm'] ?? [];

        return new self(
            runId: $runId,
            employeeId: $employeeId,
            sessionId: $sessionId,
            dispatchId: $dispatchId,
            provider: (string) ($llm['provider'] ?? $meta['provider_name'] ?? 'unknown'),
            model: (string) ($llm['model'] ?? $meta['model'] ?? 'unknown'),
            outcome: isset($meta['error']) ? 'error' : 'success',
            latencyMs: isset($meta['latency_ms']) ? (int) $meta['latency_ms'] : null,
            tokens: [
                'prompt' => $meta['tokens']['prompt'] ?? null,
                'completion' => $meta['tokens']['completion'] ?? null,
            ],
            toolActions: self::normalizeToolActions($meta['tool_actions'] ?? []),
            fallbackAttempts: is_array($meta['fallback_attempts'] ?? null) ? $meta['fallback_attempts'] : [],
            retryAttempts: (int) ($meta['retry_attempts'] ?? 0),
            errorType: $meta['error_type'] ?? null,
            errorMessage: $meta['error'] ?? null,
            recordedAt: $recordedAt,
        );
    }

    /**
     * Build from an AiRun model instance.
     *
     * Maps the Eloquent model directly to the inspection DTO.
     */
    public static function fromAiRun(AiRun $run): self
    {
        return new self(
            runId: $run->id,
            employeeId: $run->employee_id,
            sessionId: $run->session_id ?? '',
            dispatchId: $run->dispatch_id,
            provider: $run->provider_name ?? 'unknown',
            model: $run->model ?? 'unknown',
            outcome: match ($run->status) {
                AiRunStatus::Succeeded => 'success',
                AiRunStatus::Failed, AiRunStatus::TimedOut => 'error',
                AiRunStatus::Cancelled => 'cancelled',
                default => 'unknown',
            },
            latencyMs: $run->latency_ms,
            tokens: [
                'prompt' => $run->prompt_tokens,
                'completion' => $run->completion_tokens,
            ],
            toolActions: self::normalizeToolActions($run->tool_actions ?? []),
            fallbackAttempts: $run->fallback_attempts ?? [],
            retryAttempts: count($run->retry_attempts ?? []),
            errorType: $run->error_type,
            errorMessage: $run->error_message,
            recordedAt: $run->created_at?->toIso8601String() ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'employee_id' => $this->employeeId,
            'session_id' => $this->sessionId,
            'dispatch_id' => $this->dispatchId,
            'provider' => $this->provider,
            'model' => $this->model,
            'outcome' => $this->outcome,
            'latency_ms' => $this->latencyMs,
            'tokens' => $this->tokens,
            'tool_actions' => $this->toolActions,
            'fallback_attempts' => $this->fallbackAttempts,
            'retry_attempts' => $this->retryAttempts,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * Normalize tool actions to a consistent shape with no raw output.
     *
     * @param  array<int, mixed>  $actions
     * @return list<array{tool: string, result_length: int|null}>
     */
    private static function normalizeToolActions(array $actions): array
    {
        $normalized = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $normalized[] = [
                'tool' => (string) ($action['tool'] ?? $action['name'] ?? 'unknown'),
                'result_length' => isset($action['result_length']) ? (int) $action['result_length'] : null,
            ];
        }

        return $normalized;
    }
}
