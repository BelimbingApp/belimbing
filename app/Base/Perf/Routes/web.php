<?php

use App\Base\Perf\Livewire\Dashboard\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/performance', Index::class)
        ->middleware('authz:admin.system.perf.view')
        ->name('admin.system.perf.index');
});
