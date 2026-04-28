<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Support\Json as BlbJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Derives a human-oriented presentation from retained wire-log entries.
 *
 * The JSONL on disk remains the source of truth. This formatter is a pure
 * interpretation layer: it groups adjacent stream chunks, reassembles
 * assistant content / reasoning / tool-call arguments, segments multi-attempt
 * runs, and extracts derived signals (anomalies, timing markers).
 */
class WireLogReadableFormatter
{
    /** Threshold above which inter-fragment gaps render as a stall warning. */
    private const GAP_WARNING_MS = 5_000;

    /** Maximum consecutive empty deltas before they collapse into a heartbeat run. */
    private const EMPTY_RUN_COLLAPSE = 2;

    private const SSE_DATA_PREFIX = 'data: ';

    private const SSE_DONE_LINE = 'data: [DONE]';

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
     * @return array{
     *     overview: array<string, mixed>,
     *     anomalies: list<array<string, mixed>>,
     *     attempts: list<array<string, mixed>>,
     *     has_entries: bool
     * }
     */
    public function format(array $entries): array
    {
        if ($entries === []) {
            return [
                'overview' => $this->emptyOverview(),
                'anomalies' => [],
                'attempts' => [],
                'has_entries' => false,
            ];
        }

        $attempts = $this->buildAttempts($entries);
        $anomalies = $this->collectAnomalies($entries, $attempts);
        $overview = $this->buildOverview($entries, $attempts, $anomalies);

        return [
            'overview' => $overview,
            'anomalies' => $anomalies,
            'attempts' => $attempts,
            'has_entries' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function buildAttempts(array $entries): array
    {
        $attempts = [];
        $current = null;

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type === 'llm.request') {
                if ($current !== null) {
                    $attempts[] = $this->finalizeAttempt($current, count($attempts) + 1);
                }
                $current = $this->newAttemptBucket($entry);

                continue;
            }

            if ($current === null) {
                $current = $this->newAttemptBucket(null);
            }

            $current['entries'][] = $entry;
        }

        if ($current !== null) {
            $attempts[] = $this->finalizeAttempt($current, count($attempts) + 1);
        }

        return $attempts;
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     * @return array<string, mixed>
     */
    private function newAttemptBucket(?array $requestEntry): array
    {
        return [
            'request_entry' => $requestEntry,
            'entries' => $requestEntry !== null ? [$requestEntry] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function finalizeAttempt(array $bucket, int $index): array
    {
        $entries = $bucket['entries'];
        $requestEntry = $bucket['request_entry'];
        $sections = $this->groupEntries($entries);

        $startedAt = $this->firstAt($entries);
        $endedAt = $this->lastAt($entries);
        $statusCode = $this->extractStatusCode($entries);
        $finishReason = $this->extractFinishReason($entries);
        $errorMessage = $this->extractErrorMessage($entries);

        $hasResponse = $this->containsAny($entries, ['llm.response_body', 'llm.complete']);
        $hasError = $this->containsAny($entries, ['llm.error']);
        $hasNonOkStatus = $statusCode !== null && ($statusCode < 200 || $statusCode >= 300);

        $outcome = match (true) {
            $hasError || $hasNonOkStatus => 'failed',
            $hasResponse => 'succeeded',
            default => 'pending',
        };

        $replay = $this->buildReplay($requestEntry);

        return [
            'index' => $index,
            'is_implicit' => $requestEntry === null,
            'provider' => $this->extractProvider($requestEntry),
            'model' => $this->extractModel($requestEntry),
            'endpoint' => $this->extractEndpoint($requestEntry),
            'stream' => $this->extractStream($requestEntry),
            'status_code' => $statusCode,
            'finish_reason' => $finishReason,
            'finish_reason_severity' => $this->finishReasonSeverity($finishReason),
            'outcome' => $outcome,
            'outcome_severity' => match ($outcome) {
                'succeeded' => 'success',
                'failed' => 'danger',
                default => 'warning',
            },
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $this->diffMs($startedAt, $endedAt),
            'summary' => $this->buildAttemptSummary(
                $index,
                $requestEntry,
                $statusCode,
                $finishReason,
                $outcome,
                $this->diffMs($startedAt, $endedAt),
                $errorMessage,
            ),
            'error_message' => $errorMessage,
            'replay' => $replay,
            'sections' => $sections,
            'entry_count' => count($entries),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function groupEntries(array $entries): array
    {
        $sections = [];
        $streamBuffer = [];

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type === 'llm.stream_line') {
                $streamBuffer[] = $entry;

                continue;
            }

            if ($streamBuffer !== []) {
                $sections[] = $this->buildStreamBlock($streamBuffer);
                $streamBuffer = [];
            }

            $sections[] = $this->buildEvent($entry);
        }

        if ($streamBuffer !== []) {
            $sections[] = $this->buildStreamBlock($streamBuffer);
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function buildEvent(array $entry): array
    {
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : 'unknown';
        $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
        $previewStatus = is_string($entry['preview_status'] ?? null) ? $entry['preview_status'] : 'full';
        $details = $this->buildEventDetails($type, $decoded);

        return [
            'kind' => 'event',
            'entry_number' => (int) ($entry['entry_number'] ?? 0),
            'at' => is_string($entry['at'] ?? null) ? $entry['at'] : null,
            'type' => $type,
            'label' => $this->eventLabel($type),
            'severity' => $this->eventSeverity($type, $decoded, $previewStatus),
            'summary' => $this->eventSummary($type, $decoded, $entry),
            'details' => $details,
            'preview_status' => $previewStatus,
            'payload_pretty' => is_string($entry['payload_pretty'] ?? null) ? $entry['payload_pretty'] : '',
        ];
    }

    private function eventLabel(string $type): string
    {
        return match ($type) {
            'llm.request' => __('Request'),
            'llm.response_status' => __('Response Status'),
            'llm.response_body' => __('Response Body'),
            'llm.first_byte' => __('First Byte'),
            'llm.error' => __('Error'),
            'llm.complete' => __('Complete'),
            default => $type,
        };
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function eventSeverity(string $type, ?array $decoded, string $previewStatus): string
    {
        if ($previewStatus === 'decode_error' || $previewStatus === 'encode_error') {
            return 'danger';
        }

        if ($type === 'llm.error') {
            return 'danger';
        }

        if ($type === 'llm.response_status' && is_array($decoded)) {
            $status = (int) ($decoded['status_code'] ?? 0);

            if ($status > 0 && ($status < 200 || $status >= 300)) {
                return 'danger';
            }

            return 'success';
        }

        if ($type === 'llm.complete') {
            return 'success';
        }

        return 'default';
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     * @param  array<string, mixed>  $entry
     */
    private function eventSummary(string $type, ?array $decoded, array $entry): string
    {
        return match ($type) {
            'llm.request' => $this->requestSummary($decoded),
            'llm.response_status' => __(':status response received', [
                'status' => is_array($decoded) ? (string) ($decoded['status_code'] ?? '?') : '?',
            ]),
            'llm.response_body' => $this->responseBodySummary($decoded),
            'llm.first_byte' => __('First byte arrived'),
            'llm.error' => is_array($decoded) ? (string) ($decoded['message'] ?? __('Transport error')) : __('Transport error'),
            'llm.complete' => __('Transport completed'),
            default => is_string($entry['summary_preview'] ?? null) ? (string) $entry['summary_preview'] : '',
        };
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function requestSummary(?array $decoded): string
    {
        if ($decoded === null) {
            return __('Outbound request');
        }

        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
        $tools = is_array($request['tools'] ?? null) ? $request['tools'] : [];
        $model = is_string($request['model'] ?? null) ? $request['model'] : null;
        $model ??= is_string($decoded['model'] ?? null) ? $decoded['model'] : '?';
        $provider = is_string($request['provider_name'] ?? null) ? $request['provider_name'] : '?';

        return __(':provider / :model — :messages messages, :tools tools', [
            'provider' => $provider,
            'model' => $model,
            'messages' => count($messages),
            'tools' => count($tools),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function responseBodySummary(?array $decoded): string
    {
        if ($decoded === null) {
            return __('Response body');
        }

        $rawBody = is_string($decoded['raw_body'] ?? null) ? $decoded['raw_body'] : '';
        $bytes = strlen($rawBody);
        $statusCode = is_int($decoded['status_code'] ?? null) ? (int) $decoded['status_code'] : null;

        return __(':status, :bytes bytes', [
            'status' => $statusCode ?? '?',
            'bytes' => number_format($bytes),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     * @return array<string, mixed>
     */
    private function buildEventDetails(string $type, ?array $decoded): array
    {
        if ($decoded === null) {
            return [];
        }

        return match ($type) {
            'llm.request' => $this->requestDetails($decoded),
            'llm.response_status' => [
                'status_code' => (int) ($decoded['status_code'] ?? 0),
                'stream' => (bool) ($decoded['stream'] ?? false),
            ],
            'llm.response_body' => [
                'status_code' => (int) ($decoded['status_code'] ?? 0),
                'bytes' => is_string($decoded['raw_body'] ?? null) ? strlen($decoded['raw_body']) : 0,
                'has_decoded' => is_array($decoded['decoded_body'] ?? null),
            ],
            'llm.error' => [
                'stage' => is_string($decoded['stage'] ?? null) ? $decoded['stage'] : null,
                'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : null,
            ],
            'llm.complete' => [
                'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : null,
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function requestDetails(array $decoded): array
    {
        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
        $tools = is_array($request['tools'] ?? null) ? $request['tools'] : [];

        return [
            'endpoint' => is_string($decoded['endpoint'] ?? null) ? $decoded['endpoint'] : null,
            'stream' => (bool) ($decoded['stream'] ?? false),
            'provider' => is_string($request['provider_name'] ?? null) ? $request['provider_name'] : null,
            'model' => is_string($request['model'] ?? null) ? $request['model'] : null,
            'message_count' => count($messages),
            'tool_count' => count($tools),
            'timeout_seconds' => $request['timeout'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function buildStreamBlock(array $entries): array
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
            $fragment = $this->buildFragment($entry, $previousAt);
            $previousAt = $fragment['at'];

            if ($fragment['unknown_keys'] !== []) {
                foreach ($fragment['unknown_keys'] as $key) {
                    $unknownKeys[$key] = true;
                }

                $unknownKeyEntries[] = $fragment['entry_number'];
            }

            $rawFragments[] = $fragment;

            if ($fragment['kind'] === 'content') {
                $assembledContent .= $fragment['text'];
            } elseif ($fragment['kind'] === 'reasoning') {
                $assembledReasoning .= $fragment['text'];
            } elseif ($fragment['kind'] === 'tool_call' || $fragment['kind'] === 'tool_args') {
                $this->mergeToolCallFragment($toolCalls, $fragment);
            } elseif ($fragment['kind'] === 'finish_reason') {
                $finishReason = $fragment['text'];
                $finishReasonSeverity = $this->finishReasonSeverity($finishReason);
            }
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
            'duration_ms' => $this->diffMs($startedAt, $endedAt),
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
    private function buildFragment(array $entry, ?string $previousAt): array
    {
        $entryNumber = (int) ($entry['entry_number'] ?? 0);
        $at = is_string($entry['at'] ?? null) ? $entry['at'] : null;
        $previewStatus = is_string($entry['preview_status'] ?? null) ? $entry['preview_status'] : 'full';
        $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
        $rawLine = is_string($decoded['raw_line'] ?? null) ? $decoded['raw_line'] : '';

        $gapMs = $this->diffMs($previousAt, $at);

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

        if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            return array_merge($base, [
                'kind' => 'content',
                'text' => $delta['content'],
                'severity' => 'default',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        if (is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
            return array_merge($base, [
                'kind' => 'reasoning',
                'text' => $delta['reasoning_content'],
                'severity' => 'info',
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
        }

        $toolCalls = $delta['tool_calls'] ?? null;

        if (is_array($toolCalls) && isset($toolCalls[0]) && is_array($toolCalls[0])) {
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
        }

        if (is_string($finishReason) && $finishReason !== '') {
            return array_merge($base, [
                'kind' => 'finish_reason',
                'text' => $finishReason,
                'severity' => $this->finishReasonSeverity($finishReason),
                'tool_index' => null,
                'tool_call_id' => null,
            ]);
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

    private function finishReasonSeverity(?string $finishReason): string
    {
        return match ($finishReason) {
            null, '', 'stop' => 'default',
            'tool_calls' => 'info',
            'length' => 'warning',
            'content_filter', 'error' => 'danger',
            default => 'default',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    private function collectAnomalies(array $entries, array $attempts): array
    {
        $anomalies = [];

        $decodeErrors = [];
        $omittedEntries = [];
        $unknownKeys = [];
        $unknownKeyEntries = [];

        foreach ($entries as $entry) {
            $previewStatus = is_string($entry['preview_status'] ?? null) ? $entry['preview_status'] : 'full';
            $entryNumber = (int) ($entry['entry_number'] ?? 0);

            if ($previewStatus === 'decode_error' || $previewStatus === 'encode_error') {
                $decodeErrors[] = $entryNumber;
            }

            if ($previewStatus === 'line_omitted' || $previewStatus === 'payload_truncated') {
                $omittedEntries[] = $entryNumber;
            }
        }

        foreach ($attempts as $attempt) {
            foreach ($attempt['sections'] as $section) {
                if (($section['kind'] ?? null) !== 'stream_block') {
                    continue;
                }

                foreach ($section['unknown_keys'] as $key) {
                    $unknownKeys[$key] = true;
                }

                foreach ($section['unknown_key_entries'] ?? [] as $entryNumber) {
                    $unknownKeyEntries[] = (int) $entryNumber;
                }
            }

            $statusCode = $attempt['status_code'] ?? null;

            if (is_int($statusCode) && ($statusCode < 200 || $statusCode >= 300)) {
                $anomalies[] = [
                    'type' => 'http_error',
                    'severity' => 'danger',
                    'label' => __('HTTP :status', ['status' => $statusCode]),
                    'detail' => __('Attempt :index returned a non-2xx status.', ['index' => $attempt['index']]),
                    'entry_numbers' => [],
                ];
            }

            $finishReason = $attempt['finish_reason'] ?? null;

            if (is_string($finishReason) && ! in_array($finishReason, ['', 'stop'], true)) {
                $anomalies[] = [
                    'type' => 'finish_reason',
                    'severity' => $this->finishReasonSeverity($finishReason),
                    'label' => __('Finish reason: :reason', ['reason' => $finishReason]),
                    'detail' => __('Attempt :index ended with :reason rather than stop.', [
                        'index' => $attempt['index'],
                        'reason' => $finishReason,
                    ]),
                    'entry_numbers' => [],
                ];
            }

            if (is_string($attempt['error_message'] ?? null) && $attempt['error_message'] !== '') {
                $anomalies[] = [
                    'type' => 'transport_error',
                    'severity' => 'danger',
                    'label' => __('Transport error'),
                    'detail' => $attempt['error_message'],
                    'entry_numbers' => [],
                ];
            }
        }

        if ($decodeErrors !== []) {
            $anomalies[] = [
                'type' => 'decode_error',
                'severity' => 'warning',
                'label' => __(':count decode errors', ['count' => count($decodeErrors)]),
                'detail' => __('Some entries could not be decoded as JSON.'),
                'entry_numbers' => $decodeErrors,
            ];
        }

        if ($omittedEntries !== []) {
            $anomalies[] = [
                'type' => 'oversized',
                'severity' => 'warning',
                'label' => __(':count oversized entries', ['count' => count($omittedEntries)]),
                'detail' => __('Some entries exceeded preview limits and must be opened raw.'),
                'entry_numbers' => $omittedEntries,
            ];
        }

        if ($unknownKeys !== []) {
            $anomalies[] = [
                'type' => 'unknown_keys',
                'severity' => 'warning',
                'label' => __('Unknown delta keys'),
                'detail' => __('Provider delta contained keys the formatter does not recognize: :keys', [
                    'keys' => implode(', ', array_keys($unknownKeys)),
                ]),
                'entry_numbers' => array_values(array_unique($unknownKeyEntries)),
            ];
        }

        return $anomalies;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<array<string, mixed>>  $anomalies
     * @return array<string, mixed>
     */
    private function buildOverview(array $entries, array $attempts, array $anomalies): array
    {
        $streamChunks = 0;
        $errorCount = 0;
        $toolCallCount = 0;

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type === 'llm.stream_line') {
                $streamChunks++;
            }

            if ($type === 'llm.error') {
                $errorCount++;
            }
        }

        $finalAttempt = $attempts !== [] ? $attempts[count($attempts) - 1] : null;
        $finishReason = $finalAttempt['finish_reason'] ?? null;
        $finalOutcome = $finalAttempt['outcome'] ?? 'pending';

        foreach ($attempts as $attempt) {
            foreach ($attempt['sections'] as $section) {
                if (($section['kind'] ?? null) === 'stream_block') {
                    $toolCallCount += count($section['tool_calls']);
                }
            }
        }

        $firstAt = $this->firstAt($entries);
        $lastAt = $this->lastAt($entries);
        $totalDurationMs = $this->diffMs($firstAt, $lastAt);

        $timing = $this->buildTimingMarkers($entries, $firstAt);
        $contentLength = $this->totalContentLength($attempts);

        $chunksPerSecond = null;
        $tokensPerSecondApprox = null;

        if ($totalDurationMs !== null && $totalDurationMs > 0) {
            $chunksPerSecond = round($streamChunks * 1000 / $totalDurationMs, 2);
            $tokensPerSecondApprox = $contentLength > 0
                ? round(($contentLength / 4) * 1000 / $totalDurationMs, 2)
                : null;
        }

        return [
            'total_entries' => count($entries),
            'stream_chunks' => $streamChunks,
            'tool_calls' => $toolCallCount,
            'error_count' => $errorCount,
            'attempt_count' => count($attempts),
            'final_outcome' => $finalOutcome,
            'final_outcome_severity' => match ($finalOutcome) {
                'succeeded' => 'success',
                'failed' => 'danger',
                default => 'warning',
            },
            'finish_reason' => $finishReason,
            'finish_reason_severity' => $this->finishReasonSeverity($finishReason),
            'first_at' => $firstAt,
            'last_at' => $lastAt,
            'total_duration_ms' => $totalDurationMs,
            'time_to_first_byte_ms' => $timing['first_byte'],
            'time_to_first_content_ms' => $timing['first_content'],
            'time_to_first_reasoning_ms' => $timing['first_reasoning'],
            'time_to_first_tool_call_ms' => $timing['first_tool_call'],
            'chunks_per_second' => $chunksPerSecond,
            'tokens_per_second_approx' => $tokensPerSecondApprox,
            'anomaly_count' => count($anomalies),
            'reassembled_content_length' => $contentLength,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{first_byte: int|null, first_content: int|null, first_reasoning: int|null, first_tool_call: int|null}
     */
    private function buildTimingMarkers(array $entries, ?string $firstAt): array
    {
        $markers = [
            'first_byte' => null,
            'first_content' => null,
            'first_reasoning' => null,
            'first_tool_call' => null,
        ];

        if ($firstAt === null) {
            return $markers;
        }

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';
            $at = is_string($entry['at'] ?? null) ? $entry['at'] : null;

            if ($at === null) {
                continue;
            }

            if ($markers['first_byte'] === null && in_array($type, ['llm.first_byte', 'llm.response_body', 'llm.stream_line'], true)) {
                $markers['first_byte'] = $this->diffMs($firstAt, $at);
            }

            if ($type !== 'llm.stream_line') {
                continue;
            }

            $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
            $rawLine = is_string($decoded['raw_line'] ?? null) ? $decoded['raw_line'] : '';

            if (! str_starts_with($rawLine, self::SSE_DATA_PREFIX) || $rawLine === self::SSE_DONE_LINE) {
                continue;
            }

            $payload = BlbJson::decodeArray(substr($rawLine, strlen(self::SSE_DATA_PREFIX)));

            if (! is_array($payload)) {
                continue;
            }

            $delta = is_array($payload['choices'][0]['delta'] ?? null) ? $payload['choices'][0]['delta'] : [];

            if ($markers['first_content'] === null && is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                $markers['first_content'] = $this->diffMs($firstAt, $at);
            }

            if ($markers['first_reasoning'] === null && is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
                $markers['first_reasoning'] = $this->diffMs($firstAt, $at);
            }

            if ($markers['first_tool_call'] === null) {
                $toolCalls = $delta['tool_calls'] ?? null;

                if (is_array($toolCalls) && isset($toolCalls[0]) && is_array($toolCalls[0])) {
                    $name = $toolCalls[0]['function']['name'] ?? null;

                    if (is_string($name) && $name !== '') {
                        $markers['first_tool_call'] = $this->diffMs($firstAt, $at);
                    }
                }
            }
        }

        return $markers;
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     */
    private function totalContentLength(array $attempts): int
    {
        $total = 0;

        foreach ($attempts as $attempt) {
            foreach ($attempt['sections'] as $section) {
                if (($section['kind'] ?? null) === 'stream_block') {
                    $total += strlen($section['reassembled_content']);
                }
            }
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOverview(): array
    {
        return [
            'total_entries' => 0,
            'stream_chunks' => 0,
            'tool_calls' => 0,
            'error_count' => 0,
            'attempt_count' => 0,
            'final_outcome' => 'pending',
            'final_outcome_severity' => 'warning',
            'finish_reason' => null,
            'finish_reason_severity' => 'default',
            'first_at' => null,
            'last_at' => null,
            'total_duration_ms' => null,
            'time_to_first_byte_ms' => null,
            'time_to_first_content_ms' => null,
            'time_to_first_reasoning_ms' => null,
            'time_to_first_tool_call_ms' => null,
            'chunks_per_second' => null,
            'tokens_per_second_approx' => null,
            'anomaly_count' => 0,
            'reassembled_content_length' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     * @return array<string, mixed>|null
     */
    private function buildReplay(?array $requestEntry): ?array
    {
        if ($requestEntry === null) {
            return null;
        }

        $decoded = is_array($requestEntry['decoded_payload'] ?? null) ? $requestEntry['decoded_payload'] : null;

        if ($decoded === null) {
            return null;
        }

        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];
        $mapped = is_array($decoded['mapped'] ?? null) ? $decoded['mapped'] : [];
        $endpoint = is_string($decoded['endpoint'] ?? null) ? $decoded['endpoint'] : '';
        $baseUrl = is_string($request['base_url'] ?? null) ? rtrim($request['base_url'], '/') : '';
        $url = $endpoint !== '' ? $baseUrl.$endpoint : $baseUrl;

        $headers = is_array($mapped['headers'] ?? null) ? $mapped['headers'] : [];
        $payload = $mapped['payload'] ?? null;

        $body = is_array($payload) || is_object($payload)
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $curl = $this->buildCurl($url, $headers, $body);

        return [
            'method' => 'POST',
            'url' => $url,
            'headers' => $headers,
            'body' => is_string($body) ? $body : '',
            'body_byte_count' => is_string($body) ? strlen($body) : 0,
            'curl' => $curl,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function buildCurl(string $url, array $headers, ?string $body): string
    {
        $lines = ['curl -X POST '.$this->shellQuote($url).' \\'];
        $normalizedHeaders = array_change_key_case($headers);
        $hasContentType = array_key_exists('content-type', $normalizedHeaders);

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string) $name);
            $headerValue = $normalized === 'authorization'
                ? 'Bearer $API_KEY'
                : (string) $value;
            $lines[] = '  -H '.$this->shellQuote($name.': '.$headerValue).' \\';
        }

        if (! $hasContentType) {
            $lines[] = "  -H 'Content-Type: application/json' \\";
        }

        if (is_string($body) && $body !== '') {
            $lines[] = '  --data-raw '.$this->shellQuote($body);

            return implode("\n", $lines);
        }

        $last = array_pop($lines);
        $lines[] = rtrim((string) $last, ' \\');

        return implode("\n", $lines);
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     */
    private function extractProvider(?array $requestEntry): ?string
    {
        if ($requestEntry === null) {
            return null;
        }

        $decoded = is_array($requestEntry['decoded_payload'] ?? null) ? $requestEntry['decoded_payload'] : null;
        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];

        return is_string($request['provider_name'] ?? null) ? $request['provider_name'] : null;
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     */
    private function extractModel(?array $requestEntry): ?string
    {
        if ($requestEntry === null) {
            return null;
        }

        $decoded = is_array($requestEntry['decoded_payload'] ?? null) ? $requestEntry['decoded_payload'] : null;
        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];

        return is_string($request['model'] ?? null) ? $request['model'] : null;
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     */
    private function extractEndpoint(?array $requestEntry): ?string
    {
        if ($requestEntry === null) {
            return null;
        }

        $decoded = is_array($requestEntry['decoded_payload'] ?? null) ? $requestEntry['decoded_payload'] : null;

        return is_string($decoded['endpoint'] ?? null) ? $decoded['endpoint'] : null;
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     */
    private function extractStream(?array $requestEntry): bool
    {
        if ($requestEntry === null) {
            return false;
        }

        $decoded = is_array($requestEntry['decoded_payload'] ?? null) ? $requestEntry['decoded_payload'] : null;

        return (bool) ($decoded['stream'] ?? false);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function extractStatusCode(array $entries): ?int
    {
        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type !== 'llm.response_status' && $type !== 'llm.response_body') {
                continue;
            }

            $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;

            if (is_array($decoded) && isset($decoded['status_code']) && is_numeric($decoded['status_code'])) {
                return (int) $decoded['status_code'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function extractFinishReason(array $entries): ?string
    {
        $finishReason = null;

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type !== 'llm.stream_line') {
                continue;
            }

            $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
            $rawLine = is_string($decoded['raw_line'] ?? null) ? $decoded['raw_line'] : '';

            if (! str_starts_with($rawLine, self::SSE_DATA_PREFIX) || $rawLine === self::SSE_DONE_LINE) {
                continue;
            }

            $payload = BlbJson::decodeArray(substr($rawLine, strlen(self::SSE_DATA_PREFIX)));

            if (! is_array($payload)) {
                continue;
            }

            $candidate = $payload['choices'][0]['finish_reason'] ?? null;

            if (is_string($candidate) && $candidate !== '') {
                $finishReason = $candidate;
            }
        }

        return $finishReason;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function extractErrorMessage(array $entries): ?string
    {
        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type !== 'llm.error') {
                continue;
            }

            $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;

            if (is_array($decoded) && is_string($decoded['message'] ?? null) && $decoded['message'] !== '') {
                return $decoded['message'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $types
     */
    private function containsAny(array $entries, array $types): bool
    {
        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if (in_array($type, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function firstAt(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (is_string($entry['at'] ?? null)) {
                return $entry['at'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function lastAt(array $entries): ?string
    {
        $last = null;

        foreach ($entries as $entry) {
            if (is_string($entry['at'] ?? null)) {
                $last = $entry['at'];
            }
        }

        return $last;
    }

    private function diffMs(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);
        } catch (Throwable) {
            return null;
        }

        $diff = (int) round(($end->getTimestamp() - $start->getTimestamp()) * 1000);
        $diff += (int) round(((int) $end->format('u') - (int) $start->format('u')) / 1000);

        return $diff < 0 ? 0 : $diff;
    }

    /**
     * @param  array<string, mixed>|null  $requestEntry
     */
    private function buildAttemptSummary(
        int $index,
        ?array $requestEntry,
        ?int $statusCode,
        ?string $finishReason,
        string $outcome,
        ?int $durationMs,
        ?string $errorMessage,
    ): string {
        $provider = $this->extractProvider($requestEntry);
        $model = $this->extractModel($requestEntry);

        $head = trim(implode(' / ', array_filter([$provider, $model], fn (?string $v): bool => $v !== null && $v !== '')));

        if ($head === '') {
            $head = $requestEntry === null ? __('captured stream') : __('attempt');
        }

        $duration = $durationMs !== null ? number_format($durationMs / 1000, 1).'s' : '?';

        $tail = match ($outcome) {
            'failed' => $this->failedAttemptTail($errorMessage, $statusCode, $duration),
            'succeeded' => $finishReason !== null && $finishReason !== ''
                ? __('streamed :duration, finish=:reason', ['duration' => $duration, 'reason' => $finishReason])
                : __('completed in :duration', ['duration' => $duration]),
            default => __('pending after :duration', ['duration' => $duration]),
        };

        return __('Attempt :index — :head, :tail', [
            'index' => $index,
            'head' => $head,
            'tail' => $tail,
        ]);
    }

    private function failedAttemptTail(?string $errorMessage, ?int $statusCode, string $duration): string
    {
        if ($errorMessage !== null && $errorMessage !== '') {
            return __('failed: :error after :duration', [
                'error' => Str::limit($errorMessage, 80),
                'duration' => $duration,
            ]);
        }

        if ($statusCode !== null) {
            return __('HTTP :status after :duration', [
                'status' => $statusCode,
                'duration' => $duration,
            ]);
        }

        return __('failed after :duration', ['duration' => $duration]);
    }
}
