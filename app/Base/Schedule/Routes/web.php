<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Schedule\Controllers\ScheduledTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/scheduled-tasks', [ScheduledTaskController::class, 'index'])->name('admin.system.scheduled-tasks.index');
});
