<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Setup\Lara $this */
?>
<div>
    <x-slot name="title">{{ $laraActivated ? __('Lara') : __('Set Up Lara') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="$laraActivated ? __('Lara') : __('Set Up Lara')"
            :subtitle="$laraActivated ? __('Manage Lara\'s AI configuration') : __('Activate BLB\'s built-in AI assistant')"
        />

        @if ($laraActivated)
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Current Configuration') }}</h3>

                <div class="flex items-baseline gap-3 mb-1">
                    <span class="text-sm text-muted">{{ __('Provider') }}</span>
                    <span class="text-sm font-medium text-ink">{{ $activeProviderName ?? '—' }}</span>
                    @if ($isUsingDefault)
                        <x-ui.badge variant="info">{{ __('Default') }}</x-ui.badge>
                    @endif
                </div>
                <div class="flex items-baseline gap-3">
                    <span class="text-sm text-muted">{{ __('Model') }}</span>
                    <span class="text-sm font-medium text-ink font-mono">{{ $activeModelId ?? '—' }}</span>
                    @if ($isUsingDefault)
                        <x-ui.badge variant="info">{{ __('Default') }}</x-ui.badge>
                    @endif
                </div>

                @if ($isUsingDefault)
                    <p class="text-xs text-muted mt-3">{{ __('Lara is using the company\'s default provider and model. Set a specific model below to override.') }}</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Change Model') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Select a provider and model for Lara. Frontier models (Claude Opus, GPT-5 class) are recommended for orchestration and reasoning.') }}</p>

                @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                    'context' => 'lara-change',
                    'providers' => $providers,
                    'models' => $models,
                    'selectedProviderId' => $selectedProviderId,
                    'providerBinding' => 'selectedProviderId',
                    'modelBinding' => 'selectedModelId',
                ])

                @include('livewire.admin.setup.partials.provider-diagnostics')
            </x-ui.card>
        @elseif (! $licenseeExists)
            <x-ui.alert variant="info">
                {{ __('Lara Belimbing is BLB\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
            </x-ui.alert>

            <x-ui.alert variant="warning">
                {{ __('The Licensee company must be set up before Lara can be provisioned.') }}
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Set up Licensee') }}
                </a>
            </x-ui.alert>
        @elseif (! $laraExists)
            <x-ui.alert variant="info">
                {{ __('Lara Belimbing is BLB\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
            </x-ui.alert>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Provision Lara') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Lara\'s employee record does not exist yet. Provision her to create the system Agent record for the Licensee company.') }}</p>

                <form wire:submit="provisionLara">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Provision Lara') }}
                    </x-ui.button>
                </form>
            </x-ui.card>
        @elseif (! $laraActivated)
            <x-ui.alert variant="info">
                {{ __('Lara Belimbing is BLB\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
            </x-ui.alert>

            @if ($providers->isEmpty())
                <x-ui.card>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Connect a Provider') }}</h3>
                    <p class="text-xs text-muted mb-4">{{ __('No AI providers are configured yet. Lara needs a provider to process AI requests. She is activated when a provider is connected.') }}</p>

                    <x-ui.button variant="primary" href="{{ route('admin.ai.providers') }}" wire:navigate>
                        <x-icon name="heroicon-o-magnifying-glass" class="w-4 h-4" />
                        {{ __('Browse AI Providers') }}
                    </x-ui.button>
                </x-ui.card>
            @else
                <x-ui.card>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Activate Lara') }}</h3>
                    <p class="text-xs text-muted mb-4">{{ __('Select an AI provider and model for Lara. Frontier models (Claude Opus, GPT-5 class) are recommended for the best experience with orchestration and reasoning.') }}</p>

                    @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                        'context' => 'lara-activate',
                        'providers' => $providers,
                        'models' => $models,
                        'selectedProviderId' => $selectedProviderId,
                        'providerBinding' => 'selectedProviderId',
                        'modelBinding' => 'selectedModelId',
                    ])

                    @include('livewire.admin.setup.partials.provider-diagnostics')

                    <div class="flex items-center gap-4">
                        <x-ui.button wire:click="activateLara" variant="primary">
                            {{ __('Activate Lara') }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" href="{{ route('admin.ai.providers') }}" wire:navigate>
                            {{ __('Manage Providers') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        @endif
    </div>
</div>
