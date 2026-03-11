<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\ProviderSetup $this */
?>
<div>
    <x-slot name="title">{{ __('Set Up :provider', ['provider' => $displayName]) }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Set Up :provider', ['provider' => $displayName])" :subtitle="__('Enter your credentials to connect :provider.', ['provider' => $displayName])">
            <x-slot name="actions">
                <x-ui.button variant="ghost" wire:click="backToCatalog">
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
                @if($authType !== 'device_flow' || $deviceFlow['status'] === 'success')
                    <x-ui.button variant="primary" wire:click="connect">
                        <x-icon name="heroicon-m-bolt" class="w-4 h-4" />
                        {{ __('Connect & Import Models') }}
                    </x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-base font-medium tracking-tight text-ink">{{ $displayName }}</h3>
                    @if($providerKey === 'copilot-proxy')
                        <p class="text-xs text-muted mt-0.5">{{ __('Requires the Copilot Proxy extension running in VS Code — start the extension, then connect.') }}</p>
                    @elseif($authType === 'local')
                        <p class="text-xs text-muted mt-0.5">{{ __('Local server — API key is optional') }}</p>
                    @elseif($authType === 'oauth')
                        <p class="text-xs text-muted mt-0.5">{{ __('OAuth provider — paste API key if available, or configure after connecting') }}</p>
                    @elseif($authType === 'subscription')
                        <p class="text-xs text-muted mt-0.5">{{ __('Subscription service — paste access token or API key') }}</p>
                    @elseif($authType === 'custom')
                        <p class="text-xs text-muted mt-0.5">{{ __('Requires additional configuration after connecting') }}</p>
                    @elseif($authType === 'device_flow')
                        <p class="text-xs text-muted mt-0.5">{{ __('Requires GitHub device login — an active GitHub Copilot subscription is needed') }}</p>
                    @endif
                </div>
                @if(!empty($apiKeyUrl))
                    <a
                        href="{{ $apiKeyUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm text-accent hover:underline inline-flex items-center gap-1"
                    >
                        {{ __('Get API Key') }}
                        <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                    </a>
                @endif
            </div>

            @if($connectError)
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 mb-3">
                    <p class="text-sm text-red-700 dark:text-red-400">{{ $connectError }}</p>
                </div>
            @endif

            @if($authType === 'device_flow')
                {{-- ── Device Flow UI (GitHub Copilot) ── --}}
                @if($deviceFlow['status'] === 'pending')
                    <div wire:poll.5s="pollDeviceFlow">
                        <div
                            class="bg-surface-subtle rounded-lg p-4 space-y-3"
                            x-data="{ copied: false }"
                        >
                            <div class="space-y-2">
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted block">{{ __('Step 1 — Copy your authorization code') }}</span>
                                <div class="flex items-center gap-3">
                                    <p class="text-2xl font-mono font-bold text-ink tracking-[0.3em] select-all">{{ $deviceFlow['user_code'] }}</p>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-accent bg-surface-card border border-border-default rounded-md hover:bg-surface-subtle transition-colors focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                        x-on:click="navigator.clipboard.writeText('{{ $deviceFlow['user_code'] }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})"
                                        x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy') }}'"
                                        :aria-label="copied ? '{{ __('Code copied to clipboard') }}' : '{{ __('Copy authorization code') }}'"
                                    >
                                    </button>
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted block">{{ __('Step 2 — Paste it on GitHub') }}</span>
                                <p class="text-xs text-muted">{{ __('Open the link below, paste the code, and approve access for BLB.') }}</p>
                                <a
                                    href="{{ $deviceFlow['verification_uri'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-sm text-accent hover:underline inline-flex items-center gap-1"
                                >
                                    {{ $deviceFlow['verification_uri'] }}
                                    <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                                </a>
                            </div>

                            <div class="flex items-center gap-2 pt-1 border-t border-border-default">
                                <div class="animate-spin h-3.5 w-3.5 border-2 border-accent border-t-transparent rounded-full"></div>
                                <span class="text-xs text-muted">{{ __('Listening for approval — this will update automatically once you authorize on GitHub.') }}</span>
                            </div>
                        </div>
                    </div>
                @elseif($deviceFlow['status'] === 'idle')
                    <div class="space-y-3">
                        <p class="text-xs text-muted">{{ __('Connecting to GitHub Copilot requires that you authorize this application on GitHub.') }}</p>
                        <x-ui.button variant="primary" wire:click="startDeviceFlow">
                            <x-icon name="github" class="w-4 h-4" />
                            {{ __('Start GitHub Login') }}
                        </x-ui.button>
                    </div>
                @elseif($deviceFlow['status'] === 'success')
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <x-icon name="heroicon-o-check-circle" class="w-5 h-5 text-status-success" />
                            <span class="text-sm font-medium text-ink">{{ __('GitHub Copilot authorized successfully') }}</span>
                        </div>
                        <p class="text-xs text-muted">{{ __('Click "Connect & Import Models" above to finish setup.') }}</p>
                    </div>
                @else
                    {{-- error / expired / denied --}}
                    <div class="space-y-3">
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                            <p class="text-sm text-red-700 dark:text-red-400">{{ $deviceFlow['error'] ?? __('Authorization failed') }}</p>
                        </div>
                        <x-ui.button variant="ghost" wire:click="startDeviceFlow">
                            <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                            {{ __('Try Again') }}
                        </x-ui.button>
                    </div>
                @endif
            @elseif($providerKey === 'cloudflare-ai-gateway')
                {{-- ── Cloudflare AI Gateway (Account ID + Gateway ID + API Key) ── --}}
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="cloudflareAccountId"
                            label="{{ __('Account ID') }}"
                            required
                            placeholder="{{ __('Cloudflare Account ID') }}"
                            :error="$errors->first('cloudflareAccountId')"
                        />
                        <x-ui.input
                            wire:model="cloudflareGatewayId"
                            label="{{ __('Gateway ID') }}"
                            required
                            placeholder="{{ __('AI Gateway name') }}"
                            :error="$errors->first('cloudflareGatewayId')"
                        />
                    </div>
                    <x-ui.input
                        wire:model="apiKey"
                        type="password"
                        label="{{ __('API Key') }}"
                        required
                        placeholder="{{ __('Cloudflare API token') }}"
                        :error="$errors->first('apiKey')"
                    />
                    <p class="text-xs text-muted">{{ __('The base URL will be computed as: gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openai') }}</p>
                </div>
            @else
                {{-- ── Standard API Key / URL form ── --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        wire:model="baseUrl"
                        label="{{ __('Base URL') }}"
                        required
                        :error="$errors->first('baseUrl')"
                    />

                    <x-ui.input
                        wire:model="apiKey"
                        type="password"
                        :label="in_array($authType, ['local', 'oauth', 'subscription']) ? __('API Key (optional)') : __('API Key')"
                        :required="in_array($authType, ['api_key', 'custom'])"
                        :placeholder="match($authType) {
                            'local' => __('Leave empty for local servers'),
                            'oauth' => __('Paste API key if available'),
                            'subscription' => __('Paste access token'),
                            default => __('Paste your API key'),
                        }"
                        :error="$errors->first('apiKey')"
                    />
                </div>
                @if($providerKey === 'copilot-proxy')
                    <div class="bg-surface-subtle rounded-lg p-3 mt-3">
                        <p class="text-xs font-medium text-ink mb-1">{{ __('Setup instructions') }}</p>
                        <ol class="text-xs text-muted space-y-0.5 list-decimal list-inside">
                            <li>{{ __('Install the "Copilot Proxy" extension in VS Code.') }}</li>
                            <li>{{ __('Open VS Code and ensure you are signed in to GitHub Copilot.') }}</li>
                            <li>{{ __('Start the proxy via the extension (it listens on localhost:1337 by default).') }}</li>
                            <li>{{ __('Click "Connect & Import Models" above — BLB will discover available models from the proxy.') }}</li>
                        </ol>
                    </div>
                @endif
            @endif
        </x-ui.card>
    </div>
</div>
