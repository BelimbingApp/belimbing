<?php

namespace App\Base\Perf\Console;

use App\Base\Perf\Services\PerfLog;
use Illuminate\Console\Command;

final class PerfRequestsCommand extends Command
{
    protected $signature = 'perf:requests
        {--since=1h : Window to search, e.g. 30m, 2h, 7d}
        {--route= : Substring filter on route name or path}
        {--min-ms=0 : Only show requests at least this slow}
        {--limit=20 : Number of requests to show}';

    protected $description = 'Show individual requests from the performance log, newest first';

    public function handle(PerfLog $log): int
    {
        $cutoff = PerfLog::parseSince((string) $this->option('since'));
        $routeFilter = (string) $this->option('route');
        $minMs = (float) $this->option('min-ms');

        $matches = [];

        foreach ($log->entriesSince($cutoff) as $entry) {
            if (($entry['ms'] ?? 0) < $minMs) {
                continue;
            }

            if ($routeFilter !== ''
                && ! str_contains((string) ($entry['route'] ?? ''), $routeFilter)
                && ! str_contains((string) ($entry['path'] ?? ''), $routeFilter)) {
                continue;
            }

            $matches[] = $entry;
        }

        if ($matches === []) {
            $this->info("No matching perf entries since {$cutoff->toDateTimeString()} in ".$log->directory());

            return self::SUCCESS;
        }

        $matches = array_reverse(array_slice($matches, -(int) $this->option('limit')));

        $this->table(
            ['Time', 'Method', 'Path', 'Status', 'ms', 'DB ms', 'Queries', 'Cache h/m', 'Procs', 'Proc ms', 'Resp KB', 'Nav'],
            array_map(static fn (array $entry): array => [
                substr((string) ($entry['ts'] ?? ''), 11, 8),
                $entry['method'] ?? '',
                $entry['path'] ?? '',
                $entry['status'] ?? '',
                $entry['ms'] ?? '',
                $entry['db_ms'] ?? '',
                $entry['queries'] ?? '',
                ($entry['cache_hits'] ?? 0).'/'.($entry['cache_misses'] ?? 0),
                $entry['procs'] ?? '',
                $entry['proc_ms'] ?? '',
                isset($entry['resp_bytes']) ? round($entry['resp_bytes'] / 1024) : '',
                ($entry['navigate'] ?? false) ? 'y' : '',
            ], $matches),
        );

        return self::SUCCESS;
    }
}
