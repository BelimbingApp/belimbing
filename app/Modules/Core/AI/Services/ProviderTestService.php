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
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
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
     * @param  array{api_key: string, base_url: string, headers?: array<string, string>}  $credentials
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
                providerHeaders: $credentials['headers'] ?? [],
            ));
        } catch (\Throwable $e) {
            return $this->loggedFailure($providerName, $model, AiRuntimeError::unexpected($e->getMessage()));
        }

        if (isset($response['runtime_error'])) {
            /** @var AiRuntimeError $runtimeError */
            $runtimeError = $this->normalizeProviderError($providerName, $response['runtime_error']);

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

        if ($providerName === OpenAiCodexDefinition::KEY && $result->error !== null && $this->isCodexTransportRejection($result->error)) {
            $context['compatibility_contract'] = 'undocumented_chatgpt_backend';
            $context['operator_action'] = 'reconnect_or_disable';
            $context['contract_change_suspected'] = true;
        }

        $level = $result->connected ? 'info' : 'warning';
        Log::log($level, 'AI provider test completed', $context);
    }

    private function normalizeProviderError(string $providerName, AiRuntimeError $error): AiRuntimeError
    {
        if ($providerName === OpenAiCodexDefinition::KEY && $this->isCodexMissingInstructions($error)) {
            return new AiRuntimeError(
                errorType: AiErrorType::ConfigError,
                userMessage: __('OpenAI Codex rejected the request because BLB did not send instructions.'),
                diagnostic: $error->diagnostic,
                hint: __('Retry after updating BLB. This is a provider-integration request-shape issue, not an OAuth reconnect issue.'),
                httpStatus: $error->httpStatus,
                latencyMs: $error->latencyMs,
                retryable: false,
            );
        }

        if ($providerName === OpenAiCodexDefinition::KEY && $this->isCodexUnsupportedModel($error)) {
            return new AiRuntimeError(
                errorType: AiErrorType::BadRequest,
                userMessage: __('This OpenAI Codex model is not available for ChatGPT-backed Codex accounts.'),
                diagnostic: $error->diagnostic,
                hint: __('Sync models and switch to a supported Codex model such as gpt-5.4, gpt-5.4-mini, or gpt-5.2.'),
                httpStatus: $error->httpStatus,
                latencyMs: $error->latencyMs,
                retryable: false,
            );
        }

        if ($providerName !== OpenAiCodexDefinition::KEY || ! $this->isCodexTransportRejection($error)) {
            return $error;
        }

        return new AiRuntimeError(
            errorType: $error->errorType,
            userMessage: __('OpenAI Codex rejected the ChatGPT backend session.'),
            diagnostic: $error->diagnostic,
            hint: __('Reconnect OpenAI Codex. If the failure persists, disable this provider because the external ChatGPT backend contract may have changed.'),
            httpStatus: $error->httpStatus,
            latencyMs: $error->latencyMs,
            retryable: false,
        );
    }

    private function isCodexTransportRejection(AiRuntimeError $error): bool
    {
        $diagnostic = strtolower($error->diagnostic);

        if ($this->isCodexUnsupportedModel($error)) {
            return false;
        }

        if ($this->isCodexMissingInstructions($error)) {
            return false;
        }

        if (
            str_contains($diagnostic, 'chatgpt-account-id')
            || str_contains($diagnostic, 'backend-api')
            || str_contains($diagnostic, 'responses=experimental')
            || str_contains($diagnostic, 'codex/responses')
        ) {
            return true;
        }

        return match ($error->errorType) {
            AiErrorType::AuthError,
            AiErrorType::BadRequest,
            AiErrorType::NotFound,
            AiErrorType::HtmlResponse,
            AiErrorType::UnsupportedResponseShape => true,
            default => false,
        };
    }

    private function isCodexUnsupportedModel(AiRuntimeError $error): bool
    {
        $diagnostic = strtolower($error->diagnostic);

        return str_contains($diagnostic, 'not supported when using codex with a chatgpt account');
    }

    private function isCodexMissingInstructions(AiRuntimeError $error): bool
    {
        return str_contains(strtolower($error->diagnostic), 'instructions are required');
    }
}
