<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\AI\Services\LlmResponsesDecoder;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

final class ResponsesProtocolClient extends AbstractLlmProtocolClient
{
    protected function pathSuffix(): string
    {
        return 'responses';
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

        foreach ($data['output'] ?? [] as $item) {
            if (is_array($item)) {
                LlmResponsesDecoder::applyOutputItem($item, $content, $toolCalls);
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

            public int $toolCallIndex = 0;

            public mixed $currentToolCallId = null;

            public mixed $currentToolCallName = null;

            public mixed $currentMessagePhase = null;
        };

        foreach ($this->sseLines($response) as $line) {
            $done = false;

            yield from $this->yieldResponsesSseLineEvents(
                $line,
                $ctx,
                $startTime,
                $done,
            );

            if ($done) {
                return;
            }
        }

        yield $this->buildDoneEvent('stop', null, $startTime, $mapping);
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
            public int $toolCallIndex = 0;
            public mixed $currentToolCallId = null;
            public mixed $currentToolCallName = null;
            public mixed $currentMessagePhase = null;
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

                $done = false;
                yield from $this->yieldResponsesSseLineEvents(
                    $line,
                    $ctx,
                    $startTime,
                    $done,
                );

                if ($done) {
                    return;
                }
            }
        }

        if (trim($buffer) !== '') {
            foreach (explode("\n", $buffer) as $line) {
                $line = trim($line);
                $request->transportTap?->streamLine($line);

                $done = false;
                yield from $this->yieldResponsesSseLineEvents(
                    $line,
                    $ctx,
                    $startTime,
                    $done,
                );
                if ($done) {
                    return;
                }
            }
        }

        yield $this->buildDoneEvent('stop', null, $startTime, $mapping);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function yieldResponsesSseLineEvents(
        string $line,
        object $ctx,
        int $startTime,
        bool &$terminal,
    ): Generator {
        if ($line === '' || str_starts_with($line, ':')) {
            return;
        }

        if (str_starts_with($line, 'event: ')) {
            $ctx->pendingEventType = substr($line, 7);

            return;
        }

        if (! str_starts_with($line, 'data: ')) {
            return;
        }

        yield from LlmResponsesDecoder::processDataLine(
            $line,
            $ctx->pendingEventType,
            $startTime,
            $ctx->toolCallIndex,
            $ctx->currentToolCallId,
            $ctx->currentToolCallName,
            $ctx->currentMessagePhase,
        );

        if ($ctx->pendingEventType === '__done__') {
            $terminal = true;
        }
    }
}
