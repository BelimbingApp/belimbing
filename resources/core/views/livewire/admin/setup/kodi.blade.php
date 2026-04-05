<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Setup\Kodi $this */
?>
<div>
    <x-slot name="title">{{ __('Kodi') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Kodi')"
            :subtitle="__('Manage Kodi\'s AI configuration')"
        />

        @if (! $licenseeExists)
            <x-ui.alert variant="warning">
                {{ __('The Licensee company must be set up before Kodi can be configured.') }}
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Set up Licensee') }}
                </a>
            </x-ui.alert>
        @elseif (! $laraActivated)
            <x-ui.alert variant="info">
                {{ __('Kodi is BLB\'s developer agent. He is configured separately from Lara, but the Kodi setup page only makes sense after Lara is activated.') }}
            </x-ui.alert>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Activate Lara First') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Activate Lara to ensure a working provider/model baseline for the system AI runtime.') }}</p>

                <x-ui.button variant="primary" href="{{ route('admin.setup.lara') }}" wire:navigate>
                    <x-icon name="heroicon-o-sparkles" class="w-4 h-4" />
                    {{ __('Set Up Lara') }}
                </x-ui.button>
            </x-ui.card>
        @else
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Current Configuration') }}</h3>

                <div class="flex items-baseline gap-3">
                    <span class="text-sm text-muted">{{ __('Primary') }}</span>
                    <span class="text-sm font-medium text-ink font-mono">{{ ($activeProviderName ?? '—') . '/' . ($activeModelId ?? '—') }}</span>
                    @if ($isUsingDefault)
                        <x-ui.badge variant="info">{{ __('Default') }}</x-ui.badge>
                    @endif
                </div>

                @if ($activeBackupModelId !== null)
                    <div class="flex items-baseline gap-3 mt-2">
                        <span class="text-sm text-muted">{{ __('Backup') }}</span>
                        <span class="text-sm font-medium text-ink font-mono">{{ $activeBackupProviderName ?? '—' }}/{{ $activeBackupModelId }}</span>
                        <x-ui.badge variant="warning">{{ __('Backup') }}</x-ui.badge>
                    </div>
                @endif

                @if ($isUsingDefault)
                    <p class="text-xs text-muted mt-3">{{ __('Kodi is using the company\'s default provider and model. Set a specific model below to override.') }}</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Primary Model') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Select a provider and model for Kodi. For code-heavy tasks, frontier models (Claude Opus, GPT-5 class) are recommended.') }}</p>

                @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                    'context' => 'kodi-change',
                    'providers' => $providers,
                    'models' => $models,
                    'selectedProviderId' => $selectedProviderId,
                    'providerBinding' => 'selectedProviderId',
                    'modelBinding' => 'selectedModelId',
                ])

                @include('livewire.admin.setup.partials.provider-diagnostics')

                <p class="text-xs text-muted">
                    {{ __('Changes are saved automatically when you select a model.') }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Backup Model') }} <span class="normal-case tracking-normal font-normal">{{ __('(Optional)') }}</span></h3>
                <p class="text-xs text-muted mb-4">{{ __('If the primary model fails, the system will automatically retry with this model.') }}</p>

                @if ($backupProviderId !== null)
                    <div class="mb-3 flex items-center justify-end">
                        <x-ui.button variant="ghost" size="sm" wire:click="removeBackup" class="text-red-500 hover:text-red-600">
                            <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                            {{ __('Clear backup') }}
                        </x-ui.button>
                    </div>
                @endif

                @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                    'context' => 'kodi-backup',
                    'providers' => $providers,
                    'models' => $backupModels,
                    'selectedProviderId' => $backupProviderId,
                    'providerBinding' => 'backupProviderId',
                    'modelBinding' => 'backupModelId',
                ])
            </x-ui.card>
        @endif
    </div>
</div>

