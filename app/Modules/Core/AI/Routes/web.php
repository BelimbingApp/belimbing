<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Livewire\Playground;
use App\Modules\Core\AI\Livewire\Providers\Catalog;
use App\Modules\Core\AI\Livewire\Providers\Connections;
use App\Modules\Core\AI\Livewire\Providers\ProviderSetup;
use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Livewire\Tools;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // Lara setup
    Route::get('admin/setup/lara', Lara::class)
        ->name('admin.setup.lara');

    Route::get('admin/ai/playground', Playground::class)
        ->name('admin.ai.playground');
    Route::get('admin/ai/providers/browse', Catalog::class)
        ->name('admin.ai.providers.browse');
    Route::get('admin/ai/providers/setup/{providerKey}', ProviderSetup::class)
        ->name('admin.ai.providers.setup');
    Route::get('admin/ai/providers/connections', Connections::class)
        ->name('admin.ai.providers.connections');
    Route::get('admin/ai/tools/{toolName?}', Tools::class)
        ->name('admin.ai.tools');
});
