<?php

use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Livewire\BundleManager;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/system/bundles', BundleManager::class)
        ->middleware('authz:admin.system.bundles.view')
        ->name('admin.system.bundles.index');

    Route::get('admin/system/update/business-domains', DomainManager::class)
        ->middleware('authz:admin.system.update.business-domain.view')
        ->name('admin.system.update.business-domains.index');
});
