<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\DTO\ProviderTestResult;
use Illuminate\Support\Facades\Log;

/**
 * Runs end-to-end connectivity tests against a configured provider+model.
 *
 * Exercises the full runtime chain: ConfigResolver → RuntimeCredentialResolver →
 * LlmClient. Returns a structured result suitable for admin diagnostics.
 *
 * Agent-agnostic — works for any provider/model selection, not tied to a
 * specific agent identity.
 */
final readonly class ProviderTestService
{
    private const TEST_PROMPT = 'Reply with OK only.';

    private const TEST_MAX_TOKENS = 16;

    public function __construct(
        private ConfigResolver $configResolver,
        private RuntimeCredentialResolver $credentialResolver,
        private LlmClient $llmClient,
    ) {}

    /**
     * Test connectivity for a specific provider and model selection.
     *
     * Resolves config → credentials → sends a tiny API call → returns diagnosis.
     *
     * @param  int  $providerId  Provider database ID
     * @param  string  $modelId  Model identifier
     */
    public function testSelection(int $providerId, string $modelId): ProviderTestResult
    {
        $config = $this->configResolver->resolveForProvider($providerId, $modelId);

        if ($config === null) {
            return $this->configFailure($modelId);
        }

        $providerName = (string) ($config['provider_name'] ?? 'unknown');
        $model = (string) $config['model'];

        $credentials = $this->credentialResolver->resolve($config);

        if (isset($credentials['runtime_error'])) {
            return $this->loggedFailure($providerName, $model, $credentials['runtime_error']);
        }

        return $this->executeTestCall($providerName, $model, $credentials, $config);
    }

    /**
     * Execute the minimal LLM call and return a test result.
     *
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  array{model: string, timeout: int, provider_name: string|null}  $config
     */
    private function executeTestCall(string $providerName, string $model, array $credentials, array $config): ProviderTestResult
    {
        try {
            $response = $this->llmClient->chat(new ChatRequest(
                baseUrl: $credentials['base_url'],
                apiKey: $credentials['api_key'],
                model: $model,
                messages: [['role' => 'user', 'content' => self::TEST_PROMPT]],
                executionControls: ExecutionControls::defaults(
                    maxOutputTokens: self::TEST_MAX_TOKENS,
                    temperature: null,
                ),
                timeout: (int) $config['timeout'],
                providerName: $providerName,
                apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
            ));
        } catch (\Throwable $e) {
            return $this->loggedFailure($providerName, $model, AiRuntimeError::unexpected($e->getMessage()));
        }

        if (isset($response['runtime_error'])) {
            /** @var AiRuntimeError $runtimeError */
            $runtimeError = $response['runtime_error'];

            // Empty content on a minimal test prompt is acceptable — it proves
            // connectivity. The model reached us and returned a valid response
            // structure; it just produced no text for "Reply with OK only."
            if ($runtimeError->errorType !== AiErrorType::EmptyResponse) {
                return $this->loggedFailure($providerName, $model, $runtimeError);
            }
        }

        $result = ProviderTestResult::success(
            providerName: $providerName,
            model: $model,
            latencyMs: (int) ($response['latency_ms'] ?? 0),
        );

        $this->logResult($providerName, $model, $result);

        return $result;
    }

    /**
     * Create a failure result for unresolvable config.
     */
    private function configFailure(string $modelId): ProviderTestResult
    {
        return ProviderTestResult::failure(
            providerName: 'unknown',
            model: $modelId,
            error: AiRuntimeError::fromType(
                AiErrorType::ConfigError,
                'Selected provider/model could not be resolved.',
            ),
        );
    }

    /**
     * Log and return a failure result.
     */
    private function loggedFailure(string $providerName, string $model, AiRuntimeError $error): ProviderTestResult
    {
        $result = ProviderTestResult::failure($providerName, $model, $error);

        $this->logResult($providerName, $model, $result);

        return $result;
    }

    /**
     * Log a test result via the default log channel.
     */
    private function logResult(string $providerName, string $model, ProviderTestResult $result): void
    {
        $context = [
            'provider_name' => $providerName,
            'model' => $model,
            'connected' => $result->connected,
            'latency_ms' => $result->latencyMs,
        ];

        if ($result->error !== null) {
            $context = array_merge($context, $result->error->toLogContext());
        }

        $level = $result->connected ? 'info' : 'warning';
        Log::log($level, 'AI provider test completed', $context);
    }
}
