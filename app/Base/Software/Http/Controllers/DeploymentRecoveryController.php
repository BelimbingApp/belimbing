<?php

namespace App\Base\Software\Http\Controllers;

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
    public function __invoke(): RedirectResponse
    {
        Artisan::call('up');

        return redirect()
            ->route('admin.system.software.updates.index')
            ->with('status', __('Belimbing is back online.'));
    }
}
