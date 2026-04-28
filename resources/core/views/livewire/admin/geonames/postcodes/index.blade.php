<div>
    <x-slot name="title">{{ __('Geonames Postcodes') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Geonames Postcodes')">
            <x-slot name="actions">
                <x-ui.button
                    wire:click="{{ $showCountryPicker ? 'import' : 'toggleCountryPicker' }}"
                    wire:loading.attr="disabled"
                    wire:target="import"
                >
                    <x-icon name="heroicon-o-arrow-down-tray" class="w-5 h-5 shrink-0" />
                    @if ($showCountryPicker && count($selectedCountries) > 0)
                        <span wire:loading.remove wire:target="import">{{ __('Import') }} ({{ count($selectedCountries) }})</span>
                        <span wire:loading wire:target="import">{{ __('Importing...') }}</span>
                    @else
                        {{ __('Import') }}
                    @endif
                </x-ui.button>
                @if ($hasData)
                    <x-ui.button wire:click="update" wire:loading.attr="disabled" wire:target="update">
                        <x-icon name="heroicon-o-arrow-path" class="w-5 h-5 shrink-0" wire:loading.class="animate-spin" wire:target="update" />
                        <span wire:loading.remove wire:target="update">{{ __('Update') }}</span>
                        <span wire:loading wire:target="update">{{ __('Updating...') }}</span>
                    </x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        {{-- Country Picker --}}
        @if ($showCountryPicker)
            <x-ui.card>
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h2 class="text-sm font-semibold text-ink">{{ __('Select countries to import') }}</h2>
                        <p class="text-xs text-muted mt-0.5">{{ __('Already imported countries are marked. Use the Update button to refresh their data.') }}</p>
                    </div>
                    <button wire:click="toggleCountryPicker" class="text-muted hover:text-ink shrink-0">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <div x-data="{ countryFilter: '' }">
                    <input
                        type="text"
                        x-model="countryFilter"
                        placeholder="{{ __('Search countries...') }}"
                        class="w-full mb-2 px-3 py-1.5 text-sm border border-border-input rounded-2xl bg-surface-card text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent"
                    />
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-1 max-h-64 overflow-y-auto">
                        @foreach ($allCountries as $iso => $name)
                            @php $imported = in_array($iso, $importedIsos); @endphp
                            <label
                                x-show="!countryFilter || '{{ strtolower($name) }}'.includes(countryFilter.toLowerCase()) || '{{ strtolower($iso) }}'.includes(countryFilter.toLowerCase())"
                                class="flex items-center gap-2 px-2 py-1 rounded text-sm {{ $imported ? 'opacity-50' : 'hover:bg-surface-subtle cursor-pointer' }}"
                            >
                                @if ($imported)
                                    <x-icon name="heroicon-o-check-circle" class="w-4 h-4 text-status-success shrink-0" />
                                    <span class="text-muted truncate" title="{{ $name }} ({{ $iso }}) — already imported">{{ $name }}</span>
                                    <span class="text-muted text-xs shrink-0">{{ $iso }}</span>
                                @else
                                    <input type="checkbox" wire:model.live="selectedCountries" value="{{ $iso }}" class="rounded border-border-input accent-accent focus:ring-accent">
                                    <span class="text-ink truncate" title="{{ $name }} ({{ $iso }})">{{ $name }}</span>
                                    <span class="text-muted text-xs shrink-0">{{ $iso }}</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </div>
            </x-ui.card>
        @endif

        <div
            wire:loading.flex
            wire:target="import,update"
            class="flex items-center gap-3 p-4 bg-status-info-subtle border border-status-info-border rounded-2xl text-status-info"
        >
            <x-icon name="heroicon-o-arrow-path" class="w-5 h-5 shrink-0 animate-spin" />
            <div class="flex-1">
                <div class="text-sm font-medium">{{ __('Importing...') }}</div>
                <p class="text-xs mt-1 opacity-75">{{ __('This may take several minutes. Do not close this page.') }}</p>
            </div>
        </div>

        {{-- Country record counts --}}
        @if ($hasData && $countryRecordCounts->isNotEmpty())
            <x-ui.card>
                <h2 class="text-sm font-semibold text-ink mb-3">{{ __('Postcodes by country') }}</h2>
                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <x-ui.sortable-th
                                    column="country_name"
                                    :sort-by="$summarySortBy"
                                    :sort-dir="$summarySortDir"
                                    action="sortSummary('country_name')"
                                    :label="__('Country')"
                                />
                                <x-ui.sortable-th
                                    column="country_iso"
                                    :sort-by="$summarySortBy"
                                    :sort-dir="$summarySortDir"
                                    action="sortSummary('country_iso')"
                                    :label="__('ISO')"
                                    class="w-24"
                                />
                                <x-ui.sortable-th
                                    column="record_count"
                                    align="right"
                                    :sort-by="$summarySortBy"
                                    :sort-dir="$summarySortDir"
                                    action="sortSummary('record_count')"
                                    :label="__('Records')"
                                />
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @foreach ($countryRecordCounts as $row)
                                <tr class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                                        {{ $row->country_name ?? $row->country_iso }}
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-mono text-xs text-muted tabular-nums">
                                        {{ $row->country_iso }}
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right font-medium text-ink tabular-nums">{{ app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($row->record_count) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by postcode, place name, or country...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="country_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('country_name')"
                                :label="__('Country')"
                            />
                            <x-ui.sortable-th
                                column="postcode"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('postcode')"
                                :label="__('Postcode')"
                            />
                            <x-ui.sortable-th
                                column="place_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('place_name')"
                                :label="__('Place Name')"
                            />
                            <x-ui.sortable-th
                                column="admin1Code"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('admin1Code')"
                                :label="__('Admin1 Code')"
                            />
                            <x-ui.sortable-th
                                column="updated_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('updated_at')"
                                :label="__('Updated')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($postcodes as $postcode)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <span class="font-mono text-xs text-muted">{{ $postcode->country_iso }}</span>
                                    <span class="ml-1">{{ $postcode->country_name ?? '' }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-medium text-ink tabular-nums">{{ $postcode->postcode }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted">{{ $postcode->place_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $postcode->admin1Code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums"><x-ui.datetime :value="$postcode->updated_at" format="date" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-muted">{{ __('No postcodes found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $postcodes->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
