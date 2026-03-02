<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Cache\Controllers\CacheController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/cache', [CacheController::class, 'index'])->name('admin.system.cache.index');
    Route::post('admin/system/cache/clear', [CacheController::class, 'clear'])->name('admin.system.cache.clear');
});
