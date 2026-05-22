<?php

use App\Modules\Commerce\Catalog\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/catalog', Index::class)
        ->middleware('authz:commerce.catalog.view')
        ->defaults('tab', 'categories')
        ->name('commerce.catalog.index');

    Route::get('commerce/catalog/categories', Index::class)
        ->middleware('authz:commerce.catalog.view')
        ->defaults('tab', 'categories')
        ->name('commerce.catalog.categories');

    Route::get('commerce/catalog/templates', Index::class)
        ->middleware('authz:commerce.catalog.view')
        ->defaults('tab', 'templates')
        ->name('commerce.catalog.templates');

    Route::get('commerce/catalog/attributes', Index::class)
        ->middleware('authz:commerce.catalog.view')
        ->defaults('tab', 'attributes')
        ->name('commerce.catalog.attributes');
});
