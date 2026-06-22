<?php

use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Livewire\BundleManager;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/system/software/bundles', BundleManager::class)
        ->middleware('authz:admin.system.software.bundles.view')
        ->name('admin.system.software.bundles.index');

    Route::get('admin/system/software/business-domains', DomainManager::class)
        ->middleware('authz:admin.system.software.business-domain.view')
        ->name('admin.system.software.business-domains.index');
});
