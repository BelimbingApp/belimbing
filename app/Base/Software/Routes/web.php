<?php

use App\Base\Software\Http\Controllers\DeploymentRecoveryController;
use App\Base\Software\Livewire\Deployment\Index as DeploymentIndex;
use App\Base\Software\Livewire\GitHubAccess\Index as GitHubAccessIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/software/updates', DeploymentIndex::class)
        ->middleware('authz:admin.system.software.updates.manage')
        ->name('admin.system.software.updates.index');

    // Lifts maintenance mode (artisan up). Excepted from maintenance in bootstrap/app.php
    // so it works even when an interrupted run has stranded the site on a 503.
    Route::post('admin/system/software/online', DeploymentRecoveryController::class)
        ->middleware('authz:admin.system.software.updates.manage')
        ->name('admin.system.software.online');

    Route::get('admin/system/software/github-access', GitHubAccessIndex::class)
        ->middleware('authz:admin.system.software.github-access.manage')
        ->name('admin.system.software.github-access.index');
});
