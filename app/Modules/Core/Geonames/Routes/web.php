<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Geonames\Controllers\Admin\Admin1Controller;
use App\Modules\Core\Geonames\Controllers\Admin\CountryController;
use App\Modules\Core\Geonames\Controllers\Admin\PostcodeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/geonames/countries', [CountryController::class, 'index'])->name('admin.geonames.countries.index');
    Route::post('admin/geonames/countries/update', [CountryController::class, 'update'])->name('admin.geonames.countries.update');

    Route::get('admin/geonames/admin1', [Admin1Controller::class, 'index'])->name('admin.geonames.admin1.index');
    Route::post('admin/geonames/admin1/update', [Admin1Controller::class, 'update'])->name('admin.geonames.admin1.update');

    Route::get('admin/geonames/postcodes', [PostcodeController::class, 'index'])->name('admin.geonames.postcodes.index');
    Route::post('admin/geonames/postcodes/import', [PostcodeController::class, 'import'])->name('admin.geonames.postcodes.import');
    Route::post('admin/geonames/postcodes/update', [PostcodeController::class, 'update'])->name('admin.geonames.postcodes.update');
});
