<?php

use App\Base\Foundation\Livewire\Domains;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/system/software/domains', Domains::class)
        ->middleware('authz:admin.system.software.domains.view')
        ->name('admin.system.software.domains.index');

    // Preserve saved links while keeping Domains as the only rendered surface.
    Route::get('admin/system/software/modules', fn () => redirect()->route(
        'admin.system.software.domains.index',
        request()->query(),
    ))
        ->name('admin.system.software.modules.index');
});
