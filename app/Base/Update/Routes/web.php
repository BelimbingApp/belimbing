<?php

use App\Base\Update\Http\Controllers\DeploymentRecoveryController;
use App\Base\Update\Livewire\Deployment\Index as DeploymentIndex;
use App\Base\Update\Livewire\GitHubAccess\Index as GitHubAccessIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/update/deployment', DeploymentIndex::class)
        ->middleware('authz:admin.system.update.deployment.manage')
        ->name('admin.system.update.deployment.index');

    // Lifts maintenance mode (artisan up). Excepted from maintenance in bootstrap/app.php
    // so it works even when an interrupted run has stranded the site on a 503.
    Route::post('admin/system/update/online', DeploymentRecoveryController::class)
        ->middleware('authz:admin.system.update.deployment.manage')
        ->name('admin.system.update.online');

    Route::get('admin/system/update/github-access', GitHubAccessIndex::class)
        ->middleware('authz:admin.system.update.github-access.manage')
        ->name('admin.system.update.github-access.index');
});
