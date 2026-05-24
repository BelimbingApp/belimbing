<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Livewire\Items\Index;
use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/inventory/items', Index::class)
        ->middleware('authz:commerce.inventory.item.list')
        ->name('commerce.inventory.items.index');

    Route::get('commerce/inventory/items/create', Create::class)
        ->middleware('authz:commerce.inventory.item.create')
        ->name('commerce.inventory.items.create');

    Route::get('commerce/inventory/items/{item}', Show::class)
        ->middleware('authz:commerce.inventory.item.view')
        ->name('commerce.inventory.items.show');
});
