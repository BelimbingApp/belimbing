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
    public function chat(ChatRequest $request): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/responses',
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->withProviderMapping(
            $this->parseResponse($response, LlmClientSupport::latencyMs($startTime), $request->model),
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers, stream: true);

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

        yield from $this->streamSse($response, $startTime, $mapping);
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
    private function streamSse(Response $response, int $startTime, ProviderRequestMapping $mapping): Generator
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
}
