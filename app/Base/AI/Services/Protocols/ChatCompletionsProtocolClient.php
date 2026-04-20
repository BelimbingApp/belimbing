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

final class ChatCompletionsProtocolClient extends AbstractLlmProtocolClient
{
    protected function pathSuffix(): string
    {
        return 'chat/completions';
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
    protected function protocolStreamSse(Response $response, int $startTime, ProviderRequestMapping $mapping): Generator
    {
        $finishReason = null;
        $firstByteRecorded = false;
        foreach ($this->sseLines($response) as $line) {
            if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data: ')) {
                continue;
            }

            yield from $this->parseSsePayload(substr($line, 6), $finishReason, $startTime, $mapping);

            if ($finishReason === '__done__') {
                return;
            }
        }

        yield $this->buildDoneEvent($finishReason ?? 'stop', null, $startTime, $mapping);
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
        $finishReason = null;
        $firstByteRecorded = false;

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

                if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $request->transportTap?->streamLine($line);

                yield from $this->parseSsePayload(substr($line, 6), $finishReason, $startTime, $mapping);

                if ($finishReason === '__done__') {
                    return;
                }
            }
        }

        if (trim($buffer) !== '') {
            foreach (explode("\n", $buffer) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $request->transportTap?->streamLine($line);

                yield from $this->parseSsePayload(substr($line, 6), $finishReason, $startTime, $mapping);

                if ($finishReason === '__done__') {
                    return;
                }
            }
        }

        yield $this->buildDoneEvent($finishReason ?? 'stop', null, $startTime, $mapping);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function parseSsePayload(
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
}
