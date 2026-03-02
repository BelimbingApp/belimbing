<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Controllers\PlaygroundController;
use App\Modules\Core\AI\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/ai/playground', [PlaygroundController::class, 'index'])->name('admin.ai.playground');
    Route::post('admin/ai/playground/sessions', [PlaygroundController::class, 'createSession'])->name('admin.ai.playground.sessions');
    Route::post('admin/ai/playground/messages', [PlaygroundController::class, 'sendMessage'])->name('admin.ai.playground.messages');

    Route::get('admin/ai/providers', [ProviderController::class, 'index'])->name('admin.ai.providers.index');
    Route::get('admin/ai/providers/create', [ProviderController::class, 'create'])->name('admin.ai.providers.create');
    Route::post('admin/ai/providers', [ProviderController::class, 'store'])->name('admin.ai.providers.store');
    Route::get('admin/ai/providers/{provider}', [ProviderController::class, 'show'])->name('admin.ai.providers.show');
    Route::put('admin/ai/providers/{provider}', [ProviderController::class, 'update'])->name('admin.ai.providers.update');
    Route::delete('admin/ai/providers/{provider}', [ProviderController::class, 'destroy'])->name('admin.ai.providers.destroy');
});
