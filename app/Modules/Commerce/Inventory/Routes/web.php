<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Livewire\Items\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/inventory/items', Index::class)
        ->middleware('authz:inventory.item.list')
        ->name('commerce.inventory.items.index');

    Route::get('commerce/inventory/items/create', Create::class)
        ->middleware('authz:inventory.item.create')
        ->name('commerce.inventory.items.create');
});
