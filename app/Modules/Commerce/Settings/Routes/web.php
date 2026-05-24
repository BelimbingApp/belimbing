<?php

use App\Modules\Commerce\Settings\Livewire\Settings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/settings', Settings::class)
        ->middleware('authz:commerce.inventory.manage')
        ->name('commerce.settings');
});
