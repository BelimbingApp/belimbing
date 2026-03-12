<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Http\Controllers\ChatStreamController;
use App\Modules\Core\AI\Livewire\Playground;
use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Livewire\Providers\ProviderSetup;
use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Livewire\Tools;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // DW chat streaming (SSE)
    Route::get('api/ai/chat/stream', ChatStreamController::class)
        ->name('ai.chat.stream');
    // Lara setup
    Route::get('admin/setup/lara', Lara::class)
        ->name('admin.setup.lara');

    Route::get('admin/ai/playground', Playground::class)
        ->name('admin.ai.playground');

    // Unified AI Providers page (management + catalog)
    Route::get('admin/ai/providers', Providers::class)
        ->name('admin.ai.providers');
    Route::get('admin/ai/providers/setup/{providerKey}', ProviderSetup::class)
        ->name('admin.ai.providers.setup');

    // Legacy redirects — old Browse and Connections URLs point to the unified page.
    Route::redirect('admin/ai/providers/browse', '/admin/ai/providers')
        ->name('admin.ai.providers.browse');
    Route::redirect('admin/ai/providers/connections', '/admin/ai/providers')
        ->name('admin.ai.providers.connections');

    Route::get('admin/ai/tools/{toolName?}', Tools::class)
        ->name('admin.ai.tools');
});
