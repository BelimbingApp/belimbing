<?php

use App\Core\Geonames\Livewire\Admin1\Index as Admin1Index;
use App\Core\Geonames\Livewire\Countries\Index as CountriesIndex;
use App\Core\Geonames\Livewire\Postcodes\Index as PostcodesIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'authz:admin.geonames.list'])->group(function () {
    Route::get('admin/geonames/countries', CountriesIndex::class)->name('admin.geonames.countries.index');
    Route::get('admin/geonames/admin1', Admin1Index::class)->name('admin.geonames.admin1.index');
    Route::get('admin/geonames/postcodes', PostcodesIndex::class)->name('admin.geonames.postcodes.index');
});
