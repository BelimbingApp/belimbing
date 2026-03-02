<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Database\Controllers\MigrationController;
use App\Base\Database\Controllers\SeederController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('admin/system/migrations', [MigrationController::class, 'index'])->name('admin.system.migrations.index');
    Route::get('admin/system/seeders', [SeederController::class, 'index'])->name('admin.system.seeders.index');
    Route::post('admin/system/seeders/run', [SeederController::class, 'run'])->name('admin.system.seeders.run');
});
