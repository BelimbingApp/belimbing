<x-layouts.app :title="__('Geonames Postcodes')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Geonames Postcodes')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.geonames.postcodes.import') }}" class="inline-flex items-center gap-2">
                    @csrf
                    <x-ui.button type="submit"><x-icon name="heroicon-o-arrow-down-tray" class="w-5 h-5 shrink-0" />{{ __('Import Selected') }}</x-ui.button>
                </form>
                @if ($hasData)
                    <form method="POST" action="{{ route('admin.geonames.postcodes.update') }}" class="inline-flex items-center gap-2">
                        @csrf
                        <x-ui.button type="submit"><x-icon name="heroicon-o-arrow-path" class="w-5 h-5 shrink-0" />{{ __('Update') }}</x-ui.button>
                    </form>
                @endif
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))<x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>@endif
        @if (session('error'))<x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>@endif

        <x-ui.card>
            <h2 class="text-sm font-semibold text-ink mb-3">{{ __('Select countries to import') }}</h2>
            <form method="POST" action="{{ route('admin.geonames.postcodes.import') }}">
                @csrf
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-1 max-h-64 overflow-y-auto">
                    @foreach ($allCountries as $iso => $name)
                        @php $imported = in_array($iso, $importedIsos, true); @endphp
                        <label class="flex items-center gap-2 px-2 py-1 rounded text-sm {{ $imported ? 'opacity-50' : 'hover:bg-surface-subtle cursor-pointer' }}">
                            @if ($imported)
                                <x-icon name="heroicon-o-check-circle" class="w-4 h-4 text-status-success shrink-0" />
                                <span class="text-muted truncate" title="{{ $name }} ({{ $iso }}) — already imported">{{ $name }}</span>
                                <span class="text-muted text-xs shrink-0">{{ $iso }}</span>
                            @else
                                <input type="checkbox" name="selected_countries[]" value="{{ $iso }}" class="rounded border-border-input text-accent focus:ring-accent">
                                <span class="text-ink truncate" title="{{ $name }} ({{ $iso }})">{{ $name }}</span>
                                <span class="text-muted text-xs shrink-0">{{ $iso }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                <div class="mt-3"><x-ui.button type="submit">{{ __('Import Selected Countries') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        @if ($hasData && $countryRecordCounts->isNotEmpty())
            <x-ui.card>
                <h2 class="text-sm font-semibold text-ink mb-3">{{ __('Postcodes by country') }}</h2>
                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80"><tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Country') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Records') }}</th></tr></thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @foreach ($countryRecordCounts as $row)
                                <tr class="hover:bg-surface-subtle/50 transition-colors"><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm"><span class="font-mono text-xs text-muted">{{ $row->country_iso }}</span><span class="ml-1 text-ink">{{ $row->country_name ?? $row->country_iso }}</span></td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right font-medium text-ink tabular-nums">{{ number_format($row->record_count) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <form method="GET" action="{{ route('admin.geonames.postcodes.index') }}" class="mb-2"><x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by postcode, place name, or country...') }}" /></form>
            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80"><tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Country') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Postcode') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Place Name') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Admin1 Code') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Updated') }}</th></tr></thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($postcodes as $postcode)
                            <tr class="hover:bg-surface-subtle/50 transition-colors"><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"><span class="font-mono text-xs text-muted">{{ $postcode->country_iso }}</span><span class="ml-1">{{ $postcode->country_name ?? '' }}</span></td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-medium text-ink tabular-nums">{{ $postcode->postcode }}</td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted">{{ $postcode->place_name }}</td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $postcode->admin1_code }}</td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $postcode->updated_at?->format('Y-m-d') }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-table-cell-x py-8 text-center text-muted">{{ __('No postcodes found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $postcodes->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
