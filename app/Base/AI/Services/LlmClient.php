<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\ProviderMapping\ProviderRequestMapperRegistry;
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

    public function __construct(
        private readonly ?ProviderRequestMapperRegistry $requestMappers = null,
    ) {}

    /**
     * Execute a sync LLM call using the protocol specified by the request.
     */
    public function chat(ChatRequest $request): array
    {
        return match ($request->apiType) {
            AiApiType::OpenAiResponses => $this->chatViaResponses($request),
            AiApiType::AnthropicMessages => $this->chatViaAnthropicMessages($request),
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
            AiApiType::AnthropicMessages => yield from $this->chatStreamViaAnthropicMessages($request),
            default => yield from $this->chatStreamViaChatCompletions($request),
        };
    }

    private function chatViaChatCompletions(ChatRequest $request): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/chat/completions',
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->withProviderMapping(
            $this->parseChatCompletionsResponse($response, LlmClientSupport::latencyMs($startTime), $request->model),
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaChatCompletions(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/chat/completions',
                $mapping->payload,
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

        yield from $this->streamChatCompletionsSse($response, $startTime, $mapping);
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
        $reasoningContent = $choice['reasoning_content'] ?? null;
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

        if (is_string($reasoningContent) && $reasoningContent !== '') {
            $result['reasoning_content'] = $reasoningContent;
        }

        return $result;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamChatCompletionsSse(Response $response, int $startTime, ProviderRequestMapping $mapping): Generator
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

                yield from $this->parseChatCompletionsSsePayload(substr($line, 6), $finishReason, $startTime, $mapping);

                if ($finishReason === '__done__') {
                    return;
                }
            }

            if ($finishReason !== null) {
                return;
            }
        }

        yield $this->buildDoneEvent(
            $finishReason ?? 'stop',
            null,
            $startTime,
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function parseChatCompletionsSsePayload(
        string $payload,
        ?string &$finishReason,
        int $startTime,
        ProviderRequestMapping $mapping,
    ): Generator {
        if ($payload === '[DONE]') {
            yield $this->buildDoneEvent($finishReason ?? 'stop', null, $startTime, $mapping);

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

        $reasoningDelta = $delta['reasoning_content'] ?? null;
        if (is_string($reasoningDelta) && $reasoningDelta !== '') {
            yield ['type' => 'thinking_delta', 'text' => $reasoningDelta, 'source' => 'reasoning_content'];
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
            yield $this->buildDoneEvent(
                $finishReason,
                [
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                ],
                $startTime,
                $mapping,
            );

            $finishReason = '__done__';
        }
    }

    private function chatViaResponses(ChatRequest $request): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->withProviderMapping(
            $this->parseResponsesResponse($response, LlmClientSupport::latencyMs($startTime), $request->model),
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaResponses(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $mapping->payload,
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

        yield from $this->streamResponsesSse($response, $startTime, $mapping);
    }

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
                LlmResponsesDecoder::applyOutputItem($item, $content, $toolCalls);
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
     * @return Generator<int, array<string, mixed>>
     */
    private function streamResponsesSse(Response $response, int $startTime, ProviderRequestMapping $mapping): Generator
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $pendingEventType = null;
        $toolCallIndex = 0;
        $currentToolCallId = null;
        $currentToolCallName = null;
        $currentMessagePhase = null;

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
                    yield from LlmResponsesDecoder::processDataLine(
                        $line,
                        $pendingEventType,
                        $startTime,
                        $toolCallIndex,
                        $currentToolCallId,
                        $currentToolCallName,
                        $currentMessagePhase,
                    );

                    if ($pendingEventType === '__done__') {
                        return;
                    }
                }
            }
        }

        yield $this->buildDoneEvent('stop', null, $startTime, $mapping);
    }

    private function chatViaAnthropicMessages(ChatRequest $request): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/messages',
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->withProviderMapping(
            $this->parseAnthropicMessagesResponse($response, LlmClientSupport::latencyMs($startTime), $request->model),
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function chatStreamViaAnthropicMessages(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);

        try {
            $http = LlmClientSupport::buildHttp($request, self::COPILOT_HEADERS, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/messages',
                $mapping->payload,
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

        yield from $this->streamAnthropicMessagesSse($response, $startTime, $mapping);
    }

    private function parseAnthropicMessagesResponse(Response $response, int $latencyMs, string $model): array
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
        $reasoningBlocks = [];

        foreach ($data['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $content .= (string) ($block['text'] ?? '');

                continue;
            }

            if ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => $this->encodeAnthropicToolInput($block['input'] ?? []),
                    ],
                ];

                continue;
            }

            if (in_array($type, ['thinking', 'redacted_thinking'], true)) {
                $reasoningBlocks[] = $block;
            }
        }

        $hasToolCalls = $toolCalls !== [];
        $usage = $data['usage'] ?? [];

        if ($content === '' && ! $hasToolCalls) {
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

        if ($reasoningBlocks !== []) {
            $result['reasoning_blocks'] = $reasoningBlocks;
        }

        return $result;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamAnthropicMessagesSse(
        Response $response,
        int $startTime,
        ProviderRequestMapping $mapping,
    ): Generator {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $pendingEventType = null;
        $contentBlocks = [];
        $toolInputJson = [];
        $reasoningBlocks = [];
        $promptTokens = null;
        $completionTokens = null;
        $finishReason = 'stop';

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

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = BlbJson::decodeArray(substr($line, 6));
                if ($data === null) {
                    continue;
                }

                switch ($pendingEventType) {
                    case 'message_start':
                        $promptTokens = $data['message']['usage']['input_tokens'] ?? null;
                        break;

                    case 'content_block_start':
                        $index = (int) ($data['index'] ?? 0);
                        $contentBlocks[$index] = $data['content_block'] ?? [];

                        if (($contentBlocks[$index]['type'] ?? null) === 'tool_use') {
                            $toolInputJson[$index] = '';

                            yield [
                                'type' => 'tool_call_delta',
                                'index' => $index,
                                'id' => $contentBlocks[$index]['id'] ?? null,
                                'name' => $contentBlocks[$index]['name'] ?? null,
                                'arguments_delta' => '',
                            ];
                        }
                        break;

                    case 'content_block_delta':
                        $index = (int) ($data['index'] ?? 0);
                        $delta = $data['delta'] ?? [];
                        $deltaType = $delta['type'] ?? null;

                        if ($deltaType === 'text_delta') {
                            $text = (string) ($delta['text'] ?? '');
                            if ($text !== '') {
                                $contentBlocks[$index]['text'] = ($contentBlocks[$index]['text'] ?? '').$text;
                                yield ['type' => 'content_delta', 'text' => $text];
                            }

                            break;
                        }

                        if ($deltaType === 'thinking_delta') {
                            $thinking = (string) ($delta['thinking'] ?? '');
                            if ($thinking !== '') {
                                $contentBlocks[$index]['thinking'] = ($contentBlocks[$index]['thinking'] ?? '').$thinking;
                                yield ['type' => 'thinking_delta', 'text' => $thinking, 'source' => 'anthropic_thinking'];
                            }

                            break;
                        }

                        if ($deltaType === 'signature_delta') {
                            $contentBlocks[$index]['signature'] = $delta['signature'] ?? null;

                            break;
                        }

                        if ($deltaType === 'input_json_delta') {
                            $partialJson = (string) ($delta['partial_json'] ?? '');
                            $toolInputJson[$index] = ($toolInputJson[$index] ?? '').$partialJson;

                            yield [
                                'type' => 'tool_call_delta',
                                'index' => $index,
                                'id' => $contentBlocks[$index]['id'] ?? null,
                                'name' => $contentBlocks[$index]['name'] ?? null,
                                'arguments_delta' => $partialJson,
                            ];
                        }
                        break;

                    case 'content_block_stop':
                        $index = (int) ($data['index'] ?? 0);
                        $block = $contentBlocks[$index] ?? null;

                        if (! is_array($block)) {
                            break;
                        }

                        if (($block['type'] ?? null) === 'tool_use') {
                            $block['input'] = BlbJson::decodeArray($toolInputJson[$index] ?? '') ?? [];
                            $contentBlocks[$index] = $block;
                        }

                        if (in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
                            $reasoningBlocks[] = $block;
                        }

                        unset($toolInputJson[$index]);
                        break;

                    case 'message_delta':
                        $finishReason = $data['delta']['stop_reason'] ?? $finishReason;
                        $completionTokens = $data['usage']['output_tokens'] ?? $completionTokens;
                        break;

                    case 'message_stop':
                        yield $this->buildDoneEvent(
                            $finishReason,
                            [
                                'prompt_tokens' => $promptTokens,
                                'completion_tokens' => $completionTokens,
                            ],
                            $startTime,
                            $mapping,
                            $reasoningBlocks !== [] ? ['reasoning_blocks' => $reasoningBlocks] : [],
                        );

                        return;

                    case 'error':
                        $message = $data['error']['message'] ?? 'Anthropic stream error';

                        yield [
                            'type' => 'error',
                            'runtime_error' => AiRuntimeError::fromType(
                                AiErrorType::ServerError,
                                $message,
                                latencyMs: LlmClientSupport::latencyMs($startTime),
                            ),
                            'latency_ms' => LlmClientSupport::latencyMs($startTime),
                        ];

                        return;

                    default:
                        break;
                }
            }
        }

        yield $this->buildDoneEvent(
            $finishReason,
            [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
            $startTime,
            $mapping,
            $reasoningBlocks !== [] ? ['reasoning_blocks' => $reasoningBlocks] : [],
        );
    }

    private function requestMapperRegistry(): ProviderRequestMapperRegistry
    {
        return $this->requestMappers ?? app(ProviderRequestMapperRegistry::class);
    }

    private function mapRequest(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        return $this->requestMapperRegistry()
            ->forApiType($request->apiType)
            ->mapPayload($request, $stream);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withProviderMapping(array $result, ProviderRequestMapping $mapping): array
    {
        $meta = $mapping->meta();

        if ($meta !== null) {
            $result['provider_mapping'] = $meta;
        }

        return $result;
    }

    /**
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null}|null  $usage
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function buildDoneEvent(
        string $finishReason,
        ?array $usage,
        int $startTime,
        ProviderRequestMapping $mapping,
        array $extra = [],
    ): array {
        $event = array_merge([
            'type' => 'done',
            'finish_reason' => $finishReason,
            'usage' => $usage,
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ], $extra);

        $meta = $mapping->meta();
        if ($meta !== null) {
            $event['provider_mapping'] = $meta;
        }

        return $event;
    }

    private function encodeAnthropicToolInput(mixed $input): string
    {
        if (! is_array($input)) {
            return '{}';
        }

        $normalized = $input === [] ? (object) [] : $input;

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
