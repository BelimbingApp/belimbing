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
        {--limit=20 : Number of requests to show}
        {--type= : Only http, job, or command entries}
        {--sql : Show the slowest captured SQL under each entry}';

    protected $description = 'Show individual requests, jobs, and commands from the performance log, newest first';

    public function handle(PerfLog $log): int
    {
        $cutoff = PerfLog::parseSince((string) $this->option('since'));
        $matches = [];

        foreach ($log->entriesSince($cutoff) as $entry) {
            if (! $this->matchesFilters($entry)) {
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
            array_map($this->tableRow(...), $matches),
        );

        if ($this->option('sql')) {
            $this->showSqlStatements($matches);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function matchesFilters(array $entry): bool
    {
        $routeFilter = (string) $this->option('route');
        $type = (string) $this->option('type');

        if (($entry['ms'] ?? 0) < (float) $this->option('min-ms')) {
            return false;
        }

        if ($type !== '' && ($entry['type'] ?? 'http') !== $type) {
            return false;
        }

        return $routeFilter === ''
            || str_contains((string) ($entry['route'] ?? ''), $routeFilter)
            || str_contains((string) ($entry['path'] ?? ''), $routeFilter);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<mixed>
     */
    private function tableRow(array $entry): array
    {
        return [
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
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function showSqlStatements(array $matches): void
    {
        foreach ($matches as $entry) {
            foreach ($entry['top_sql'] ?? [] as $statement) {
                $this->line(sprintf(
                    '  %s %s %6.1f ms  %s',
                    substr((string) ($entry['ts'] ?? ''), 11, 8),
                    str_pad((string) ($entry['path'] ?? ''), 30),
                    $statement['ms'] ?? 0,
                    $statement['sql'] ?? '',
                ));
            }
        }
    }
}
