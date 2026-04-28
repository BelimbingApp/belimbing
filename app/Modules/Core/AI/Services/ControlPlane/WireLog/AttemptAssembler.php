<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

use App\Base\Support\Json as BlbJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * @internal
 *
 * Builds attempt buckets (segmented by llm.request) and derives per-attempt metadata.
 */
final class AttemptAssembler
{
    public function __construct(
        private readonly EntryGrouper $entryGrouper,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public function buildAttempts(array $entries): array
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
        $sections = $this->entryGrouper->groupEntries($entries);

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
            'finish_reason_severity' => StreamAssembler::finishReasonSeverity($finishReason),
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

            if (! str_starts_with($rawLine, StreamAssembler::SSE_DATA_PREFIX) || $rawLine === StreamAssembler::SSE_DONE_LINE) {
                continue;
            }

            $payload = BlbJson::decodeArray(substr($rawLine, strlen(StreamAssembler::SSE_DATA_PREFIX)));

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
