<?php

namespace App\Base\Perf\Console;

use App\Base\Perf\Services\PerfLog;
use Illuminate\Console\Command;

final class PerfPruneCommand extends Command
{
    protected $signature = 'perf:prune {--days= : Delete daily perf files older than this (default: perf.retention_days)}';

    protected $description = 'Delete performance log files past the retention window';

    public function handle(PerfLog $log): int
    {
        $days = (int) ($this->option('days') ?? config('perf.retention_days'));
        $cutoffDay = now()->subDays($days)->format('Y-m-d');
        $deleted = 0;

        foreach ($log->files() as $file) {
            if (preg_match('/perf-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $matches) === 1
                && $matches[1] < $cutoffDay) {
                unlink($file);
                $deleted++;
            }
        }

        $this->info("Deleted $deleted perf file(s) older than $days day(s).");

        return self::SUCCESS;
    }
}
