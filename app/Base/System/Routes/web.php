<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\System\Livewire\Info\Index;
use App\Base\System\Livewire\Localization\Index as LocalizationIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/info', Index::class)
        ->name('admin.system.info.index');

    Route::get('admin/system/localization', LocalizationIndex::class)
        ->middleware('authz:admin.system_localization.manage')
        ->name('admin.system.localization.index');
});
