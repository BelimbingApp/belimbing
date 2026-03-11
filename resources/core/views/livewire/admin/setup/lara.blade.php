<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Setup\Lara $this */
?>
<div>
    <x-slot name="title">{{ __('Set Up Lara') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Set Up Lara')" :subtitle="__('Activate BLB\'s built-in AI assistant')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" href="{{ route('admin.ai.playground') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.alert variant="info">
            {{ __('Lara Belimbing is BLB\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
        </x-ui.alert>

        @if (! $licenseeExists)
            <x-ui.alert variant="warning">
                {{ __('The Licensee company must be set up before Lara can be provisioned.') }}
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Set up Licensee') }}
                </a>
            </x-ui.alert>
        @elseif (! $laraExists)
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Provision Lara') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Lara\'s employee record does not exist yet. Provision her to create the system Digital Worker record for the Licensee company.') }}</p>

                <form wire:submit="provisionLara">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Provision Lara') }}
                    </x-ui.button>
                </form>
            </x-ui.card>
        @elseif (! $laraActivated)
            @if ($providers->isEmpty())
                <x-ui.card>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Connect a Provider') }}</h3>
                    <p class="text-xs text-muted mb-4">{{ __('No AI providers are configured yet. Connect your first provider and model, then return to activate Lara.') }}</p>

                    <x-ui.button variant="primary" href="{{ route('admin.ai.providers.browse') }}" wire:navigate>
                        <x-icon name="heroicon-o-magnifying-glass" class="w-4 h-4" />
                        {{ __('Browse AI Providers') }}
                    </x-ui.button>
                </x-ui.card>
            @else
                <x-ui.card>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Activate Lara') }}</h3>
                    <p class="text-xs text-muted mb-4">{{ __('Select an AI provider and model for Lara. Frontier models (Claude Opus, GPT-5 class) are recommended for the best experience with orchestration and reasoning.') }}</p>

                    <form wire:submit="activateLara" class="space-y-4 max-w-md">
                        <x-ui.select wire:model.live="selectedProviderId" label="{{ __('Provider') }}" :error="$errors->first('selectedProviderId')">
                            <option value="">{{ __('Select a provider...') }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->display_name }}</option>
                            @endforeach
                        </x-ui.select>

                        @if ($selectedProviderId)
                            @if ($models->isEmpty())
                                <p class="text-xs text-muted">
                                    {{ __('No active models found for this provider. Add one in provider connections, then come back.') }}
                                </p>
                            @else
                                <x-ui.select wire:model="selectedModelId" label="{{ __('Model') }}" :error="$errors->first('selectedModelId')">
                                    <option value="">{{ __('Select a model...') }}</option>
                                    @foreach($models as $model)
                                        <option value="{{ $model->model_id }}">{{ $model->model_id }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif
                        @endif

                        <div class="flex items-center gap-4">
                            <x-ui.button type="submit" variant="primary">
                                {{ __('Activate Lara') }}
                            </x-ui.button>
                            <x-ui.button variant="ghost" href="{{ route('admin.ai.providers.connections') }}" wire:navigate>
                                {{ __('Manage Provider Connections') }}
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        @endif
    </div>
</div>
