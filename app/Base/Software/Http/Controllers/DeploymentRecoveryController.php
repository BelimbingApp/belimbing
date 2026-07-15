<?php

namespace App\Base\Software\Http\Controllers;

use App\Base\Software\Services\DeploymentMaintenanceGuard;
use App\Base\Software\Services\DeploymentRunHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Lifts maintenance mode from the in-app update console.
 *
 * The updater drops the site into maintenance mode while it pulls, migrates, and
 * reloads. If that run is interrupted before it can lift maintenance, the whole site
 * is stranded on a 503. This route is excepted from maintenance (see bootstrap/app.php)
 * so an operator can recover from the UI instead of shelling in to run `artisan up`.
 */
class DeploymentRecoveryController
{
    public function __invoke(
        DeploymentMaintenanceGuard $maintenance,
        DeploymentRunHistory $history,
    ): RedirectResponse {
        $runId = $maintenance->activeRunId();

        if ($runId !== null && $maintenance->leaseExists($runId)) {
            if (! $maintenance->leaseExpired($runId)) {
                return redirect()
                    ->route('admin.system.software.updates.index')
                    ->with('error', __('The update is still active and owns maintenance mode. Automatic recovery will bring Belimbing online if that process stops responding.'));
            }

            if (! $maintenance->recoverExpired($runId, $history)) {
                return redirect()
                    ->route('admin.system.software.updates.index')
                    ->with('error', __('Maintenance recovery is already running or could not bring Belimbing online. Try again shortly.'));
            }
        }

        if (app()->isDownForMaintenance() && Artisan::call('up') !== 0) {
            return redirect()
                ->route('admin.system.software.updates.index')
                ->with('error', __('Belimbing could not leave maintenance mode. Check the deployment log and try again.'));
        }

        return redirect()
            ->route('admin.system.software.updates.index')
            ->with('status', __('Belimbing is back online.'));
    }
}
