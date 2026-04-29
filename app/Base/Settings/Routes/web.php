<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Settings\Livewire\Admin\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/system/settings', Index::class)
        ->middleware('authz:admin.settings.manage')
        ->name('admin.settings.index');
});
