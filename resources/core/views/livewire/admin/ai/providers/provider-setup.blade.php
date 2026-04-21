<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\ProviderSetup $this */
/** @var \App\Modules\Core\AI\Models\AiProvider|null $connectedProvider */
/** @var \Illuminate\Support\Collection $models */

$providerHeaderHelpPartial = $this->providerHeaderHelpPartial();
$providerHeaderSubtitle = $this->providerHeaderSubtitle();
$providerConnectedActionsPartial = $this->providerConnectedActionsPartial();
$providerStatusPanelPartial = $this->providerStatusPanelPartial();
$providerCredentialsFormPartial = $this->providerCredentialsFormPartial();
$providerConnectionDescription = $this->providerConnectionDescription();
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
                @if($providerConnectedActionsPartial)
                    @include($providerConnectedActionsPartial)
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
            :subtitle="$providerHeaderSubtitle ?? __('Enter your credentials to connect :provider.', ['provider' => $displayName])"
        >
            @if($providerHeaderHelpPartial)
                <x-slot name="help">
                    @include($providerHeaderHelpPartial)
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

        @if($providerStatusPanelPartial)
            <x-ui.card>
                @include($providerStatusPanelPartial)
            </x-ui.card>
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
                        @if($providerConnectionDescription)
                            <p class="text-xs text-muted mt-0.5">{{ $providerConnectionDescription }}</p>
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
                @elseif($providerCredentialsFormPartial)
                    @include($providerCredentialsFormPartial)
                @else
                    @include('livewire.admin.ai.providers.partials.setup-form.standard')
                @endif
            </x-ui.card>
        @endif
    </div>
</div>
