<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Address\Http\Controllers\CitySearchController;
use App\Modules\Core\Address\Http\Controllers\PostcodeSearchController;
use App\Modules\Core\Address\Livewire\Addresses\Create;
use App\Modules\Core\Address\Livewire\Addresses\Index;
use App\Modules\Core\Address\Livewire\Addresses\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/addresses/postcodes/search', PostcodeSearchController::class)
        ->name('admin.addresses.postcodes.search');
    Route::get('admin/addresses/cities/search', CitySearchController::class)
        ->name('admin.addresses.cities.search');
    Route::get('admin/addresses', Index::class)->name('admin.addresses.index');
    Route::get('admin/addresses/create', Create::class)->name('admin.addresses.create');
    Route::get('admin/addresses/{address}', Show::class)->name('admin.addresses.show');
});
