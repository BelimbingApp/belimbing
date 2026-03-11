<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\Connections $this */
/** @var bool $laraActivated */
?>
<div>
    <x-slot name="title">{{ __('Provider Connections') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Provider Connections')" :subtitle="__('Manage AI providers, their models, and priority order. Higher-priority providers are tried first.')">
            <x-slot name="help">
                <div class="space-y-3">
                    <p>{{ __('This page shows the LLM providers and models your organization has connected. Digital Workers use these models to think, reason, and respond — at least one active provider with one active model is required.') }}</p>

                    <div>
                        <p class="font-medium text-ink">{{ __('Priority') }}</p>
                        <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                            <li>{{ __('The Priority column shows the order in which providers are tried when a Digital Worker needs a model.') }}</li>
                            <li>{{ __('Lower numbers mean higher priority — provider #1 is tried first.') }}</li>
                            <li>{{ __('Click the ↑ arrow to move a provider up one position.') }}</li>
                            <li>{{ __('If the top-priority provider fails or is unavailable, the system automatically falls back to the next one.') }}</li>
                        </ul>
                    </div>

                    <div>
                        <p class="font-medium text-ink">{{ __('Default model') }}</p>
                        <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                            <li>{{ __('Each provider has a default model, marked with a') }} <span class="text-accent">★</span> {{ __('star icon.') }}</li>
                            <li>{{ __('The default model is used as the fallback when a Digital Worker does not specify a particular model.') }}</li>
                            <li>{{ __('Click the ☆ next to a model to set it as the default. The current default is marked with') }} <span class="text-accent">★</span>.</li>
                        </ul>
                    </div>

                    <div>
                        <p class="font-medium text-ink">{{ __('Costs & billing') }}</p>
                        <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                            <li>{{ __('API providers (OpenAI, Anthropic, etc.) bill per token used — costs are shown per 1M tokens.') }}</li>
                            <li>{{ __('Subscription providers (GitHub Copilot) are included in your subscription at no extra per-token cost.') }}</li>
                            <li>{{ __('Local providers (Ollama, vLLM) run on your own hardware and have no API fees.') }}</li>
                        </ul>
                    </div>

                    <p>{!! __('Once providers and models are set up here, assign them to Digital Workers from the :link.', ['link' => '<a href="' . route('admin.ai.playground') . '" class="text-accent hover:underline">' . e(__('AI Playground')) . '</a>']) !!}</p>
                </div>
            </x-slot>
            <x-slot name="actions">
                <x-ui.button variant="ghost" wire:click="openCreateProvider">
                    <x-icon name="heroicon-m-plus" class="w-4 h-4" />
                    {{ __('Manual Add') }}
                </x-ui.button>
                <x-ui.button variant="primary" href="{{ route('admin.ai.providers.browse') }}" wire:navigate>
                    <x-icon name="heroicon-m-sparkles" class="w-4 h-4" />
                    {{ __('Browse AI Providers') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y w-8"></th>
                            <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Display Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</th>
                            <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Base URL') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Models') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($providers as $provider)
                            <tr
                                wire:key="provider-{{ $provider->id }}"
                                wire:click="toggleProvider({{ $provider->id }})"
                                class="hover:bg-surface-subtle/50 transition-colors cursor-pointer"
                            >
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-icon
                                        :name="$expandedProviderId === $provider->id ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                        class="w-4 h-4 text-muted"
                                    />
                                </td>
                                <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                    <div class="flex items-center gap-1">
                                        <span>{{ $provider->name }}</span>
                                        <x-ui.help
                                            wire:click.stop="openProviderHelp('{{ $provider->name }}', '{{ $provider->auth_type ?? 'api_key' }}')"
                                            title="{{ __('Setup & troubleshooting') }}"
                                        />
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $provider->display_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-left" @click.stop>
                                    <div class="inline-flex items-center gap-1">
                                        <span class="text-xs text-muted tabular-nums">{{ $provider->priority }}</span>
                                        @if($provider->priority > 1)
                                            <button
                                                wire:click="movePriorityUp({{ $provider->id }})"
                                                class="text-muted hover:text-ink hover:bg-surface-subtle p-0.5 rounded transition-colors"
                                                type="button"
                                                title="{{ __('Move up') }}"
                                                aria-label="{{ __('Move up') }}"
                                            >
                                                <x-icon name="heroicon-m-arrow-up" class="w-3.5 h-3.5" />
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-xs text-muted font-mono truncate max-w-[200px]">{{ $provider->base_url }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $provider->models_count }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        @if($provider->is_active)
                                            <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            wire:click.stop="openEditProvider({{ $provider->id }})"
                                            class="text-accent hover:bg-surface-subtle p-1 rounded"
                                            type="button"
                                            title="{{ __('Edit') }}"
                                            aria-label="{{ __('Edit provider') }}"
                                        >
                                            <x-icon name="heroicon-o-pencil" class="w-4 h-4" />
                                        </button>
                                        <button
                                            wire:click.stop="confirmDeleteProvider({{ $provider->id }})"
                                            class="text-accent hover:bg-surface-subtle p-1 rounded"
                                            type="button"
                                            title="{{ __('Delete') }}"
                                            aria-label="{{ __('Delete provider') }}"
                                        >
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            {{-- Provider help panel --}}
                            @if($helpProviderKey === $provider->name)
                                <x-ai.provider-help-panel
                                    wire:key="provider-{{ $provider->id }}-help"
                                    :provider-name="$provider->display_name"
                                    :help="$this->activeProviderHelp()"
                                    :colspan="8"
                                />
                            @endif

                            {{-- Expanded models sub-table --}}
                            @if($expandedProviderId === $provider->id)
                                <tr wire:key="provider-{{ $provider->id }}-models">
                                    <td colspan="8" class="p-0">
                                        <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                           <div class="flex items-center justify-between mb-2">
                                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Models') }}</span>
                                                <div class="flex items-center gap-1">
                                                    <x-ui.button variant="ghost" size="sm" wire:click.stop="syncProviderModels({{ $provider->id }})">
                                                        <x-icon name="heroicon-o-arrow-path" class="w-3.5 h-3.5" />
                                                        {{ __('Update Models') }}
                                                    </x-ui.button>
                                                    <x-ui.button variant="ghost" size="sm" wire:click.stop="openCreateModel({{ $provider->id }})">
                                                        <x-icon name="heroicon-o-plus" class="w-3.5 h-3.5" />
                                                        {{ __('Add Model') }}
                                                    </x-ui.button>
                                                </div>
                                            </div>

                                           @if($syncMessage)
                                                <div
                                                    class="mb-2 px-3 py-1.5 bg-surface-subtle rounded text-sm text-muted"
                                                    x-data="{ show: true }"
                                                    x-init="setTimeout(() => { show = false; $wire.set('syncMessage', null) }, 4000)"
                                                    x-show="show"
                                                    x-transition.opacity
                                                >
                                                    {{ $syncMessage }}
                                                </div>
                                            @endif

                                            @if($syncError && $syncErrorProviderId === $provider->id)
                                                @php $helpAdvice = app(\App\Base\AI\Providers\Help\ProviderHelpRegistry::class)->get($provider->name, $provider->auth_type ?? 'api_key')->connectionErrorAdvice(); @endphp
                                                <div class="mb-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <div class="flex items-start gap-2 min-w-0">
                                                            <x-icon name="heroicon-o-exclamation-circle" class="w-4 h-4 text-red-500 dark:text-red-400 mt-0.5 shrink-0" />
                                                            <div class="min-w-0">
                                                                <p class="text-sm text-red-700 dark:text-red-300 font-medium">{{ $syncError }}</p>
                                                                <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ $helpAdvice }}</p>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-1 shrink-0">
                                                            <button
                                                                type="button"
                                                                wire:click.stop="openProviderHelp('{{ $provider->name }}', '{{ $provider->auth_type ?? 'api_key' }}')"
                                                                class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200 underline whitespace-nowrap"
                                                            >
                                                                {{ __('Get help') }}
                                                            </button>
                                                            <button
                                                                wire:click.stop="clearSyncError"
                                                                class="p-0.5 rounded text-red-400 hover:text-red-600 dark:hover:text-red-200 hover:bg-red-100 dark:hover:bg-red-800/50"
                                                                type="button"
                                                                title="{{ __('Dismiss') }}"
                                                                aria-label="{{ __('Dismiss error') }}"
                                                            >
                                                                <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif

                                            @if($expandedModels->count() > 0)
                                                <table class="min-w-full divide-y divide-border-default text-sm">
                                                     <thead class="bg-surface-subtle/80">
                                                        <tr>
                                                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model ID') }}</th>
                                                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost Override Input $/1M') }}</th>
                                                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost Override Output $/1M') }}</th>
                                                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cache Read $/1M') }}</th>
                                                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cache Write $/1M') }}</th>
                                                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="bg-surface-card divide-y divide-border-default">
                                                        @foreach($expandedModels as $model)
                                                            <tr wire:key="model-{{ $model->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink font-mono">
                                                                    <div class="flex items-center gap-1.5">
                                                                        @if($model->is_default)
                                                                            <span class="text-accent" title="{{ __('Default model') }}" aria-label="{{ __('Default model') }}">★</span>
                                                                        @else
                                                                            <button
                                                                                wire:click="setDefaultModel({{ $model->id }})"
                                                                                class="text-muted hover:text-accent transition-colors"
                                                                                type="button"
                                                                                title="{{ __('Set as default') }}"
                                                                                aria-label="{{ __('Set as default model') }}"
                                                                            >☆</button>
                                                                        @endif
                                                                        <span>{{ $model->model_id }}</span>
                                                                    </div>
                                                                </td>
                                                                @php $cost = $model->cost_override ?? []; @endphp
                                                                <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['input'] ?? null) }}</td>
                                                                <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['output'] ?? null) }}</td>
                                                                <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['cache_read'] ?? null) }}</td>
                                                                <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['cache_write'] ?? null) }}</td>
                                                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                                    @if($model->is_active)
                                                                        <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                                                    @else
                                                                        <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                                                    @endif
                                                                </td>
                                                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                                                    <div class="flex items-center justify-end gap-1">
                                                                        <button wire:click="openEditModel({{ $model->id }})" class="text-accent hover:bg-surface-subtle p-1 rounded" type="button" title="{{ __('Edit') }}" aria-label="{{ __('Edit model') }}">
                                                                            <x-icon name="heroicon-o-pencil" class="w-4 h-4" />
                                                                        </button>
                                                                        <button wire:click="confirmDeleteModel({{ $model->id }})" class="text-accent hover:bg-surface-subtle p-1 rounded" type="button" title="{{ __('Delete') }}" aria-label="{{ __('Delete model') }}">
                                                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @else
                                                <p class="text-sm text-muted py-4 text-center">{{ __('No models registered for this provider.') }}</p>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center">
                                    <div class="space-y-2">
                                        <p class="text-sm text-muted">{{ __('No providers connected yet.') }}</p>
                                        <x-ui.button variant="primary" href="{{ route('admin.ai.providers.browse') }}" wire:navigate>
                                            <x-icon name="heroicon-m-sparkles" class="w-4 h-4" />
                                            {{ __('Browse AI Providers') }}
                                        </x-ui.button>
                                        @if (! $laraActivated)
                                            <p class="text-xs text-muted mt-2">
                                                {{ __('Once you\'ve connected a provider,') }}
                                                <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">{{ __('activate Lara') }}</a>
                                                {{ __('to start chatting.') }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    {{-- Provider Create/Edit Modal (manual add) --}}
    <x-ui.modal wire:model="showProviderForm" class="max-w-lg">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">
                    {{ $isEditingProvider ? __('Edit Provider') : __('Add Provider') }}
                </h3>
                <button wire:click="$set('showProviderForm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <form wire:submit="saveProvider" class="space-y-4">
                @unless($isEditingProvider)
                    <x-ui.select wire:change="applyTemplate($event.target.value)" label="{{ __('Template') }}">
                        <option value="">{{ __('Other provider') }}</option>
                        @foreach($templateOptions as $tpl)
                            <option value="{{ $tpl['value'] }}" @selected($selectedTemplate === $tpl['value'])>{{ $tpl['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                @endunless

                <x-ui.input
                    wire:model="providerName"
                    label="{{ __('Name') }}"
                    required
                    placeholder="{{ __('e.g. openai') }}"
                    :disabled="$isEditingProvider"
                    :error="$errors->first('providerName')"
                />

                <x-ui.input
                    wire:model="providerDisplayName"
                    label="{{ __('Display Name') }}"
                    placeholder="{{ __('e.g. OpenAI') }}"
                    :error="$errors->first('providerDisplayName')"
                />

                <x-ui.input
                    wire:model="providerBaseUrl"
                    label="{{ __('Base URL') }}"
                    required
                    placeholder="{{ __('e.g. https://api.openai.com/v1') }}"
                    :error="$errors->first('providerBaseUrl')"
                />

                <x-ui.input
                    wire:model="providerApiKey"
                    type="password"
                    label="{{ __('API Key') }}"
                    :required="!$isEditingProvider"
                    :placeholder="$isEditingProvider ? __('Leave blank to keep current key') : ''"
                    :error="$errors->first('providerApiKey')"
                />

                <x-ui.checkbox wire:model="providerIsActive" label="{{ __('Active') }}" />

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="ghost" wire:click="$set('showProviderForm', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ $isEditingProvider ? __('Update') : __('Create') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- Provider Delete Confirmation --}}
    <x-ui.modal wire:model="showDeleteProvider" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Delete Provider') }}</h3>
                <button wire:click="$set('showDeleteProvider', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">
                {{ __('Are you sure you want to delete :name? All associated models will also be removed.', ['name' => $deletingProviderName]) }}
            </p>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('showDeleteProvider', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteProvider">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Model Create/Edit Modal --}}
    <x-ui.modal wire:model="showModelForm" class="max-w-lg">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">
                    {{ $isEditingModel ? __('Edit Model') : __('Add Model') }}
                </h3>
                <button wire:click="$set('showModelForm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <form wire:submit="saveModel" class="space-y-4">
                <x-ui.input
                    wire:model="modelModelName"
                    label="{{ __('Model ID') }}"
                    required
                    placeholder="{{ __('e.g. gpt-4o') }}"
                    :disabled="$isEditingModel"
                    :error="$errors->first('modelModelName')"
                />

                <div class="grid grid-cols-2 gap-4">
                    <x-ui.input
                        wire:model="modelCostInput"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Input Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostInput')"
                    />
                    <x-ui.input
                        wire:model="modelCostOutput"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Output Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostOutput')"
                    />
                    <x-ui.input
                        wire:model="modelCostCacheRead"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Cache Read Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostCacheRead')"
                    />
                    <x-ui.input
                        wire:model="modelCostCacheWrite"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Cache Write Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostCacheWrite')"
                    />
                </div>

                <x-ui.checkbox wire:model="modelIsActive" label="{{ __('Active') }}" />

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="ghost" wire:click="$set('showModelForm', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ $isEditingModel ? __('Update') : __('Create') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- Model Delete Confirmation --}}
    <x-ui.modal wire:model="showDeleteModel" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Delete Model') }}</h3>
                <button wire:click="$set('showDeleteModel', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">
                {{ __('Are you sure you want to delete :name?', ['name' => $deletingModelName]) }}
            </p>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('showDeleteModel', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteModel">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
