<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Providers\Catalog $this */
?>
<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Choose Providers')" :subtitle="__('Browse available LLM providers and select the ones you want to connect.')">
        <x-slot name="help">
            <div class="space-y-3">
                <p>{{ __('An LLM provider is a service that hosts AI models your Digital Workers use to think and respond. You need at least one provider connected before Digital Workers can function.') }}</p>

                <div>
                    <p class="font-medium text-ink">{{ __('Which provider should I choose?') }}</p>
                    <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                        <li>{{ __('Already have a GitHub Copilot subscription? Start there — it includes models from OpenAI, Anthropic, Google, and xAI at no extra per-token cost.') }}</li>
                        <li>{{ __('Need the latest models with full control? OpenAI and Anthropic offer direct API access with pay-per-token pricing.') }}</li>
                        <li>{{ __('Want to keep data on-premise? Ollama runs models locally on your own hardware for free.') }}</li>
                        <li>{{ __('Not sure? Select multiple providers now — you can disable or remove any of them later.') }}</li>
                    </ul>
                </div>

                <div>
                    <p class="font-medium text-ink">{{ __('How to use this page') }}</p>
                    <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                        <li>{{ __('Tap a row to expand it and compare models, context windows, and pricing.') }}</li>
                        <li>{{ __('Check the box next to each provider you want, then click "Connect Providers".') }}</li>
                        <li>{{ __('On the next step you\'ll enter your API key (or log in for GitHub Copilot).') }}</li>
                    </ul>
                </div>
            </div>
        </x-slot>
        <x-slot name="actions">
            @if($hasProviders)
                <x-ui.button variant="ghost" wire:click="cancelWizard">
                    {{ __('Cancel') }}
                </x-ui.button>
            @endif
            <x-ui.button
                variant="primary"
                wire:click="proceedToConnect"
                :disabled="$selectedTemplates === []"
            >
                <x-icon name="heroicon-m-sparkles" class="w-4 h-4" />
                {{ $selectedTemplates === [] ? __('Connect Providers') : __('Connect Providers (:count)', ['count' => count($selectedTemplates)]) }}
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

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

        <div class="overflow-x-auto -mx-card-inner px-card-inner">
            <table class="min-w-full divide-y divide-border-default text-sm">
                <thead class="bg-surface-subtle/80">
                    <tr>
                        <th class="px-table-cell-x py-table-header-y w-8"></th>
                        <th class="px-table-cell-x py-table-header-y w-8"></th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Provider') }}</th>
                        <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                        <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Models') }}</th>
                        <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost $/1M') }}</th>
                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-surface-card divide-y divide-border-default">
                    @foreach($catalog as $entry)
                        <tr
                            wire:key="catalog-{{ $entry['key'] }}"
                            wire:click="toggleCatalogProvider('{{ $entry['key'] }}')"
                            class="hover:bg-surface-subtle/50 transition-colors cursor-pointer"
                            x-show="matchesSearch('{{ mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? '')) }}') && matchesFilters({{ json_encode($entry['category']) }}, {{ json_encode($entry['region']) }})"
                        >
                            <td class="px-table-cell-x py-table-cell-y" wire:click.stop>
                                @if($entry['connected'])
                                    <span class="w-4 h-4 block"></span>
                                @else
                                    <input
                                        type="checkbox"
                                        class="w-4 h-4 rounded border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                        @checked(in_array($entry['key'], $selectedTemplates, true))
                                        wire:click="toggleSelectTemplate('{{ $entry['key'] }}')"
                                    />
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y">
                                <x-icon
                                    :name="$expandedCatalogProvider === $entry['key'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                    class="w-4 h-4 text-muted"
                                />
                            </td>
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                <div class="flex items-center gap-1">
                                    <span>{{ $entry['display_name'] }}</span>
                                    <x-ui.help
                                        wire:click.stop="openProviderHelp('{{ $entry['key'] }}', '{{ $entry['auth_type'] ?? 'api_key' }}')"
                                        title="{{ __('Setup & troubleshooting') }}"
                                    />
                                </div>
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
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                @if($entry['connected'])
                                    <x-ui.badge variant="success">{{ __('Connected') }}</x-ui.badge>
                                @endif
                            </td>
                        </tr>

                        {{-- Provider help panel (inline, like page-header help) --}}
                        @if($helpProviderKey === $entry['key'])
                            <x-ai.provider-help-panel
                                wire:key="catalog-{{ $entry['key'] }}-help"
                                :provider-name="$entry['display_name']"
                                :help="$this->activeProviderHelp()"
                                :colspan="7"
                                x-show="matchesSearch('{{ mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? '')) }}') && matchesFilters({{ json_encode($entry['category']) }}, {{ json_encode($entry['region']) }})"
                            />
                        @endif

                        {{-- Expanded model catalog --}}
                        @if($expandedCatalogProvider === $entry['key'] && count($entry['models']) > 0)
                            <tr wire:key="catalog-{{ $entry['key'] }}-models"
                                x-show="matchesSearch('{{ mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? '')) }}') && matchesFilters({{ json_encode($entry['category']) }}, {{ json_encode($entry['region']) }})"
                            >
                                <td colspan="7" class="p-0">
                                    <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                        <span class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2 block">{{ __('Model Catalog') }}</span>
                                        <table class="min-w-full divide-y divide-border-default text-sm">
                                            <thead class="bg-surface-subtle/80">
                                                <tr>
                                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Context') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Max Output') }}</th>
                                                    <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Input $/1M') }}</th>
                                                    <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Output $/1M') }}</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody class="bg-surface-card divide-y divide-border-default">
                                                    @foreach($entry['models'] as $catModel)
                                                    <tr class="hover:bg-surface-subtle/50 transition-colors">
                                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">{{ $catModel['display_name'] }}</td>
                                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatTokenCount($catModel['context_window'] ?? null) }}</td>
                                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatTokenCount($catModel['max_tokens'] ?? null) }}</td>
                                                        @php $cost = $catModel['cost'] ?? []; @endphp
                                                        <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['input'] ?? null) }}</td>
                                                        <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['output'] ?? null) }}</td>
                                                        </tr>
                                                        @endforeach
                                                        </tbody>
                                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @elseif($expandedCatalogProvider === $entry['key'] && count($entry['models']) === 0)
                            <tr wire:key="catalog-{{ $entry['key'] }}-empty">
                                <td colspan="7" class="p-0">
                                    <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                        <p class="text-sm text-muted py-2 text-center">{{ __('Models are discovered dynamically after connecting. Add models manually from the management view.') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
