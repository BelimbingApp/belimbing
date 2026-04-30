<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\AI\Services\LlmResponsesDecoder;
use App\Base\AI\Services\LlmUsageNormalizer;
use Generator;
use Illuminate\Http\Client\Response;

abstract class AbstractResponsesProtocolClient extends AbstractLlmProtocolClient
{
    protected function parseResponse(Response $response, int $latencyMs, string $model): array
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

        $result = $content === '' && ! $hasToolCalls
            ? [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::EmptyResponse,
                    "Model \"{$model}\" produced no text content",
                    'The model may be unavailable for this provider key or endpoint.',
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ]
            : [
                'content' => $content,
                'usage' => LlmUsageNormalizer::fromProviderArray(is_array($usage) ? $usage : null),
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
    protected function protocolStreamSse(
        Response $response,
        int $startTime,
        ProviderRequestMapping $mapping,
        ?LlmTransportTap $transportTap,
    ): Generator {
        $ctx = new class
        {
            public ?string $pendingEventType = null;

            public int $toolCallIndex = 0;

            public mixed $currentToolCallId = null;

            public mixed $currentToolCallName = null;

            public mixed $currentMessagePhase = null;
        };

        foreach ($this->sseLines($response, $transportTap) as $line) {
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
    private function yieldResponsesSseLineEvents(
        string $line,
        object $ctx,
        int $startTime,
        bool &$terminal,
    ): Generator {
        if ($line !== '' && ! str_starts_with($line, ':') && str_starts_with($line, 'event: ')) {
            $ctx->pendingEventType = substr($line, 7);

            return;
        }

        if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data: ')) {
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
