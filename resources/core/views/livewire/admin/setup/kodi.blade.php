<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Setup\Kodi $this */
?>
<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Kodi Setup')"
        :subtitle="__('Kodi is Belimbing\'s system developer — he builds modules, writes code, and works through IT tickets assigned by supervisors. Configure his primary and backup models here.')"
    />

    <div class="space-y-section-gap">
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Current Configuration') }}</h3>
                <div class="space-y-2 text-sm">
                    @if ($activeModelId !== null)
                        <div class="flex items-baseline gap-3">
                            <span class="text-sm text-muted">{{ __('Primary') }}</span>
                            <span class="text-sm font-medium text-ink font-mono">{{ ($activeProviderName ?? '—') . '/' . ($activeModelId ?? '—') }}</span>
                            <x-ui.badge variant="primary">{{ __('Active') }}</x-ui.badge>
                        </div>
                    @else
                        <div class="flex items-center gap-2 text-warning">
                            <x-icon name="heroicon-o-exclamation-triangle" class="w-4 h-4" />
                            <span>{{ __('Kodi does not have a primary model configured yet.') }}</span>
                        </div>
                    @endif
                </div>

                @if ($activeBackupModelId !== null)
                    <div class="flex items-baseline gap-3 mt-2">
                        <span class="text-sm text-muted">{{ __('Backup') }}</span>
                        <span class="text-sm font-medium text-ink font-mono">{{ $activeBackupProviderName ?? '—' }}/{{ $activeBackupModelId }}</span>
                    </div>
                @endif


            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Primary Model') }}</h3>
                <p class="text-xs text-muted mb-1">{{ __('Select the default primary model for delegated task execution.') }}</p>

                @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                    'context' => 'kodi-primary',
                    'providers' => $providers,
                    'models' => $models,
                    'selectedProviderId' => $selectedProviderId,
                    'providerBinding' => 'selectedProviderId',
                    'modelBinding' => 'selectedModelId',
                ])

                @include('livewire.admin.setup.partials.provider-diagnostics')

                <x-action-message on="primary-saved" class="text-xs text-status-success" />
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Backup Model') }} <span class="normal-case tracking-normal font-normal">{{ __('(Optional)') }}</span></h3>
                    @if ($backupProviderId !== null)
                        <x-ui.button variant="ghost" size="sm" wire:click="removeBackup" class="text-red-500 hover:text-red-600">
                            <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                            {{ __('Clear backup') }}
                        </x-ui.button>
                    @endif
                </div>
                <p class="text-xs text-muted mb-1">{{ __('If the primary model fails, the system will automatically retry with this model.') }}</p>

                @if ($selectedProviderId === null || blank($selectedModelId))
                    <div class="rounded-2xl border border-dashed border-line/70 px-4 py-6 text-sm text-muted">
                        {{ __('Configure a primary model first before selecting a backup.') }}
                    </div>
                @else
                    @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                        'context' => 'kodi-backup',
                        'providers' => $providers,
                        'models' => $backupModels,
                        'selectedProviderId' => $backupProviderId,
                        'providerBinding' => 'backupProviderId',
                        'modelBinding' => 'backupModelId',
                    ])

                    @include('livewire.admin.setup.partials.provider-diagnostics', [
                        'testAction' => 'testBackupProvider',
                        'testProviderId' => $backupProviderId,
                        'testModelId' => $backupModelId,
                        'testResult' => $this->backupProviderTestResult,
                    ])

                    <x-action-message on="backup-saved" class="text-xs text-status-success" />
                @endif
            </x-ui.card>
    </div>
</div>