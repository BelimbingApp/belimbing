<?php

use App\Base\Update\Livewire\Belimbing\Index as BelimbingIndex;
use App\Base\Update\Livewire\GitHubAccess\Index as GitHubAccessIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/update/belimbing', BelimbingIndex::class)
        ->middleware('authz:admin.system.update.belimbing.manage')
        ->name('admin.system.update.belimbing.index');

    Route::get('admin/system/update/github-access', GitHubAccessIndex::class)
        ->middleware('authz:admin.system.update.github-access.manage')
        ->name('admin.system.update.github-access.index');
});
