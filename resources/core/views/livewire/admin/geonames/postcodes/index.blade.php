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

        {{-- Fallback: show as soon as Import/Update is clicked (no Echo required) --}}
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

        {{-- Live progress via WebSocket (when Reverb + Echo are configured) --}}
        <div
            x-data="{
                progress: null,
                init() {
                    if (window.Echo) {
                        window.Echo.channel('postcode-import')
                            .listen('.App\\Modules\\Core\\Geonames\\Events\\PostcodeImportProgress', (e) => {
                                this.progress = e && (e.status !== undefined ? e : e.payload || e)
                                if (this.progress && this.progress.status === 'completed' && this.progress.current === this.progress.total) {
                                    setTimeout(() => {
                                        this.progress = null
                                        $wire.$refresh()
                                    }, 3000)
                                }
                            })
                    }
                }
            }"
            x-show="progress"
            x-cloak
            class="flex items-center gap-3 p-4 bg-status-info-subtle border border-status-info-border rounded-2xl text-status-info"
        >
            <template x-if="progress && progress.status !== 'failed'">
                <div class="flex items-center gap-3 w-full">
                    <x-icon name="heroicon-o-arrow-path" class="w-5 h-5 shrink-0 animate-spin" />
                    <div class="flex-1">
                        <div class="text-sm font-medium" x-text="progress?.message"></div>
                        <div class="mt-1 w-full bg-status-info-border rounded-full h-1.5">
                            <div class="bg-status-info h-1.5 rounded-full transition-all duration-300" :style="'width: ' + (progress ? Math.round((progress.current / progress.total) * 100) : 0) + '%'"></div>
                        </div>
                        <div class="text-xs mt-1 opacity-75" x-text="progress ? progress.current + ' / ' + progress.total + ' countries' : ''"></div>
                    </div>
                </div>
            </template>
            <template x-if="progress && progress.status === 'failed'">
                <div class="flex items-center gap-3">
                    <x-icon name="heroicon-o-exclamation-circle" class="w-5 h-5 shrink-0 text-status-danger" />
                    <span class="text-sm text-status-danger" x-text="progress?.message"></span>
                </div>
            </template>
        </div>

        {{-- Country record counts --}}
        @if ($hasData && $countryRecordCounts->isNotEmpty())
            <x-ui.card>
                <h2 class="text-sm font-semibold text-ink mb-3">{{ __('Postcodes by country') }}</h2>
                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Country') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Records') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @foreach ($countryRecordCounts as $row)
                                <tr class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                                        <span class="font-mono text-xs text-muted">{{ $row->country_iso }}</span>
                                        <span class="ml-1 text-ink">{{ $row->country_name ?? $row->country_iso }}</span>
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
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Country') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Postcode') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Place Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Admin1 Code') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Updated') }}</th>
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
