<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

use App\Base\Support\Json as BlbJson;

/**
 * @internal
 *
 * Builds the top-level overview metrics for the loaded window.
 */
final class OverviewBuilder
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<array<string, mixed>>  $anomalies
     * @return array<string, mixed>
     */
    public function buildOverview(array $entries, array $attempts, array $anomalies, callable $diffMs): array
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

        $firstAt = EntryAtBounds::firstAt($entries);
        $lastAt = EntryAtBounds::lastAt($entries);
        $totalDurationMs = $diffMs($firstAt, $lastAt);

        $timing = $this->buildTimingMarkers($entries, $firstAt, $diffMs);
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
            'finish_reason_severity' => StreamAssembler::finishReasonSeverity($finishReason),
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
    private function buildTimingMarkers(array $entries, ?string $firstAt, callable $diffMs): array
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
                $markers['first_byte'] = $diffMs($firstAt, $at);
            }

            if ($type !== 'llm.stream_line') {
                continue;
            }

            $delta = $this->streamDelta($entry);
            if ($delta === null) {
                continue;
            }

            if ($markers['first_content'] === null && is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                $markers['first_content'] = $diffMs($firstAt, $at);
            }

            if ($markers['first_reasoning'] === null && is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
                $markers['first_reasoning'] = $diffMs($firstAt, $at);
            }

            if ($markers['first_tool_call'] !== null) {
                continue;
            }

            $toolCalls = $delta['tool_calls'] ?? null;

            if (is_array($toolCalls) && isset($toolCalls[0]) && is_array($toolCalls[0])) {
                $name = $toolCalls[0]['function']['name'] ?? null;

                if (is_string($name) && $name !== '') {
                    $markers['first_tool_call'] = $diffMs($firstAt, $at);
                }
            }
        }

        return $markers;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function streamDelta(array $entry): ?array
    {
        $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
        $rawLine = is_string($decoded['raw_line'] ?? null) ? $decoded['raw_line'] : '';

        if (! str_starts_with($rawLine, StreamAssembler::SSE_DATA_PREFIX) || $rawLine === StreamAssembler::SSE_DONE_LINE) {
            return null;
        }

        $payload = BlbJson::decodeArray(substr($rawLine, strlen(StreamAssembler::SSE_DATA_PREFIX)));
        if (! is_array($payload)) {
            return null;
        }

        $choice = $payload['choices'][0] ?? null;
        if (! is_array($choice)) {
            return null;
        }

        return is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
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
    public static function emptyOverview(): array
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
}
