<?php

use App\Base\Schedule\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/system/schedule', Index::class)
        ->middleware('authz:admin.system.schedule.view')
        ->name('admin.system.schedule.index');
});
