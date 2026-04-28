<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

use App\Base\Support\Json as BlbJson;
use Illuminate\Support\Str;
use Throwable;

/**
 * @internal
 *
 * Parses OpenAI-style SSE stream chunks and reassembles artifacts for readable display.
 */
final class StreamAssembler
{
    /** Threshold above which inter-fragment gaps render as a stall warning. */
    private const GAP_WARNING_MS = 5_000;

    /** Maximum consecutive empty deltas before they collapse into a heartbeat run. */
    private const EMPTY_RUN_COLLAPSE = 2;

    public const SSE_DATA_PREFIX = 'data: ';

    public const SSE_DONE_LINE = 'data: [DONE]';

    /** Recognized keys inside an OpenAI-style streaming `delta` object. */
    private const KNOWN_DELTA_KEYS = [
        'role',
        'content',
        'reasoning_content',
        'tool_calls',
        'function_call',
        'refusal',
    ];

    /** Recognized keys inside an OpenAI-style streaming `choice` object. */
    private const KNOWN_CHOICE_KEYS = [
        'index',
        'delta',
        'finish_reason',
        'native_finish_reason',
        'logprobs',
    ];

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function buildStreamBlock(array $entries, callable $diffMs): array
    {
        $rawFragments = [];
        $previousAt = null;
        $assembledContent = '';
        $assembledReasoning = '';
        $toolCalls = [];
        $finishReason = null;
        $finishReasonSeverity = 'default';
        $unknownKeys = [];
        $unknownKeyEntries = [];

        foreach ($entries as $entry) {
            $fragment = $this->buildFragment($entry, $previousAt, $diffMs);
            $previousAt = $fragment['at'];

            $this->trackUnknownKeys($fragment, $unknownKeys, $unknownKeyEntries);

            $rawFragments[] = $fragment;
            $this->mergeArtifactsFromFragment(
                $fragment,
                $assembledContent,
                $assembledReasoning,
                $toolCalls,
                $finishReason,
                $finishReasonSeverity,
            );
        }

        $fragments = $this->collapseEmptyRuns($rawFragments);

        $first = $entries[0];
        $last = end($entries);

        $startedAt = is_string($first['at'] ?? null) ? $first['at'] : null;
        $endedAt = is_string($last['at'] ?? null) ? $last['at'] : null;

        $maxGapMs = 0;

        foreach ($fragments as $fragment) {
            $gap = $fragment['gap_ms'] ?? null;

            if (is_int($gap) && $gap > $maxGapMs) {
                $maxGapMs = $gap;
            }
        }

        return [
            'kind' => 'stream_block',
            'first_entry_number' => (int) ($first['entry_number'] ?? 0),
            'last_entry_number' => (int) ($last['entry_number'] ?? 0),
            'chunk_count' => count($entries),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $diffMs($startedAt, $endedAt),
            'reassembled_content' => $assembledContent,
            'reassembled_reasoning' => $assembledReasoning,
            'tool_calls' => $this->finalizeToolCalls($toolCalls),
            'finish_reason' => $finishReason,
            'finish_reason_severity' => $finishReasonSeverity,
            'fragments' => $fragments,
            'max_gap_ms' => $maxGapMs,
            'unknown_keys' => array_keys($unknownKeys),
            'unknown_key_entries' => array_values(array_unique($unknownKeyEntries)),
        ];
    }

    /**
     * @param  array<string, mixed>  $fragment
     * @param  array<string, true>  $unknownKeys
     * @param  list<int>  $unknownKeyEntries
     */
    private function trackUnknownKeys(array $fragment, array &$unknownKeys, array &$unknownKeyEntries): void
    {
        if ($fragment['unknown_keys'] === []) {
            return;
        }

        foreach ($fragment['unknown_keys'] as $key) {
            $unknownKeys[$key] = true;
        }

        $unknownKeyEntries[] = $fragment['entry_number'];
    }

    /**
     * @param  array<string, mixed>  $fragment
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function mergeArtifactsFromFragment(
        array $fragment,
        string &$assembledContent,
        string &$assembledReasoning,
        array &$toolCalls,
        ?string &$finishReason,
        string &$finishReasonSeverity,
    ): void {
        if ($fragment['kind'] === 'content') {
            $assembledContent .= $fragment['text'];

            return;
        }

        if ($fragment['kind'] === 'reasoning') {
            $assembledReasoning .= $fragment['text'];

            return;
        }

        if ($fragment['kind'] === 'tool_call' || $fragment['kind'] === 'tool_args') {
            $this->mergeToolCallFragment($toolCalls, $fragment);

            return;
        }

        if ($fragment['kind'] === 'finish_reason') {
            $finishReason = $fragment['text'];
            $finishReasonSeverity = self::finishReasonSeverity($finishReason);
        }
    }

    /**
     * Collapse runs of three or more empty fragments into a single heartbeat marker.
     *
     * @param  list<array<string, mixed>>  $fragments
     * @return list<array<string, mixed>>
     */
    private function collapseEmptyRuns(array $fragments): array
    {
        $output = [];
        $emptyBuffer = [];

        $flush = function () use (&$output, &$emptyBuffer): void {
            if ($emptyBuffer === []) {
                return;
            }

            if (count($emptyBuffer) > self::EMPTY_RUN_COLLAPSE) {
                $first = $emptyBuffer[0];
                $last = end($emptyBuffer);
                $output[] = [
                    'kind' => 'empty_run',
                    'count' => count($emptyBuffer),
                    'first_entry_number' => $first['entry_number'],
                    'last_entry_number' => $last['entry_number'],
                    'first_at' => $first['at'],
                    'last_at' => $last['at'],
                    'contained_entries' => array_map(fn (array $f): int => $f['entry_number'], $emptyBuffer),
                    'severity' => 'default',
                ];
            } else {
                foreach ($emptyBuffer as $fragment) {
                    $output[] = $fragment;
                }
            }

            $emptyBuffer = [];
        };

        foreach ($fragments as $fragment) {
            if ($fragment['kind'] === 'empty') {
                $emptyBuffer[] = $fragment;

                continue;
            }

            $flush();
            $output[] = $fragment;
        }

        $flush();

        return $output;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function buildFragment(array $entry, ?string $previousAt, callable $diffMs): array
    {
        $entryNumber = (int) ($entry['entry_number'] ?? 0);
        $at = is_string($entry['at'] ?? null) ? $entry['at'] : null;
        $previewStatus = is_string($entry['preview_status'] ?? null) ? $entry['preview_status'] : 'full';
        $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
        $rawLine = is_string($decoded['raw_line'] ?? null) ? $decoded['raw_line'] : '';

        $gapMs = $diffMs($previousAt, $at);

        $base = [
            'entry_number' => $entryNumber,
            'at' => $at,
            'gap_ms' => $gapMs,
            'has_gap_warning' => $gapMs !== null && $gapMs > self::GAP_WARNING_MS,
            'raw_line' => $rawLine,
            'unknown_keys' => [],
        ];

        if ($previewStatus === 'decode_error' || $previewStatus === 'encode_error' || $previewStatus === 'line_omitted') {
            return array_merge($base, [
                'kind' => 'decode_error',
                'text' => $previewStatus === 'line_omitted'
                    ? __('Line omitted (oversized)')
                    : __('Decode error'),
                'severity' => 'warning',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        if ($rawLine === '' || $rawLine === self::SSE_DATA_PREFIX) {
            return array_merge($base, [
                'kind' => 'empty',
                'text' => '',
                'severity' => 'default',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        if ($rawLine === self::SSE_DONE_LINE) {
            return array_merge($base, [
                'kind' => 'done',
                'text' => '[DONE]',
                'severity' => 'default',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        if (! str_starts_with($rawLine, self::SSE_DATA_PREFIX)) {
            return array_merge($base, [
                'kind' => 'raw',
                'text' => Str::limit($rawLine, 160, '…'),
                'severity' => 'default',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        $payload = BlbJson::decodeArray(substr($rawLine, strlen(self::SSE_DATA_PREFIX)));

        if (! is_array($payload)) {
            return array_merge($base, [
                'kind' => 'decode_error',
                'text' => __('Could not decode SSE chunk'),
                'severity' => 'warning',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        return $this->classifySseChunk($base, $payload);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function classifySseChunk(array $base, array $payload): array
    {
        $choice = $payload['choices'][0] ?? null;

        if (! is_array($choice)) {
            return array_merge($base, [
                'kind' => 'raw',
                'text' => Str::limit(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 160, '…'),
                'severity' => 'default',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        $finishReason = $choice['finish_reason'] ?? null;

        $unknownKeys = array_values(array_diff(array_keys($delta), self::KNOWN_DELTA_KEYS));
        $unknownChoiceKeys = array_values(array_diff(array_keys($choice), self::KNOWN_CHOICE_KEYS));
        $base['unknown_keys'] = array_merge($unknownKeys, $unknownChoiceKeys);

        $content = $this->contentFragment($base, $delta);
        if ($content !== null) {
            return $content;
        }

        $reasoning = $this->reasoningFragment($base, $delta);
        if ($reasoning !== null) {
            return $reasoning;
        }

        $tool = $this->toolFragment($base, $delta);
        if ($tool !== null) {
            return $tool;
        }

        $finish = $this->finishReasonFragment($base, $finishReason);
        if ($finish !== null) {
            return $finish;
        }

        return array_merge($base, [
            'kind' => 'empty',
            'text' => '',
            'severity' => 'default',
            'tool_index' => null,
            'tool_call_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>|null
     */
    private function contentFragment(array $base, array $delta): ?array
    {
        if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            return array_merge($base, [
                'kind' => 'content',
                'text' => $delta['content'],
                'severity' => 'default',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>|null
     */
    private function reasoningFragment(array $base, array $delta): ?array
    {
        if (is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
            return array_merge($base, [
                'kind' => 'reasoning',
                'text' => $delta['reasoning_content'],
                'severity' => 'info',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>|null
     */
    private function toolFragment(array $base, array $delta): ?array
    {
        $toolCalls = $delta['tool_calls'] ?? null;

        if (! (is_array($toolCalls) && isset($toolCalls[0]) && is_array($toolCalls[0]))) {
            return null;
        }

        $tc = $toolCalls[0];
        $index = is_int($tc['index'] ?? null) ? (int) $tc['index'] : 0;
        $callId = is_string($tc['id'] ?? null) ? $tc['id'] : null;
        $name = is_string($tc['function']['name'] ?? null) ? $tc['function']['name'] : null;
        $arguments = is_string($tc['function']['arguments'] ?? null) ? $tc['function']['arguments'] : null;

        if (($name !== null && $name !== '') || ($arguments !== null && $arguments !== '')) {
            return array_merge($base, [
                'kind' => $name !== null && $name !== '' ? 'tool_call' : 'tool_args',
                'text' => $name !== null && $name !== '' ? $name : (string) $arguments,
                'severity' => $name !== null && $name !== '' ? 'accent' : 'info',
                'tool_index' => $index,
                'tool_call_id' => $callId,
                'tool_name' => $name !== null && $name !== '' ? $name : null,
                'tool_arguments' => $arguments !== null && $arguments !== '' ? $arguments : null,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function finishReasonFragment(array $base, mixed $finishReason): ?array
    {
        if (is_string($finishReason) && $finishReason !== '') {
            return array_merge($base, [
                'kind' => 'finish_reason',
                'text' => $finishReason,
                'severity' => self::finishReasonSeverity($finishReason),
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<string, mixed>  $fragment
     */
    private function mergeToolCallFragment(array &$toolCalls, array $fragment): void
    {
        $index = $fragment['tool_index'] ?? 0;
        $existing = $toolCalls[$index] ?? [
            'index' => $index,
            'id' => null,
            'name' => null,
            'arguments' => '',
            'source_entries' => [],
        ];

        $name = $fragment['tool_name'] ?? ($fragment['kind'] === 'tool_call' ? $fragment['text'] : null);
        $arguments = $fragment['tool_arguments'] ?? ($fragment['kind'] === 'tool_args' ? $fragment['text'] : null);

        if (is_string($name) && $name !== '' && $existing['name'] === null) {
            $existing['name'] = $name;
        }

        if (is_string($arguments) && $arguments !== '') {
            $existing['arguments'] .= $arguments;
        }

        if (is_string($fragment['tool_call_id']) && $fragment['tool_call_id'] !== '' && $existing['id'] === null) {
            $existing['id'] = $fragment['tool_call_id'];
        }

        $existing['source_entries'][] = $fragment['entry_number'];

        $toolCalls[$index] = $existing;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return list<array<string, mixed>>
     */
    private function finalizeToolCalls(array $toolCalls): array
    {
        ksort($toolCalls);

        $finalized = [];

        foreach ($toolCalls as $tc) {
            $arguments = (string) ($tc['arguments'] ?? '');
            $parsed = $this->parseToolArguments($arguments);

            $finalized[] = [
                'index' => (int) ($tc['index'] ?? 0),
                'id' => $tc['id'] ?? null,
                'name' => $tc['name'] ?? null,
                'arguments' => $arguments,
                'arguments_pretty' => $parsed['pretty'],
                'arguments_valid_json' => $parsed['valid'],
                'arguments_parse_error' => $parsed['error'],
                'source_entries' => array_values(array_unique($tc['source_entries'] ?? [])),
            ];
        }

        return $finalized;
    }

    /**
     * @return array{valid: bool, pretty: string, error: string|null}
     */
    private function parseToolArguments(string $arguments): array
    {
        if ($arguments === '') {
            return ['valid' => false, 'pretty' => '', 'error' => null];
        }

        try {
            $decoded = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'pretty' => $arguments,
                'error' => $e->getMessage(),
            ];
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'valid' => true,
            'pretty' => is_string($pretty) ? $pretty : $arguments,
            'error' => null,
        ];
    }

    public static function finishReasonSeverity(?string $finishReason): string
    {
        return match ($finishReason) {
            null, '', 'stop' => 'default',
            'tool_calls' => 'info',
            'length' => 'warning',
            'content_filter', 'error' => 'danger',
            default => 'default',
        };
    }
}
