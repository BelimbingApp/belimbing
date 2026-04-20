<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\Support\Json as BlbJson;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

final class AnthropicMessagesProtocolClient extends AbstractLlmProtocolClient
{
    protected function pathSuffix(): string
    {
        return 'messages';
    }

    public function chat(ChatRequest $request): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);
        $request->transportTap?->request($request, $mapping, '/'.$this->pathSuffix(), false);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/'.$this->pathSuffix(),
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            $request->transportTap?->error('connection', $e->getMessage());
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);

            return LlmClientSupport::connectionError($e, $startTime);
        }

        $request->transportTap?->responseStatus($response->status(), false);
        $request->transportTap?->responseBody($response->body(), $response->status());

        $result = $this->parseResponse($response, LlmClientSupport::latencyMs($startTime), $request->model);

        if (isset($result['runtime_error'])) {
            $request->transportTap?->error('normalize', $result['runtime_error']->userMessage, [
                'error_type' => $result['runtime_error']->errorType->value,
            ]);
        }

        $request->transportTap?->complete([
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ]);

        return $this->withProviderMapping($result, $mapping);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);
        $request->transportTap?->request($request, $mapping, '/'.$this->pathSuffix(), true);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/'.$this->pathSuffix(),
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            $request->transportTap?->error('connection', $e->getMessage());
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);
            yield from LlmClientSupport::connectionErrorStream($e, $startTime);

            return;
        }

        $request->transportTap?->responseStatus($response->status(), true);

        $error = LlmClientSupport::checkFailedResponse($response, $startTime);
        if ($error !== null) {
            $request->transportTap?->responseBody($response->body(), $response->status());
            $request->transportTap?->error(
                'http',
                (string) (($error['runtime_error'] ?? null)?->userMessage ?? 'Streaming request failed'),
            );
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);
            yield $error;

            return;
        }

        try {
            yield from $this->streamSse($response, $startTime, $mapping, $request);
        } finally {
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);
        }
    }

    private function parseResponse(Response $response, int $latencyMs, string $model): array
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
                        'arguments' => $this->encodeToolInput($block['input'] ?? []),
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
    protected function protocolStreamSse(Response $response, int $startTime, ProviderRequestMapping $mapping): Generator
    {
        $ctx = new class
        {
            public ?string $pendingEventType = null;

            /** @var array<int, array<string, mixed>> */
            public array $contentBlocks = [];

            /** @var array<int, string> */
            public array $toolInputJson = [];

            /** @var array<int, array<string, mixed>> */
            public array $reasoningBlocks = [];

            public mixed $promptTokens = null;

            public mixed $completionTokens = null;

            public string $finishReason = 'stop';
        };

        foreach ($this->sseLines($response, flushTrailingBuffer: true) as $line) {
            $terminal = false;

            yield from $this->anthropicYieldFromSseLine(
                $line,
                $ctx,
                $startTime,
                $mapping,
                $terminal,
            );

            if ($terminal) {
                return;
            }
        }

        yield $this->buildDoneEvent(
            $ctx->finishReason,
            [
                'prompt_tokens' => $ctx->promptTokens,
                'completion_tokens' => $ctx->completionTokens,
            ],
            $startTime,
            $mapping,
            $ctx->reasoningBlocks !== [] ? ['reasoning_blocks' => $ctx->reasoningBlocks] : [],
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamSse(
        Response $response,
        int $startTime,
        ProviderRequestMapping $mapping,
        ChatRequest $request,
    ): Generator {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $firstByteRecorded = false;

        $ctx = new class
        {
            public ?string $pendingEventType = null;

            /** @var array<int, array<string, mixed>> */
            public array $contentBlocks = [];

            /** @var array<int, string> */
            public array $toolInputJson = [];

            /** @var array<int, array<string, mixed>> */
            public array $reasoningBlocks = [];

            public mixed $promptTokens = null;

            public mixed $completionTokens = null;

            public string $finishReason = 'stop';
        };

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            if (! $firstByteRecorded) {
                $request->transportTap?->firstByte();
                $firstByteRecorded = true;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = (string) array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                $request->transportTap?->streamLine($line);

                $terminal = false;

                yield from $this->anthropicYieldFromSseLine(
                    $line,
                    $ctx,
                    $startTime,
                    $mapping,
                    $terminal,
                );

                if ($terminal) {
                    return;
                }
            }
        }

        if (trim($buffer) !== '') {
            foreach (explode("\n", $buffer) as $line) {
                $line = trim($line);
                $request->transportTap?->streamLine($line);

                $terminal = false;
                yield from $this->anthropicYieldFromSseLine(
                    $line,
                    $ctx,
                    $startTime,
                    $mapping,
                    $terminal,
                );
                if ($terminal) {
                    return;
                }
            }
        }

        yield $this->buildDoneEvent(
            $ctx->finishReason,
            [
                'prompt_tokens' => $ctx->promptTokens,
                'completion_tokens' => $ctx->completionTokens,
            ],
            $startTime,
            $mapping,
            $ctx->reasoningBlocks !== [] ? ['reasoning_blocks' => $ctx->reasoningBlocks] : [],
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicYieldFromSseLine(
        string $line,
        object $ctx,
        int $startTime,
        ProviderRequestMapping $mapping,
        bool &$terminal,
    ): Generator {
        $line = trim($line);
        $data = null;

        if ($line !== '' && ! str_starts_with($line, ':')) {
            if (str_starts_with($line, 'event: ')) {
                $ctx->pendingEventType = substr($line, 7);
            } elseif (str_starts_with($line, 'data: ')) {
                $data = BlbJson::decodeArray(substr($line, 6));
            }
        }

        if ($data !== null) {
            yield from $this->anthropicDispatchSseData($ctx, $data, $startTime, $mapping, $terminal);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicDispatchSseData(
        object $ctx,
        array $data,
        int $startTime,
        ProviderRequestMapping $mapping,
        bool &$terminal,
    ): Generator {
        yield from match ($ctx->pendingEventType) {
            'message_start' => $this->anthropicOnMessageStart($data, $ctx),
            'content_block_start' => $this->anthropicOnContentBlockStart($data, $ctx),
            'content_block_delta' => $this->anthropicOnContentBlockDelta($data, $ctx),
            'content_block_stop' => $this->anthropicOnContentBlockStop($data, $ctx),
            'message_delta' => $this->anthropicOnMessageDelta($data, $ctx),
            'message_stop' => $this->anthropicOnMessageStop($ctx, $startTime, $mapping, $terminal),
            'error' => $this->anthropicOnStreamError($data, $startTime, $terminal),
            default => $this->anthropicNoOpStream(),
        };
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicNoOpStream(): Generator
    {
        yield from [];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnMessageStart(array $data, object $ctx): Generator
    {
        $ctx->promptTokens = $data['message']['usage']['input_tokens'] ?? null;

        yield from [];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnContentBlockStart(array $data, object $ctx): Generator
    {
        $index = (int) ($data['index'] ?? 0);
        $ctx->contentBlocks[$index] = $data['content_block'] ?? [];

        if (($ctx->contentBlocks[$index]['type'] ?? null) !== 'tool_use') {
            yield from [];
        } else {
            $ctx->toolInputJson[$index] = '';

            yield [
                'type' => 'tool_call_delta',
                'index' => $index,
                'id' => $ctx->contentBlocks[$index]['id'] ?? null,
                'name' => $ctx->contentBlocks[$index]['name'] ?? null,
                'arguments_delta' => '',
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnContentBlockDelta(array $data, object $ctx): Generator
    {
        $index = (int) ($data['index'] ?? 0);
        $delta = $data['delta'] ?? [];
        $deltaType = $delta['type'] ?? null;

        yield from match ($deltaType) {
            'text_delta' => $this->anthropicOnTextDelta($index, $delta, $ctx),
            'thinking_delta' => $this->anthropicOnThinkingDelta($index, $delta, $ctx),
            'signature_delta' => $this->anthropicOnSignatureDelta($index, $delta, $ctx),
            'input_json_delta' => $this->anthropicOnInputJsonDelta($index, $delta, $ctx),
            default => $this->anthropicNoOpStream(),
        };
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnTextDelta(int $index, array $delta, object $ctx): Generator
    {
        $text = (string) ($delta['text'] ?? '');
        if ($text === '') {
            yield from [];
        } else {
            $ctx->contentBlocks[$index]['text'] = ($ctx->contentBlocks[$index]['text'] ?? '').$text;

            yield ['type' => 'content_delta', 'text' => $text];
        }
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnThinkingDelta(int $index, array $delta, object $ctx): Generator
    {
        $thinking = (string) ($delta['thinking'] ?? '');
        if ($thinking === '') {
            yield from [];
        } else {
            $ctx->contentBlocks[$index]['thinking'] = ($ctx->contentBlocks[$index]['thinking'] ?? '').$thinking;

            yield ['type' => 'thinking_delta', 'text' => $thinking, 'source' => 'anthropic_thinking'];
        }
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnSignatureDelta(int $index, array $delta, object $ctx): Generator
    {
        $ctx->contentBlocks[$index]['signature'] = $delta['signature'] ?? null;

        yield from [];
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnInputJsonDelta(int $index, array $delta, object $ctx): Generator
    {
        $partialJson = (string) ($delta['partial_json'] ?? '');
        $ctx->toolInputJson[$index] = ($ctx->toolInputJson[$index] ?? '').$partialJson;

        yield [
            'type' => 'tool_call_delta',
            'index' => $index,
            'id' => $ctx->contentBlocks[$index]['id'] ?? null,
            'name' => $ctx->contentBlocks[$index]['name'] ?? null,
            'arguments_delta' => $partialJson,
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnContentBlockStop(array $data, object $ctx): Generator
    {
        $index = (int) ($data['index'] ?? 0);
        $block = $ctx->contentBlocks[$index] ?? null;

        if (! is_array($block)) {
            yield from [];
        } else {
            if (($block['type'] ?? null) === 'tool_use') {
                $block['input'] = BlbJson::decodeArray($ctx->toolInputJson[$index] ?? '') ?? [];
                $ctx->contentBlocks[$index] = $block;
            }

            if (in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
                $ctx->reasoningBlocks[] = $block;
            }

            unset($ctx->toolInputJson[$index]);

            yield from [];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnMessageDelta(array $data, object $ctx): Generator
    {
        $ctx->finishReason = $data['delta']['stop_reason'] ?? $ctx->finishReason;
        $ctx->completionTokens = $data['usage']['output_tokens'] ?? $ctx->completionTokens;

        yield from [];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnMessageStop(
        object $ctx,
        int $startTime,
        ProviderRequestMapping $mapping,
        bool &$terminal,
    ): Generator {
        yield $this->buildDoneEvent(
            $ctx->finishReason,
            [
                'prompt_tokens' => $ctx->promptTokens,
                'completion_tokens' => $ctx->completionTokens,
            ],
            $startTime,
            $mapping,
            $ctx->reasoningBlocks !== [] ? ['reasoning_blocks' => $ctx->reasoningBlocks] : [],
        );

        $terminal = true;

        yield from [];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function anthropicOnStreamError(array $data, int $startTime, bool &$terminal): Generator
    {
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

        $terminal = true;

        yield from [];
    }

    private function encodeToolInput(mixed $input): string
    {
        if (! is_array($input)) {
            return '{}';
        }

        $normalized = $input === [] ? (object) [] : $input;

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
