<?php

namespace App\Base\Software\Console\Commands;

use App\Base\Software\Services\DeploymentMaintenanceGuard;
use App\Base\Software\Services\DeploymentRunHistory;
use Illuminate\Console\Command;

final class SoftwareUpdateWatchdogCommand extends Command
{
    protected $signature = 'blb:software:update-watchdog {runId}';

    protected $description = 'Recover maintenance mode if a detached software update terminates abnormally.';

    public function handle(DeploymentMaintenanceGuard $maintenance, DeploymentRunHistory $history): int
    {
        $runId = (string) $this->argument('runId');

        if (! $maintenance->acknowledgeWatchdog($runId)) {
            return self::FAILURE;
        }

        while ($maintenance->leaseExists($runId)) {
            if ($maintenance->recoverExpired($runId, $history)) {
                return self::FAILURE;
            }

            sleep(2);
        }

        return self::SUCCESS;
    }
}
