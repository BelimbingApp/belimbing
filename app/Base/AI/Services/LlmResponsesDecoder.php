<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Base\Support\Json as BlbJson;
use Generator;

final class LlmResponsesDecoder
{
    private const UNKNOWN_ERROR = 'Unknown error';

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    public static function applyOutputItem(array $item, string &$content, array &$toolCalls): void
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
    public static function processDataLine(
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

        yield from self::processSseEvent(
            $event,
            $data,
            $startTime,
            $toolCallIndex,
            $currentToolCallId,
            $currentToolCallName,
        );

        if (in_array($event, ['response.completed', 'response.failed', 'error'], true)) {
            $pendingEventType = '__done__';
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Generator<int, array<string, mixed>>
     */
    private static function processSseEvent(
        string $event,
        array $data,
        int $startTime,
        int &$toolCallIndex,
        ?string &$currentToolCallId,
        ?string &$currentToolCallName,
    ): Generator {
        switch ($event) {
            case 'response.created':
                $resp = $data['response'] ?? $data;
                yield [
                    'type' => 'response_created',
                    'response_id' => $resp['id'] ?? null,
                ];

                return;

            case 'response.output_text.delta':
                $deltaEvent = self::responseTextDeltaEvent($data);

                if ($deltaEvent !== null) {
                    yield $deltaEvent;
                }

                return;

            case 'response.output_text.annotation.added':
                $annotation = $data['annotation'] ?? $data;
                yield [
                    'type' => 'annotation',
                    'annotation_type' => $annotation['type'] ?? 'unknown',
                    'url' => $annotation['url'] ?? null,
                    'title' => $annotation['title'] ?? null,
                    'start_index' => $annotation['start_index'] ?? null,
                    'end_index' => $annotation['end_index'] ?? null,
                ];

                return;

            case 'response.refusal.delta':
                $delta = $data['delta'] ?? '';
                if ($delta !== '') {
                    yield ['type' => 'content_delta', 'text' => $delta];
                }

                return;

            case 'response.refusal.done':
                return;

            case 'response.output_item.added':
                $toolCallAddedEvent = self::responseOutputItemAddedEvent(
                    data: $data,
                    toolCallIndex: $toolCallIndex,
                    currentToolCallId: $currentToolCallId,
                    currentToolCallName: $currentToolCallName,
                );

                if ($toolCallAddedEvent !== null) {
                    yield $toolCallAddedEvent;
                }

                return;

            case 'response.function_call_arguments.delta':
                yield self::responseFunctionCallArgumentsDeltaEvent($data, $toolCallIndex);

                return;

            case 'response.output_item.done':
                self::completeResponseOutputItem($data, $toolCallIndex, $currentToolCallId, $currentToolCallName);

                return;

            case 'response.completed':
                yield self::responseCompletedEvent($data, $startTime);

                return;

            case 'response.failed':
                yield self::responseFailureEvent($data, $startTime);

                return;

            case 'error':
                yield self::responseErrorEvent($data, $startTime);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function responseTextDeltaEvent(array $data): ?array
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
    private static function responseOutputItemAddedEvent(
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
    private static function responseFunctionCallArgumentsDeltaEvent(array $data, int $toolCallIndex): array
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
    private static function completeResponseOutputItem(
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
    private static function responseCompletedEvent(array $data, int $startTime): array
    {
        $resp = $data['response'] ?? $data;
        $usage = $resp['usage'] ?? null;
        $status = $resp['status'] ?? 'completed';

        return [
            'type' => 'done',
            'finish_reason' => self::responseFinishReason($status),
            'usage' => $usage !== null ? [
                'prompt_tokens' => $usage['input_tokens'] ?? null,
                'completion_tokens' => $usage['output_tokens'] ?? null,
            ] : null,
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ];
    }

    private static function responseFinishReason(string $status): string
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
    private static function responseFailureEvent(array $data, int $startTime): array
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
    private static function responseErrorEvent(array $data, int $startTime): array
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
