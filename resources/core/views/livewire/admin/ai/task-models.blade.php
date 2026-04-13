<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\TaskModels $this */
?>
<div>
    <x-slot name="title">{{ __('Task Models') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Task Models')"
            :subtitle="__('Choose which models Lara should use for titling, research, and coding tasks.')"
        />

        @unless ($laraActivated)
            <x-ui.alert variant="warning">
                {{ __('Task models become available after Lara has been activated with a primary model.') }}
                <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Open Lara setup') }}
                </a>
            </x-ui.alert>
        @else
            <x-ui.card>
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Lara Primary') }}</h3>
                        <p class="mt-1 text-sm font-medium text-ink font-mono">{{ ($currentPrimary['provider_name'] ?? '—') . '/' . ($currentPrimary['model'] ?? '—') }}</p>
                    </div>

                    <x-ui.button variant="ghost" href="{{ route('admin.setup.lara') }}" wire:navigate>
                        {{ __('Back to Lara') }}
                    </x-ui.button>
                </div>
            </x-ui.card>

            @foreach ($tasks as $task)
                @php
                    $taskKey = $task->key;
                    $taskMode = $this->taskModes[$taskKey] ?? 'recommended';
                    $taskProviderId = $this->taskProviderIds[$taskKey] ?? null;
                    $taskModelId = $this->taskModelIds[$taskKey] ?? null;
                    $taskReason = $this->taskReasons[$taskKey] ?? '';
                    $taskError = $this->taskRecommendationErrors[$taskKey] ?? null;
                    $taskModels = $modelsByTask[$taskKey] ?? collect();
                @endphp

                <x-ui.card>
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __($task->label) }}</h3>
                                <x-ui.badge variant="{{ $task->type->value === 'agentic' ? 'accent' : 'info' }}">{{ __($task->type->label()) }}</x-ui.badge>
                                <x-ui.badge variant="{{ $task->runtimeReady ? 'success' : 'warning' }}">
                                    {{ $task->runtimeReady ? __('Runtime wired') : __('Runtime pending') }}
                                </x-ui.badge>
                            </div>

                            <p class="text-sm text-muted">{{ __($task->description) }}</p>

                            @if ($taskMode === 'primary')
                                <p class="text-xs text-muted">{{ __('Currently uses Lara\'s primary model.') }}</p>
                            @elseif ($taskProviderId !== null && $taskModelId !== null)
                                <p class="text-xs text-muted">
                                    {{ __('Current selection: :provider/:model', [
                                        'provider' => optional($providers->firstWhere('id', $taskProviderId))->name ?? '—',
                                        'model' => $taskModelId,
                                    ]) }}
                                </p>
                            @else
                                <p class="text-xs text-muted">{{ __('No saved model selection yet. Lara will fall back to the primary model until one is saved.') }}</p>
                            @endif
                        </div>

                        <div class="w-full lg:max-w-xs">
                            <x-ui.select
                                id="task-mode-{{ $taskKey }}"
                                label="{{ __('Mode') }}"
                                wire:model.live="taskModes.{{ $taskKey }}"
                            >
                                @foreach (\App\Modules\Core\AI\Enums\TaskModelSelectionMode::cases() as $mode)
                                    <option value="{{ $mode->value }}">{{ __($mode->label()) }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    </div>

                    @if ($taskMode === 'recommended')
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <x-ui.button wire:click="recommendTask('{{ $taskKey }}')" variant="primary">
                                <x-icon name="heroicon-o-sparkles" class="h-4 w-4" />
                                {{ __('Recommend with Lara') }}
                            </x-ui.button>

                            @if ($taskReason !== '')
                                <p class="text-sm text-muted">{{ $taskReason }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($taskMode === 'manual')
                        <div class="mt-4">
                            @include('livewire.admin.setup.partials.llm-provider-model-picker', [
                                'context' => 'task-'.$taskKey,
                                'providers' => $providers,
                                'models' => $taskModels,
                                'selectedProviderId' => $taskProviderId,
                                'providerBinding' => 'taskProviderIds.'.$taskKey,
                                'modelBinding' => 'taskModelIds.'.$taskKey,
                                'providerErrorKey' => 'taskProviderIds.'.$taskKey,
                                'modelErrorKey' => 'taskModelIds.'.$taskKey,
                            ])
                        </div>
                    @endif

                    @if ($taskReason !== '' && $taskMode !== 'recommended')
                        <p class="mt-4 text-sm text-muted">{{ $taskReason }}</p>
                    @endif

                    @if ($taskError)
                        <x-ui.alert variant="warning" class="mt-4">
                            {{ $taskError }}
                        </x-ui.alert>
                    @endif

                    <div class="mt-4">
                        <x-action-message on="task-{{ $taskKey }}-saved" class="text-xs text-status-success" />
                    </div>
                </x-ui.card>
            @endforeach
        @endunless
    </div>
</div>
