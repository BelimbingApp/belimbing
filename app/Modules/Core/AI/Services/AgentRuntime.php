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
use App\Modules\Core\AI\DTO\Message;
use Illuminate\Support\Str;

/**
 * Stage 0 Agent runtime adapter.
 *
 * Delegates LLM execution to the stateless Base LlmClient. Resolves a single
 * config for the agent and makes one call. Failures surface honestly to the
 * caller — there is no silent retry across providers or models.
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
     * Resolves a single LLM config for the given Agent (workspace config.json
     * or company default by priority), calls the model once, and returns the
     * result. On failure, the error surfaces directly — no fallback chain.
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Agent employee ID
     * @param  string|null  $systemPrompt  Optional system prompt for the Agent
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(array $messages, int $employeeId, ?string $systemPrompt = null): array
    {
        $runId = 'run_'.Str::random(12);
        $config = $this->configResolver->resolveDefault($employeeId);

        if ($config === null) {
            return $this->responseFactory->error(
                $runId,
                'unknown',
                'unknown',
                AiRuntimeError::fromType(AiErrorType::ConfigError, 'No LLM configuration available'),
            );
        }

        return $this->callModel($messages, $systemPrompt, $config, $runId);
    }

    /**
     * Call the resolved model and shape the result into the runtime response envelope.
     *
     * For GitHub Copilot, exchanges the stored GitHub OAuth token for a
     * short-lived Copilot API token before each request (cached by
     * GithubCopilotAuthService until near expiry).
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  string|null  $systemPrompt  Optional system prompt
     * @param  array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, api_type?: AiApiType}  $config
     * @param  string  $runId  Run identifier
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function callModel(array $messages, ?string $systemPrompt, array $config, string $runId): array
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
            executionControls: $config['execution_controls'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
            providerHeaders: $credentials['headers'] ?? [],
        ));

        if (isset($result['runtime_error'])) {
            return $this->responseFactory->error(
                $runId,
                $model,
                (string) ($config['provider_name'] ?? 'unknown'),
                $result['runtime_error'],
            );
        }

        $extraMeta = [];

        if (is_array($result['provider_mapping'] ?? null) && $result['provider_mapping'] !== []) {
            $extraMeta['provider_mapping'] = $result['provider_mapping'];
        }

        return $this->responseFactory->success($runId, $config, $result, $extraMeta);
    }
}
