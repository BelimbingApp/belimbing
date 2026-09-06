<?php

use App\Base\FeatureFlags\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/system/feature-flags', Index::class)
        ->middleware('authz:admin.system.feature-flags.view')
        ->name('admin.system.feature-flags.index');
});
