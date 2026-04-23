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
            :subtitle="$laraActivated ? __('Manage Lara\'s AI configuration') : __('Activate Belimbing\'s built-in AI assistant')"
        />

        @if ($laraActivated)
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
                    </div>
                @endif

                @if ($isUsingDefault)
                    <p class="text-xs text-muted mt-3">{{ __('Lara is using the company\'s default provider and model. Set a specific model below to override.') }}</p>
                @endif

                <div class="mt-4 border-t border-border-default pt-4">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="space-y-2">
                            <h4 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Task Models') }}</h4>
                            @if ($taskSummaries === [])
                                <p class="text-xs text-muted">{{ __('No task models have been configured yet.') }}</p>
                            @else
                                @foreach ($taskSummaries as $taskSummary)
                                    <div class="flex items-baseline gap-3">
                                        <span class="text-sm text-muted">{{ __($taskSummary['label']) }}</span>
                                        <span class="text-sm font-medium text-ink">{{ $taskSummary['summary'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        <x-ui.button variant="ghost" href="{{ route('admin.ai.task-models') }}" wire:navigate>
                            {{ __('Manage Task Models') }}
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                @php
                    $selectedPrimaryProvider = $providers->firstWhere('id', $selectedProviderId);
                    $selectedPrimaryLabel = $selectedPrimaryProvider !== null && $selectedModelId !== null
                        ? ($selectedPrimaryProvider->name ?? '—') . '/' . $selectedModelId
                        : null;
                @endphp
                <div class="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Primary Model') }}</h3>
                    @if ($selectedPrimaryLabel !== null)
                        <p class="text-sm font-medium text-ink font-mono">{{ $selectedPrimaryLabel }}</p>
                    @endif
                </div>
                <p class="text-xs text-muted mb-1">{{ __('Select a provider and model for Lara. Frontier models (Claude Opus, GPT-5 class) are recommended for orchestration and reasoning.') }}</p>
                @php
                    $primaryTabs = [['id' => 'model', 'label' => __('Model')]];
                    if (is_array($primaryExecutionControlSchema)) {
                        $primaryTabs[] = ['id' => 'controls', 'label' => __('Execution Controls')];
                    }
                @endphp

                <x-ui.tabs
                    :tabs="$primaryTabs"
                    default="model"
                    size="sm"
                    persistence="query"
                    query-key="lara-primary-tab"
                    class="mt-4"
                >
                    <x-ui.tab id="model" class="space-y-4">
                        @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                            'context' => 'lara-change',
                            'providers' => $providers,
                            'models' => $models,
                            'selectedProviderId' => $selectedProviderId,
                            'providerBinding' => 'selectedProviderId',
                            'modelBinding' => 'selectedModelId',
                        ])

                        @include('livewire.admin.setup.partials.provider-diagnostics')
                    </x-ui.tab>

                    @if (is_array($primaryExecutionControlSchema))
                        <x-ui.tab id="controls">
                            @include('livewire.admin.ai.partials.execution-controls', [
                                'schema' => $primaryExecutionControlSchema,
                                'statePath' => 'primaryExecutionControls',
                                'subtitle' => __('Editing controls for :provider / :model. Provider-enforced wire values are shown as facts without overwriting your saved intent.', [
                                    'provider' => $primaryExecutionControlSchema['provider_name'] ?? '—',
                                    'model' => $primaryExecutionControlSchema['model'] ?? '—',
                                ]),
                            ])
                        </x-ui.tab>
                    @endif
                </x-ui.tabs>

                <x-ui.action-message on="primary-saved" class="text-xs text-status-success" />
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
                @php
                    $backupTabs = [['id' => 'model', 'label' => __('Model')]];
                    if (is_array($backupExecutionControlSchema)) {
                        $backupTabs[] = ['id' => 'controls', 'label' => __('Execution Controls')];
                    }
                @endphp

                <x-ui.tabs
                    :tabs="$backupTabs"
                    default="model"
                    size="sm"
                    persistence="query"
                    query-key="lara-backup-tab"
                    class="mt-4"
                >
                    <x-ui.tab id="model" class="space-y-4">
                        @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                            'context' => 'lara-backup',
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
                    </x-ui.tab>

                    @if (is_array($backupExecutionControlSchema))
                        <x-ui.tab id="controls">
                            @include('livewire.admin.ai.partials.execution-controls', [
                                'schema' => $backupExecutionControlSchema,
                                'statePath' => 'backupExecutionControls',
                                'subtitle' => __('Editing controls for :provider / :model. These controls apply only when Lara falls back to the backup model.', [
                                    'provider' => $backupExecutionControlSchema['provider_name'] ?? '—',
                                    'model' => $backupExecutionControlSchema['model'] ?? '—',
                                ]),
                            ])
                        </x-ui.tab>
                    @endif
                </x-ui.tabs>

                <x-ui.action-message on="backup-saved" class="text-xs text-status-success" />
            </x-ui.card>
        @elseif (! $licenseeExists)
            <x-ui.alert variant="info">
                {{ __('Lara is Belimbing\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
            </x-ui.alert>

            <x-ui.alert variant="warning">
                {{ __('The Licensee company must be set up before Lara can be provisioned.') }}
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Set up Licensee') }}
                </a>
            </x-ui.alert>
        @elseif (! $laraExists)
            <x-ui.alert variant="info">
                {{ __('Lara is Belimbing\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
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
                {{ __('Lara is Belimbing\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
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
                    <p class="text-xs text-muted mb-1">{{ __('Select an AI provider and model for Lara. Frontier models (Claude Opus, GPT-5 class) are recommended for the best experience with orchestration and reasoning.') }}</p>
                    @php
                        $activationTabs = [['id' => 'model', 'label' => __('Model')]];
                        if (is_array($primaryExecutionControlSchema)) {
                            $activationTabs[] = ['id' => 'controls', 'label' => __('Execution Controls')];
                        }
                    @endphp

                    <x-ui.tabs
                        :tabs="$activationTabs"
                        default="model"
                        size="sm"
                        persistence="query"
                        query-key="lara-activation-tab"
                        class="mt-4"
                    >
                        <x-ui.tab id="model" class="space-y-4">
                            @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                                'context' => 'lara-activate',
                                'providers' => $providers,
                                'models' => $models,
                                'selectedProviderId' => $selectedProviderId,
                                'providerBinding' => 'selectedProviderId',
                                'modelBinding' => 'selectedModelId',
                            ])

                            @include('livewire.admin.setup.partials.provider-diagnostics')
                        </x-ui.tab>

                        @if (is_array($primaryExecutionControlSchema))
                            <x-ui.tab id="controls">
                                @include('livewire.admin.ai.partials.execution-controls', [
                                    'schema' => $primaryExecutionControlSchema,
                                    'statePath' => 'primaryExecutionControls',
                                    'subtitle' => __('Editing controls for :provider / :model. These settings will be stored before Lara is activated.', [
                                        'provider' => $primaryExecutionControlSchema['provider_name'] ?? '—',
                                        'model' => $primaryExecutionControlSchema['model'] ?? '—',
                                    ]),
                                ])
                            </x-ui.tab>
                        @endif
                    </x-ui.tabs>

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
