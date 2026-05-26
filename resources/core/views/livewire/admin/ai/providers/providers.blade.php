<?php

use App\Modules\Core\AI\Livewire\Providers\Providers;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var Providers $this */
/** @var bool $laraActivated */
?>
<div>
    <x-slot name="title">{{ __('AI Providers') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('AI Providers')" :subtitle="__('Manage connected providers and their models. In each model table, use the Access column: ★ / ☆ for the provider default, the checkbox to offer or withhold a model from Agents, and sliders for optional execution overrides.')">
            <x-slot name="help">
                <div class="space-y-3">
                    <p>{{ __('This page shows the LLM providers and models your organization has connected. Agents use these models to think, reason, and respond — at least one active provider with one active model is required.') }}</p>

                    <div>
                        <p class="font-medium text-ink">{{ __('Priority') }}</p>
                        <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                            <li>{{ __('Priority decides which provider/model is the default — the highest-priority active provider with a default model wins.') }}</li>
                            <li>{{ __('Lower numbers mean higher priority — provider #1 is the default.') }}</li>
                            <li>{{ __('Click the ↑ arrow to move a provider up one position.') }}</li>
                            <li>{{ __('Priority is not a runtime fallback chain. When the chosen provider fails, the failure is surfaced honestly — pick another model in the chat or fix the upstream issue.') }}</li>
                        </ul>
                    </div>

                    @include('livewire.admin.ai.providers.partials.model-help')

                    <p>{!! __('Once providers and models are set up here, configure Lara and her task models from the :link.', ['link' => '<a href="' . route('admin.setup.lara') . '" class="text-accent hover:underline">' . e(__('Lara setup')) . '</a>']) !!}</p>
                </div>
            </x-slot>
            <x-slot name="actions">
                <x-ui.button variant="ghost" wire:click="openCreateProvider">
                    <x-icon name="heroicon-m-plus" class="w-4 h-4" />
                    {{ __('Manual Add') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (! $laraActivated)
            <x-ui.alert variant="info">
                <p>{{ __('Lara stays inactive until one connected provider has an active model available to Agents.') }}</p>
                <p class="mt-2">
                    {!! __('Connect a provider below and enable at least one model. If Lara still needs provisioning afterward, finish it on the :link page.', ['link' => '<a href="' . route('admin.setup.lara') . '" wire:navigate class="text-accent hover:underline">' . e(__('Lara')) . '</a>']) !!}
                </p>
            </x-ui.alert>
        @endif

        {{-- ═══════════════════════════════════════════════════
             Section 1: Connected Providers (management)
             ═══════════════════════════════════════════════════ --}}
        @if($providers->isNotEmpty())
            <x-ui.card>
                <x-ui.table container="flush" :caption="__('Connected providers')">
                    <x-slot name="head">
                            <tr>
                                <x-ui.th class="w-8"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                                <x-ui.th class="hidden md:table-cell">{{ __('Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Display Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Priority') }}</x-ui.th>
                                <x-ui.th class="hidden md:table-cell">{{ __('Base URL') }}</x-ui.th>
                                <x-ui.th>{{ __('Models') }}</x-ui.th>
                                <x-ui.th>{{ __('Status') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                            </tr>
                        </x-slot>

                            @foreach($providers as $provider)
                                <tr
                                    wire:key="provider-{{ $provider->id }}"
                                    wire:click="toggleProvider({{ $provider->id }})"
                                    x-data="{ flash: false }"
                                    x-init="$wire.$on('priority-changed', (detail) => { const id = Array.isArray(detail) ? detail[0] : detail; if (id == {{ $provider->id }}) { flash = true; setTimeout(() => flash = false, 1800) } })"
                                    :class="flash ? 'bg-accent/10' : ''"
                                    class="cursor-pointer duration-800"
                                >
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-icon
                                            :name="$expandedProviderId === $provider->id ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                            class="w-4 h-4 text-muted"
                                        />
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                        @include('livewire.admin.ai.providers.partials.provider-help-trigger', [
                                            'label' => $provider->name,
                                            'providerKey' => $provider->name,
                                            'authType' => $provider->auth_type->value,
                                            'scope' => 'connected',
                                        ])
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
                                        <x-ui.icon-action-group>
                                            <x-ui.icon-action
                                                icon="heroicon-o-pencil"
                                                :label="__('Edit provider')"
                                                :title="__('Edit')"
                                                wire:click.stop="openEditProvider({{ $provider->id }})"
                                            />
                                            <x-ui.icon-action
                                                icon="heroicon-o-link-slash"
                                                :label="__('Disconnect provider')"
                                                :title="__('Disconnect')"
                                                wire:click.stop="confirmDeleteProvider({{ $provider->id }})"
                                            />
                                        </x-ui.icon-action-group>
                                    </td>
                                </tr>

                                {{-- Provider help panel --}}
                                @include('livewire.admin.ai.providers.partials.provider-help-inline-panel', [
                                    'matchKey' => $provider->name,
                                    'scope' => 'connected',
                                    'wireKey' => 'provider-'.$provider->id.'-help',
                                    'providerName' => $provider->display_name,
                                    'colspan' => 8,
                                    'visibilityExpression' => null,
                                ])

                                {{-- Expanded models sub-table --}}
                                @if($expandedProviderId === $provider->id)
                                    <tr wire:key="provider-{{ $provider->id }}-models">
                                        <td colspan="8" class="p-0">
                                            @include('livewire.admin.ai.providers.partials.model-table', [
                                                'provider' => $provider,
                                                'models' => $expandedModels,
                                            ])
                                        </td>
                                    </tr>
                                @endif
                            @endforeach

                </x-ui.table>
            </x-ui.card>
        @endif

        {{-- ═══════════════════════════════════════════════════
             Section 2: Provider Catalog (discovery)
             ═══════════════════════════════════════════════════ --}}
        <x-ui.card x-data="{
            catalogSearch: '',
            selectedCategories: [],
            selectedRegions: [],
            categoryOpen: false,
            regionOpen: false,
            toggleCategory(cat) {
                const idx = this.selectedCategories.indexOf(cat);
                idx === -1 ? this.selectedCategories.push(cat) : this.selectedCategories.splice(idx, 1);
            },
            toggleRegion(reg) {
                const idx = this.selectedRegions.indexOf(reg);
                idx === -1 ? this.selectedRegions.push(reg) : this.selectedRegions.splice(idx, 1);
            },
            matchesFilters(categories, regions) {
                const catMatch = this.selectedCategories.length === 0 || categories.some(c => this.selectedCategories.includes(c));
                const regMatch = this.selectedRegions.length === 0 || regions.some(r => this.selectedRegions.includes(r));
                return catMatch && regMatch;
            },
            matchesSearch(text) {
                return this.catalogSearch === '' || text.includes(this.catalogSearch.toLowerCase());
            },
            categoryLabels: {
                'cloud-provider': '{{ __('Cloud Provider') }}',
                'developer-tool': '{{ __('Developer Tool') }}',
                'gateway': '{{ __('Gateway') }}',
                'inference-platform': '{{ __('Inference Platform') }}',
                'leading-lab': '{{ __('Leading Lab') }}',
                'local': '{{ __('Local') }}',
                'specialized': '{{ __('Specialized') }}',
            },
            regionLabels: {
                'china': '{{ __('China') }}',
                'europe': '{{ __('Europe') }}',
                'global': '{{ __('Global') }}',
            },
        }">
            <div class="mb-2">
                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Add a Provider') }}</span>
            </div>

            <div class="mb-2 flex flex-col sm:flex-row gap-2">
                <div class="flex-1">
                    <x-ui.search-input
                        x-model.debounce.200ms="catalogSearch"
                        placeholder="{{ __('Search providers...') }}"
                    />
                </div>

                {{-- Category filter --}}
                <div class="relative" @click.outside="categoryOpen = false">
                    <button
                        type="button"
                        @click="categoryOpen = !categoryOpen"
                        class="inline-flex items-center gap-1.5 px-3 py-input-y text-sm border border-border-input rounded-2xl bg-surface-card text-ink hover:bg-surface-subtle/50 transition-colors whitespace-nowrap"
                    >
                        <x-icon name="heroicon-o-funnel" class="w-4 h-4 text-muted" />
                        {{ __('Category') }}
                        <template x-if="selectedCategories.length > 0">
                            <span class="inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold rounded-full bg-accent text-on-accent" x-text="selectedCategories.length"></span>
                        </template>
                        <x-icon name="heroicon-m-chevron-down" class="w-3.5 h-3.5 text-muted" />
                    </button>
                    <div
                        x-show="categoryOpen"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute z-20 mt-1 w-56 rounded-xl border border-border-default bg-surface-card shadow-lg py-1"
                    >
                        @foreach($categoryOptions as $cat)
                            <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-ink hover:bg-surface-subtle/50 cursor-pointer">
                                <input type="checkbox" :checked="selectedCategories.includes('{{ $cat }}')" @click="toggleCategory('{{ $cat }}')" class="w-4 h-4 rounded border border-border-input accent-accent" />
                                <span x-text="categoryLabels['{{ $cat }}'] || '{{ $cat }}'">{{ $cat }}</span>
                            </label>
                        @endforeach
                        <template x-if="selectedCategories.length > 0">
                            <div class="border-t border-border-default mt-1 pt-1 px-3 pb-1">
                                <button type="button" @click="selectedCategories = []" class="text-xs text-accent hover:text-accent/80">{{ __('Clear') }}</button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Region filter --}}
                <div class="relative" @click.outside="regionOpen = false">
                    <button
                        type="button"
                        @click="regionOpen = !regionOpen"
                        class="inline-flex items-center gap-1.5 px-3 py-input-y text-sm border border-border-input rounded-2xl bg-surface-card text-ink hover:bg-surface-subtle/50 transition-colors whitespace-nowrap"
                    >
                        <x-icon name="heroicon-o-globe-alt" class="w-4 h-4 text-muted" />
                        {{ __('Region') }}
                        <template x-if="selectedRegions.length > 0">
                            <span class="inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold rounded-full bg-accent text-on-accent" x-text="selectedRegions.length"></span>
                        </template>
                        <x-icon name="heroicon-m-chevron-down" class="w-3.5 h-3.5 text-muted" />
                    </button>
                    <div
                        x-show="regionOpen"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute z-20 mt-1 w-44 rounded-xl border border-border-default bg-surface-card shadow-lg py-1"
                    >
                        @foreach($regionOptions as $reg)
                            <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-ink hover:bg-surface-subtle/50 cursor-pointer">
                                <input type="checkbox" :checked="selectedRegions.includes('{{ $reg }}')" @click="toggleRegion('{{ $reg }}')" class="w-4 h-4 rounded border border-border-input accent-accent" />
                                <span x-text="regionLabels['{{ $reg }}'] || '{{ $reg }}'">{{ $reg }}</span>
                            </label>
                        @endforeach
                        <template x-if="selectedRegions.length > 0">
                            <div class="border-t border-border-default mt-1 pt-1 px-3 pb-1">
                                <button type="button" @click="selectedRegions = []" class="text-xs text-accent hover:text-accent/80">{{ __('Clear') }}</button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('Provider catalog')">

                <x-slot name="head">
                        <tr>
                            <x-ui.th class="w-8"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                            <x-ui.th>{{ __('Provider') }}</x-ui.th>
                            <x-ui.th class="hidden md:table-cell">{{ __('Description') }}</x-ui.th>
                            <x-ui.th align="right" class="hidden md:table-cell">{{ __('Models') }}</x-ui.th>
                            <x-ui.th align="right" class="hidden md:table-cell">{{ __('Cost $/1M') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Status') }}</x-ui.th>
                        </tr>
                    </x-slot>

                        @foreach($catalog as $entry)
                            @php
                                $catalogSearchText = mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? ''));
                                $catalogVisibility = 'matchesSearch('.\Illuminate\Support\Js::from($catalogSearchText).') && matchesFilters('.\Illuminate\Support\Js::from($entry['category']).', '.\Illuminate\Support\Js::from($entry['region']).')';
                            @endphp
                            <tr
                                wire:key="catalog-{{ $entry['key'] }}"
                                wire:click="toggleCatalogProvider('{{ $entry['key'] }}')" class="cursor-pointer"
                                x-show="{{ $catalogVisibility }}"
                            >
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-icon
                                        :name="$expandedCatalogProvider === $entry['key'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                        class="w-4 h-4 text-muted"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                    @include('livewire.admin.ai.providers.partials.provider-help-trigger', [
                                        'label' => $entry['display_name'],
                                        'providerKey' => $entry['key'],
                                        'authType' => $entry['auth_type'] ?? 'api_key',
                                        'scope' => 'catalog',
                                    ])
                                </td>
                                <td class="hidden md:table-cell px-table-cell-x py-table-cell-y text-sm text-muted">{{ $entry['description'] }}</td>
                                <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $entry['model_count'] ?: '—' }}</td>
                                <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">
                                    @if(is_array($entry['cost_range'] ?? null))
                                        {{ $this->formatCost((string) $entry['cost_range']['min']) }}–{{ $this->formatCost((string) $entry['cost_range']['max']) }}
                                    @elseif(($entry['cost_range'] ?? null) !== null)
                                        {{ $this->formatCost((string) $entry['cost_range']) }}
                                    @elseif($entry['model_count'] > 0)
                                        {{ __('Subscription') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right" @click.stop>
                                    @if($entry['connected'])
                                        <x-ui.badge variant="success">{{ __('Connected') }}</x-ui.badge>
                                    @else
                                        <x-ui.button variant="primary" size="sm" wire:click="connectProvider('{{ $entry['key'] }}')">
                                            {{ __('Connect') }}
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>

                            {{-- Provider help panel (inline) --}}
                            @include('livewire.admin.ai.providers.partials.provider-help-inline-panel', [
                                'matchKey' => $entry['key'],
                                'scope' => 'catalog',
                                'wireKey' => 'catalog-'.$entry['key'].'-help',
                                'providerName' => $entry['display_name'],
                                'colspan' => 6,
                                'visibilityExpression' => $catalogVisibility,
                            ])

                            {{-- Expanded model catalog --}}
                            @if($expandedCatalogProvider === $entry['key'] && count($entry['models']) > 0)
                                <tr wire:key="catalog-{{ $entry['key'] }}-models"
                                    x-show="{{ $catalogVisibility }}"
                                >
                                    <td colspan="6" class="p-0">
                                        <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2 block">{{ __('Model Catalog') }}</span>
                                            <x-ui.table container="plain" :caption="__('Model catalog')">
                                                <x-slot name="head">
                                                    <tr>
                                                        <x-ui.th>{{ __('Model') }}</x-ui.th>
                                                        <x-ui.th align="right">{{ __('Context') }}</x-ui.th>
                                                        <x-ui.th align="right">{{ __('Max Output') }}</x-ui.th>
                                                        <x-ui.th align="right" class="hidden lg:table-cell">{{ __('Input $/1M') }}</x-ui.th>
                                                        <x-ui.th align="right" class="hidden lg:table-cell">{{ __('Output $/1M') }}</x-ui.th>
                                                    </tr>
                                                </x-slot>

                                                    @foreach($entry['models'] as $catModel)
                                                        <tr>
                                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">{{ $catModel['display_name'] }}</td>
                                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatTokenCount($catModel['context_window'] ?? null) }}</td>
                                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatTokenCount($catModel['max_tokens'] ?? null) }}</td>
                                                            @php $catCost = $catModel['cost'] ?? []; @endphp
                                                            <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($catCost['input'] ?? null) }}</td>
                                                            <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($catCost['output'] ?? null) }}</td>
                                                        </tr>
                                                    @endforeach
                                            </x-ui.table>
                                        </div>
                                    </td>
                                </tr>
                            @elseif($expandedCatalogProvider === $entry['key'] && count($entry['models']) === 0)
                                <tr wire:key="catalog-{{ $entry['key'] }}-empty">
                                    <td colspan="6" class="p-0">
                                        <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                            <p class="text-sm text-muted py-2 text-center">{{ __('Models are discovered dynamically after connecting.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    
            </x-ui.table>
        </x-ui.card>

        {{-- Empty state: shown when no providers connected and catalog is the only section --}}
        @if($providers->isEmpty())
            <div class="text-center py-2">
                <p class="text-sm text-muted">{{ __('No providers connected yet. Browse the catalog above and click "Connect" to get started.') }}</p>
                @if (! $laraActivated)
                    <p class="text-xs text-muted mt-2">
                        {{ __('Once you\'ve connected a provider,') }}
                        <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">{{ __('activate Lara') }}</a>
                        {{ __('to start chatting.') }}
                    </p>
                @endif
            </div>
        @endif
    </div>

    {{-- Provider Create/Edit Modal (manual add) --}}
    <x-ui.modal wire:model="showProviderForm" class="max-w-lg">
        <div @class([
            'p-card-inner',
            // Keep modal height stable across tabs (General is taller than Advanced).
            // This must be >= the General tab height, otherwise switching to Advanced will shrink.
            'min-h-[30rem]' => $isEditingProvider && ($this->advancedSettingsSchema ?? []) !== [],
        ])>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">
                    {{ $isEditingProvider ? __('Edit Provider') : __('Add Provider') }}
                </h3>
                <button wire:click="$set('showProviderForm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            @php
                $advancedAvailable = $isEditingProvider && ($this->advancedSettingsSchema ?? []) !== [];
            @endphp

            @if($advancedAvailable)
                <x-ui.tabs :tabs="[
                    ['id' => 'general', 'label' => __('General')],
                    ['id' => 'advanced', 'label' => __('Advanced')],
                ]" default="general" persistence="none">
                    <x-ui.tab id="general">
                        @include('livewire.admin.ai.providers.partials.provider-form')
                    </x-ui.tab>

                    <x-ui.tab id="advanced">
                        <div class="space-y-4">
                            @foreach(($this->advancedSettingsSchema ?? []) as $setting)
                                @php
                                    $stateKey = $setting['state_key'] ?? '';
                                    $label = $setting['label'] ?? $stateKey;
                                    $help = $setting['help'] ?? null;
                                    $type = $setting['input_type'] ?? 'text';
                                @endphp

                                @if(is_string($stateKey) && $stateKey !== '')
                                    <div>
                                        <x-ui.input
                                            :id="'provider-advanced-'.$stateKey"
                                            wire:model="advancedSettings.{{ $stateKey }}"
                                            :type="$type"
                                            :label="$label"
                                            :error="$errors->first('advancedSettings.'.$stateKey)"
                                        />
                                        @if(is_string($help) && $help !== '')
                                            <p class="text-xs text-muted mt-1">{{ $help }}</p>
                                        @endif
                                    </div>
                                @endif
                            @endforeach

                            <div class="flex justify-end gap-2 pt-2">
                                <x-ui.button
                                    variant="ghost"
                                    type="button"
                                    wire:click="resetAdvancedSettings"
                                >
                                    {{ __('Reset to default') }}
                                </x-ui.button>
                                <x-ui.button
                                    variant="primary"
                                    type="button"
                                    wire:click="saveAdvancedSettings"
                                >
                                    {{ __('Save') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </x-ui.tab>
                </x-ui.tabs>
            @else
                @include('livewire.admin.ai.providers.partials.provider-form')
            @endif
        </div>
    </x-ui.modal>

    {{-- Provider Disconnect Confirmation --}}
    <x-ui.modal wire:model="showDeleteProvider" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Disconnect Provider') }}</h3>
                <button wire:click="$set('showDeleteProvider', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">
                {{ __('Are you sure you want to disconnect :name? All associated models will also be removed.', ['name' => $deletingProviderName]) }}
            </p>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('showDeleteProvider', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteProvider">{{ __('Disconnect') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Model Add Modal (simplified — no cost fields, no edit) --}}
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
                    id="providers-model-name"
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

    {{-- Per-model execution controls editor --}}
    @php
        $editingControlsSchema = $editingControlsModelId !== null ? $this->editingModelExecutionControlSchema() : null;
    @endphp
    <x-ui.modal wire:model="showExecutionControlsModal" class="max-w-2xl">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Execution Controls') }}</h3>
                    @if (is_array($editingControlsSchema))
                        <p class="text-xs text-muted mt-0.5 font-mono">{{ ($editingControlsSchema['provider_name'] ?? '—') . '/' . ($editingControlsSchema['model'] ?? '—') }}</p>
                    @endif
                </div>
                <button wire:click="closeModelExecutionControls" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            @if (is_array($editingControlsSchema))
                @include('livewire.admin.ai.partials.execution-controls', [
                    'schema' => $editingControlsSchema,
                    'statePath' => 'editingExecutionControls',
                    'subtitle' => __('Per-model defaults. Saved automatically as you change values; agents using this model inherit these settings unless a session override is set.'),
                ])
            @else
                <p class="text-sm text-muted">{{ __('No editable controls available for this model.') }}</p>
            @endif

            <div class="mt-6 flex justify-between gap-2">
                <x-ui.button variant="ghost" wire:click="clearModelExecutionControls" class="text-red-500 hover:text-red-600">
                    <x-icon name="heroicon-o-arrow-uturn-left" class="w-3.5 h-3.5" />
                    {{ __('Reset to system defaults') }}
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="closeModelExecutionControls">{{ __('Done') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
