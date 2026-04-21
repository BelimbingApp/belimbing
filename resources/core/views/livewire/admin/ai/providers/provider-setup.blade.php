<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\ProviderSetup $this */
/** @var \App\Modules\Core\AI\Models\AiProvider|null $connectedProvider */
/** @var \Illuminate\Support\Collection $models */
?>
<div>
    <x-slot name="title">{{ __('Set Up :provider', ['provider' => $displayName]) }}</x-slot>

    <div class="space-y-section-gap">
        @if($connectedProviderId)
        <x-ui.page-header
            :title="__(':provider Connected', ['provider' => $displayName])"
        >
            <x-slot name="subtitle">
                <span class="block">{{ __('★ Default = fallback when no model is specified.') }}</span>
                <span class="block">{{ __('☑ Available = offered to Agents.') }}</span>
            </x-slot>
            <x-slot name="help">
                <div class="space-y-3">
                    @include('livewire.admin.ai.providers.partials.model-help')
                </div>
            </x-slot>
            <x-slot name="actions">
                @if($providerKey === 'openai-codex')
                    <x-ui.button variant="ghost" wire:click="disconnect">
                        <x-icon name="heroicon-o-arrow-left-on-rectangle" class="w-4 h-4" />
                        {{ __('Disconnect') }}
                    </x-ui.button>
                @endif
                <x-ui.button variant="primary" wire:click="done">
                    <x-icon name="heroicon-o-check" class="w-4 h-4" />
                    {{ __('Done') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>
        @else
        <x-ui.page-header
            :title="__('Set Up :provider', ['provider' => $displayName])"
            :subtitle="__('Enter your credentials to connect :provider.', ['provider' => $displayName])"
        >
            @if($providerKey === 'openai-codex')
                <x-slot name="subtitle">
                    <span class="block">{{ __('Connect with browser OAuth to store refreshable subscription credentials.') }}</span>
                </x-slot>
            @endif
            @if($providerKey === 'cloudflare-ai-gateway')
                <x-slot name="help">
                    <div class="space-y-2 text-sm text-muted">
                        <p class="text-ink font-medium">{{ __('What this is') }}</p>
                        <p>{{ __('Cloudflare AI Gateway is a gateway/proxy layer in front of model providers (for example OpenAI). It is not the model provider itself.') }}</p>

                        <p class="text-ink font-medium pt-1">{{ __('Why use it') }}</p>
                        <ul class="list-disc list-inside space-y-1">
                            <li>{{ __('Single endpoint for routing and failover') }}</li>
                            <li>{{ __('Centralized observability, rate limits, and governance') }}</li>
                            <li>{{ __('Provider changes without app-side endpoint rewiring') }}</li>
                        </ul>

                        <p class="text-ink font-medium pt-1">{{ __('What you need') }}</p>
                        <ul class="list-disc list-inside space-y-1">
                            <li>{{ __('A Cloudflare account') }}</li>
                            <li>{{ __('Cloudflare Account ID and AI Gateway ID from your Cloudflare dashboard') }}</li>
                            <li>{{ __('API credentials for the upstream provider (for example OpenAI) configured in your gateway flow') }}</li>
                        </ul>
                    </div>
                </x-slot>
            @endif
            <x-slot name="actions">
                <x-ui.button variant="ghost" wire:click="backToCatalog">
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>
        @endif

        @if($connectedProviderId && $connectedProvider)
            {{-- ═══════════════════════════════════════════════════
                 Phase 2: Connected — model management
                 ═══════════════════════════════════════════════════ --}}
            <x-ui.card>
                <div class="flex items-center gap-2 mb-3">
                    <x-icon name="heroicon-o-check-circle" class="w-5 h-5 text-status-success" />
                    <span class="text-sm font-medium text-ink">{{ __(':provider connected successfully.', ['provider' => $displayName]) }}</span>
                </div>

                @include('livewire.admin.ai.providers.partials.model-table', [
                    'provider' => $connectedProvider,
                    'models' => $models,
                ])
            </x-ui.card>

            {{-- Model Add Modal --}}
            <x-ui.modal wire:model="showModelForm" class="max-w-sm">
                <div class="p-card-inner">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Model') }}</h3>
                        <button wire:click="$set('showModelForm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                            <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                        </button>
                    </div>

                    <form wire:submit="saveModel" class="space-y-4">
                        <x-ui.input
                            id="provider-setup-model-name"
                            wire:model="modelModelName"
                            label="{{ __('Model ID') }}"
                            required
                            placeholder="{{ __('e.g. gpt-4o') }}"
                            :error="$errors->first('modelModelName')"
                        />

                        <div class="flex justify-end gap-2 pt-2">
                            <x-ui.button variant="ghost" wire:click="$set('showModelForm', false)">{{ __('Cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="primary">{{ __('Add') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            </x-ui.modal>
        @else
            {{-- ═══════════════════════════════════════════════════
                 Phase 1: Credentials form
                 ═══════════════════════════════════════════════════ --}}
            <x-ui.card>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-base font-medium tracking-tight text-ink">{{ $displayName }}</h3>
                        @if($providerKey === 'copilot-proxy')
                            <p class="text-xs text-muted mt-0.5">{{ __('Requires the Copilot Proxy extension running in VS Code — start the extension, then connect.') }}</p>
                        @elseif($providerKey === 'openai-codex')
                            <p class="text-xs text-muted mt-0.5">{{ __('Codex subscription — browser sign-in is required. This integration depends on an undocumented external contract and may break without notice.') }}</p>
                        @elseif($authType === 'local')
                            <p class="text-xs text-muted mt-0.5">{{ __('Local server — API key is optional') }}</p>
                        @elseif($authType === 'oauth')
                            <p class="text-xs text-muted mt-0.5">{{ __('OAuth provider — connect via sign-in flow (API keys are not required)') }}</p>
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
                    @include('livewire.admin.ai.providers.partials.auth-device-flow')
                @elseif($providerKey === 'openai-codex')
                    <div class="space-y-3">
                        <x-ui.alert variant="warning">
                            {{ __('Browser sign-in required. OpenAI Codex uses subscription-backed ChatGPT credentials, not OpenAI API keys. This integration depends on an undocumented external contract and may break without notice.') }}
                        </x-ui.alert>
                        <x-ui.input
                            id="provider-base-url"
                            wire:model.live.blur="baseUrl"
                            label="{{ __('Base URL') }}"
                            required
                            :error="$errors->first('baseUrl')"
                        />
                        <div class="flex justify-end">
                            <x-ui.button variant="primary" wire:click="startOauthLogin">
                                <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-4 h-4" />
                                {{ __('Sign in') }}
                            </x-ui.button>
                        </div>
                    </div>
                @elseif($providerKey === 'cloudflare-ai-gateway')
                    {{-- ── Cloudflare AI Gateway (Account ID + Gateway ID + API Key) ── --}}
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-ui.input
                                id="cloudflare-account-id"
                                wire:model="cloudflareAccountId"
                                label="{{ __('Account ID') }}"
                                required
                                placeholder="{{ __('Cloudflare Account ID') }}"
                                :error="$errors->first('cloudflareAccountId')"
                            />
                            <x-ui.input
                                id="cloudflare-gateway-id"
                                wire:model.live.blur="cloudflareGatewayId"
                                label="{{ __('Gateway ID') }}"
                                required
                                placeholder="{{ __('AI Gateway name') }}"
                                :error="$errors->first('cloudflareGatewayId')"
                            />
                        </div>
                        <x-ui.input
                            id="cloudflare-api-key"
                            wire:model.live.blur="apiKey"
                            type="password"
                            label="{{ __('API Key') }}"
                            required
                            placeholder="{{ __('Cloudflare API token') }}"
                            :error="$errors->first('apiKey')"
                        />
                        @if($this->maskedApiKey)
                            <p class="text-xs text-muted font-mono mt-1">{{ $this->maskedApiKey }}</p>
                        @endif
                        <p class="text-xs text-muted">{{ __('The base URL will be computed as: gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openai') }}</p>
                    </div>
                @else
                    {{-- ── Standard API Key / URL form ── --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-ui.input
                                id="provider-base-url"
                                wire:model.live.blur="baseUrl"
                                label="{{ __('Base URL') }}"
                                required
                                :error="$errors->first('baseUrl')"
                            />
                            @if($providerKey === 'copilot-proxy')
                                {{-- Trigger the HTTP probe after first render so the spinner is visible --}}
                                @if($baseUrlStatus === 'checking')
                                    <span wire:init="checkBaseUrl" class="hidden" aria-hidden="true"></span>
                                @endif
                                @if($baseUrlStatus !== null)
                                    <div class="mt-1.5 flex items-center gap-1.5">
                                        @if($baseUrlStatus === 'checking')
                                            <div class="animate-spin h-3 w-3 border border-accent border-t-transparent rounded-full"></div>
                                            <span class="text-xs text-muted">{{ $baseUrlStatusMessage }}</span>
                                        @elseif($baseUrlStatus === 'online')
                                            <x-icon name="heroicon-o-check-circle" class="w-3.5 h-3.5 text-status-success" />
                                            <span class="text-xs text-status-success">{{ $baseUrlStatusMessage }}</span>
                                        @elseif($baseUrlStatus === 'offline')
                                            <x-icon name="heroicon-o-x-circle" class="w-3.5 h-3.5 text-status-error" />
                                            <span class="text-xs text-status-error">{{ $baseUrlStatusMessage }}</span>
                                            <button
                                                type="button"
                                                wire:click="checkBaseUrl"
                                                class="ml-1 text-xs text-accent hover:underline focus:ring-2 focus:ring-accent focus:ring-offset-1 rounded"
                                            >
                                                {{ __('Retry') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>

                        <div>
                            <x-ui.input
                                id="provider-api-key"
                                wire:model.live.blur="apiKey"
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
                            @if($this->maskedApiKey)
                                <p class="text-xs text-muted font-mono mt-1">{{ $this->maskedApiKey }}</p>
                            @endif
                        </div>
                    </div>
                    @if($providerKey === 'copilot-proxy')
                        <div class="bg-surface-subtle rounded-lg p-3 mt-3">
                            <p class="text-xs font-medium text-ink mb-1">{{ __('Setup instructions') }}</p>
                            <ol class="text-xs text-muted space-y-0.5 list-decimal list-inside">
                                <li>{{ __('Install the "Copilot Proxy" extension in VS Code.') }}</li>
                                <li>{{ __('Open VS Code and ensure you are signed in to GitHub Copilot.') }}</li>
                                <li>{{ __('Start the proxy via the extension (it listens on localhost:1337 by default). BLB will connect automatically.') }}</li>
                            </ol>
                        </div>
                    @endif
                @endif
            </x-ui.card>
        @endif
    </div>
</div>
