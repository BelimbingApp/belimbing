<?php

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\RunEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mark orphaned AI runs as failed.
 *
 * Finds runs stuck in Running status past a configurable threshold
 * (default: 2× the RunAgentTaskJob timeout of 600s = 1200s) and
 * marks them as Failed with error_type 'orphaned'. Intended to be
 * run every 5 minutes via the scheduler.
 *
 * Safe for concurrent execution — only transitions rows still in
 * Running status.
 */
#[AsCommand(name: 'blb:ai:runs:reap-orphans')]
class ReapOrphanRunsCommand extends Command
{
    protected $description = 'Mark orphaned AI runs (stuck in running) as failed';

    protected $signature = 'blb:ai:runs:reap-orphans
        {--threshold-seconds=1200 : Seconds before a running run is considered orphaned}';

    public function handle(RunEventPublisher $publisher): int
    {
        $thresholdSeconds = (int) $this->option('threshold-seconds');
        $cutoff = Carbon::now()->subSeconds($thresholdSeconds);

        $this->components->info("Reaping runs with no progress since before {$cutoff->toIso8601String()}...");

        $staleRuns = AiRun::query()
            ->where('status', AiRunStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->whereDoesntHave('events', fn ($query) => $query->where('created_at', '>=', $cutoff))
            ->get();

        $count = 0;

        foreach ($staleRuns as $turn) {
            $turn->refresh();

            if (
                $turn->status !== AiRunStatus::Running
                || $turn->events()->where('created_at', '>=', $cutoff)->exists()
            ) {
                continue;
            }

            $publisher->turnFailed(
                $turn,
                'orphaned',
                'Run did not complete — process may have crashed',
            );

            $count++;
        }

        if ($count === 0) {
            $this->components->info('No orphaned runs found.');
        } else {
            $this->components->warn("Reaped {$count} orphaned run(s).");
        }

        return self::SUCCESS;
    }
}
