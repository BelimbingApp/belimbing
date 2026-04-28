<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\Services\ControlPlane\WireLog\AnomalyCollector;
use App\Modules\Core\AI\Services\ControlPlane\WireLog\AttemptAssembler;
use App\Modules\Core\AI\Services\ControlPlane\WireLog\EntryGrouper;
use App\Modules\Core\AI\Services\ControlPlane\WireLog\EntryPresenter;
use App\Modules\Core\AI\Services\ControlPlane\WireLog\OverviewBuilder;
use App\Modules\Core\AI\Services\ControlPlane\WireLog\StreamAssembler;
use Carbon\CarbonImmutable;
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
    private readonly AttemptAssembler $attemptAssembler;

    private readonly AnomalyCollector $anomalyCollector;

    private readonly OverviewBuilder $overviewBuilder;

    public function __construct()
    {
        $diffMs = \Closure::fromCallable([$this, 'diffMs']);
        $entryGrouper = new EntryGrouper(new EntryPresenter, new StreamAssembler, $diffMs);

        $this->attemptAssembler = new AttemptAssembler($entryGrouper);
        $this->anomalyCollector = new AnomalyCollector;
        $this->overviewBuilder = new OverviewBuilder;
    }

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
                'overview' => OverviewBuilder::emptyOverview(),
                'anomalies' => [],
                'attempts' => [],
                'has_entries' => false,
            ];
        }

        $attempts = $this->attemptAssembler->buildAttempts($entries);
        $anomalies = $this->anomalyCollector->collectAnomalies($entries, $attempts);
        $overview = $this->overviewBuilder->buildOverview($entries, $attempts, $anomalies, \Closure::fromCallable([$this, 'diffMs']));

        return [
            'overview' => $overview,
            'anomalies' => $anomalies,
            'attempts' => $attempts,
            'has_entries' => true,
        ];
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
}
