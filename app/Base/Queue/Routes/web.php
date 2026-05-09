<?php
use App\Base\Queue\Livewire\FailedJobs\Index as FailedJobsIndex;
use App\Base\Queue\Livewire\JobBatches\Index as JobBatchesIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/failed-jobs', FailedJobsIndex::class)
        ->name('admin.system.failed-jobs.index');
    Route::get('admin/system/job-batches', JobBatchesIndex::class)
        ->name('admin.system.job-batches.index');
});
