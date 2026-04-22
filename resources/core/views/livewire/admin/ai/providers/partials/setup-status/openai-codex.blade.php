<?php

use App\Modules\Core\AI\Livewire\Providers\OpenAiCodexSetup;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var OpenAiCodexSetup $this */
$codexAuthState = is_array($this->authState) ? $this->authState : [];
$codexVerification = is_array($this->verificationResult) ? $this->verificationResult : null;
$codexStatus = (string) ($codexAuthState['status'] ?? '');
$codexStatusLabel = match ($codexStatus) {
    'connected' => __('Connected'),
    'pending' => __('Waiting for browser sign-in'),
    'expired' => __('Reconnect required'),
    'error' => __('Sign-in failed'),
    default => __('Disconnected'),
};
$codexStatusVariant = match ($codexStatus) {
    'connected' => 'success',
    'pending' => 'info',
    'expired' => 'warning',
    'error' => 'danger',
    default => 'default',
};
$codexModeLabel = match ((string) ($codexAuthState['mode'] ?? '')) {
    'browser_pkce' => __('Browser OAuth'),
    'device_code' => __('Device code'),
    'external_bearer' => __('External bearer'),
    default => __('Unknown'),
};
?>
<div class="space-y-2">
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-base font-medium tracking-tight text-ink">{{ __('Connection status') }}</h3>
        <x-ui.badge :variant="$codexStatusVariant">{{ $codexStatusLabel }}</x-ui.badge>
        @if(!empty($codexAuthState['plan_type']))
            <x-ui.badge variant="accent">{{ __('Plan: :plan', ['plan' => $codexAuthState['plan_type']]) }}</x-ui.badge>
        @endif
    </div>
    <p class="text-sm text-muted">{{ __('OpenAI Codex uses browser OAuth and the ChatGPT backend. Belimbing mirrors the OpenClaw localhost callback flow, so a pending sign-in must be completed by pasting the localhost redirect URL back into this page.') }}</p>
</div>

<dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
    <div>
        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Auth mode') }}</dt>
        <dd class="mt-1 text-ink">{{ $codexModeLabel }}</dd>
    </div>
    <div>
        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Connected at') }}</dt>
        <dd class="mt-1 text-ink tabular-nums">
            @if(!empty($codexAuthState['completed_at']))
                <x-ui.datetime :value="$codexAuthState['completed_at']" />
            @else
                {{ __('Not yet') }}
            @endif
        </dd>
    </div>
    <div>
        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last refresh') }}</dt>
        <dd class="mt-1 text-ink tabular-nums">
            @if(!empty($codexAuthState['last_refresh_at']))
                <x-ui.datetime :value="$codexAuthState['last_refresh_at']" />
            @else
                {{ __('Never') }}
            @endif
        </dd>
    </div>
    <div>
        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last diagnostic') }}</dt>
        <dd class="mt-1 text-ink tabular-nums">
            @if(!empty($codexVerification['checked_at']))
                <x-ui.datetime :value="$codexVerification['checked_at']" />
            @else
                {{ __('Not run') }}
            @endif
        </dd>
    </div>
</dl>

@if(!empty($codexAuthState['last_error_message']))
    <div class="mt-4">
        <x-ui.alert :variant="$codexStatus === 'expired' ? 'warning' : 'danger'">
            {{ __('Last provider error: :message', ['message' => $codexAuthState['last_error_message']]) }}
        </x-ui.alert>
    </div>
@endif

@if($codexVerification)
    <div class="mt-4">
        <x-ui.alert :variant="$codexVerification['connected'] ? 'success' : 'danger'">
            @if($codexVerification['connected'])
                {{ __('Verification succeeded for :model in :latency ms.', ['model' => $codexVerification['model'], 'latency' => $codexVerification['latency_ms'] ?? 0]) }}
            @else
                {{ __('Verification failed for :model. :message', ['model' => $codexVerification['model'] !== '' ? $codexVerification['model'] : __('No model selected'), 'message' => $codexVerification['user_message'] ?? __('Unknown error.')]) }}
            @endif
        </x-ui.alert>
    </div>
@endif

@if(!empty($codexVerification['hint']))
    <div class="mt-4">
        <x-ui.alert variant="info">
            {{ $codexVerification['hint'] }}
        </x-ui.alert>
    </div>
@elseif(in_array($codexStatus, ['expired', 'error'], true))
    <p class="mt-4 text-sm text-muted">
        {{ __('Reconnect OpenAI Codex first. If the same failure returns, disable the provider until the external ChatGPT backend contract works again.') }}
    </p>
@endif
