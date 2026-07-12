<?php

namespace App\Base\Perf\Console;

use App\Base\Perf\Services\PerfLog;
use Illuminate\Console\Command;

final class PerfSlowestCommand extends Command
{
    protected $signature = 'perf:slowest
        {--since=24h : Window to analyze, e.g. 30m, 2h, 7d}
        {--limit=15 : Number of routes to show}
        {--min-ms=0 : Ignore requests faster than this}';

    protected $description = 'Aggregate the request performance log by route, slowest first';

    public function handle(PerfLog $log): int
    {
        $cutoff = PerfLog::parseSince((string) $this->option('since'));
        $minMs = (float) $this->option('min-ms');

        $byRoute = [];

        foreach ($log->entriesSince($cutoff) as $entry) {
            if (($entry['ms'] ?? 0) < $minMs) {
                continue;
            }

            $key = $entry['route'] ?? $entry['path'] ?? '(unknown)';
            $byRoute[$key][] = $entry;
        }

        if ($byRoute === []) {
            $this->info("No perf entries since {$cutoff->toDateTimeString()} in ".$log->directory());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($byRoute as $route => $entries) {
            $times = array_column($entries, 'ms');
            sort($times);

            $rows[] = [
                'route' => $route,
                'hits' => count($entries),
                'p50' => self::percentile($times, 50),
                'p95' => self::percentile($times, 95),
                'max' => end($times),
                'avg_db_ms' => round(array_sum(array_column($entries, 'db_ms')) / count($entries), 1),
                'avg_queries' => round(array_sum(array_column($entries, 'queries')) / count($entries), 1),
                'avg_procs' => round(array_sum(array_column($entries, 'procs')) / count($entries), 1),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['p95'] <=> $a['p95']);

        $this->table(
            ['Route', 'Hits', 'p50 ms', 'p95 ms', 'Max ms', 'Avg DB ms', 'Avg queries', 'Avg procs'],
            array_map(array_values(...), array_slice($rows, 0, (int) $this->option('limit'))),
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<float|int>  $sorted  Values sorted ascending
     */
    private static function percentile(array $sorted, int $percentile): float
    {
        $index = (int) ceil($percentile / 100 * count($sorted)) - 1;

        return (float) $sorted[max(0, $index)];
    }
}
