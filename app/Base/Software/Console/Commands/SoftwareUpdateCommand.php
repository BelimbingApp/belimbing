<?php

namespace App\Base\Software\Console\Commands;

use App\Base\Software\Exceptions\DeploymentMaintenanceException;
use App\Base\Software\Services\DeploymentLogClassifier;
use App\Base\Software\Services\DeploymentMaintenanceGuard;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\SoftwareUpdateLauncher;
use Illuminate\Console\Command;
use Throwable;

final class SoftwareUpdateCommand extends Command
{
    protected $signature = 'blb:software:update
        {keys?* : Software source keys to update.}
        {--run-id= : Reserved deployment run identifier.}';

    protected $description = 'Run a software update outside the web-worker lifecycle.';

    public function handle(
        DeploymentService $deployment,
        DeploymentRunHistory $history,
        DeploymentMaintenanceGuard $maintenance,
    ): int {
        $runId = (string) $this->option('run-id');
        $lock = cache()->restoreLock(SoftwareUpdateLauncher::LOCK_KEY, $runId);

        if ($runId === '' || ! $lock->isOwnedByCurrentProcess()) {
            $failure = (string) __('FAILED: detached update run :run could not restore its active reservation.', [
                'run' => $runId !== '' ? $runId : __('unknown'),
            ]);

            if ($runId !== '') {
                $history->interruptDeploymentRun($runId, $failure);
            }

            $this->error($failure);

            return self::FAILURE;
        }

        if (! $history->acknowledgeDeploymentRunStart($runId)) {
            // Lock owner tokens are transferable, so a duplicate child carrying
            // this run id can also restore the first child's live reservation.
            // Losing the durable scheduled -> starting transition proves only
            // that this child must stop; it does not prove the shared fence is
            // stale or safe for this child to release.
            if ($history->deploymentRunReservationIsObsolete($runId)) {
                $lock->release();
            }

            $this->error('This software update no longer owns an active scheduled run.');

            return self::FAILURE;
        }

        $log = [];
        $maintenanceState = new class
        {
            public bool $owned = false;
        };
        $reloadAttempted = false;
        $reloadOk = false;
        $record = function (string $line) use (&$log, $maintenanceState, $history, $maintenance, $runId): void {
            $log[] = $line;
            $history->appendDeploymentLine($runId, $line);

            if ($maintenanceState->owned && ! $maintenance->renew($runId)) {
                throw new DeploymentMaintenanceException('The update lost its maintenance recovery lease.');
            }

            $this->line($line);
        };

        try {
            $maintenance->arm($runId);
            $maintenance->enter($runId);
            $maintenanceState->owned = true;

            if (! $history->markDeploymentRunRunning($runId)) {
                throw new DeploymentMaintenanceException('The update lost its durable run before startup completed.');
            }

            $log = $deployment->update(
                array_values(array_filter($this->argument('keys'), 'is_string')),
                $record,
                // Leave maintenance only after the workers were reloaded, so no
                // request is ever served by old worker code against the freshly
                // pulled files (mixed-version window). If the reload failed, the
                // old workers are still live and the reload already cleared the
                // compiled-view/opcache caches, so reopening would render the new
                // templates against the old component code — the count(null)
                // TypeError this command exists to prevent. Stay in maintenance
                // for manual recovery instead; the operator brings the site back
                // online once the reload is fixed.
                //
                // Updating while the app is stopped is not that case: with no
                // worker pool there is nothing holding old code, so the reloader
                // reports it as a notice rather than a warning and $reloadSucceeded
                // stays true. Starting the app is also the moment the hold stops
                // being useful in general, so blb:software:maintenance-heal clears
                // any hold left here whose run is no longer active.
                afterReload: function (bool $reloadSucceeded) use ($maintenanceState, &$reloadAttempted, &$reloadOk, $maintenance, $runId): void {
                    $reloadAttempted = true;
                    $reloadOk = $reloadSucceeded;

                    if (! $reloadSucceeded) {
                        return;
                    }

                    if (! $maintenance->leave($runId)) {
                        throw new DeploymentMaintenanceException('Belimbing could not leave maintenance mode after the runtime reload.');
                    }

                    $maintenanceState->owned = false;
                    $maintenance->disarm($runId);
                },
            );

            $status = match (true) {
                DeploymentLogClassifier::hasError($log) => 'error',
                DeploymentLogClassifier::hasWarning($log) => 'warning',
                default => 'success',
            };
            $history->finishDeploymentRun($runId, $status, $log);

            return $status === 'error' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $failure = (string) __('FAILED: software update stopped unexpectedly: :message', ['message' => $exception->getMessage()]);
            $log[] = $failure;
            $history->finishDeploymentRun($runId, 'error', $log);
            $this->error($failure);

            return self::FAILURE;
        } finally {
            // When the worker reload was attempted but failed, keep the site in
            // maintenance for manual recovery (see afterReload). The process has
            // completed normally rather than crashed, so disarm the crash-recovery
            // watchdog — the operator owns bringing the site back. Every other
            // reachable path (success, pre-reload failure, exception) leaves
            // maintenance here as a safety net.
            $this->finishMaintenance($maintenance, $runId, $reloadAttempted, $reloadOk);

            $lock->release();
        }
    }

    private function finishMaintenance(
        DeploymentMaintenanceGuard $maintenance,
        string $runId,
        bool $reloadAttempted,
        bool $reloadOk,
    ): void {
        if (($reloadAttempted && ! $reloadOk) || $maintenance->leave($runId)) {
            $maintenance->disarm($runId);
        }
    }
}
