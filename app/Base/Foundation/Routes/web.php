<?php
use App\Base\Foundation\Livewire\PluginManager;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/system/plugins', PluginManager::class)
        ->middleware('authz:admin.system.plugins.view')
        ->name('admin.system.plugins.index');
});
