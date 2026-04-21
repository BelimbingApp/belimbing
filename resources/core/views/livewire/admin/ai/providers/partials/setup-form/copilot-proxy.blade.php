<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\CopilotProxySetup $this */
?>
<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <x-ui.input
            id="provider-base-url"
            wire:model.live.blur="baseUrl"
            label="{{ __('Base URL') }}"
            required
            :error="$errors->first('baseUrl')"
        />
        @if($baseUrlStatus === 'checking')
            <span wire:init="checkBaseUrl" class="hidden" aria-hidden="true"></span>
        @endif
        @if($baseUrlStatus !== null)
            <div class="mt-1.5 flex items-center gap-1.5">
                @if($baseUrlStatus === 'checking')
                    <div class="h-3 w-3 animate-spin rounded-full border border-accent border-t-transparent"></div>
                    <span class="text-xs text-muted">{{ $baseUrlStatusMessage }}</span>
                @elseif($baseUrlStatus === 'online')
                    <x-icon name="heroicon-o-check-circle" class="h-3.5 w-3.5 text-status-success" />
                    <span class="text-xs text-status-success">{{ $baseUrlStatusMessage }}</span>
                @elseif($baseUrlStatus === 'offline')
                    <x-icon name="heroicon-o-x-circle" class="h-3.5 w-3.5 text-status-error" />
                    <span class="text-xs text-status-error">{{ $baseUrlStatusMessage }}</span>
                    <button
                        type="button"
                        wire:click="checkBaseUrl"
                        class="ml-1 rounded text-xs text-accent hover:underline focus:ring-2 focus:ring-accent focus:ring-offset-1"
                    >
                        {{ __('Retry') }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    <div>
        <x-ui.input
            id="provider-api-key"
            wire:model.live.blur="apiKey"
            type="password"
            label="{{ __('API Key (optional)') }}"
            placeholder="{{ __('Leave empty for local servers') }}"
            :error="$errors->first('apiKey')"
        />
        @if($this->maskedApiKey)
            <p class="mt-1 font-mono text-xs text-muted">{{ $this->maskedApiKey }}</p>
        @endif
    </div>
</div>

<div class="mt-3 rounded-lg bg-surface-subtle p-3">
    <p class="mb-1 text-xs font-medium text-ink">{{ __('Setup instructions') }}</p>
    <ol class="list-inside list-decimal space-y-0.5 text-xs text-muted">
        <li>{{ __('Install the "Copilot Proxy" extension in VS Code.') }}</li>
        <li>{{ __('Open VS Code and ensure you are signed in to GitHub Copilot.') }}</li>
        <li>{{ __('Start the proxy via the extension (it listens on localhost:1337 by default). BLB will connect automatically.') }}</li>
    </ol>
</div>
