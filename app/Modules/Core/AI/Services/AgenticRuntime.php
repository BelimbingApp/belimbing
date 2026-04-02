<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\AiRuntimeLogger;
use App\Base\AI\Services\LlmClient;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\Message;
use Illuminate\Support\Str;

/**
 * Agentic runtime for Agents with tool-calling loop.
 *
 * Extends the standard agent runtime pattern with an iterative tool-calling loop:
 * LLM call → tool execution → feed results back → LLM call → ... until the
 * LLM produces a final text response or the maximum iteration limit is reached.
 *
 * Resilience strategy:
 * - Single retry on transient failures (timeout, connection, rate_limit, server_error, empty_response)
 * - Provider fallback before the tool-calling loop commits (first successful LLM call)
 * - No mid-loop fallback: once tool calls start, the provider is locked for consistency
 */
class AgenticRuntime
{
    private const MAX_ITERATIONS = 10;

    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LlmClient $llmClient,
        private readonly AgentToolRegistry $toolRegistry,
        private readonly RuntimeCredentialResolver $credentialResolver,
        private readonly RuntimeMessageBuilder $messageBuilder,
        private readonly RuntimeResponseFactory $responseFactory,
        private readonly AiRuntimeLogger $runtimeLogger,
        private readonly RuntimeHookCoordinator $hookCoordinator,
    ) {}

    /**
     * Run an agentic conversation turn with tool calling.
     *
     * Resolves all available provider configs and attempts each in order.
     * Falls back to the next provider on retryable failures before the
     * tool-calling loop commits. Each LLM call is retried once on transient errors.
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Agent employee ID
     * @param  string|null  $systemPrompt  System prompt
     * @param  string|null  $modelOverride  Optional model ID to override the resolved config
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(array $messages, int $employeeId, ?string $systemPrompt = null, ?string $modelOverride = null): array
    {
        $runId = 'run_'.Str::random(12);
        $configs = $this->configResolver->resolveWithDefaultFallback($employeeId);

        if ($configs === []) {
            return $this->responseFactory->error(
                $runId,
                'unknown',
                'unknown',
                AiRuntimeError::fromType(AiErrorType::ConfigError, 'No LLM configuration resolved for employee '.$employeeId),
                ['employee_id' => $employeeId],
            );
        }

        $fallbackAttempts = [];
        $lastErrorResult = null;

        foreach ($configs as $config) {
            if ($modelOverride !== null) {
                $config = $this->applyCompositeOrSimpleOverride($config, $modelOverride);
            }

            $credentials = $this->credentialResolver->resolve($config);

            if (isset($credentials['runtime_error'])) {
                $fallbackAttempts[] = $this->buildFallbackAttempt($config, $credentials['runtime_error']);
                $lastErrorResult = $this->responseFactory->error(
                    $runId,
                    $config['model'],
                    (string) ($config['provider_name'] ?? 'unknown'),
                    $credentials['runtime_error'],
                    ['employee_id' => $employeeId],
                );

                continue;
            }

            $result = $this->runToolCallingLoop($runId, $config, $credentials, $messages, $systemPrompt, $fallbackAttempts);
            $result['meta']['fallback_attempts'] = $fallbackAttempts;

            return $result;
        }

        $result = $lastErrorResult ?? $this->responseFactory->error(
            $runId,
            'unknown',
            'unknown',
            AiRuntimeError::fromType(AiErrorType::ConfigError, 'All provider configurations failed'),
        );
        $result['meta']['fallback_attempts'] = $fallbackAttempts;

        return $result;
    }

    /**
     * Run an agentic conversation turn with streaming for the final response.
     *
     * Tool-calling iterations run synchronously. Only the final text response
     * is streamed as SSE-compatible events. Yields arrays with 'event' and 'data' keys.
     *
     * Provider fallback is attempted before the tool loop commits. Each LLM call
     * is retried once on transient errors.
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Agent employee ID
     * @param  string|null  $systemPrompt  System prompt
     * @param  string|null  $modelOverride  Optional model ID override
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function runStream(array $messages, int $employeeId, ?string $systemPrompt = null, ?string $modelOverride = null): \Generator
    {
        $runId = 'run_'.Str::random(12);
        $configs = $this->configResolver->resolveWithDefaultFallback($employeeId);

        if ($configs === []) {
            $error = AiRuntimeError::fromType(AiErrorType::ConfigError, 'No LLM configuration resolved for employee '.$employeeId);
            $this->runtimeLogger->runFailed($runId, $error, ['employee_id' => $employeeId, 'streaming' => true]);
            yield ['event' => 'error', 'data' => [
                'message' => $error->userMessage,
                'run_id' => $runId,
                'meta' => $this->responseFactory->errorMeta('unknown', 'unknown', $error),
            ]];

            return;
        }

        $fallbackAttempts = [];
        $lastError = null;
        $lastConfig = null;

        foreach ($configs as $config) {
            if ($modelOverride !== null) {
                $config = $this->applyCompositeOrSimpleOverride($config, $modelOverride);
            }

            $credentials = $this->credentialResolver->resolve($config);

            if (isset($credentials['runtime_error'])) {
                $error = $credentials['runtime_error'];
                $fallbackAttempts[] = $this->buildFallbackAttempt($config, $error);
                $this->runtimeLogger->runFailed($runId, $error, [
                    'employee_id' => $employeeId,
                    'model' => $config['model'],
                    'provider_name' => $config['provider_name'] ?? 'unknown',
                    'streaming' => true,
                ]);
                $lastError = $error;
                $lastConfig = $config;

                continue;
            }

            yield from $this->runStreamingToolLoop($runId, $config, $credentials, $messages, $systemPrompt, $fallbackAttempts);

            return;
        }

        $error = $lastError ?? AiRuntimeError::fromType(AiErrorType::ConfigError, 'All provider configurations failed');
        $errorConfig = $lastConfig ?? $configs[0];
        yield ['event' => 'error', 'data' => [
            'message' => $error->userMessage,
            'run_id' => $runId,
            'meta' => array_merge(
                $this->responseFactory->errorMeta(
                    $errorConfig['model'] ?? 'unknown',
                    (string) ($errorConfig['provider_name'] ?? 'unknown'),
                    $error,
                ),
                ['fallback_attempts' => $fallbackAttempts],
            ),
        ]];
    }

    /**
     * Apply a composite (providerId:::modelId) or plain model override.
     *
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @return array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}
     */
    private function applyCompositeOrSimpleOverride(array $config, string $modelOverride): array
    {
        if (! str_contains($modelOverride, ':::')) {
            $config['model'] = $modelOverride;

            return $config;
        }

        [$providerId, $modelId] = explode(':::', $modelOverride, 2);
        $providerConfig = $this->configResolver->resolveForProvider((int) $providerId, $modelId);

        if ($providerConfig !== null) {
            return $providerConfig;
        }

        // Fallback: use the model ID only if provider resolution fails
        $config['model'] = $modelId;

        return $config;
    }

    /**
     * Execute the iterative tool-calling loop after configuration has been resolved.
     *
     * The first LLM call uses retry. Once tool calls start, the provider is
     * locked and subsequent calls use retry but not provider fallback.
     *
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<Message>  $messages
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $fallbackAttempts
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function runToolCallingLoop(
        string $runId,
        array $config,
        array $credentials,
        array $messages,
        ?string $systemPrompt,
        array &$fallbackAttempts,
    ): array {
        $employeeId = (int) ($config['employee_id'] ?? 0);

        // Hook: PreContextBuild — augment system prompt before message assembly
        $systemPrompt = $this->hookCoordinator->preContextBuild($runId, $employeeId, $systemPrompt);

        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);
        $tools = $this->toolRegistry->toolDefinitionsForCurrentUser();

        // Hook: PreToolRegistry — add/remove tools before the LLM sees them
        $tools = $this->hookCoordinator->preToolRegistry($runId, $employeeId, $tools);

        $toolActions = [];
        $clientActions = [];
        $retryAttempts = [];
        $hookMetadata = [];

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            // Hook: PreLlmCall — observe or augment before each LLM call
            $this->hookCoordinator->preLlmCall($runId, $employeeId, $iteration, $hookMetadata);

            $result = $this->chatWithRetry($credentials, $config, $apiMessages, $tools, $retryAttempts);

            if (isset($result['runtime_error'])) {
                // On first iteration, this is a pre-commit failure — record for fallback
                if ($iteration === 0) {
                    $fallbackAttempts[] = $this->buildFallbackAttempt($config, $result['runtime_error']);
                }

                $errorResult = $this->responseFactory->error(
                    $runId,
                    $config['model'],
                    (string) ($config['provider_name'] ?? 'unknown'),
                    $result['runtime_error'],
                );
                $errorResult['meta']['retry_attempts'] = $retryAttempts;

                // Hook: PostRun on error
                $this->hookCoordinator->postRun($runId, $employeeId, false, $hookMetadata);
                $errorResult['meta']['hooks'] = $hookMetadata;

                return $errorResult;
            }

            if (($result['tool_calls'] ?? []) === []) {
                $successResult = $this->successResult(
                    $runId,
                    $config,
                    $result,
                    $toolActions,
                    $clientActions,
                );
                $successResult['meta']['retry_attempts'] = $retryAttempts;

                // Hook: PostRun on success
                $this->hookCoordinator->postRun($runId, $employeeId, true, $hookMetadata);
                $successResult['meta']['hooks'] = $hookMetadata;

                return $successResult;
            }

            $this->appendAssistantToolCallMessage($apiMessages, $result);
            $this->executeToolCallsWithHooks($runId, $employeeId, $result['tool_calls'], $apiMessages, $toolActions, $clientActions, $hookMetadata);
        }

        $maxIterResult = $this->maxIterationsResult($runId, $config);
        $maxIterResult['meta']['retry_attempts'] = $retryAttempts;

        // Hook: PostRun on max iterations
        $this->hookCoordinator->postRun($runId, $employeeId, false, $hookMetadata);
        $maxIterResult['meta']['hooks'] = $hookMetadata;

        return $maxIterResult;
    }

    /**
     * Call the LLM with a single retry on transient failures.
     *
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $retryAttempts
     * @return array<string, mixed>
     */
    private function chatWithRetry(
        array $credentials,
        array $config,
        array $apiMessages,
        array $tools,
        array &$retryAttempts,
    ): array {
        $result = $this->chatWithTools($credentials, $config, $apiMessages, $tools);

        if (! isset($result['runtime_error'])) {
            return $result;
        }

        $runtimeError = $result['runtime_error'];

        if (! $runtimeError->retryable) {
            return $result;
        }

        $retryAttempts[] = [
            'provider' => $config['provider_name'] ?? 'unknown',
            'model' => $config['model'] ?? 'unknown',
            'error' => $runtimeError->userMessage,
            'error_type' => $runtimeError->errorType->value,
            'latency_ms' => $runtimeError->latencyMs,
        ];

        $this->runtimeLogger->retryAttempted(
            providerName: (string) ($config['provider_name'] ?? 'unknown'),
            model: $config['model'],
            error: $runtimeError,
        );

        return $this->chatWithTools($credentials, $config, $apiMessages, $tools);
    }

    /**
     * Call the LLM with the current conversation and available tools.
     *
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function chatWithTools(array $credentials, array $config, array $apiMessages, array $tools): array
    {
        return $this->llmClient->chat(new ChatRequest(
            $credentials['base_url'],
            $credentials['api_key'],
            $config['model'],
            $apiMessages,
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $tools !== [] ? $tools : null,
            toolChoice: $tools !== [] ? 'auto' : null,
            apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
        ));
    }

    /**
     * Append the assistant tool-call payload into the running conversation.
     *
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  array<string, mixed>  $result
     */
    private function appendAssistantToolCallMessage(array &$apiMessages, array $result): void
    {
        $apiMessages[] = [
            'role' => 'assistant',
            'content' => $result['content'] ?? null,
            'tool_calls' => $result['tool_calls'],
        ];
    }

    /**
     * Execute a single tool call and format the follow-up metadata.
     *
     * Receives a ToolResult from the registry and casts to string for the
     * LLM tool message. Structured error data is preserved in the action
     * metadata for downstream UI consumption.
     *
     * @param  array<string, mixed>  $toolCall
     * @return array{
     *     action: array{tool: string, arguments: array<string, mixed>, result_preview: string, error_payload?: array<string, mixed>},
     *     client_actions: list<string>,
     *     message: array{role: string, tool_call_id: string, content: string}
     * }
     */
    private function executeToolCall(array $toolCall): array
    {
        $functionName = (string) ($toolCall['function']['name'] ?? '');
        $arguments = $this->decodeToolArguments($toolCall);
        $toolCallId = (string) ($toolCall['id'] ?? '');
        $toolResult = $this->toolRegistry->execute($functionName, $arguments);
        $resultString = (string) $toolResult;

        $action = [
            'tool' => $functionName,
            'arguments' => $arguments,
            'result_preview' => Str::limit($resultString, 200),
        ];

        if ($toolResult->isError && $toolResult->errorPayload !== null) {
            $errorData = [
                'code' => $toolResult->errorPayload->code,
                'message' => $toolResult->errorPayload->message,
            ];

            if ($toolResult->errorPayload->hint !== null) {
                $errorData['hint'] = $toolResult->errorPayload->hint;
            }

            if ($toolResult->errorPayload->action !== null) {
                $errorData['setup_action'] = [
                    'label' => $toolResult->errorPayload->action->label,
                    'suggested_prompt' => $toolResult->errorPayload->action->suggestedPrompt,
                ];
            }

            $action['error_payload'] = $errorData;
        }

        return [
            'action' => $action,
            'client_actions' => $this->extractClientActions($resultString),
            'message' => [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => $resultString,
            ],
        ];
    }

    /**
     * Decode JSON arguments from a tool call payload.
     *
     * @param  array<string, mixed>  $toolCall
     * @return array<string, mixed>
     */
    private function decodeToolArguments(array $toolCall): array
    {
        return BlbJson::decodeArray((string) ($toolCall['function']['arguments'] ?? '{}')) ?? [];
    }

    /**
     * Extract agent client-action blocks from tool output.
     *
     * @return list<string>
     */
    private function extractClientActions(string $toolResult): array
    {
        if (preg_match_all('/<agent-action>.*?<\/agent-action>/s', $toolResult, $matches) < 1) {
            return [];
        }

        return $matches[0];
    }

    /**
     * Build the standard max-iteration failure response.
     *
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function maxIterationsResult(string $runId, array $config): array
    {
        return $this->responseFactory->error(
            $runId,
            $config['model'],
            (string) ($config['provider_name'] ?? 'unknown'),
            AiRuntimeError::fromType(AiErrorType::MaxIterations, 'Reached '.self::MAX_ITERATIONS.' tool-calling iterations'),
        );
    }

    /**
     * Build a success result, guarding against blank responses.
     *
     * If the LLM returned empty content and there are no client actions,
     * this is treated as an empty_response error rather than silently
     * persisting a blank assistant message.
     */
    private function successResult(string $runId, array $config, array $llmResult, array $toolActions, array $clientActions = []): array
    {
        $content = $llmResult['content'] ?? '';

        if ($clientActions !== []) {
            $content = implode("\n", $clientActions)."\n".$content;
        }

        if (trim($content) === '' && $clientActions === []) {
            return $this->responseFactory->error(
                $runId,
                $config['model'],
                (string) ($config['provider_name'] ?? 'unknown'),
                AiRuntimeError::fromType(
                    AiErrorType::EmptyResponse,
                    'LLM returned blank content after tool-calling loop',
                    'The model may be unavailable or the prompt may need adjustment.',
                    latencyMs: (int) ($llmResult['latency_ms'] ?? 0),
                ),
            );
        }

        return $this->responseFactory->success(
            $runId,
            $config,
            $llmResult,
            $toolActions !== [] ? ['tool_actions' => $toolActions] : [],
            $content,
        );
    }

    /**
     * Execute the tool-calling loop with streaming on the final response.
     *
     * Intermediate tool-call iterations use synchronous chat with retry.
     * The final text response iteration uses the streaming client.
     *
     * @param  array<string, mixed>  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<Message>  $messages
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $fallbackAttempts
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function runStreamingToolLoop(
        string $runId,
        array $config,
        array $credentials,
        array $messages,
        ?string $systemPrompt,
        array $fallbackAttempts,
    ): \Generator {
        $employeeId = (int) ($config['employee_id'] ?? 0);

        // Hook: PreContextBuild — augment system prompt before message assembly
        $systemPrompt = $this->hookCoordinator->preContextBuild($runId, $employeeId, $systemPrompt);

        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);
        $tools = $this->toolRegistry->toolDefinitionsForCurrentUser();

        // Hook: PreToolRegistry — add/remove tools before the LLM sees them
        $tools = $this->hookCoordinator->preToolRegistry($runId, $employeeId, $tools);

        $toolActions = [];
        $clientActions = [];
        $retryAttempts = [];
        $hookMetadata = [];

        yield ['event' => 'status', 'data' => ['phase' => 'thinking', 'run_id' => $runId]];

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            // Hook: PreLlmCall
            $this->hookCoordinator->preLlmCall($runId, $employeeId, $iteration, $hookMetadata);

            $result = $this->chatWithRetry($credentials, $config, $apiMessages, $tools, $retryAttempts);

            if (isset($result['runtime_error'])) {
                $runtimeError = $result['runtime_error'];
                $this->runtimeLogger->runFailed($runId, $runtimeError, [
                    'model' => $config['model'],
                    'provider_name' => $config['provider_name'] ?? 'unknown',
                    'streaming' => true,
                    'iteration' => $iteration,
                ]);

                // Hook: PostRun on error
                $this->hookCoordinator->postRun($runId, $employeeId, false, $hookMetadata);

                yield ['event' => 'error', 'data' => [
                    'message' => $runtimeError->userMessage,
                    'run_id' => $runId,
                    'meta' => array_merge(
                        $this->responseFactory->errorMeta(
                            $config['model'],
                            (string) ($config['provider_name'] ?? 'unknown'),
                            $runtimeError,
                        ),
                        [
                            'retry_attempts' => $retryAttempts,
                            'fallback_attempts' => $fallbackAttempts,
                            'hooks' => $hookMetadata,
                        ],
                    ),
                ]];

                return;
            }

            if (($result['tool_calls'] ?? []) === []) {
                // Hook: PostRun on success
                $this->hookCoordinator->postRun($runId, $employeeId, true, $hookMetadata);

                yield from $this->streamFinalResponse(
                    $runId,
                    $config,
                    $credentials,
                    [
                        'api_messages' => $apiMessages,
                        'tools' => $tools,
                        'tool_actions' => $toolActions,
                        'client_actions' => $clientActions,
                        'retry_attempts' => $retryAttempts,
                        'fallback_attempts' => $fallbackAttempts,
                        'hooks' => $hookMetadata,
                    ],
                );

                return;
            }

            $this->appendAssistantToolCallMessage($apiMessages, $result);

            foreach ($result['tool_calls'] as $toolCall) {
                $functionName = (string) ($toolCall['function']['name'] ?? '');

                yield ['event' => 'status', 'data' => [
                    'phase' => 'tool_started',
                    'tool' => $functionName,
                    'run_id' => $runId,
                ]];

                $toolExecution = $this->executeToolCall($toolCall);
                $toolActions[] = $toolExecution['action'];
                array_push($clientActions, ...$toolExecution['client_actions']);
                $apiMessages[] = $toolExecution['message'];

                // Hook: PostToolResult
                $this->hookCoordinator->postToolResult($runId, $employeeId, $toolExecution['action'], $hookMetadata);

                yield ['event' => 'status', 'data' => [
                    'phase' => 'tool_finished',
                    'tool' => $functionName,
                    'run_id' => $runId,
                ]];
            }
        }

        $maxIterError = AiRuntimeError::fromType(AiErrorType::MaxIterations, 'Reached '.self::MAX_ITERATIONS.' streaming tool-calling iterations');
        $this->runtimeLogger->runFailed($runId, $maxIterError, [
            'model' => $config['model'],
            'provider_name' => $config['provider_name'] ?? 'unknown',
            'streaming' => true,
        ]);

        // Hook: PostRun on max iterations
        $this->hookCoordinator->postRun($runId, $employeeId, false, $hookMetadata);

        yield ['event' => 'error', 'data' => [
            'message' => $maxIterError->userMessage,
            'run_id' => $runId,
            'meta' => array_merge(
                $this->responseFactory->errorMeta(
                    $config['model'],
                    (string) ($config['provider_name'] ?? 'unknown'),
                    $maxIterError,
                ),
                [
                    'retry_attempts' => $retryAttempts,
                    'fallback_attempts' => $fallbackAttempts,
                    'hooks' => $hookMetadata,
                ],
            ),
        ]];
    }

    /**
     * Stream the final text response from the LLM after all tool calls are resolved.
     *
     * @param  array<string, mixed>  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  array{
     *     api_messages: list<array<string, mixed>>,
     *     tools: list<array<string, mixed>>,
     *     tool_actions: list<array<string, mixed>>,
     *     client_actions: list<string>,
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     hooks: array<string, mixed>
     * }  $streamState
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function streamFinalResponse(
        string $runId,
        array $config,
        array $credentials,
        array $streamState,
    ): \Generator {
        $fullContent = '';
        $usage = null;
        $latencyMs = 0;

        $stream = $this->llmClient->chatStream(new ChatRequest(
            baseUrl: $credentials['base_url'],
            apiKey: $credentials['api_key'],
            model: $config['model'],
            messages: $streamState['api_messages'],
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $streamState['tools'] !== [] ? $streamState['tools'] : null,
            toolChoice: $streamState['tools'] !== [] ? 'auto' : null,
            apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
        ));

        foreach ($stream as $event) {
            if ($event['type'] === 'content_delta') {
                $fullContent .= $event['text'];
                yield ['event' => 'delta', 'data' => ['text' => $event['text']]];

                continue;
            }

            if ($event['type'] === 'done') {
                $usage = $event['usage'] ?? null;
                $latencyMs = $event['latency_ms'] ?? 0;

                continue;
            }

            if ($event['type'] === 'error') {
                yield $this->streamFinalErrorEvent($runId, $config, $event, $streamState);

                return;
            }
        }

        $fullContent = $this->prependClientActions($fullContent, $streamState['client_actions']);

        if (trim($fullContent) === '' && $streamState['client_actions'] === []) {
            yield $this->streamEmptyContentError($runId, $config, $latencyMs, $streamState);

            return;
        }

        $meta = [
            'model' => $config['model'],
            'provider_name' => $config['provider_name'],
            'llm' => [
                'provider' => (string) ($config['provider_name'] ?? 'unknown'),
                'model' => $config['model'],
            ],
            'latency_ms' => $latencyMs,
            'tokens' => [
                'prompt' => $usage['prompt_tokens'] ?? null,
                'completion' => $usage['completion_tokens'] ?? null,
            ],
            'fallback_attempts' => $streamState['fallback_attempts'],
            'retry_attempts' => $streamState['retry_attempts'],
        ];

        if ($streamState['tool_actions'] !== []) {
            $meta['tool_actions'] = $streamState['tool_actions'];
        }

        if (($streamState['hooks'] ?? []) !== []) {
            $meta['hooks'] = $streamState['hooks'];
        }

        yield ['event' => 'done', 'data' => [
            'run_id' => $runId,
            'content' => $fullContent,
            'meta' => $meta,
        ]];
    }

    /**
     * @param  list<string>  $clientActions
     */
    private function prependClientActions(string $fullContent, array $clientActions): string
    {
        if ($clientActions === []) {
            return $fullContent;
        }

        return implode("\n", $clientActions)."\n".$fullContent;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $event
     * @param  array{
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>
     * }  $streamState
     * @return array{event: string, data: array<string, mixed>}
     */
    private function streamFinalErrorEvent(string $runId, array $config, array $event, array $streamState): array
    {
        $runtimeError = $event['runtime_error'] ?? null;
        $message = $runtimeError instanceof AiRuntimeError
            ? $runtimeError->userMessage
            : ($event['message'] ?? __('An unexpected error occurred. Please try again.'));

        if ($runtimeError instanceof AiRuntimeError) {
            $this->runtimeLogger->runFailed($runId, $runtimeError, [
                'model' => $config['model'],
                'provider_name' => $config['provider_name'] ?? 'unknown',
                'streaming' => true,
            ]);
        }

        return ['event' => 'error', 'data' => [
            'message' => $message,
            'run_id' => $runId,
            'meta' => $runtimeError instanceof AiRuntimeError
                ? array_merge(
                    $this->responseFactory->errorMeta(
                        $config['model'],
                        (string) ($config['provider_name'] ?? 'unknown'),
                        $runtimeError,
                    ),
                    [
                        'retry_attempts' => $streamState['retry_attempts'],
                        'fallback_attempts' => $streamState['fallback_attempts'],
                    ],
                )
                : null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>
     * }  $streamState
     * @return array{event: string, data: array<string, mixed>}
     */
    private function streamEmptyContentError(string $runId, array $config, int $latencyMs, array $streamState): array
    {
        $emptyError = AiRuntimeError::fromType(
            AiErrorType::EmptyResponse,
            'Streaming response completed with no content',
            latencyMs: $latencyMs,
        );
        $this->runtimeLogger->runFailed($runId, $emptyError, [
            'model' => $config['model'],
            'provider_name' => $config['provider_name'] ?? 'unknown',
            'streaming' => true,
        ]);

        return ['event' => 'error', 'data' => [
            'message' => $emptyError->userMessage,
            'run_id' => $runId,
            'meta' => array_merge(
                $this->responseFactory->errorMeta(
                    $config['model'],
                    (string) ($config['provider_name'] ?? 'unknown'),
                    $emptyError,
                ),
                [
                    'retry_attempts' => $streamState['retry_attempts'],
                    'fallback_attempts' => $streamState['fallback_attempts'],
                ],
            ),
        ]];
    }

    /**
     * Build a structured fallback attempt entry for metadata.
     *
     * @param  array<string, mixed>  $config
     * @return array{provider: string, model: string, error: string, error_type: string, latency_ms: int}
     */
    private function buildFallbackAttempt(array $config, AiRuntimeError $error): array
    {
        return [
            'provider' => $config['provider_name'] ?? 'unknown',
            'model' => $config['model'] ?? 'unknown',
            'error' => $error->userMessage,
            'error_type' => $error->errorType->value,
            'latency_ms' => $error->latencyMs,
        ];
    }

    /**
     * Execute tool calls with PostToolResult hooks.
     *
     * Extends executeToolCalls with hook integration at each tool result.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  list<array<string, mixed>>  $toolActions
     * @param  list<string>  $clientActions
     * @param  array<string, array<string, mixed>>  $hookMetadata
     */
    private function executeToolCallsWithHooks(
        string $runId,
        int $employeeId,
        array $toolCalls,
        array &$apiMessages,
        array &$toolActions,
        array &$clientActions,
        array &$hookMetadata,
    ): void {
        foreach ($toolCalls as $toolCall) {
            $toolExecution = $this->executeToolCall($toolCall);

            $toolActions[] = $toolExecution['action'];
            array_push($clientActions, ...$toolExecution['client_actions']);
            $apiMessages[] = $toolExecution['message'];

            $this->hookCoordinator->postToolResult($runId, $employeeId, $toolExecution['action'], $hookMetadata);
        }
    }
}
