<?php
use App\Base\Cache\Livewire\CacheManagement\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/cache', Index::class)
        ->name('admin.system.cache.index');
});
