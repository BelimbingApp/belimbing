<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * Assembles prompt timeline rows (meta + wire) for control-plane diagnostics.
 */
final class RunPromptTimelineBuilder
{
    public function __construct(
        private readonly WireLogger $wireLogger,
    ) {}

    /**
     * @return array{
     *     timeline: list<array<string, mixed>>,
     *     wire_count: int,
     *     meta_count: int,
     *     delta_collapsed: bool,
     *     has_wire_log: bool,
     * }
     */
    public function compose(AiRun $run, bool $collapseDelta): array
    {
        [$metaEntries, $totalMetaEntries] = $this->buildPromptTimelineMetaEntries($run, $collapseDelta);
        [$wireEntries, $totalWireEntries] = $this->buildPromptTimelineWireEntries($run->id, $collapseDelta);

        $all = array_merge($metaEntries, $wireEntries);

        usort($all, function (array $a, array $b): int {
            $cmp = $this->timelineTimestampOrder((string) $a['timestamp'])
                <=> $this->timelineTimestampOrder((string) $b['timestamp']);

            if ($cmp !== 0) {
                return $cmp;
            }

            if ($a['source'] !== $b['source']) {
                return $a['source'] === 'meta' ? -1 : 1;
            }

            $aOrder = $a['seq'] ?? $a['entry_number'] ?? 0;
            $bOrder = $b['seq'] ?? $b['entry_number'] ?? 0;

            return $aOrder <=> $bOrder;
        });

        return [
            'timeline' => array_values($all),
            'wire_count' => $totalWireEntries,
            'meta_count' => $totalMetaEntries,
            'delta_collapsed' => $collapseDelta,
            'has_wire_log' => $totalWireEntries > 0,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildPromptTimelineMetaEntries(AiRun $run, bool $collapseDelta): array
    {
        $metaEntries = [];
        $totalMetaEntries = 0;
        $previousAt = null;

        foreach ($run->events as $event) {
            $totalMetaEntries++;
            $isDelta = $event->event_type->isDelta();

            if ($collapseDelta && $isDelta) {
                continue;
            }

            $gapMs = $previousAt?->diffInMilliseconds($event->created_at) ?? null;

            $metaEntries[] = [
                'timestamp' => $event->created_at?->toIso8601String() ?? '',
                'source' => 'meta',
                'type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'summary' => $this->eventSummary($event),
                'severity' => $event->event_type->severity(),
                'is_delta' => $isDelta,
                'gap_ms' => $gapMs,
                'has_gap_warning' => $gapMs !== null && $gapMs > 30_000,
                'is_stuck' => ! $run->isTerminal()
                    && $event->seq === $run->last_event_seq
                    && $event->created_at?->lt(now()->subSeconds(30)),
                'payload' => $event->payload,
                'seq' => $event->seq,
                'entry_number' => null,
            ];

            $previousAt = $event->created_at;
        }

        return [$metaEntries, $totalMetaEntries];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildPromptTimelineWireEntries(string $runId, bool $collapseDelta): array
    {
        $wireEntries = [];
        $totalWireEntries = 0;
        $collapsedCount = 0;
        $collapsedFrom = 0;
        $collapsedLastAt = '';

        foreach ($this->wireLogger->read($runId) as $entry) {
            $totalWireEntries++;
            $type = (string) ($entry['type'] ?? 'unknown');
            $isDelta = $type === 'llm.stream_line';

            if ($collapseDelta && $isDelta) {
                if ($collapsedCount === 0) {
                    $collapsedFrom = $totalWireEntries;
                }
                $collapsedCount++;
                $collapsedLastAt = is_string($entry['at'] ?? null) ? (string) $entry['at'] : $collapsedLastAt;

                continue;
            }

            if ($collapsedCount > 0) {
                $wireEntries[] = $this->collapsedDeltaEntry($collapsedFrom, $totalWireEntries - 1, $collapsedCount, $collapsedLastAt);
                $collapsedCount = 0;
                $collapsedFrom = 0;
                $collapsedLastAt = '';
            }

            $wireEntries[] = [
                'timestamp' => is_string($entry['at'] ?? null) ? (string) $entry['at'] : '',
                'source' => 'wire',
                'type' => $type,
                'label' => $this->wireEntryLabel($type),
                'summary' => $this->wireEntrySummary($type, $entry),
                'severity' => $type === 'llm.error' ? 'error' : 'info',
                'is_delta' => $isDelta,
                'gap_ms' => null,
                'has_gap_warning' => false,
                'is_stuck' => false,
                'payload' => $entry,
                'seq' => null,
                'entry_number' => $totalWireEntries,
            ];
        }

        if ($collapsedCount > 0) {
            $wireEntries[] = $this->collapsedDeltaEntry($collapsedFrom, $collapsedFrom + $collapsedCount - 1, $collapsedCount, $collapsedLastAt);
        }

        return [$wireEntries, $totalWireEntries];
    }

    private function timelineTimestampOrder(string $timestamp): int
    {
        if ($timestamp === '') {
            return PHP_INT_MAX;
        }

        try {
            $instant = new DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }

        return ((int) $instant->format('U')) * 1000 + ((int) $instant->format('v'));
    }

    /**
     * @return array<string, mixed>
     */
    private function collapsedDeltaEntry(int $from, int $to, int $count, string $lastAt): array
    {
        return [
            'timestamp' => $lastAt,
            'source' => 'wire',
            'type' => 'stream_lines_collapsed',
            'label' => __('Stream Deltas'),
            'summary' => __('#:from – #:to (:count collapsed)', ['from' => $from, 'to' => $to, 'count' => $count]),
            'severity' => 'default',
            'is_delta' => true,
            'gap_ms' => null,
            'has_gap_warning' => false,
            'is_stuck' => false,
            'payload' => null,
            'seq' => null,
            'entry_number' => null,
        ];
    }

    private function wireEntryLabel(string $type): string
    {
        return match ($type) {
            'llm.request' => __('LLM Request'),
            'llm.first_byte' => __('First Byte'),
            'llm.response_status' => __('Response Status'),
            'llm.response_body' => __('Response Body'),
            'llm.stream_line' => __('Stream Delta'),
            'llm.complete' => __('LLM Complete'),
            'llm.error' => __('LLM Error'),
            default => __(ucwords(str_replace(['.', '_'], ' ', $type))),
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function wireEntrySummary(string $type, array $entry): string
    {
        return match ($type) {
            'llm.request' => trim(implode(' / ', array_filter([
                is_array($entry['request'] ?? null) ? ($entry['request']['model'] ?? null) : null,
                is_array($entry['request']['messages'] ?? null)
                    ? __(':n messages', ['n' => count((array) $entry['request']['messages'])])
                    : null,
            ], fn (mixed $v): bool => is_string($v) && $v !== ''))),
            'llm.response_status' => isset($entry['status_code']) ? (string) $entry['status_code'] : '',
            'llm.response_body' => isset($entry['status_code']) ? (string) $entry['status_code'] : '',
            'llm.stream_line' => Str::limit((string) ($entry['raw_line'] ?? ''), 120),
            'llm.error' => (string) ($entry['message'] ?? __('LLM error')),
            'llm.complete' => $this->summarizeLlmCompleteWireEntry($entry),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function summarizeLlmCompleteWireEntry(array $entry): string
    {
        if (! is_array($entry['context'] ?? null) || $entry['context'] === []) {
            return '';
        }

        return Str::limit(json_encode($entry['context'], JSON_UNESCAPED_SLASHES) ?: '', 120);
    }

    private function eventSummary(AiRunEvent $event): string
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return match ($event->event_type->value) {
            'run.phase_changed' => (string) ($payload['label'] ?? $payload['phase'] ?? __('Phase updated')),
            'run.started' => trim(implode(' / ', array_filter([
                $payload['run_id'] ?? null,
                $payload['provider'] ?? null,
                $payload['model'] ?? null,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'run.failed' => (string) ($payload['message'] ?? __('Run failed')),
            'tool.started' => trim(implode(' - ', array_filter([
                $payload['tool'] ?? null,
                $payload['args_summary'] ?? null,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'tool.finished' => trim(implode(' - ', array_filter([
                $payload['tool'] ?? null,
                $payload['status'] ?? null,
                isset($payload['result_preview']) ? Str::limit((string) $payload['result_preview'], 120) : null,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'tool.denied' => trim(implode(' - ', array_filter([
                $payload['tool'] ?? null,
                $payload['reason'] ?? null,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'assistant.thinking_started' => (string) ($payload['description'] ?? ''),
            'assistant.output_delta', 'assistant.thinking_delta', 'tool.stdout_delta' => Str::limit((string) ($payload['delta'] ?? ''), 120),
            'run.cancelled' => (string) ($payload['reason'] ?? ''),
            'usage.updated' => __('Prompt: :prompt, Completion: :completion', [
                'prompt' => (string) ($payload['prompt_tokens'] ?? 'n/a'),
                'completion' => (string) ($payload['completion_tokens'] ?? 'n/a'),
            ]),
            'heartbeat' => '',
            default => '',
        };
    }
}
