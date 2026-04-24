<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Http\Controllers\ChatAttachmentController;
use App\Modules\Core\AI\Http\Controllers\ChatTurnStreamController;
use App\Modules\Core\AI\Http\Controllers\MessagingWebhookController;
use App\Modules\Core\AI\Http\Controllers\OpenAiCodexOAuthCallbackController;
use App\Modules\Core\AI\Http\Controllers\ProviderSetupController;
use App\Modules\Core\AI\Http\Controllers\TurnEventStreamController;
use App\Modules\Core\AI\Http\Controllers\WireLogEntryController;
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
    // Session attachment retrieval (images/files referenced from transcript meta)
    Route::get('api/ai/chat/attachments/{employeeId}/{sessionId}/{attachmentId}', ChatAttachmentController::class)
        ->name('ai.chat.attachments.show')
        ->whereNumber('employeeId')
        ->where('sessionId', '[0-9]{8}-[0-9]{6}')
        ->where('attachmentId', '[a-zA-Z0-9_]+');
    // Lara setup
    Route::get('admin/setup/lara', Lara::class)
        ->middleware('authz:admin.ai_lara.manage')
        ->name('admin.setup.lara');

    Route::get('admin/ai/task-models', TaskModels::class)
        ->middleware('authz:admin.ai_task_model.manage')
        ->name('admin.ai.task-models');

    // Unified AI Providers page (management + catalog)
    Route::get('admin/ai/providers', Providers::class)
        ->middleware('authz:admin.ai_provider.manage')
        ->name('admin.ai.providers');

    // Legacy provider sub-pages — keep backward compatible redirects
    Route::get('admin/ai/providers/browse', fn () => redirect()->route('admin.ai.providers'))
        ->middleware('authz:admin.ai_provider.manage')
        ->name('admin.ai.providers.browse');

    Route::get('admin/ai/providers/connections', fn () => redirect()->route('admin.ai.providers'))
        ->middleware('authz:admin.ai_provider.manage')
        ->name('admin.ai.providers.connections');

    // Dynamic provider setup - resolve component class in controller
    Route::get('admin/ai/providers/setup/{providerKey}', ProviderSetupController::class)
        ->middleware('authz:admin.ai_provider.manage')
        ->name('admin.ai.providers.setup');

    // OpenAI Codex OAuth callback (browser PKCE)
    Route::get('admin/ai/providers/openai-codex/auth/callback', OpenAiCodexOAuthCallbackController::class)
        ->middleware('authz:admin.ai_provider.manage')
        ->name('admin.ai.providers.openai-codex.callback');

    Route::get('admin/ai/tools/{toolName?}', Tools::class)
        ->middleware('authz:admin.ai_tool.manage')
        ->name('admin.ai.tools');

    Route::get('admin/ai/control-plane', ControlPlane::class)
        ->middleware('authz:admin.ai_control_plane.view')
        ->name('admin.ai.control-plane');

    Route::get('admin/ai/runs/{runId}', RunDetail::class)
        ->middleware('authz:admin.ai_control_plane.view')
        ->name('admin.ai.runs.show')
        ->where('runId', '[a-zA-Z0-9_\-]+');

    Route::get('admin/ai/runs/{runId}/wire-log/{entryNumber}', WireLogEntryController::class)
        ->middleware('authz:admin.ai_control_plane.view')
        ->name('admin.ai.runs.wire-log-entry')
        ->where('runId', '[a-zA-Z0-9_\-]+')
        ->whereNumber('entryNumber');
});
