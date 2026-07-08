<?php

use App\Base\Scheduling\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('admin/system/scheduling', Index::class)
        ->middleware('authz:admin.system.scheduling.view')
        ->name('admin.system.scheduling.index');
});
