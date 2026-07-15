<?php

namespace App\Base\Software\Console\Commands;

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
        $record = function (string $line) use (&$log, &$maintenanceOwned, $history, $maintenance, $runId): void {
            $log[] = $line;
            $history->appendDeploymentLine($runId, $line);

            if ($maintenanceOwned && ! $maintenance->renew($runId)) {
                throw new \RuntimeException('The update lost its maintenance recovery lease.');
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
                reloadWorkers: true,
                manageMaintenance: false,
                beforeReload: function () use (&$maintenanceOwned, $maintenance, $runId): void {
                    if (! $maintenance->leave($runId)) {
                        throw new \RuntimeException('Belimbing could not leave maintenance mode before the runtime reload.');
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
            if ($maintenance->leave($runId)) {
                $maintenance->disarm($runId);
            }

            $lock->release();
        }
    }
}
