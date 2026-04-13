<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Http\Controllers\MessagingWebhookController;
use App\Modules\Core\AI\Http\Controllers\ProviderSetupController;
use App\Modules\Core\AI\Http\Controllers\ChatTurnStreamController;
use App\Modules\Core\AI\Http\Controllers\TurnEventStreamController;
use App\Modules\Core\AI\Livewire\ControlPlane;
use App\Modules\Core\AI\Livewire\Providers\Providers;
use App\Modules\Core\AI\Livewire\RunDetail;
use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Livewire\TaskModels;
use App\Modules\Core\AI\Livewire\Tools;
use Illuminate\Support\Facades\Route;

// Inbound messaging webhook — unauthenticated (external platforms POST here)
Route::post('api/ai/messaging/webhook/{channel}/{accountId?}', MessagingWebhookController::class)
    ->name('ai.messaging.webhook')
    ->where('channel', '[a-z]+')
    ->where('accountId', '[0-9]+');

Route::middleware(['auth'])->group(function () {
    // Turn event replay (JSON for resume and gap-fill)
    Route::get('api/ai/chat/turns/{turnId}/events', TurnEventStreamController::class)
        ->name('ai.chat.turn.events');
    // Direct streaming for interactive chat turns (NDJSON)
    Route::get('api/ai/chat/turns/{turnId}/stream', ChatTurnStreamController::class)
        ->name('ai.chat.turn.stream');
    // Lara setup
    Route::get('admin/setup/lara', Lara::class)
        ->name('admin.setup.lara');

    Route::redirect('admin/setup/kodi', '/admin/setup/lara')
        ->name('admin.setup.kodi');

    Route::get('admin/ai/task-models', TaskModels::class)
        ->name('admin.ai.task-models');

    Route::redirect('admin/ai/playground', '/admin/ai/task-models')
        ->name('admin.ai.playground');

    // Unified AI Providers page (management + catalog)
    Route::get('admin/ai/providers', Providers::class)
        ->name('admin.ai.providers');

    // Dynamic provider setup - resolve component class in controller
    Route::get('admin/ai/providers/setup/{providerKey}', ProviderSetupController::class)
        ->name('admin.ai.providers.setup');

    // Legacy redirects — old Browse and Connections URLs point to the unified page.
    Route::redirect('admin/ai/providers/browse', '/admin/ai/providers')
        ->name('admin.ai.providers.browse');
    Route::redirect('admin/ai/providers/connections', '/admin/ai/providers')
        ->name('admin.ai.providers.connections');

    Route::get('admin/ai/tools/{toolName?}', Tools::class)
        ->name('admin.ai.tools');

    Route::get('admin/ai/control-plane', ControlPlane::class)
        ->name('admin.ai.control-plane');

    Route::get('admin/ai/runs/{runId}', RunDetail::class)
        ->name('admin.ai.runs.show')
        ->where('runId', '[a-zA-Z0-9_\-]+');
});
