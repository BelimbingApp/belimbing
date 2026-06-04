<?php

use App\Modules\Core\AI\Livewire\Providers\CatalogBrowser;

/** @var CatalogBrowser $this */
/** @var array $catalog */
/** @var array $categoryOptions */
/** @var array $regionOptions */
?>
{{-- ═══════════════════════════════════════════════════
     Provider Catalog (discovery) — lazy island.
     Rendered after first paint so the ~100-row models.dev
     catalog never blocks the connected-providers view.
     ═══════════════════════════════════════════════════ --}}
<div>
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
</div>
