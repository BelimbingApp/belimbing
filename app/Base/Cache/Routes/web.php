<?php

use App\Base\Cache\Livewire\CacheManagement\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/cache', Index::class)
        ->middleware('authz:admin.system.cache.view')
        ->name('admin.system.cache.index');
});
