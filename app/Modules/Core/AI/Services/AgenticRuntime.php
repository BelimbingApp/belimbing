<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Tools\ToolResult;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorderStartInput;
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
    private const ALL_PROVIDER_CONFIGURATIONS_FAILED = 'All provider configurations failed';

    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LlmClient $llmClient,
        private readonly AgentToolRegistry $toolRegistry,
        private readonly RuntimeCredentialResolver $credentialResolver,
        private readonly RuntimeMessageBuilder $messageBuilder,
        private readonly RuntimeResponseFactory $responseFactory,
        private readonly RuntimeHookCoordinator $hookCoordinator,
        private readonly RunRecorder $runRecorder,
        private readonly AgenticToolLoopStreamReader $toolLoopStreamReader,
        private readonly RuntimeSessionContext $sessionContext,
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
     * @param  ExecutionPolicy|null  $policy  Execution policy (defaults to interactive)
     * @param  string|null  $sessionId  Chat session ID for run ledger correlation
     * @param  array<string, mixed>|null  $configOverride  Optional fully resolved config override
     * @param  list<string>|null  $allowedToolNames  Optional task-profile tool allowlist
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(
        array $messages,
        int $employeeId,
        ?string $systemPrompt = null,
        ?string $modelOverride = null,
        ?ExecutionPolicy $policy = null,
        ?string $sessionId = null,
        ?array $configOverride = null,
        ?array $allowedToolNames = null,
    ): array {
        $this->sessionContext->set($sessionId);

        try {
            $runId = 'run_'.Str::random(12);
            $policy ??= ExecutionPolicy::interactive();

            $this->runRecorder->start(new RunRecorderStartInput(
                runId: $runId,
                employeeId: $employeeId,
                source: 'chat',
                executionMode: $policy->mode->value,
                sessionId: $sessionId,
                actingForUserId: auth()->id(),
                timeoutSeconds: $policy->timeoutSeconds,
            ));

            $configs = $configOverride !== null
                ? [$configOverride]
                : $this->configResolver->resolveWithDefaultFallback($employeeId);

            if ($configs === []) {
                $configError = AiRuntimeError::fromType(AiErrorType::ConfigError, 'No LLM configuration resolved for employee '.$employeeId);
                $this->runRecorder->fail($runId, $configError);

                return $this->responseFactory->error(
                    $runId,
                    'unknown',
                    'unknown',
                    $configError,
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
                    );

                    continue;
                }

                $config['timeout'] = $policy->timeoutSeconds;

                $result = $this->runToolCallingLoop(
                    $runId,
                    $config,
                    $credentials,
                    $messages,
                    $systemPrompt,
                    $fallbackAttempts,
                    $allowedToolNames,
                );

                // Check if we should fallback on runtime error
                if (isset($result['meta']['error_type'])) {
                    $errorTypeValue = $result['meta']['error_type'];
                    if ($this->shouldFallbackFromErrorType($errorTypeValue)) {
                        // runToolCallingLoop already added to $fallbackAttempts
                        $lastErrorResult = $result;

                        continue;
                    }
                }

                $result['meta']['fallback_attempts'] = $fallbackAttempts;
                $this->runRecorder->complete($runId, $result['meta']);

                return $result;
            }

            $result = $lastErrorResult ?? $this->responseFactory->error(
                $runId,
                'unknown',
                'unknown',
                AiRuntimeError::fromType(AiErrorType::ConfigError, self::ALL_PROVIDER_CONFIGURATIONS_FAILED),
            );
            $result['meta']['fallback_attempts'] = $fallbackAttempts;

            $this->runRecorder->fail(
                $runId,
                AiRuntimeError::fromType(AiErrorType::ConfigError, self::ALL_PROVIDER_CONFIGURATIONS_FAILED),
                $result['meta'],
            );

            return $result;
        } finally {
            $this->sessionContext->clear();
        }
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
     * @param  ExecutionPolicy|null  $policy  Execution policy (defaults to interactive)
     * @param  string|null  $sessionId  Chat session ID for run ledger correlation
     * @param  string|null  $turnId  Chat turn ULID for linking the run to a turn
     * @param  array<string, mixed>|null  $configOverride  Optional fully resolved config override
     * @param  list<string>|null  $allowedToolNames  Optional task-profile tool allowlist
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function runStream(
        array $messages,
        int $employeeId,
        ?string $systemPrompt = null,
        ?string $modelOverride = null,
        ?ExecutionPolicy $policy = null,
        ?string $sessionId = null,
        ?string $turnId = null,
        ?array $configOverride = null,
        ?array $allowedToolNames = null,
    ): \Generator {
        $this->sessionContext->set($sessionId);

        try {
            $runId = 'run_'.Str::random(12);
            $policy ??= ExecutionPolicy::interactive();

            $this->runRecorder->start(new RunRecorderStartInput(
                runId: $runId,
                employeeId: $employeeId,
                source: 'stream',
                executionMode: $policy->mode->value,
                sessionId: $sessionId,
                actingForUserId: auth()->id(),
                timeoutSeconds: $policy->timeoutSeconds,
                turnId: $turnId,
            ));

            $configs = $configOverride !== null
                ? [$configOverride]
                : $this->configResolver->resolveWithDefaultFallback($employeeId);

            if ($configs === []) {
                $error = AiRuntimeError::fromType(AiErrorType::ConfigError, 'No LLM configuration resolved for employee '.$employeeId);
                $this->runRecorder->fail($runId, $error);
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
            $fallbackAttemptIndex = 0;

            foreach ($configs as $config) {
                if ($modelOverride !== null) {
                    $config = $this->applyCompositeOrSimpleOverride($config, $modelOverride);
                }

                $credentials = $this->credentialResolver->resolve($config);

                if (isset($credentials['runtime_error'])) {
                    $error = $credentials['runtime_error'];
                    $fallbackAttempts[] = $this->buildFallbackAttempt($config, $error);
                    $lastError = $error;
                    $lastConfig = $config;
                    $fallbackAttemptIndex++;

                    yield ['event' => 'status', 'data' => [
                        'phase' => 'recovery_attempted',
                        'attempt' => $fallbackAttemptIndex,
                        'reason' => 'provider_fallback: '.$error->userMessage,
                        'run_id' => $runId,
                    ]];

                    continue;
                }

                $config['timeout'] = $policy->timeoutSeconds;

                $stream = $this->runStreamingToolLoop(
                    $runId,
                    $config,
                    $credentials,
                    $messages,
                    $systemPrompt,
                    $fallbackAttempts,
                    $allowedToolNames,
                );

                $providerCommitted = false;
                foreach ($stream as $event) {
                    if ($event['event'] === 'error') {
                        $streamError = $event['data']['meta']['error_type'] ?? null;

                        if (
                            $this->shouldFallbackFromErrorType($streamError)
                            && ! $providerCommitted
                        ) {
                            $fallbackAttempts[] = $this->buildFallbackAttemptFromStreamError($config, $streamError);
                            $lastConfig = $config;
                            $fallbackAttemptIndex++;

                            yield ['event' => 'status', 'data' => [
                                'phase' => 'recovery_attempted',
                                'attempt' => $fallbackAttemptIndex,
                                'reason' => 'provider_fallback: '.$this->errorTypeToUserMessage($streamError),
                                'run_id' => $runId,
                            ]];

                            continue 2;
                        }

                        yield $event;

                        return;
                    }

                    yield $event;

                    if ($this->streamEventLocksProvider($event)) {
                        $providerCommitted = true;
                        $lastConfig = $config;
                    }

                    if ($event['event'] === 'done') {
                        return;
                    }
                }
            }

            $error = $lastError ?? AiRuntimeError::fromType(AiErrorType::ConfigError, self::ALL_PROVIDER_CONFIGURATIONS_FAILED);
            $this->runRecorder->fail($runId, $error, [
                'fallback_attempts' => $fallbackAttempts,
            ]);

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
        } finally {
            $this->sessionContext->clear();
        }
    }

    /**
     * Apply a composite (providerId:::modelId) or plain model override.
     *
     * @param  array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}  $config
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}
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
     * @param  array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<Message>  $messages
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $fallbackAttempts
     * @param  list<string>|null  $allowedToolNames
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function runToolCallingLoop(
        string $runId,
        array $config,
        array $credentials,
        array $messages,
        ?string $systemPrompt,
        array &$fallbackAttempts,
        ?array $allowedToolNames = null,
    ): array {
        $employeeId = (int) ($config['employee_id'] ?? 0);

        // Hook: PreContextBuild — augment system prompt before message assembly
        $systemPrompt = $this->hookCoordinator->preContextBuild($runId, $employeeId, $systemPrompt);

        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);
        $tools = $this->toolRegistry->toolDefinitionsForCurrentUser($allowedToolNames);

        // Hook: PreToolRegistry — add/remove tools before the LLM sees them
        $originalToolNames = array_map(fn (array $t): string => $t['function']['name'] ?? '', $tools);
        $tools = $this->hookCoordinator->preToolRegistry($runId, $employeeId, $tools);
        $filteredToolNames = array_map(fn (array $t): string => $t['function']['name'] ?? '', $tools);
        $removedTools = array_values(array_diff($originalToolNames, $filteredToolNames));

        $toolActions = [];
        $clientActions = [];
        $retryAttempts = [];
        $hookMetadata = [];

        if ($removedTools !== []) {
            $hookMetadata['pre_tool_registry_removed'] = $removedTools;
        }

        $iteration = 0;

        while (true) {
            // Hook: PreLlmCall — observe or augment before each LLM call
            $this->hookCoordinator->preLlmCall($runId, $employeeId, $iteration, $hookMetadata);

            $result = $this->chatWithRetry($credentials, $config, $apiMessages, $tools, $retryAttempts);

            if (isset($result['runtime_error'])) {
                // On first iteration, this is a pre-commit failure — record for fallback
                // Only record if error is retryable (we might actually fallback)
                if ($iteration === 0 && $result['runtime_error']->retryable) {
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
            $this->executeToolCallsWithHooks(
                $runId,
                $employeeId,
                $result['tool_calls'],
                $apiMessages,
                $toolActions,
                $clientActions,
                $hookMetadata,
                $allowedToolNames,
            );

            $iteration++;
        }
    }

    /**
     * Call the LLM with a single retry on transient failures.
     *
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}  $config
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

        // Don't retry timeouts that consumed most of the budget — retrying
        // with the same budget will fail identically.
        if ($runtimeError->errorType === AiErrorType::Timeout) {
            $budgetMs = ($config['timeout'] ?? 60) * 1000;

            if ($runtimeError->latencyMs >= $budgetMs * 0.5) {
                return $result;
            }
        }

        $retryAttempts[] = [
            'provider' => $config['provider_name'] ?? 'unknown',
            'model' => $config['model'] ?? 'unknown',
            'error' => $runtimeError->userMessage,
            'error_type' => $runtimeError->errorType->value,
            'latency_ms' => $runtimeError->latencyMs,
        ];

        return $this->chatWithTools($credentials, $config, $apiMessages, $tools);
    }

    /**
     * Call the LLM with the current conversation and available tools.
     *
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}  $config
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function chatWithTools(array $credentials, array $config, array $apiMessages, array $tools): array
    {
        $executionControls = $tools !== []
            ? $config['execution_controls']->withToolChoice(ToolChoiceMode::Auto)
            : $config['execution_controls']->withToolChoice(null);

        if (($config['api_type'] ?? AiApiType::OpenAiChatCompletions) === AiApiType::OpenAiResponses) {
            $executionControls = $executionControls
                ->withReasoningVisibility(ReasoningVisibility::Summary)
                ->withReasoningContextPreservation(true);
        }

        return $this->llmClient->chat(new ChatRequest(
            $credentials['base_url'],
            $credentials['api_key'],
            $config['model'],
            $apiMessages,
            executionControls: $executionControls,
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $tools !== [] ? $tools : null,
            apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
        ));
    }

    /**
     * Append the assistant tool-call payload into the running conversation.
     *
     * When $phase is provided (e.g., 'commentary'), it is preserved on the
     * message so convertToResponsesInputWithInstructions() can pass it through.
     *
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  array<string, mixed>  $result
     * @param  string|null  $phase  Responses API phase ('commentary' or 'final_answer')
     */
    private function appendAssistantToolCallMessage(array &$apiMessages, array $result, ?string $phase = null): void
    {
        $message = [
            'role' => 'assistant',
            'content' => $result['content'] ?? null,
            'tool_calls' => $result['tool_calls'],
        ];

        if (is_string($result['reasoning_content'] ?? null) && $result['reasoning_content'] !== '') {
            $message['reasoning_content'] = $result['reasoning_content'];
        }

        if ($phase !== null) {
            $message['phase'] = $phase;
        }

        $apiMessages[] = $message;
    }

    /**
     * Execute a single tool call and format the follow-up metadata.
     *
     * Receives a ToolResult from the registry and casts to string for the
     * LLM tool message. Structured error data is preserved in the action
     * metadata for downstream UI consumption.
     *
     * @param  array<string, mixed>  $toolCall
     * @param  list<string>|null  $allowedToolNames
     * @return array{
     *     action: array{tool: string, arguments: array<string, mixed>, result_preview: string, error_payload?: array<string, mixed>},
     *     client_actions: list<string>,
     *     message: array{role: string, tool_call_id: string, content: string}
     * }
     */
    private function executeToolCall(array $toolCall, ?array $allowedToolNames = null): array
    {
        $functionName = (string) ($toolCall['function']['name'] ?? '');
        $arguments = $this->decodeToolArguments($toolCall);
        $toolCallId = (string) ($toolCall['id'] ?? '');
        $toolResult = $this->toolRegistry->execute($functionName, $arguments, $allowedToolNames);

        return $this->buildToolExecution($functionName, $arguments, $toolCallId, $toolResult);
    }

    /**
     * Build execution result from a completed ToolResult.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{action: array<string, mixed>, client_actions: list<string>, message: array<string, mixed>}
     */
    private function buildToolExecution(string $functionName, array $arguments, string $toolCallId, ToolResult $toolResult): array
    {
        $resultString = (string) $toolResult;

        $action = [
            'tool' => $functionName,
            'arguments' => $arguments,
            'result_preview' => Str::limit($resultString, 200),
        ];

        if ($toolResult->isError && $toolResult->errorPayload !== null) {
            $action['error_payload'] = $this->buildErrorPayload($toolResult);
        }

        $clientActions = [];
        if (preg_match_all('/<agent-action>.*?<\/agent-action>/s', $resultString, $matches) >= 1) {
            $clientActions = $matches[0];
        }

        return [
            'action' => $action,
            'client_actions' => $clientActions,
            'message' => [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => $resultString,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildErrorPayload(ToolResult $toolResult): array
    {
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

        return $errorData;
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
     * Execute the tool-calling loop with streaming at every iteration.
     *
     * Every LLM call streams its response. Reasoning summary and preamble
     * text yield to the UI in real time. Tool calls are accumulated from
     * the same stream and executed as before. The final iteration completes
     * inline — no second LLM call.
     *
     * @param  array<string, mixed>  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<Message>  $messages
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $fallbackAttempts
     * @param  list<string>|null  $allowedToolNames
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function runStreamingToolLoop(
        string $runId,
        array $config,
        array $credentials,
        array $messages,
        ?string $systemPrompt,
        array $fallbackAttempts,
        ?array $allowedToolNames = null,
    ): \Generator {
        $employeeId = (int) ($config['employee_id'] ?? 0);
        $toolLoopState = $this->initializeToolLoopState($runId, $employeeId, $messages, $systemPrompt, $allowedToolNames);

        if ($toolLoopState['removedTools'] !== []) {
            yield ['event' => 'status', 'data' => [
                'phase' => 'hook_action',
                'stage' => 'pre_tool_registry',
                'tools_removed' => $toolLoopState['removedTools'],
                'run_id' => $runId,
            ]];
        }

        $iteration = 0;
        $toolIndex = 0;
        $apiType = $config['api_type'] ?? AiApiType::OpenAiChatCompletions;

        while (true) {
            // Emit thinking status at the start of every iteration
            yield ['event' => 'status', 'data' => [
                'phase' => 'thinking',
                'run_id' => $runId,
                'iteration' => $iteration,
                'description' => $iteration === 0 ? 'Analyzing request' : 'Analyzing result',
            ]];

            $this->hookCoordinator->preLlmCall($runId, $employeeId, $iteration, $toolLoopState['hookMetadata']);

            $iterResult = yield from $this->toolLoopStreamReader->consumeIterationStream(
                $runId,
                $config,
                $credentials,
                $toolLoopState,
                $apiType,
            );

            if (isset($iterResult['runtime_error'])) {
                $this->hookCoordinator->postRun($runId, $employeeId, false, $toolLoopState['hookMetadata']);
                yield $this->streamRuntimeErrorEvent($runId, $config, $iterResult['runtime_error'], $toolLoopState, $fallbackAttempts);

                return;
            }

            if (($iterResult['tool_calls'] ?? []) === []) {
                $this->hookCoordinator->postRun($runId, $employeeId, true, $toolLoopState['hookMetadata']);

                yield from $this->emitFinalResponse(
                    $runId,
                    $config,
                    $iterResult,
                    $toolLoopState,
                    $fallbackAttempts,
                );

                return;
            }

            // Determine phase for context continuity: only commentary content is preserved
            $phase = ($iterResult['commentary'] ?? '') !== '' ? 'commentary' : null;
            $this->appendAssistantToolCallMessage($toolLoopState['apiMessages'], $iterResult, $phase);

            yield from $this->streamToolCalls(
                $runId,
                $employeeId,
                $iterResult['tool_calls'],
                $toolLoopState,
                $toolIndex,
                $allowedToolNames,
            );

            $iteration++;
        }
    }

    /**
     * Emit the final response events when the streaming loop completes without tool calls.
     *
     * Replaces AgenticFinalResponseStreamer for the streaming path.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $iterResult
     * @param  array<string, mixed>  $toolLoopState
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $fallbackAttempts
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function emitFinalResponse(
        string $runId,
        array $config,
        array $iterResult,
        array $toolLoopState,
        array $fallbackAttempts,
    ): \Generator {
        $fullContent = $iterResult['final_content'] ?? $iterResult['content'] ?? '';

        if ($toolLoopState['clientActions'] !== []) {
            $fullContent = implode("\n", $toolLoopState['clientActions'])."\n".$fullContent;
        }

        if (trim($fullContent) === '' && $toolLoopState['clientActions'] === []) {
            $emptyError = AiRuntimeError::fromType(
                AiErrorType::EmptyResponse,
                'Streaming response completed with no content',
                latencyMs: (int) ($iterResult['latency_ms'] ?? 0),
            );
            $this->runRecorder->fail($runId, $emptyError);

            yield ['event' => 'error', 'data' => [
                'message' => $emptyError->userMessage,
                'run_id' => $runId,
                'meta' => array_merge(
                    $this->responseFactory->errorMeta(
                        $config['model'],
                        (string) ($config['provider_name'] ?? 'unknown'),
                        $emptyError,
                    ),
                    [
                        'retry_attempts' => $toolLoopState['retryAttempts'],
                        'fallback_attempts' => $fallbackAttempts,
                    ],
                ),
            ]];

            return;
        }

        // Stream the final content as a single delta
        yield ['event' => 'delta', 'data' => ['text' => $fullContent]];

        $meta = [
            'model' => $config['model'],
            'provider_name' => $config['provider_name'],
            'llm' => [
                'provider' => (string) ($config['provider_name'] ?? 'unknown'),
                'model' => $config['model'],
            ],
            'latency_ms' => (int) ($iterResult['latency_ms'] ?? 0),
            'tokens' => $iterResult['usage'] ?? [
                'prompt' => null,
                'completion' => null,
            ],
            'fallback_attempts' => $fallbackAttempts,
            'retry_attempts' => $toolLoopState['retryAttempts'],
        ];

        if ($toolLoopState['toolActions'] !== []) {
            $meta['tool_actions'] = $toolLoopState['toolActions'];
        }

        if (($toolLoopState['hookMetadata'] ?? []) !== []) {
            $meta['hooks'] = $toolLoopState['hookMetadata'];
        }

        $this->runRecorder->complete($runId, $meta);

        yield ['event' => 'done', 'data' => [
            'run_id' => $runId,
            'content' => $fullContent,
            'meta' => $meta,
        ]];
    }

    /**
     * Build a structured fallback attempt entry for metadata.
     *
     * @param  array<string, mixed>  $config
     * @return array{provider: string, model: string, error: string, error_type: string, latency_ms: int, diagnostic: string|null}
     */
    private function buildFallbackAttempt(array $config, AiRuntimeError $error): array
    {
        return [
            'provider' => $config['provider_name'] ?? 'unknown',
            'model' => $config['model'] ?? 'unknown',
            'error' => $error->userMessage,
            'error_type' => $error->errorType->value,
            'latency_ms' => $error->latencyMs,
            'diagnostic' => $error->diagnostic !== '' ? $error->diagnostic : null,
        ];
    }

    /**
     * Determine whether the runtime should fall back to the next model
     * based on the error type string.
     */
    private function shouldFallbackFromErrorType(?string $errorTypeValue): bool
    {
        if ($errorTypeValue === null) {
            return false;
        }

        $errorType = AiErrorType::tryFrom($errorTypeValue);

        return $errorType?->retryable() === true;
    }

    /**
     * Build a fallback attempt entry from a stream error type string.
     *
     * @param  array<string, mixed>  $config
     * @return array{provider: string, model: string, error: string, error_type: string, latency_ms: int, diagnostic: string|null}
     */
    private function buildFallbackAttemptFromStreamError(array $config, ?string $errorTypeValue): array
    {
        $errorType = AiErrorType::tryFrom($errorTypeValue ?? 'unexpected_error') ?? AiErrorType::UnexpectedError;

        return [
            'provider' => $config['provider_name'] ?? 'unknown',
            'model' => $config['model'] ?? 'unknown',
            'error' => $errorType->userMessage(),
            'error_type' => $errorType->value,
            'latency_ms' => 0,
            'diagnostic' => null,
        ];
    }

    /**
     * Convert an error type string to a user-facing message.
     */
    private function errorTypeToUserMessage(?string $errorTypeValue): string
    {
        $errorType = AiErrorType::tryFrom($errorTypeValue ?? 'unexpected_error') ?? AiErrorType::UnexpectedError;

        return $errorType->userMessage();
    }

    /**
     * Determine whether a streamed event means the provider has committed.
     *
     * Once committed, stream-mode provider fallback is no longer valid because
     * the turn transcript has already observed output from that provider.
     *
     * @param  array{event: string, data: array<string, mixed>}  $event
     */
    private function streamEventLocksProvider(array $event): bool
    {
        if ($event['event'] === 'delta' || $event['event'] === 'done') {
            return true;
        }

        if ($event['event'] !== 'status') {
            return false;
        }

        return ! in_array(
            (string) ($event['data']['phase'] ?? ''),
            ['hook_action', 'thinking'],
            true,
        );
    }

    /**
     * Prepare the shared state for a tool-calling loop.
     *
     * @param  list<Message>  $messages
     * @return array{
     *     apiMessages: list<array<string, mixed>>,
     *     tools: list<array<string, mixed>>,
     *     toolActions: list<array<string, mixed>>,
     *     clientActions: list<string>,
     *     retryAttempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     hookMetadata: array<string, mixed>,
     *     removedTools: list<string>,
     *     allowedToolNames: list<string>|null
     * }
     */
    private function initializeToolLoopState(
        string $runId,
        int $employeeId,
        array $messages,
        ?string $systemPrompt,
        ?array $allowedToolNames = null,
    ): array {
        $systemPrompt = $this->hookCoordinator->preContextBuild($runId, $employeeId, $systemPrompt);
        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);
        $tools = $this->toolRegistry->toolDefinitionsForCurrentUser($allowedToolNames);

        $originalToolNames = array_map(fn (array $tool): string => $tool['function']['name'] ?? '', $tools);
        $tools = $this->hookCoordinator->preToolRegistry($runId, $employeeId, $tools);
        $filteredToolNames = array_map(fn (array $tool): string => $tool['function']['name'] ?? '', $tools);
        $removedTools = array_values(array_diff($originalToolNames, $filteredToolNames));

        $hookMetadata = [];

        if ($removedTools !== []) {
            $hookMetadata['pre_tool_registry_removed'] = $removedTools;
        }

        return [
            'apiMessages' => $apiMessages,
            'tools' => $tools,
            'toolActions' => [],
            'clientActions' => [],
            'retryAttempts' => [],
            'hookMetadata' => $hookMetadata,
            'removedTools' => $removedTools,
            'allowedToolNames' => $allowedToolNames,
        ];
    }

    /**
     * @param  array{
     *     retryAttempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     hookMetadata: array<string, mixed>
     * }  $toolLoopState
     * @param  list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>  $fallbackAttempts
     * @return array{event: string, data: array<string, mixed>}
     */
    private function streamRuntimeErrorEvent(
        string $runId,
        array $config,
        AiRuntimeError $runtimeError,
        array $toolLoopState,
        array $fallbackAttempts,
    ): array {
        return ['event' => 'error', 'data' => [
            'message' => $runtimeError->userMessage,
            'run_id' => $runId,
            'meta' => array_merge(
                $this->responseFactory->errorMeta(
                    $config['model'],
                    (string) ($config['provider_name'] ?? 'unknown'),
                    $runtimeError,
                ),
                [
                    'retry_attempts' => $toolLoopState['retryAttempts'],
                    'fallback_attempts' => $fallbackAttempts,
                    'hooks' => $toolLoopState['hookMetadata'],
                ],
            ),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  array{
     *     apiMessages: list<array<string, mixed>>,
     *     tools: list<array<string, mixed>>,
     *     toolActions: list<array<string, mixed>>,
     *     clientActions: list<string>,
     *     retryAttempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     hookMetadata: array<string, mixed>,
     *     removedTools: list<string>,
     *     allowedToolNames: list<string>|null
     * }  $toolLoopState
     * @param  list<string>|null  $allowedToolNames
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function streamToolCalls(
        string $runId,
        int $employeeId,
        array $toolCalls,
        array &$toolLoopState,
        int &$toolIndex,
        ?array $allowedToolNames = null,
    ): \Generator {
        $hookMetadata = &$toolLoopState['hookMetadata'];

        foreach ($toolCalls as $toolCall) {
            $functionName = (string) ($toolCall['function']['name'] ?? '');
            $arguments = $this->decodeToolArguments($toolCall);
            $toolCallId = (string) ($toolCall['id'] ?? '');
            $argsSummary = Str::limit(
                json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                200,
            );

            $hookVerdict = $this->hookCoordinator->preToolUse($runId, $employeeId, $functionName, $arguments, $hookMetadata);

            if ($hookVerdict['denied']) {
                $denialMessage = 'Tool call denied: '.($hookVerdict['reason'] ?? 'denied by policy');

                $toolLoopState['toolActions'][] = [
                    'tool' => $functionName,
                    'arguments' => $arguments,
                    'result_preview' => $denialMessage,
                    'status' => 'denied',
                    'denial_reason' => $hookVerdict['reason'],
                    'denial_source' => 'hook',
                ];
                $toolLoopState['apiMessages'][] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $denialMessage,
                ];

                yield ['event' => 'status', 'data' => [
                    'phase' => 'tool_denied',
                    'tool' => $functionName,
                    'reason' => $hookVerdict['reason'],
                    'source' => 'hook',
                    'run_id' => $runId,
                ]];

                $toolIndex++;

                continue;
            }

            yield ['event' => 'status', 'data' => [
                'phase' => 'tool_started',
                'tool' => $functionName,
                'args_summary' => $argsSummary,
                'tool_call_index' => $toolIndex,
                'started_at' => now()->toIso8601String(),
                'run_id' => $runId,
            ]];

            $toolStartTime = hrtime(true);

            // Execute with streaming: yields stdout deltas, returns ToolResult
            $toolStream = $this->toolRegistry->executeStreaming($functionName, $arguments, $allowedToolNames);

            foreach ($toolStream as $chunk) {
                yield ['event' => 'status', 'data' => [
                    'phase' => 'tool_stdout',
                    'tool' => $functionName,
                    'delta' => $chunk,
                    'run_id' => $runId,
                ]];
            }

            $toolResult = $toolStream->getReturn();
            $durationMs = (int) ((hrtime(true) - $toolStartTime) / 1_000_000);
            $toolExecution = $this->buildToolExecution($functionName, $arguments, $toolCallId, $toolResult);

            $toolLoopState['toolActions'][] = $toolExecution['action'];
            array_push($toolLoopState['clientActions'], ...$toolExecution['client_actions']);
            $toolLoopState['apiMessages'][] = $toolExecution['message'];

            $this->hookCoordinator->postToolResult($runId, $employeeId, $toolExecution['action'], $hookMetadata);

            $resultString = $toolExecution['message']['content'] ?? '';

            yield ['event' => 'status', 'data' => [
                'phase' => 'tool_finished',
                'tool' => $functionName,
                'result_preview' => $toolExecution['action']['result_preview'] ?? '',
                'result_length' => mb_strlen($resultString),
                'duration_ms' => $durationMs,
                'status' => isset($toolExecution['action']['error_payload']) ? 'error' : 'success',
                'error_payload' => $toolExecution['action']['error_payload'] ?? null,
                'run_id' => $runId,
            ]];

            // Emit authorization denial as a hook_action for transcript visibility
            if (($toolExecution['action']['error_payload']['code'] ?? null) === 'permission_denied') {
                yield ['event' => 'status', 'data' => [
                    'phase' => 'tool_denied',
                    'tool' => $functionName,
                    'reason' => $toolExecution['action']['error_payload']['message'] ?? 'permission denied',
                    'source' => 'authorization',
                    'run_id' => $runId,
                ]];
            }

            $toolIndex++;
        }
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
     * @param  list<string>|null  $allowedToolNames
     */
    private function executeToolCallsWithHooks(
        string $runId,
        int $employeeId,
        array $toolCalls,
        array &$apiMessages,
        array &$toolActions,
        array &$clientActions,
        array &$hookMetadata,
        ?array $allowedToolNames = null,
    ): void {
        foreach ($toolCalls as $toolCall) {
            $functionName = (string) ($toolCall['function']['name'] ?? '');
            $arguments = $this->decodeToolArguments($toolCall);
            $toolCallId = (string) ($toolCall['id'] ?? '');

            $hookVerdict = $this->hookCoordinator->preToolUse($runId, $employeeId, $functionName, $arguments, $hookMetadata);

            if ($hookVerdict['denied']) {
                $denialMessage = 'Tool call denied: '.($hookVerdict['reason'] ?? 'denied by policy');
                $toolActions[] = [
                    'tool' => $functionName,
                    'arguments' => $arguments,
                    'result_preview' => $denialMessage,
                    'status' => 'denied',
                    'denial_reason' => $hookVerdict['reason'],
                    'denial_source' => 'hook',
                ];
                $apiMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $denialMessage,
                ];

                continue;
            }

            $toolExecution = $this->executeToolCall($toolCall, $allowedToolNames);

            $toolActions[] = $toolExecution['action'];
            array_push($clientActions, ...$toolExecution['client_actions']);
            $apiMessages[] = $toolExecution['message'];

            $this->hookCoordinator->postToolResult($runId, $employeeId, $toolExecution['action'], $hookMetadata);
        }
    }
}
