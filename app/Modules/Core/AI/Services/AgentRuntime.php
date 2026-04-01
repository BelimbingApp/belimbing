<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\DTO\Message;
use Illuminate\Support\Str;

/**
 * Stage 0 Agent runtime adapter.
 *
 * Delegates LLM execution to the stateless Base LlmClient. Handles per-agent
 * configuration resolution, message building, and ordered fallback on
 * transient failures (connection error, HTTP 429, 5xx).
 */
class AgentRuntime
{
    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LlmClient $llmClient,
        private readonly RuntimeCredentialResolver $credentialResolver,
        private readonly RuntimeMessageBuilder $messageBuilder,
        private readonly RuntimeResponseFactory $responseFactory,
    ) {}

    /**
     * Run a conversation turn and return the assistant response with metadata.
     *
     * Resolves LLM config for the given Agent (workspace config.json),
     * falling back to the company's default provider+model when no workspace
     * config exists. Tries models in priority order with fallback on transient failures.
     *
     * Collects structured fallback attempt entries (OpenClaw-style) when multiple
     * models are tried. Each attempt records provider, model, error, error_type,
     * and latency_ms. The attempts array is included in meta['fallback_attempts'].
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Agent employee ID
     * @param  string|null  $systemPrompt  Optional system prompt for the Agent
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(array $messages, int $employeeId, ?string $systemPrompt = null): array
    {
        $runId = 'run_'.Str::random(12);
        $configs = $this->configResolver->resolveWithDefaultFallback($employeeId);
        $result = null;

        if ($configs === []) {
            $result = $this->noLlmConfigResult($runId);
        } else {
            $lastResult = null;
            $fallbackAttempts = [];

            foreach ($configs as $config) {
                $attemptResult = $this->tryModel($messages, $systemPrompt, $config, $runId);

                if (! $this->shouldFallback($attemptResult)) {
                    $attemptResult['meta']['fallback_attempts'] = $fallbackAttempts;
                    $result = $attemptResult;

                    break;
                }

                $fallbackAttempts[] = [
                    'provider' => $config['provider_name'] ?? 'unknown',
                    'model' => $config['model'] ?? 'unknown',
                    'error' => $attemptResult['meta']['error'] ?? 'Unknown error',
                    'error_type' => $attemptResult['meta']['error_type'] ?? 'unknown',
                    'latency_ms' => $attemptResult['meta']['latency_ms'] ?? 0,
                ];

                $lastResult = $attemptResult;
            }

            if ($result === null) {
                $result = $lastResult ?? $this->noLlmConfigResult($runId);
                $result['meta']['fallback_attempts'] = $fallbackAttempts;
            }
        }

        return $result;
    }

    /**
     * Build a consistent error result for the "no configuration available" state.
     *
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function noLlmConfigResult(string $runId): array
    {
        return $this->responseFactory->error(
            $runId,
            'unknown',
            'unknown',
            AiRuntimeError::fromType(AiErrorType::ConfigError, 'No LLM configuration available'),
        );
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
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null, api_type?: AiApiType}  $config
     * @param  string  $runId  Run identifier
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function tryModel(array $messages, ?string $systemPrompt, array $config, string $runId): array
    {
        $model = $config['model'];
        $credentials = $this->credentialResolver->resolve($config);

        if (isset($credentials['runtime_error'])) {
            return $this->responseFactory->error(
                $runId,
                $model,
                (string) ($config['provider_name'] ?? 'unknown'),
                $credentials['runtime_error'],
            );
        }

        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);

        $result = $this->llmClient->chat(new ChatRequest(
            $credentials['base_url'],
            $credentials['api_key'],
            $model,
            $apiMessages,
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
        ));

        if (isset($result['runtime_error'])) {
            return $this->responseFactory->error(
                $runId,
                $model,
                (string) ($config['provider_name'] ?? 'unknown'),
                $result['runtime_error'],
            );
        }

        return $this->responseFactory->success($runId, $config, $result);
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
        $errorTypeValue = $result['meta']['error_type'] ?? null;

        if ($errorTypeValue === null) {
            return false;
        }

        $errorType = AiErrorType::tryFrom($errorTypeValue);

        return $errorType?->retryable() === true;
    }
}
