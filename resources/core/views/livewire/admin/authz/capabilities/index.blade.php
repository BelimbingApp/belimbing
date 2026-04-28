<div>
    <x-slot name="title">{{ __('Capabilities') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Capabilities')" :subtitle="__('All registered capability keys and their source modules')" />

        <x-ui.card>
            <div class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by capability or module...') }}"
                    />
                </div>
                <x-ui.select id="filter-domain" wire:model.live="filterDomain">
                    <option value="">{{ __('All Domains') }}</option>
                    @foreach($domains as $domain)
                        <option value="{{ $domain }}">{{ $domain }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="key"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('key')"
                                :label="__('Capability')"
                            />
                            <x-ui.sortable-th
                                column="domain"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('domain')"
                                :label="__('Domain')"
                            />
                            <x-ui.sortable-th
                                column="resource"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('resource')"
                                :label="__('Resource')"
                            />
                            <x-ui.sortable-th
                                column="action"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('action')"
                                :label="__('Action')"
                            />
                            <x-ui.sortable-th
                                column="module"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('module')"
                                :label="__('Module')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($capabilities as $cap)
                            <tr wire:key="cap-{{ $cap->key }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $cap->key }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $cap->domain }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $cap->resource }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $cap->action }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $cap->module }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No capabilities found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2 text-xs text-muted">
                {{ trans_choice(':count capability|:count capabilities', $capabilities->count(), ['count' => $capabilities->count()]) }}
            </div>
        </x-ui.card>
    </div>
</div>
