<?php

use App\Base\Foundation\Http\Controllers\CompositionController;
use App\Base\Foundation\Livewire\Domains;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    // What is composed: every mounted module, its boot position, and pinned
    // versus mounted refs. Operator-only: it names checkout refs and paths.
    Route::get('admin/system/software/composition', CompositionController::class)
        ->middleware(['authz:admin.system.software.domains.view', 'platform-operator'])
        ->name('admin.system.software.composition');

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
