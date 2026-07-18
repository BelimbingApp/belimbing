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
        {keys?* : Distribution Bundle keys to update.}
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
            $this->error('This software update does not own the active reservation.');

            return self::FAILURE;
        }

        $log = [];
        $maintenanceOwned = false;
        $reloadAttempted = false;
        $reloadOk = false;
        $record = function (string $line) use (&$log, &$maintenanceOwned, $history, $maintenance, $runId): void {
            $log[] = $line;
            $history->appendDeploymentLine($runId, $line);

            if ($maintenanceOwned && ! $maintenance->renew($runId)) {
                throw new DeploymentMaintenanceException('The update lost its maintenance recovery lease.');
            }

            $this->line($line);
        };

        try {
            $maintenance->arm($runId);
            $maintenance->enter($runId);
            $maintenanceOwned = true;
            $record((string) __('Detached update process started; automatic maintenance recovery is armed.'));

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
                afterReload: function (bool $reloadSucceeded) use (&$maintenanceOwned, &$reloadAttempted, &$reloadOk, $maintenance, $runId): void {
                    $reloadAttempted = true;
                    $reloadOk = $reloadSucceeded;

                    if (! $reloadSucceeded) {
                        return;
                    }

                    if (! $maintenance->leave($runId)) {
                        throw new DeploymentMaintenanceException('Belimbing could not leave maintenance mode after the runtime reload.');
                    }

                    $maintenanceOwned = false;
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
            if ($reloadAttempted && ! $reloadOk) {
                $maintenance->disarm($runId);
            } elseif ($maintenance->leave($runId)) {
                $maintenance->disarm($runId);
            }

            $lock->release();
        }
    }
}
