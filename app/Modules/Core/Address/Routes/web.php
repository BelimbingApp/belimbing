<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Address\Http\Controllers\AddressController;
use App\Modules\Core\Address\Http\Controllers\GeoLookupController;
use App\Modules\Core\Address\Http\Controllers\PostcodeSearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/addresses/geo/admin1', [GeoLookupController::class, 'admin1Options'])
        ->name('admin.addresses.lookup.admin1');
    Route::get('admin/addresses/geo/localities', [GeoLookupController::class, 'localityOptions'])
        ->name('admin.addresses.lookup.localities');

    Route::get('admin/addresses/postcodes/search', PostcodeSearchController::class)
        ->name('admin.addresses.postcodes.search');

    Route::get('admin/addresses', [AddressController::class, 'index'])->name('admin.addresses.index');
    Route::get('admin/addresses/search', [AddressController::class, 'search'])->name('admin.addresses.index.search');
    Route::get('admin/addresses/create', [AddressController::class, 'create'])->name('admin.addresses.create');
    Route::post('admin/addresses', [AddressController::class, 'store'])->name('admin.addresses.store');
    Route::get('admin/addresses/{address}', [AddressController::class, 'show'])->name('admin.addresses.show');
    Route::patch('admin/addresses/{address}/field', [AddressController::class, 'updateField'])->name('admin.addresses.update-field');
    Route::patch('admin/addresses/{address}/geo-field', [AddressController::class, 'updateGeoField'])->name('admin.addresses.update-geo-field');
    Route::delete('admin/addresses/{address}', [AddressController::class, 'destroy'])->name('admin.addresses.destroy');
});
