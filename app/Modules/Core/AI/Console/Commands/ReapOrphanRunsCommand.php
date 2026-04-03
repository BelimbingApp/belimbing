<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
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

    public function handle(): int
    {
        $thresholdSeconds = (int) $this->option('threshold-seconds');
        $cutoff = Carbon::now()->subSeconds($thresholdSeconds);

        $this->components->info("Reaping runs stuck in 'running' since before {$cutoff->toIso8601String()}...");

        $count = AiRun::query()
            ->where('status', AiRunStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->update([
                'status' => AiRunStatus::Failed,
                'error_type' => 'orphaned',
                'error_message' => 'Run did not complete — process may have crashed',
                'finished_at' => Carbon::now(),
            ]);

        if ($count === 0) {
            $this->components->info('No orphaned runs found.');
        } else {
            $this->components->warn("Reaped {$count} orphaned run(s).");
        }

        return self::SUCCESS;
    }
}
