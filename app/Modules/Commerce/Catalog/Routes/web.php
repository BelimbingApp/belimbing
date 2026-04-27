<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Commerce\Catalog\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/catalog', Index::class)
        ->middleware('authz:commerce.catalog.view')
        ->name('commerce.catalog.index');
});
