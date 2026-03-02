<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Queue\Controllers\FailedJobController;
use App\Base\Queue\Controllers\JobBatchController;
use App\Base\Queue\Controllers\JobController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/jobs', [JobController::class, 'index'])->name('admin.system.jobs.index');
    Route::get('admin/system/failed-jobs', [FailedJobController::class, 'index'])->name('admin.system.failed-jobs.index');
    Route::post('admin/system/failed-jobs/retry', [FailedJobController::class, 'retry'])->name('admin.system.failed-jobs.retry');
    Route::delete('admin/system/failed-jobs/{id}', [FailedJobController::class, 'destroy'])->name('admin.system.failed-jobs.destroy');
    Route::get('admin/system/job-batches', [JobBatchController::class, 'index'])->name('admin.system.job-batches.index');
});
