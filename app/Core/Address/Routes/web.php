<?php

use App\Core\Address\Http\Controllers\CitySearchController;
use App\Core\Address\Http\Controllers\CountrySearchController;
use App\Core\Address\Http\Controllers\PostcodeSearchController;
use App\Core\Address\Livewire\Addresses\Create;
use App\Core\Address\Livewire\Addresses\Index;
use App\Core\Address\Livewire\Addresses\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/addresses/countries/search', CountrySearchController::class)
        ->name('admin.addresses.countries.search');
    Route::get('admin/addresses/postcodes/search', PostcodeSearchController::class)
        ->name('admin.addresses.postcodes.search');
    Route::get('admin/addresses/cities/search', CitySearchController::class)
        ->name('admin.addresses.cities.search');
    Route::get('admin/addresses', Index::class)
        ->middleware('authz:admin.address.list')
        ->name('admin.addresses.index');
    Route::get('admin/addresses/create', Create::class)
        ->middleware('authz:admin.address.create')
        ->name('admin.addresses.create');
    Route::get('admin/addresses/{address}', Show::class)
        ->middleware('authz:admin.address.view')
        ->name('admin.addresses.show');
});
