<?php

use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Livewire\PluginManager;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/system/plugins', PluginManager::class)
        ->middleware('authz:admin.system.plugins.view')
        ->name('admin.system.plugins.index');

    Route::get('admin/system/update/business-domains', DomainManager::class)
        ->middleware('authz:admin.system.update.business-domain.view')
        ->name('admin.system.update.business-domains.index');
});
