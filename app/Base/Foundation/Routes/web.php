<?php

use App\Base\Foundation\Livewire\Modules;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/system/software/modules', Modules::class)
        ->middleware('authz:admin.system.software.modules.view')
        ->name('admin.system.software.modules.index');
});
