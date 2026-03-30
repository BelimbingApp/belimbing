<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\ChatRequest;
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
 * Uses the same config resolution and fallback strategy as AgentRuntime
 * for the initial LLM call. Subsequent loop iterations reuse the resolved
 * provider/model (no mid-loop fallback).
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
    ) {}

    /**
     * Run an agentic conversation turn with tool calling.
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Lara's employee ID
     * @param  string|null  $systemPrompt  System prompt
     * @param  string|null  $modelOverride  Optional model ID to override the resolved config
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(array $messages, int $employeeId, ?string $systemPrompt = null, ?string $modelOverride = null): array
    {
        $runId = 'run_'.Str::random(12);
        $config = $this->configResolver->resolvePrimaryWithDefaultFallback($employeeId);

        if ($config === null) {
            return $this->responseFactory->error($runId, 'unknown', 'unknown', 0, __('No LLM configuration available.'));
        }

        $config = $this->applyModelOverride($config, $modelOverride);

        $credentials = $this->credentialResolver->resolve($config);

        if (isset($credentials['error'])) {
            return $this->responseFactory->error(
                $runId,
                $config['model'],
                (string) ($config['provider_name'] ?? 'unknown'),
                0,
                $credentials['error'],
                $credentials['error_type'] ?? 'config_error',
            );
        }

        return $this->runToolCallingLoop($runId, $config, $credentials, $messages, $systemPrompt);
    }

    /**
     * Run an agentic conversation turn with streaming for the final response.
     *
     * Tool-calling iterations run synchronously. Only the final text response
     * is streamed as SSE-compatible events. Yields arrays with 'event' and 'data' keys.
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
        $config = $this->configResolver->resolvePrimaryWithDefaultFallback($employeeId);

        if ($config === null) {
            yield ['event' => 'error', 'data' => ['message' => __('No LLM configuration available.'), 'run_id' => $runId]];

            return;
        }

        $config = $this->applyModelOverride($config, $modelOverride);

        $credentials = $this->credentialResolver->resolve($config);

        if (isset($credentials['error'])) {
            yield ['event' => 'error', 'data' => ['message' => $credentials['error'], 'run_id' => $runId]];

            return;
        }

        yield from $this->runStreamingToolLoop($runId, $config, $credentials, $messages, $systemPrompt);
    }

    /**
     * Apply a model override to the resolved config.
     *
     * Supports composite "providerId:::modelId" format to resolve the full
     * provider config (api_key, base_url, etc.) for cross-provider overrides.
     * Plain model IDs just override the model name within the existing config.
     *
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @return array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}
     */
    private function applyModelOverride(array $config, ?string $modelOverride): array
    {
        if ($modelOverride === null) {
            return $config;
        }

        return $this->applyCompositeOrSimpleOverride($config, $modelOverride);
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
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<Message>  $messages
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function runToolCallingLoop(
        string $runId,
        array $config,
        array $credentials,
        array $messages,
        ?string $systemPrompt,
    ): array {
        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);
        $tools = $this->toolRegistry->toolDefinitionsForCurrentUser();
        $toolActions = [];
        $clientActions = [];

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            $result = $this->chatWithTools($credentials, $config, $apiMessages, $tools);

            $errorResult = $this->buildLlmErrorResult($runId, $config, $result);
            if ($errorResult !== null) {
                return $errorResult;
            }

            if ($this->hasNoToolCalls($result)) {
                return $this->successResult(
                    $runId,
                    $config,
                    $result,
                    $toolActions,
                    $clientActions,
                );
            }

            $this->appendAssistantToolCallMessage($apiMessages, $result);
            $this->executeToolCalls($result['tool_calls'], $apiMessages, $toolActions, $clientActions);
        }

        return $this->maxIterationsResult($runId, $config);
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
        ));
    }

    /**
     * Build an error result from an LLM failure payload.
     *
     * @param  array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}  $config
     * @param  array<string, mixed>  $result
     * @return array{content: string, run_id: string, meta: array<string, mixed>}|null
     */
    private function buildLlmErrorResult(string $runId, array $config, array $result): ?array
    {
        if (! isset($result['error'])) {
            return null;
        }

        return $this->responseFactory->error(
            $runId,
            $config['model'],
            (string) ($config['provider_name'] ?? 'unknown'),
            (int) ($result['latency_ms'] ?? 0),
            (string) $result['error'],
            (string) ($result['error_type'] ?? 'unknown'),
        );
    }

    /**
     * Determine whether the LLM has finished without requesting tools.
     *
     * @param  array<string, mixed>  $result
     */
    private function hasNoToolCalls(array $result): bool
    {
        return ! isset($result['tool_calls']) || $result['tool_calls'] === [];
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
     * Execute requested tools and append tool responses back into the conversation.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  list<array<string, mixed>>  $toolActions
     * @param  list<string>  $clientActions
     */
    private function executeToolCalls(
        array $toolCalls,
        array &$apiMessages,
        array &$toolActions,
        array &$clientActions,
    ): void {
        foreach ($toolCalls as $toolCall) {
            $toolExecution = $this->executeToolCall($toolCall);

            $toolActions[] = $toolExecution['action'];
            array_push($clientActions, ...$toolExecution['client_actions']);
            $apiMessages[] = $toolExecution['message'];
        }
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
            0,
            __('Maximum tool-calling iterations reached. Please try a simpler request.'),
            'max_iterations',
        );
    }

    private function successResult(string $runId, array $config, array $llmResult, array $toolActions, array $clientActions = []): array
    {
        $content = $llmResult['content'] ?? '';

        if ($clientActions !== []) {
            $content = implode("\n", $clientActions)."\n".$content;
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
     * Intermediate tool-call iterations use synchronous chat. The final
     * text response iteration uses the streaming client and yields delta events.
     *
     * @param  array<string, mixed>  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<Message>  $messages
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function runStreamingToolLoop(
        string $runId,
        array $config,
        array $credentials,
        array $messages,
        ?string $systemPrompt,
    ): \Generator {
        $apiMessages = $this->messageBuilder->build($messages, $systemPrompt);
        $tools = $this->toolRegistry->toolDefinitionsForCurrentUser();
        $toolActions = [];
        $clientActions = [];

        yield ['event' => 'status', 'data' => ['phase' => 'thinking', 'run_id' => $runId]];

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            $result = $this->chatWithTools($credentials, $config, $apiMessages, $tools);

            if (isset($result['error'])) {
                yield ['event' => 'error', 'data' => ['message' => (string) $result['error'], 'run_id' => $runId]];

                return;
            }

            if ($this->hasNoToolCalls($result)) {
                yield from $this->streamFinalResponse(
                    $runId, $config, $credentials, $apiMessages, $tools, $toolActions, $clientActions,
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

                yield ['event' => 'status', 'data' => [
                    'phase' => 'tool_finished',
                    'tool' => $functionName,
                    'run_id' => $runId,
                ]];
            }
        }

        yield ['event' => 'error', 'data' => [
            'message' => __('Maximum tool-calling iterations reached.'),
            'run_id' => $runId,
        ]];
    }

    /**
     * Stream the final text response from the LLM after all tool calls are resolved.
     *
     * @param  array<string, mixed>  $config
     * @param  array{api_key: string, base_url: string}  $credentials
     * @param  list<array<string, mixed>>  $apiMessages
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $toolActions
     * @param  list<string>  $clientActions
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function streamFinalResponse(
        string $runId,
        array $config,
        array $credentials,
        array $apiMessages,
        array $tools,
        array $toolActions,
        array $clientActions,
    ): \Generator {
        $fullContent = '';
        $usage = null;
        $latencyMs = 0;

        $stream = $this->llmClient->chatStream(new ChatRequest(
            baseUrl: $credentials['base_url'],
            apiKey: $credentials['api_key'],
            model: $config['model'],
            messages: $apiMessages,
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $tools !== [] ? $tools : null,
            toolChoice: $tools !== [] ? 'auto' : null,
        ));

        foreach ($stream as $event) {
            if ($event['type'] === 'content_delta') {
                $fullContent .= $event['text'];
                yield ['event' => 'delta', 'data' => ['text' => $event['text']]];
            } elseif ($event['type'] === 'done') {
                $usage = $event['usage'] ?? null;
                $latencyMs = $event['latency_ms'] ?? 0;
            } elseif ($event['type'] === 'error') {
                yield ['event' => 'error', 'data' => ['message' => $event['message'], 'run_id' => $runId]];

                return;
            }
        }

        if ($clientActions !== []) {
            $fullContent = implode("\n", $clientActions)."\n".$fullContent;
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
            'fallback_attempts' => [],
        ];

        if ($toolActions !== []) {
            $meta['tool_actions'] = $toolActions;
        }

        yield ['event' => 'done', 'data' => [
            'run_id' => $runId,
            'content' => $fullContent,
            'meta' => $meta,
        ]];
    }
}
