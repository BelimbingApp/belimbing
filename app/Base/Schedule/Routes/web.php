<?php

use App\Base\Schedule\Livewire\ScheduledTasks\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/scheduled-tasks', Index::class)
        ->middleware('authz:admin.system.scheduled-task.list')
        ->name('admin.system.scheduled-tasks.index');
});
