<?php

namespace App\Base\Software\Console\Commands;

use App\Base\Software\Services\DeploymentMaintenanceGuard;
use App\Base\Software\Services\DeploymentRunHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Clears update-owned maintenance mode that no live update still owns.
 *
 * The updater holds the site down to close one specific window: workers that
 * already have the old classes in memory serving requests against freshly pulled
 * files. When a run cannot confirm it reloaded the pool it deliberately stays in
 * maintenance (see SoftwareUpdateCommand::finishMaintenance) — correct while
 * those old workers are still live, but it leaves the whole site on a 503 that
 * only a human can clear, including after a hard kill, an OOM, or a power cut.
 *
 * Starting the app resolves that window by construction: a fresh boot has no old
 * workers, and every worker loads the pulled code. So at start-up the hold has
 * nothing left to protect, and holding it is pure downtime. This command is the
 * start scripts' chance to say so.
 *
 * The one case it must not touch is a run that is genuinely still in progress:
 * the updater restarts the worker pool mid-run, so healing then would reopen the
 * site against half-updated code. A live (unexpired) lease means exactly that, and
 * it is left alone for the run — or its watchdog — to finish.
 */
final class SoftwareMaintenanceHealCommand extends Command
{
    protected $signature = 'blb:software:maintenance-heal';

    protected $description = 'Bring the site back online when maintenance mode was left behind by an update that never lifted it.';

    public function handle(
        DeploymentMaintenanceGuard $maintenance,
        DeploymentRunHistory $history,
    ): int {
        $runId = $maintenance->activeRunId();

        if ($runId === null) {
            // Either the site is live, or it is down for a reason we did not put
            // there (a manual `artisan down`), which is not ours to undo.
            return self::SUCCESS;
        }

        if ($maintenance->leaseExists($runId) && ! $maintenance->leaseExpired($runId)) {
            $this->line((string) __('A software update is still running and owns maintenance mode; leaving it in place.'));

            return self::SUCCESS;
        }

        // An expired lease means the run died mid-flight. recoverExpired() lifts
        // maintenance and records the interruption against the run history, so the
        // Updates console shows why the run never finished.
        if ($maintenance->leaseExists($runId) && $maintenance->recoverExpired($runId, $history)) {
            $this->info((string) __('Brought Belimbing back online after interrupted update :run.', ['run' => $runId]));

            return self::SUCCESS;
        }

        if (app()->isDownForMaintenance() && Artisan::call('up') !== 0) {
            $this->error((string) __('Could not lift the maintenance mode left behind by update :run.', ['run' => $runId]));

            return self::FAILURE;
        }

        $maintenance->disarm($runId);
        $this->info((string) __('Brought Belimbing back online; update :run had left it in maintenance mode.', ['run' => $runId]));

        return self::SUCCESS;
    }
}
