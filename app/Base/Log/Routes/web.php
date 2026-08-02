<?php

use App\Base\Log\Livewire\Logs\Index;
use App\Base\Log\Livewire\Logs\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/logs', Index::class)
        ->middleware('authz:admin.system.log.list')
        ->name('admin.system.logs.index');
    Route::get('admin/system/logs/{filename}', Show::class)
        ->middleware('authz:admin.system.log.list')
        ->name('admin.system.logs.show')
        ->where('filename', '.+');
});
