<?php

namespace App\Base\Perf\Console;

use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerfRuntimeSettings;
use Illuminate\Console\Command;

final class PerfPruneCommand extends Command
{
    protected $signature = 'perf:prune {--days= : Delete daily perf files older than the configured retention period}';

    protected $description = 'Delete performance log files past the retention window';

    public function handle(PerfLog $log, PerfRuntimeSettings $runtimeSettings): int
    {
        $daysOption = $this->option('days');
        if ($daysOption !== null && (filter_var($daysOption, FILTER_VALIDATE_INT) === false || (int) $daysOption < 1)) {
            $this->components->error('The --days value must be a positive integer.');

            return self::INVALID;
        }

        $days = (int) ($daysOption ?? $runtimeSettings->retentionDays());
        $cutoffDay = now()->subDays($days)->format('Y-m-d');
        $deleted = 0;

        foreach ($log->files() as $file) {
            if (preg_match('/perf-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $matches) === 1
                && $matches[1] < $cutoffDay) {
                if (! @unlink($file)) {
                    $this->components->error("Could not delete performance log file: {$file}");

                    return self::FAILURE;
                }
                $deleted++;
            }
        }

        $this->info("Deleted $deleted perf file(s) older than $days day(s).");

        return self::SUCCESS;
    }
}
