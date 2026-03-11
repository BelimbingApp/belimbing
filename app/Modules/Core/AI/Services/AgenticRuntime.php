<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\DTO\Message;
use Illuminate\Support\Str;

/**
 * Agentic runtime for Digital Workers with tool-calling loop.
 *
 * Extends the standard DW runtime pattern with an iterative tool-calling loop:
 * LLM call → tool execution → feed results back → LLM call → ... until the
 * LLM produces a final text response or the maximum iteration limit is reached.
 *
 * Uses the same config resolution and fallback strategy as DigitalWorkerRuntime
 * for the initial LLM call. Subsequent loop iterations reuse the resolved
 * provider/model (no mid-loop fallback).
 */
class AgenticRuntime
{
    private const MAX_ITERATIONS = 10;

    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LlmClient $llmClient,
        private readonly GithubCopilotAuthService $githubCopilotAuth,
        private readonly DigitalWorkerToolRegistry $toolRegistry,
    ) {}

    /**
     * Run an agentic conversation turn with tool calling.
     *
     * @param  list<Message>  $messages  Conversation history
     * @param  int  $employeeId  Lara's employee ID
     * @param  string|null  $systemPrompt  System prompt
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    public function run(array $messages, int $employeeId, ?string $systemPrompt = null): array
    {
        $runId = 'run_'.Str::random(12);
        $config = $this->resolveConfig($employeeId);

        if ($config === null) {
            return $this->errorResult($runId, 'unknown', 'unknown', 0, __('No LLM configuration available.'));
        }

        $credentials = $this->resolveCredentials($config);

        if (isset($credentials['error'])) {
            return $this->errorResult(
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
        $apiMessages = $this->buildApiMessages($messages, $systemPrompt);
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
        return $this->llmClient->chat(
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
        );
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

        return $this->errorResult(
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
        $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * Extract Lara client-action blocks from tool output.
     *
     * @return list<string>
     */
    private function extractClientActions(string $toolResult): array
    {
        if (preg_match_all('/<lara-action>.*?<\/lara-action>/s', $toolResult, $matches) < 1) {
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
        return $this->errorResult(
            $runId,
            $config['model'],
            (string) ($config['provider_name'] ?? 'unknown'),
            0,
            __('Maximum tool-calling iterations reached. Please try a simpler request.'),
            'max_iterations',
        );
    }

    /**
     * Resolve the best LLM config for the given employee.
     *
     * @return array{api_key: string, base_url: string, model: string, max_tokens: int, temperature: float, timeout: int, provider_name: string|null}|null
     */
    private function resolveConfig(int $employeeId): ?array
    {
        $configs = $this->configResolver->resolve($employeeId);

        if ($configs !== []) {
            return $configs[0];
        }

        $defaultConfig = null;

        try {
            $employee = \App\Modules\Core\Employee\Models\Employee::query()->find($employeeId);
        } catch (\Throwable) {
            return null;
        }

        $companyId = $employee?->company_id ? (int) $employee->company_id : null;

        if ($companyId !== null) {
            $defaultConfig = $this->configResolver->resolveDefault($companyId);
        }

        return $defaultConfig;
    }

    /**
     * Resolve API credentials, handling GitHub Copilot token exchange.
     *
     * @return array{api_key: string, base_url: string}|array{error: string, error_type: string}
     */
    private function resolveCredentials(array $config): array
    {
        $configurationError = $this->configurationError($config);

        if ($configurationError !== null) {
            return $configurationError;
        }

        $apiKey = $config['api_key'];
        $baseUrl = $config['base_url'];

        if ($config['provider_name'] === 'github-copilot') {
            try {
                $copilot = $this->githubCopilotAuth->exchangeForCopilotToken($apiKey);
                $apiKey = $copilot['token'];
                $baseUrl = $copilot['base_url'];
            } catch (\RuntimeException $e) {
                return [
                    'error' => __('Copilot token exchange failed: :error', ['error' => $e->getMessage()]),
                    'error_type' => 'auth_error',
                ];
            }
        }

        return ['api_key' => $apiKey, 'base_url' => $baseUrl];
    }

    /**
     * @return array{error: string, error_type: string}|null
     */
    private function configurationError(array $config): ?array
    {
        if (empty($config['api_key'])) {
            return [
                'error' => __('API key is not configured for provider :provider.', [
                    'provider' => $config['provider_name'] ?? 'default',
                ]),
                'error_type' => 'config_error',
            ];
        }

        if (empty($config['base_url'])) {
            return [
                'error' => __('Base URL is not configured for provider :provider.', [
                    'provider' => $config['provider_name'] ?? 'default',
                ]),
                'error_type' => 'config_error',
            ];
        }

        return null;
    }

    /**
     * Build the messages array for the OpenAI API.
     *
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
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

    /**
     * Build a success result with tool action metadata.
     *
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function successResult(string $runId, array $config, array $llmResult, array $toolActions, array $clientActions = []): array
    {
        $meta = [
            'model' => $config['model'],
            'provider_name' => $config['provider_name'],
            'llm' => [
                'provider' => (string) ($config['provider_name'] ?? 'unknown'),
                'model' => $config['model'],
            ],
            'latency_ms' => $llmResult['latency_ms'],
            'tokens' => [
                'prompt' => $llmResult['usage']['prompt_tokens'] ?? null,
                'completion' => $llmResult['usage']['completion_tokens'] ?? null,
            ],
            'fallback_attempts' => [],
        ];

        if ($toolActions !== []) {
            $meta['tool_actions'] = $toolActions;
        }

        // Prepend collected <lara-action> blocks so the frontend executor sees them
        $content = $llmResult['content'] ?? '';
        if ($clientActions !== []) {
            $content = implode("\n", $clientActions)."\n".$content;
        }

        return [
            'content' => $content,
            'run_id' => $runId,
            'meta' => $meta,
        ];
    }

    /**
     * Build an error response.
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
                'fallback_attempts' => [],
            ],
        ];
    }
}
