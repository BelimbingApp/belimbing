<?php

use App\Base\Queue\Livewire\FailedJobs\Index as FailedJobsIndex;
use App\Base\Queue\Livewire\JobBatches\Index as JobBatchesIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/failed-jobs', FailedJobsIndex::class)
        ->middleware('authz:admin.system.failed-job.list')
        ->name('admin.system.failed-jobs.index');
    Route::get('admin/system/job-batches', JobBatchesIndex::class)
        ->middleware('authz:admin.system.job-batch.list')
        ->name('admin.system.job-batches.index');
});
