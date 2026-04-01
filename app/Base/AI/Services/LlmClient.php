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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
            $http = $this->buildHttp($request);

            $response = $http->post(rtrim($request->baseUrl, '/').'/chat/completions', array_filter([
                'model' => $request->model,
                'messages' => $request->messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'tools' => $request->tools,
                'tool_choice' => $request->toolChoice,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            return $this->connectionError($e, $startTime);
        }

        return $this->parseChatCompletionsResponse($response, $this->latencyMs($startTime), $request->model);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaChatCompletions(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);

        try {
            $http = $this->buildHttp($request, stream: true);

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
            yield from $this->connectionErrorStream($e, $startTime);

            return;
        }

        $error = $this->checkFailedResponse($response, $startTime);
        if ($error !== null) {
            yield $error;

            return;
        }

        yield from $this->streamChatCompletionsSse($response, $startTime);
    }

    private function parseChatCompletionsResponse(Response $response, int $latencyMs, string $model): array
    {
        if ($response->failed()) {
            return $this->parseFailedResponse($response, $latencyMs);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return $this->invalidPayloadError($response, $latencyMs, $model);
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
            'latency_ms' => $this->latencyMs($startTime),
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
                'latency_ms' => $this->latencyMs($startTime),
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
                'latency_ms' => $this->latencyMs($startTime),
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
            $http = $this->buildHttp($request);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $this->buildResponsesPayload($request, stream: false),
            );
        } catch (ConnectionException $e) {
            return $this->connectionError($e, $startTime);
        }

        return $this->parseResponsesResponse($response, $this->latencyMs($startTime), $request->model);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaResponses(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);

        try {
            $http = $this->buildHttp($request, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $this->buildResponsesPayload($request, stream: true),
            );
        } catch (ConnectionException $e) {
            yield from $this->connectionErrorStream($e, $startTime);

            return;
        }

        $error = $this->checkFailedResponse($response, $startTime);
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
        $payload = array_filter([
            'model' => $request->model,
            'input' => $this->convertToResponsesInput($request->messages),
            'max_output_tokens' => $request->maxTokens,
            'stream' => $stream,
            'store' => false,
            'tools' => $request->tools !== null ? $this->convertToResponsesTools($request->tools) : null,
            'tool_choice' => $request->toolChoice,
        ], fn ($v) => $v !== null);

        return $payload;
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
            return $this->parseFailedResponse($response, $latencyMs);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return $this->invalidPayloadError($response, $latencyMs, $model);
        }

        $content = '';
        $toolCalls = [];

        foreach ($data['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $content .= $part['text'] ?? '';
                    }
                }
            } elseif ($type === 'function_call') {
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
                    $data = BlbJson::decodeArray(substr($line, 6));
                    if ($data === null) {
                        $pendingEventType = null;

                        continue;
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
                        return;
                    }
                }
            }
        }

        yield [
            'type' => 'done',
            'finish_reason' => 'stop',
            'usage' => null,
            'latency_ms' => $this->latencyMs($startTime),
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
        switch ($event) {
            case 'response.output_text.delta':
                $delta = $data['delta'] ?? '';
                if ($delta !== '') {
                    yield ['type' => 'content_delta', 'text' => $delta];
                }
                break;

            case 'response.output_item.added':
                $item = $data['item'] ?? [];
                if (($item['type'] ?? '') === 'function_call') {
                    $currentToolCallId = $item['call_id'] ?? null;
                    $currentToolCallName = $item['name'] ?? null;

                    yield [
                        'type' => 'tool_call_delta',
                        'index' => $toolCallIndex,
                        'id' => $currentToolCallId,
                        'name' => $currentToolCallName,
                        'arguments_delta' => '',
                    ];
                }
                break;

            case 'response.function_call_arguments.delta':
                yield [
                    'type' => 'tool_call_delta',
                    'index' => $toolCallIndex,
                    'id' => null,
                    'name' => null,
                    'arguments_delta' => $data['delta'] ?? '',
                ];
                break;

            case 'response.output_item.done':
                $item = $data['item'] ?? [];
                if (($item['type'] ?? '') === 'function_call') {
                    $toolCallIndex++;
                    $currentToolCallId = null;
                    $currentToolCallName = null;
                }
                break;

            case 'response.completed':
                $resp = $data['response'] ?? $data;
                $usage = $resp['usage'] ?? null;
                $status = $resp['status'] ?? 'completed';

                yield [
                    'type' => 'done',
                    'finish_reason' => $status === 'completed' ? 'stop' : ($status === 'incomplete' ? 'length' : 'error'),
                    'usage' => $usage !== null ? [
                        'prompt_tokens' => $usage['input_tokens'] ?? null,
                        'completion_tokens' => $usage['output_tokens'] ?? null,
                    ] : null,
                    'latency_ms' => $this->latencyMs($startTime),
                ];
                break;

            case 'response.failed':
                $error = $data['response']['error'] ?? $data['error'] ?? null;
                $msg = is_array($error) ? ($error['message'] ?? 'Unknown error') : 'Unknown error';

                yield [
                    'type' => 'error',
                    'runtime_error' => AiRuntimeError::fromType(
                        AiErrorType::ServerError,
                        $msg,
                        latencyMs: $this->latencyMs($startTime),
                    ),
                    'latency_ms' => $this->latencyMs($startTime),
                ];
                break;

            case 'error':
                $msg = ($data['message'] ?? $data['code'] ?? 'Unknown error');

                yield [
                    'type' => 'error',
                    'runtime_error' => AiRuntimeError::fromType(
                        AiErrorType::ServerError,
                        "Error code {$data['code']}: {$msg}",
                        latencyMs: $this->latencyMs($startTime),
                    ),
                    'latency_ms' => $this->latencyMs($startTime),
                ];
                break;
        }
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Build the HTTP client with auth and provider-specific headers.
     */
    private function buildHttp(ChatRequest $request, bool $stream = false): PendingRequest
    {
        $http = Http::timeout($request->timeout);

        if ($stream) {
            $http = $http->withOptions(['stream' => true]);
        }

        if ($request->apiKey !== '') {
            $http = $http->withToken($request->apiKey);
        }

        if ($request->providerName === 'github-copilot') {
            $http = $http->withHeaders(self::COPILOT_HEADERS);
        }

        return $http;
    }

    /**
     * Parse a failed HTTP response into a normalized error array.
     */
    private function parseFailedResponse(Response $response, int $latencyMs): array
    {
        $body = $response->json();
        $diagnostic = $body['error']['message']
            ?? $body['error']['code']
            ?? $response->body();

        $errorType = match (true) {
            $response->status() === 400 => AiErrorType::BadRequest,
            $response->status() === 401 => AiErrorType::AuthError,
            $response->status() === 404 => AiErrorType::NotFound,
            $response->status() === 429 => AiErrorType::RateLimit,
            $response->status() >= 500 => AiErrorType::ServerError,
            default => AiErrorType::UnexpectedError,
        };

        $hint = $errorType === AiErrorType::BadRequest
            ? (string) $diagnostic
            : null;

        return [
            'runtime_error' => AiRuntimeError::fromType(
                $errorType,
                "HTTP {$response->status()}: {$diagnostic}",
                $hint,
                httpStatus: $response->status(),
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Check a streaming response for HTTP errors before reading the body.
     *
     * @return array<string, mixed>|null Error event array, or null if response is OK
     */
    private function checkFailedResponse(Response $response, int $startTime): ?array
    {
        if (! $response->failed()) {
            return null;
        }

        $latencyMs = $this->latencyMs($startTime);
        $parsed = $this->parseFailedResponse($response, $latencyMs);

        return [
            'type' => 'error',
            'runtime_error' => $parsed['runtime_error'],
            'latency_ms' => $latencyMs,
        ];
    }

    private function connectionError(ConnectionException $e, int $startTime): array
    {
        $latencyMs = $this->latencyMs($startTime);
        $errorType = $this->classifyConnectionException($e);

        return [
            'runtime_error' => AiRuntimeError::fromType(
                $errorType,
                $e->getMessage(),
                $errorType === AiErrorType::Timeout
                    ? 'Increase the provider timeout or check network connectivity.'
                    : null,
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function connectionErrorStream(ConnectionException $e, int $startTime): Generator
    {
        $latencyMs = $this->latencyMs($startTime);
        $errorType = $this->classifyConnectionException($e);

        yield [
            'type' => 'error',
            'runtime_error' => AiRuntimeError::fromType($errorType, $e->getMessage(), latencyMs: $latencyMs),
            'latency_ms' => $latencyMs,
        ];
    }

    private function invalidPayloadError(Response $response, int $latencyMs, string $model): array
    {
        $payloadType = $this->classifyInvalidPayload($response);

        return [
            'runtime_error' => AiRuntimeError::fromType(
                $payloadType,
                "Model \"{$model}\" returned non-JSON payload (Content-Type: {$response->header('Content-Type')})",
                $payloadType === AiErrorType::HtmlResponse
                    ? 'Check that the provider base URL points to the API endpoint, not the provider website.'
                    : null,
                httpStatus: $response->status(),
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    private function latencyMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }

    private function classifyInvalidPayload(Response $response): AiErrorType
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = ltrim($response->body());

        if (str_contains($contentType, 'text/html') || str_starts_with($body, '<!DOCTYPE html') || str_starts_with($body, '<html')) {
            return AiErrorType::HtmlResponse;
        }

        return AiErrorType::UnsupportedResponseShape;
    }

    private function classifyConnectionException(ConnectionException $e): AiErrorType
    {
        $message = $e->getMessage();

        if (str_contains($message, 'timed out') || str_contains($message, 'cURL error 28')) {
            return AiErrorType::Timeout;
        }

        return AiErrorType::ConnectionError;
    }
}
