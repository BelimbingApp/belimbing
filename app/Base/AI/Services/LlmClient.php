<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\Support\Json as BlbJson;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Stateless LLM client supporting multiple wire protocols.
 *
 * Dispatches on ChatRequest::$apiType to the correct protocol handler.
 * Model-agnostic — never inspects model names. Returns a normalized
 * response array regardless of which protocol was used.
 */
class LlmClient
{
    /**
     * Copilot-required headers for IDE auth.
     *
     * GitHub Copilot's API rejects requests without these headers.
     * Values mirror those used by VS Code Copilot Chat.
     */
    private const COPILOT_HEADERS = [
        'User-Agent' => 'GitHubCopilotChat/0.35.0',
        'Editor-Version' => 'vscode/1.107.0',
        'Editor-Plugin-Version' => 'copilot-chat/0.35.0',
        'Copilot-Integration-Id' => 'vscode-chat',
    ];

    private const UNKNOWN_ERROR = 'Unknown error';

    // =========================================================================
    // Public API — route on apiType
    // =========================================================================

    /**
     * Execute a sync LLM call using the protocol specified by the request.
     */
    public function chat(ChatRequest $request): array
    {
        return match ($request->apiType) {
            AiApiType::OpenAiResponses => $this->chatViaResponses($request),
            default => $this->chatViaChatCompletions($request),
        };
    }

    /**
     * Execute a streaming LLM call using the protocol specified by the request.
     *
     * Yields normalized events regardless of protocol:
     * - ['type' => 'content_delta', 'text' => '...']
     * - ['type' => 'tool_call_delta', 'index' => int, 'id' => ?string, 'name' => ?string, 'arguments_delta' => string]
     * - ['type' => 'done', 'finish_reason' => string, 'usage' => ?array, 'latency_ms' => int]
     * - ['type' => 'error', 'runtime_error' => AiRuntimeError, 'latency_ms' => int]
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        return match ($request->apiType) {
            AiApiType::OpenAiResponses => yield from $this->chatStreamViaResponses($request),
            default => yield from $this->chatStreamViaChatCompletions($request),
        };
    }

    // =========================================================================
    // Chat Completions protocol — POST /chat/completions
    // =========================================================================

    private function chatViaChatCompletions(ChatRequest $request): array
    {
        $startTime = hrtime(true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS);

            $response = $http->post(rtrim($request->baseUrl, '/').'/chat/completions', array_filter([
                'model' => $request->model,
                'messages' => $request->messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'tools' => $request->tools,
                'tool_choice' => $request->toolChoice,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->parseChatCompletionsResponse($response, LlmClientSupport::latencyMs($startTime), $request->model);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaChatCompletions(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, stream: true);

            $response = $http->post(rtrim($request->baseUrl, '/').'/chat/completions', array_filter([
                'model' => $request->model,
                'messages' => $request->messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'stream' => true,
                'tools' => $request->tools,
                'tool_choice' => $request->toolChoice,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            yield from LlmClientSupport::connectionErrorStream($e, $startTime);

            return;
        }

        $error = LlmClientSupport::checkFailedResponse($response, $startTime);
        if ($error !== null) {
            yield $error;

            return;
        }

        yield from $this->streamChatCompletionsSse($response, $startTime);
    }

    private function parseChatCompletionsResponse(Response $response, int $latencyMs, string $model): array
    {
        if ($response->failed()) {
            return LlmClientSupport::parseFailedResponse($response, $latencyMs);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return LlmClientSupport::invalidPayloadError($response, $latencyMs, $model);
        }

        $choice = $data['choices'][0]['message'] ?? [];
        if (! is_array($choice)) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::UnsupportedResponseShape,
                    "Model \"{$model}\" returned unsupported message format",
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $content = $choice['content'] ?? '';
        $toolCalls = $choice['tool_calls'] ?? null;
        $hasToolCalls = is_array($toolCalls) && count($toolCalls) > 0;
        $usage = $data['usage'] ?? [];

        if (($content === '' || $content === null) && ! $hasToolCalls) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::EmptyResponse,
                    "Model \"{$model}\" produced no text content",
                    'The model may be unavailable for this provider key or endpoint.',
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $result = [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if ($hasToolCalls) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamChatCompletionsSse(Response $response, int $startTime): Generator
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $finishReason = null;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                yield from $this->parseChatCompletionsSsePayload(substr($line, 6), $finishReason, $startTime);

                if ($finishReason === '__done__') {
                    return;
                }
            }

            if ($finishReason !== null) {
                return;
            }
        }

        yield [
            'type' => 'done',
            'finish_reason' => $finishReason ?? 'stop',
            'usage' => null,
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function parseChatCompletionsSsePayload(string $payload, ?string &$finishReason, int $startTime): Generator
    {
        if ($payload === '[DONE]') {
            yield [
                'type' => 'done',
                'finish_reason' => $finishReason ?? 'stop',
                'usage' => null,
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ];

            $finishReason = '__done__';

            return;
        }

        $data = BlbJson::decodeArray($payload);
        if ($data === null) {
            return;
        }

        $delta = $data['choices'][0]['delta'] ?? [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? $finishReason;
        $usage = $data['usage'] ?? null;

        $contentDelta = $delta['content'] ?? null;
        if (is_string($contentDelta) && $contentDelta !== '') {
            yield ['type' => 'content_delta', 'text' => $contentDelta];
        }

        $toolCallDeltas = $delta['tool_calls'] ?? null;
        if (is_array($toolCallDeltas)) {
            foreach ($toolCallDeltas as $tcDelta) {
                yield [
                    'type' => 'tool_call_delta',
                    'index' => $tcDelta['index'] ?? 0,
                    'id' => $tcDelta['id'] ?? null,
                    'name' => $tcDelta['function']['name'] ?? null,
                    'arguments_delta' => $tcDelta['function']['arguments'] ?? '',
                ];
            }
        }

        if ($finishReason !== null && is_array($usage)) {
            yield [
                'type' => 'done',
                'finish_reason' => $finishReason,
                'usage' => [
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                ],
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ];

            $finishReason = '__done__';
        }
    }

    // =========================================================================
    // Responses protocol — POST /responses
    // =========================================================================

    private function chatViaResponses(ChatRequest $request): array
    {
        $startTime = hrtime(true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $this->buildResponsesPayload($request, stream: false),
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->parseResponsesResponse($response, LlmClientSupport::latencyMs($startTime), $request->model);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaResponses(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $this->buildResponsesPayload($request, stream: true),
            );
        } catch (ConnectionException $e) {
            yield from LlmClientSupport::connectionErrorStream($e, $startTime);

            return;
        }

        $error = LlmClientSupport::checkFailedResponse($response, $startTime);
        if ($error !== null) {
            yield $error;

            return;
        }

        yield from $this->streamResponsesSse($response, $startTime);
    }

    /**
     * Build the Responses API request payload.
     *
     * Translates Chat Completions message format to Responses API input items.
     */
    private function buildResponsesPayload(ChatRequest $request, bool $stream): array
    {
        return array_filter([
            'model' => $request->model,
            'input' => $this->convertToResponsesInput($request->messages),
            'max_output_tokens' => $request->maxTokens,
            'stream' => $stream,
            'store' => false,
            'tools' => $request->tools !== null ? $this->convertToResponsesTools($request->tools) : null,
            'tool_choice' => $request->toolChoice,
        ], fn ($v) => $v !== null);
    }

    /**
     * Convert Chat Completions messages to Responses API input items.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function convertToResponsesInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            switch ($role) {
                case 'system':
                    $input[] = [
                        'role' => 'developer',
                        'content' => $msg['content'] ?? '',
                    ];
                    break;

                case 'user':
                    $content = $msg['content'] ?? '';
                    $input[] = [
                        'role' => 'user',
                        'content' => is_string($content)
                            ? [['type' => 'input_text', 'text' => $content]]
                            : $content,
                    ];
                    break;

                case 'assistant':
                    $content = $msg['content'] ?? '';
                    if ($content !== '' && $content !== null) {
                        $input[] = [
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => [['type' => 'output_text', 'text' => $content]],
                            'status' => 'completed',
                        ];
                    }

                    $toolCalls = $msg['tool_calls'] ?? [];
                    foreach ($toolCalls as $tc) {
                        $input[] = [
                            'type' => 'function_call',
                            'call_id' => $tc['id'] ?? '',
                            'name' => $tc['function']['name'] ?? '',
                            'arguments' => $tc['function']['arguments'] ?? '{}',
                        ];
                    }
                    break;

                case 'tool':
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => $msg['tool_call_id'] ?? '',
                        'output' => $msg['content'] ?? '',
                    ];
                    break;

                default:
                    break;
            }
        }

        return $input;
    }

    /**
     * Convert Chat Completions tools to Responses API tools.
     *
     * Chat Completions: {type: "function", function: {name, description, parameters}}
     * Responses API:    {type: "function", name, description, parameters}
     *
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    private function convertToResponsesTools(array $tools): array
    {
        return array_map(function (array $tool): array {
            $fn = $tool['function'] ?? [];

            return array_filter([
                'type' => 'function',
                'name' => $fn['name'] ?? $tool['name'] ?? '',
                'description' => $fn['description'] ?? $tool['description'] ?? null,
                'parameters' => $fn['parameters'] ?? $tool['parameters'] ?? null,
            ], fn ($v) => $v !== null);
        }, $tools);
    }

    /**
     * Parse a sync Responses API response into the normalized result array.
     */
    private function parseResponsesResponse(Response $response, int $latencyMs, string $model): array
    {
        if ($response->failed()) {
            return LlmClientSupport::parseFailedResponse($response, $latencyMs);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return LlmClientSupport::invalidPayloadError($response, $latencyMs, $model);
        }

        $content = '';
        $toolCalls = [];

        foreach ($data['output'] ?? [] as $item) {
            if (is_array($item)) {
                $this->applyResponsesOutputItem($item, $content, $toolCalls);
            }
        }

        $hasToolCalls = $toolCalls !== [];
        $usage = $data['usage'] ?? [];

        if (($content === '') && ! $hasToolCalls) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::EmptyResponse,
                    "Model \"{$model}\" produced no text content",
                    'The model may be unavailable for this provider key or endpoint.',
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $result = [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $usage['input_tokens'] ?? null,
                'completion_tokens' => $usage['output_tokens'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if ($hasToolCalls) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }

    /**
     * Stream and parse Responses API SSE events.
     *
     * Responses API uses paired "event:" + "data:" lines, unlike Chat Completions
     * which uses only "data:" lines.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function streamResponsesSse(Response $response, int $startTime): Generator
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $pendingEventType = null;
        $toolCallIndex = 0;
        $currentToolCallId = null;
        $currentToolCallName = null;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                if (str_starts_with($line, 'event: ')) {
                    $pendingEventType = substr($line, 7);

                    continue;
                }

                if (str_starts_with($line, 'data: ')) {
                    yield from $this->processResponsesDataLine(
                        $line,
                        $pendingEventType,
                        $startTime,
                        $toolCallIndex,
                        $currentToolCallId,
                        $currentToolCallName,
                    );

                    if ($pendingEventType === '__done__') {
                        return;
                    }
                }
            }
        }

        yield [
            'type' => 'done',
            'finish_reason' => 'stop',
            'usage' => null,
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ];
    }

    /**
     * Process a single Responses API SSE event and yield normalized events.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function processResponsesSseEvent(
        string $event,
        array $data,
        int $startTime,
        int &$toolCallIndex,
        ?string &$currentToolCallId,
        ?string &$currentToolCallName,
    ): Generator {
        if ($event === 'response.output_text.delta') {
            $deltaEvent = $this->responseTextDeltaEvent($data);

            if ($deltaEvent !== null) {
                yield $deltaEvent;
            }

            return;
        }

        if ($event === 'response.output_item.added') {
            $toolCallAddedEvent = $this->responseOutputItemAddedEvent(
                data: $data,
                toolCallIndex: $toolCallIndex,
                currentToolCallId: $currentToolCallId,
                currentToolCallName: $currentToolCallName,
            );

            if ($toolCallAddedEvent !== null) {
                yield $toolCallAddedEvent;
            }

            return;
        }

        if ($event === 'response.function_call_arguments.delta') {
            yield $this->responseFunctionCallArgumentsDeltaEvent($data, $toolCallIndex);

            return;
        }

        if ($event === 'response.output_item.done') {
            $this->completeResponseOutputItem($data, $toolCallIndex, $currentToolCallId, $currentToolCallName);

            return;
        }

        if ($event === 'response.completed') {
            yield $this->responseCompletedEvent($data, $startTime);

            return;
        }

        if ($event === 'response.failed') {
            yield $this->responseFailureEvent($data, $startTime);

            return;
        }

        if ($event === 'error') {
            yield $this->responseErrorEvent($data, $startTime);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function responseTextDeltaEvent(array $data): ?array
    {
        $delta = $data['delta'] ?? '';

        if ($delta === '') {
            return null;
        }

        return ['type' => 'content_delta', 'text' => $delta];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function responseOutputItemAddedEvent(
        array $data,
        int $toolCallIndex,
        ?string &$currentToolCallId,
        ?string &$currentToolCallName,
    ): ?array {
        $item = $data['item'] ?? [];

        if (($item['type'] ?? '') !== 'function_call') {
            return null;
        }

        $currentToolCallId = $item['call_id'] ?? null;
        $currentToolCallName = $item['name'] ?? null;

        return [
            'type' => 'tool_call_delta',
            'index' => $toolCallIndex,
            'id' => $currentToolCallId,
            'name' => $currentToolCallName,
            'arguments_delta' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function responseFunctionCallArgumentsDeltaEvent(array $data, int $toolCallIndex): array
    {
        return [
            'type' => 'tool_call_delta',
            'index' => $toolCallIndex,
            'id' => null,
            'name' => null,
            'arguments_delta' => $data['delta'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function completeResponseOutputItem(
        array $data,
        int &$toolCallIndex,
        ?string &$currentToolCallId,
        ?string &$currentToolCallName,
    ): void {
        $item = $data['item'] ?? [];

        if (($item['type'] ?? '') !== 'function_call') {
            return;
        }

        $toolCallIndex++;
        $currentToolCallId = null;
        $currentToolCallName = null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function responseCompletedEvent(array $data, int $startTime): array
    {
        $resp = $data['response'] ?? $data;
        $usage = $resp['usage'] ?? null;
        $status = $resp['status'] ?? 'completed';

        return [
            'type' => 'done',
            'finish_reason' => $this->responseFinishReason($status),
            'usage' => $usage !== null ? [
                'prompt_tokens' => $usage['input_tokens'] ?? null,
                'completion_tokens' => $usage['output_tokens'] ?? null,
            ] : null,
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function applyResponsesOutputItem(array $item, string &$content, array &$toolCalls): void
    {
        $type = $item['type'] ?? '';

        if ($type === 'message') {
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $content .= $part['text'] ?? '';
                }
            }

            return;
        }

        if ($type === 'function_call') {
            $toolCalls[] = [
                'id' => $item['call_id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $item['name'] ?? '',
                    'arguments' => $item['arguments'] ?? '{}',
                ],
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function processResponsesDataLine(
        string $line,
        ?string &$pendingEventType,
        int $startTime,
        int &$toolCallIndex,
        ?string &$currentToolCallId,
        ?string &$currentToolCallName,
    ): Generator {
        $data = BlbJson::decodeArray(substr($line, 6));
        if ($data === null) {
            $pendingEventType = null;

            return;
        }

        $event = $pendingEventType ?? $data['type'] ?? '';
        $pendingEventType = null;

        yield from $this->processResponsesSseEvent(
            $event,
            $data,
            $startTime,
            $toolCallIndex,
            $currentToolCallId,
            $currentToolCallName,
        );

        if ($event === 'response.completed' || $event === 'response.failed' || $event === 'error') {
            $pendingEventType = '__done__';
        }
    }

    private function responseFinishReason(string $status): string
    {
        return match ($status) {
            'completed' => 'stop',
            'incomplete' => 'length',
            default => 'error',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function responseFailureEvent(array $data, int $startTime): array
    {
        $error = $data['response']['error'] ?? $data['error'] ?? null;
        $message = is_array($error)
            ? (string) ($error['message'] ?? self::UNKNOWN_ERROR)
            : self::UNKNOWN_ERROR;
        $latencyMs = LlmClientSupport::latencyMs($startTime);

        return [
            'type' => 'error',
            'runtime_error' => AiRuntimeError::fromType(
                AiErrorType::ServerError,
                $message,
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function responseErrorEvent(array $data, int $startTime): array
    {
        $code = (string) ($data['code'] ?? self::UNKNOWN_ERROR);
        $message = (string) ($data['message'] ?? $data['code'] ?? self::UNKNOWN_ERROR);
        $latencyMs = LlmClientSupport::latencyMs($startTime);

        return [
            'type' => 'error',
            'runtime_error' => AiRuntimeError::fromType(
                AiErrorType::ServerError,
                "Error code {$code}: {$message}",
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }
}
