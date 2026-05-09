<?php
namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use Illuminate\Support\Str;

/**
 * Loads ordered AiRunEvent rows for a run and reduces them to the bounded
 * set of structural milestones the Wire Log card surfaces.
 *
 * The DB event stream is bounded (lifecycle facts only — no transport
 * deltas) so this loader can safely walk the full set without paging.
 */
class MetaMilestoneAnnotator
{
    /** Heartbeats below this gap (ms) are not surfaced as milestones. */
    public const HEARTBEAT_GAP_MS_THRESHOLD = 30_000;

    /**
     * Event types that always surface as milestones.
     *
     * @var list<string>
     */
    private const STRUCTURAL_TYPES = [
        'run.started',
        'run.phase_changed',
        'run.completed',
        'run.failed',
        'run.cancelled',
        'run.ready_for_input',
        'tool.denied',
    ];

    /**
     * @return list<array{
     *     seq: int,
     *     type: string,
     *     label: string,
     *     severity: string,
     *     at: string|null,
     *     summary: string,
     *     gap_ms: float|int|null,
     *     has_gap_warning: bool,
     *     payload: array<string, mixed>|null,
     * }>
     */
    public function annotate(AiRun $run): array
    {
        $events = AiRunEvent::query()
            ->where('run_id', $run->id)
            ->orderBy('seq')
            ->get();

        $milestones = [];
        $previousAt = null;

        foreach ($events as $event) {
            if ($event->event_type->isDelta()) {
                continue;
            }

            $gapMs = $previousAt?->diffInMilliseconds($event->created_at);

            if ($event->event_type === RunEventType::Heartbeat) {
                if ($gapMs === null || $gapMs <= self::HEARTBEAT_GAP_MS_THRESHOLD) {
                    continue;
                }
            } elseif (! in_array($event->event_type->value, self::STRUCTURAL_TYPES, true)) {
                continue;
            }

            $milestones[] = [
                'seq' => $event->seq,
                'type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'severity' => $event->event_type->severity(),
                'at' => $event->created_at?->toIso8601String(),
                'summary' => $this->summary($event),
                'gap_ms' => $gapMs,
                'has_gap_warning' => $gapMs !== null && $gapMs > self::HEARTBEAT_GAP_MS_THRESHOLD,
                'payload' => is_array($event->payload) ? $event->payload : null,
            ];

            $previousAt = $event->created_at;
        }

        return $milestones;
    }

    /**
     * Build a compact lifecycle summary for the raw-mode meta rail.
     *
     * @param  list<array<string, mixed>>  $milestones
     * @return array{
     *     counts_by_type: array<string, int>,
     *     current_status: string|null,
     *     current_status_label: string|null,
     *     current_status_color: string|null,
     *     phase_progression: list<string>,
     *     total: int,
     * }
     */
    public function buildRail(AiRun $run, array $milestones): array
    {
        $counts = [];
        $phaseProgression = [];

        foreach ($milestones as $milestone) {
            $type = (string) ($milestone['type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;

            if ($type === 'run.phase_changed') {
                $phase = is_array($milestone['payload'] ?? null)
                    ? (string) ($milestone['payload']['phase'] ?? $milestone['payload']['label'] ?? '')
                    : '';

                if ($phase !== '' && (! $phaseProgression || end($phaseProgression) !== $phase)) {
                    $phaseProgression[] = $phase;
                }
            }
        }

        return [
            'counts_by_type' => $counts,
            'current_status' => $run->status?->value,
            'current_status_label' => $run->status?->label(),
            'current_status_color' => $run->status?->color(),
            'phase_progression' => array_values($phaseProgression),
            'total' => count($milestones),
        ];
    }

    /**
     * Mark wire entries in a paginated preview window whose timestamp falls
     * inside one or more meta-event time slices.
     *
     * Wire entries are matched against the milestone *immediately preceding*
     * them (i.e. milestone.at <= entry.at) so each entry is associated with
     * at most the latest applicable milestone. We do not re-scan the JSONL —
     * the caller passes the already-loaded preview entries.
     *
     * @param  list<array<string, mixed>>  $wireEntries
     * @param  list<array<string, mixed>>  $milestones
     * @return list<array<string, mixed>>
     */
    public function markEntriesWithMilestones(array $wireEntries, array $milestones): array
    {
        if ($wireEntries === [] || $milestones === []) {
            return $wireEntries;
        }

        $milestoneInstants = [];

        foreach ($milestones as $milestone) {
            $at = $milestone['at'] ?? null;

            if (! is_string($at) || $at === '') {
                continue;
            }

            $milestoneInstants[] = [
                'unix_ms' => $this->parseUnixMs($at),
                'type' => (string) ($milestone['type'] ?? ''),
                'label' => (string) ($milestone['label'] ?? ''),
                'severity' => (string) ($milestone['severity'] ?? 'info'),
            ];
        }

        if ($milestoneInstants === []) {
            return $wireEntries;
        }

        $entryInstants = array_map(function (array $entry): ?int {
            $at = $entry['at'] ?? null;

            return is_string($at) && $at !== '' ? $this->parseUnixMs($at) : null;
        }, $wireEntries);

        foreach ($wireEntries as $i => $entry) {
            $entryAt = $entryInstants[$i] ?? null;

            if ($entryAt === null) {
                continue;
            }

            $nextEntryAt = null;
            for ($j = $i + 1; $j < count($entryInstants); $j++) {
                if ($entryInstants[$j] !== null) {
                    $nextEntryAt = $entryInstants[$j];
                    break;
                }
            }

            $matches = [];

            foreach ($milestoneInstants as $milestone) {
                $instant = $milestone['unix_ms'];

                if ($instant === null || $instant < $entryAt) {
                    continue;
                }

                if ($nextEntryAt !== null && $instant >= $nextEntryAt) {
                    continue;
                }

                $matches[] = [
                    'type' => $milestone['type'],
                    'label' => $milestone['label'],
                    'severity' => $milestone['severity'],
                ];
            }

            if ($matches !== []) {
                $wireEntries[$i]['meta_milestones'] = $matches;
            }
        }

        return $wireEntries;
    }

    private function parseUnixMs(string $iso): ?int
    {
        try {
            $instant = new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return null;
        }

        return ((int) $instant->format('U')) * 1000 + ((int) $instant->format('v'));
    }

    private function summary(AiRunEvent $event): string
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return match ($event->event_type->value) {
            'run.phase_changed' => (string) ($payload['label'] ?? $payload['phase'] ?? __('Phase updated')),
            'run.started' => trim(implode(' / ', array_filter([
                $payload['provider'] ?? null,
                $payload['model'] ?? null,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'run.failed' => (string) ($payload['message'] ?? __('Run failed')),
            'run.cancelled' => (string) ($payload['reason'] ?? ''),
            'tool.denied' => trim(implode(' - ', array_filter([
                $payload['tool'] ?? null,
                $payload['reason'] ?? null,
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
            'heartbeat' => '',
            default => Str::limit((string) ($payload['message'] ?? ''), 120),
        };
    }
}
