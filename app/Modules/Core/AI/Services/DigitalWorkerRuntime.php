<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\DTO\Message;
use Illuminate\Support\Str;

/**
 * Stage 0 Digital Worker runtime adapter.
 *
 * Delegates LLM execution to the stateless Base LlmClient. Handles per-DW
 * configuration resolution, message building, and ordered fallback on
 * transient failures (connection error, HTTP 429, 5xx).
 */
class DigitalWorkerRuntime
{
    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LlmClient $llmClient,
        private readonly GithubCopilotAuthService $githubCopilotAuth,
    ) {}

    /**
     * Run a conversation turn and return the assistant response with metadata.
     *
     * Resolves LLM config for the given Digital Worker (workspace config.json),
     * falling back to the company's default provider+model when no workspace
     * config exists. Tries models in priority order with fallback on transient failures.
     *
     * Collects structured fallback attempt entries (OpenClaw-style) when multiple
     * models are tried. Each attempt records provider, model, error, error_type,
     * and latency_ms. The attempts array is included in meta['fallback_attempts'].
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Digital Worker employee ID
     * @param  string|null  $systemPrompt  Optional system prompt for the Digital Worker
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(array $messages, int $employeeId, ?string $systemPrompt = null): array
    {
        $runId = 'run_'.Str::random(12);
        $configs = $this->configResolver->resolve($employeeId);

        // Fall back to company default when no workspace config exists
        if ($configs === []) {
            $configs = $this->resolveDefaultConfigsForEmployee($employeeId);
        }

        if ($configs === []) {
            return $this->noLlmConfigResult($runId);
        }

        $lastResult = null;
        $fallbackAttempts = [];

        foreach ($configs as $config) {
            $result = $this->tryModel($messages, $systemPrompt, $config, $runId);

            if (! $this->shouldFallback($result)) {
                $result['meta']['fallback_attempts'] = $fallbackAttempts;

                return $result;
            }

            // Record failed attempt trace entry (OpenClaw-style)
            $fallbackAttempts[] = [
                'provider' => $config['provider_name'] ?? 'unknown',
                'model' => $config['model'] ?? 'unknown',
                'error' => $result['meta']['error'] ?? 'Unknown error',
                'error_type' => $result['meta']['error_type'] ?? 'unknown',
                'latency_ms' => $result['meta']['latency_ms'] ?? 0,
            ];

            $lastResult = $result;
        }

        if ($lastResult === null) {
            return $this->noLlmConfigResult($runId);
        }

        $lastResult['meta']['fallback_attempts'] = $fallbackAttempts;

        return $lastResult;
    }

    /**
     * Resolve fallback configs using the employee's company default provider.
     *
     * Returns an empty list when employee lookup fails or no default exists.
     *
     * @param  int  $employeeId  Digital Worker employee ID
     * @return list<array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}>
     */
    private function resolveDefaultConfigsForEmployee(int $employeeId): array
    {
        $default = null;

        try {
            $employee = \App\Modules\Core\Employee\Models\Employee::query()->find($employeeId);
        } catch (\Throwable) {
            return [];
        }

        $companyId = $employee?->company_id ? (int) $employee->company_id : null;

        if ($companyId !== null) {
            $default = $this->configResolver->resolveDefault($companyId);
        }

        return $default === null ? [] : [$default];
    }

    /**
     * Build a consistent error result for the "no configuration available" state.
     *
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function noLlmConfigResult(string $runId): array
    {
        $result = $this->errorResult($runId, 'unknown', 'unknown', 0, __('No LLM configuration available.'));
        $result['meta']['fallback_attempts'] = [];

        return $result;
    }

    /**
     * Try a single model configuration and return the result.
     *
     * For GitHub Copilot, exchanges the stored GitHub OAuth token for a
     * short-lived Copilot API token before each request (cached by
     * GithubCopilotAuthService until near expiry).
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  string|null  $systemPrompt  Optional system prompt
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @param  string  $runId  Run identifier
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function tryModel(array $messages, ?string $systemPrompt, array $config, string $runId): array
    {
        $model = $config['model'];

        if (empty($config['api_key'])) {
            return $this->errorResult($runId, $model, (string) ($config['provider_name'] ?? 'unknown'), 0, __('API key is not configured for provider :provider.', [
                'provider' => $config['provider_name'] ?? 'default',
            ]), 'config_error');
        }

        if (empty($config['base_url'])) {
            return $this->errorResult($runId, $model, (string) ($config['provider_name'] ?? 'unknown'), 0, __('Base URL is not configured for provider :provider.', [
                'provider' => $config['provider_name'] ?? 'default',
            ]), 'config_error');
        }

        $apiKey = $config['api_key'];
        $baseUrl = $config['base_url'];

        // GitHub Copilot: exchange stored GitHub token for short-lived Copilot API token
        if ($config['provider_name'] === 'github-copilot') {
            try {
                $copilot = $this->githubCopilotAuth->exchangeForCopilotToken($apiKey);
                $apiKey = $copilot['token'];
                $baseUrl = $copilot['base_url'];
            } catch (\RuntimeException $e) {
                return $this->errorResult($runId, $model, (string) ($config['provider_name'] ?? 'unknown'), 0, __('Copilot token exchange failed: :error', [
                    'error' => $e->getMessage(),
                ]), 'auth_error');
            }
        }

        $apiMessages = $this->buildApiMessages($messages, $systemPrompt);

        $result = $this->llmClient->chat(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            model: $model,
            messages: $apiMessages,
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
        );

        if (isset($result['error'])) {
            return $this->errorResult(
                $runId, $model, (string) ($config['provider_name'] ?? 'unknown'), $result['latency_ms'],
                $result['error'], $result['error_type'] ?? 'unknown',
            );
        }

        return [
            'content' => $result['content'] ?? '',
            'run_id' => $runId,
            'meta' => [
                'model' => $model,
                'provider_name' => $config['provider_name'],
                'llm' => [
                    'provider' => (string) ($config['provider_name'] ?? 'unknown'),
                    'model' => $model,
                ],
                'latency_ms' => $result['latency_ms'],
                'tokens' => [
                    'prompt' => $result['usage']['prompt_tokens'] ?? null,
                    'completion' => $result['usage']['completion_tokens'] ?? null,
                ],
            ],
        ];
    }

    /**
     * Determine whether the runtime should fall back to the next model.
     *
     * Falls back on transient failures (connection, rate limit, server error).
     * Does NOT fall back on client errors (400, 401, 403) or success.
     *
     * @param  array{content: string, run_id: string, meta: array<string, mixed>}  $result
     */
    private function shouldFallback(array $result): bool
    {
        $errorType = $result['meta']['error_type'] ?? null;

        return in_array($errorType, ['connection_error', 'rate_limit', 'server_error'], true);
    }

    /**
     * Build an error response with the detail surfaced in both chat and debug panel.
     *
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function errorResult(
        string $runId,
        string $model,
        string $providerName,
        int $latencyMs,
        string $detail,
        string $errorType = 'unknown'
    ): array {
        return [
            'content' => __('⚠ :detail', ['detail' => $detail]),
            'run_id' => $runId,
            'meta' => [
                'model' => $model,
                'provider_name' => $providerName,
                'llm' => [
                    'provider' => $providerName,
                    'model' => $model,
                ],
                'latency_ms' => $latencyMs,
                'error' => $detail,
                'error_type' => $errorType,
            ],
        ];
    }

    /**
     * Build the messages array for the OpenAI API.
     *
     * @param  list<Message>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function buildApiMessages(array $messages, ?string $systemPrompt): array
    {
        $apiMessages = [];

        if ($systemPrompt !== null) {
            $apiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            if ($message->role === 'user' || $message->role === 'assistant') {
                $apiMessages[] = [
                    'role' => $message->role,
                    'content' => $message->content,
                ];
            }
        }

        return $apiMessages;
    }
}
