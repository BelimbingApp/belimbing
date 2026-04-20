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
use Illuminate\Http\Client\Response;

final class ChatCompletionsProtocolClient extends AbstractLlmProtocolClient
{
    public function chat(ChatRequest $request): array
    {
        return $this->chatOverHttp(
            $request,
            'chat/completions',
            fn (Response $response, int $latencyMs, string $model): array => $this->parseResponse($response, $latencyMs, $model),
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        yield from $this->chatStreamOverHttp($request, 'chat/completions');
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

                yield from $this->parseSsePayload(substr($line, 6), $finishReason, $startTime, $mapping);

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
