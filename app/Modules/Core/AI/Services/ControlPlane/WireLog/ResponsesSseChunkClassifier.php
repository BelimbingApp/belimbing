<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

/**
 * @internal
 *
 * Isolates the Responses API streaming event classification from StreamAssembler.
 */
final class ResponsesSseChunkClassifier
{
    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function classify(
        array $base,
        string $eventType,
        array $payload,
        ?string &$responsesMessagePhase,
    ): array {
        return match ($eventType) {
            'response.output_text.delta' => self::textDelta(
                $base,
                $payload,
                $responsesMessagePhase === 'commentary' ? 'reasoning' : 'content',
                $responsesMessagePhase === 'commentary' ? 'info' : 'default',
            ),
            'response.refusal.delta' => self::textDelta($base, $payload, 'content', 'default'),
            'response.reasoning_summary_text.delta',
            'response.reasoning_text.delta' => self::textDelta($base, $payload, 'reasoning', 'info'),
            'response.output_item.added' => self::outputItemAdded($base, $payload, $responsesMessagePhase),
            'response.function_call_arguments.delta' => self::toolArgumentsDelta($base, $payload),
            'response.function_call_arguments.done' => self::toolArgumentsDone($base, $payload),
            'response.output_item.done' => self::outputItemDone($base, $payload, $responsesMessagePhase),
            'response.completed' => self::completed($base, $payload),
            'response.failed' => self::finishReason($base, 'error', 'danger'),
            default => self::empty($base),
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function textDelta(array $base, array $payload, string $kind, string $severity): array
    {
        $delta = is_string($payload['delta'] ?? null) ? $payload['delta'] : '';

        if ($delta === '') {
            return self::empty($base);
        }

        return array_merge($base, [
            'kind' => $kind,
            'text' => $delta,
            'severity' => $severity,
            'tool_index' => null,
            'tool_call_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function outputItemAdded(array $base, array $payload, ?string &$responsesMessagePhase): array
    {
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];

        if (($item['type'] ?? null) === 'message') {
            $responsesMessagePhase = is_string($item['phase'] ?? null) ? $item['phase'] : null;

            return self::empty($base);
        }

        if (($item['type'] ?? null) !== 'function_call') {
            return self::empty($base);
        }

        $index = is_int($payload['output_index'] ?? null) ? (int) $payload['output_index'] : 0;
        $callId = is_string($item['call_id'] ?? null) ? $item['call_id'] : null;
        $name = is_string($item['name'] ?? null) ? $item['name'] : null;

        if ($name === null || $name === '') {
            return self::empty($base);
        }

        return array_merge($base, [
            'kind' => 'tool_call',
            'text' => $name,
            'severity' => 'accent',
            'tool_index' => $index,
            'tool_call_id' => $callId,
            'tool_name' => $name,
            'tool_arguments' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function toolArgumentsDelta(array $base, array $payload): array
    {
        $delta = is_string($payload['delta'] ?? null) ? $payload['delta'] : '';

        if ($delta === '') {
            return self::empty($base);
        }

        $index = is_int($payload['output_index'] ?? null) ? (int) $payload['output_index'] : 0;
        $callId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;

        return array_merge($base, [
            'kind' => 'tool_args',
            'text' => $delta,
            'severity' => 'info',
            'tool_index' => $index,
            'tool_call_id' => $callId,
            'tool_name' => null,
            'tool_arguments' => $delta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function toolArgumentsDone(array $base, array $payload): array
    {
        $arguments = is_string($payload['arguments'] ?? null) ? $payload['arguments'] : '';

        if ($arguments === '') {
            return self::empty($base);
        }

        $index = is_int($payload['output_index'] ?? null) ? (int) $payload['output_index'] : 0;
        $callId = is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null;

        return array_merge($base, [
            'kind' => 'tool_args_done',
            'text' => __('complete arguments'),
            'severity' => 'success',
            'tool_index' => $index,
            'tool_call_id' => $callId,
            'tool_name' => null,
            'tool_arguments' => $arguments,
            'tool_arguments_replace' => true,
            'tool_arguments_complete' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function outputItemDone(array $base, array $payload, ?string &$responsesMessagePhase): array
    {
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];

        if (($item['type'] ?? null) === 'message') {
            $responsesMessagePhase = null;

            return self::empty($base);
        }

        if (($item['type'] ?? null) !== 'function_call') {
            return self::empty($base);
        }

        $arguments = is_string($item['arguments'] ?? null) ? $item['arguments'] : '';
        if ($arguments === '') {
            return self::empty($base);
        }

        $index = is_int($payload['output_index'] ?? null) ? (int) $payload['output_index'] : 0;
        $callId = is_string($item['call_id'] ?? null) ? $item['call_id'] : null;
        $name = is_string($item['name'] ?? null) ? $item['name'] : null;

        return array_merge($base, [
            'kind' => 'tool_args_done',
            'text' => __('complete arguments'),
            'severity' => 'success',
            'tool_index' => $index,
            'tool_call_id' => $callId,
            'tool_name' => $name,
            'tool_arguments' => $arguments,
            'tool_arguments_replace' => true,
            'tool_arguments_complete' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function completed(array $base, array $payload): array
    {
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : $payload;
        $status = is_string($response['status'] ?? null) ? $response['status'] : 'completed';

        return self::finishReason($base, match ($status) {
            'completed' => 'stop',
            'incomplete' => 'length',
            default => 'error',
        });
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private static function finishReason(array $base, string $finishReason, ?string $severity = null): array
    {
        return array_merge($base, [
            'kind' => 'finish_reason',
            'text' => $finishReason,
            'severity' => $severity ?? StreamAssembler::finishReasonSeverity($finishReason),
            'tool_index' => null,
            'tool_call_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private static function empty(array $base): array
    {
        return array_merge($base, [
            'kind' => 'empty',
            'text' => '',
            'severity' => 'default',
            'tool_index' => null,
            'tool_call_id' => null,
        ]);
    }
}
