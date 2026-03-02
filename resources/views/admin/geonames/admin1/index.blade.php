<x-layouts.app :title="__('Admin1 Divisions')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Admin1 Divisions')" :subtitle="__('States, provinces, and top-level administrative divisions')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.geonames.admin1.update') }}">
                    @csrf
                    <x-ui.button type="submit">
                        <x-icon name="heroicon-o-arrow-path" class="w-5 h-5" />
                        {{ __('Update') }}
                    </x-ui.button>
                </form>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))<x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>@endif
        @if (session('error'))<x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>@endif

        <x-ui.card>
            <form method="GET" action="{{ route('admin.geonames.admin1.index') }}" class="flex flex-col sm:flex-row gap-3 mb-3">
                <div class="flex-1"><x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by name, code, or country...') }}" /></div>
                <div class="sm:w-64">
                    <x-ui.select name="filter_country_iso">
                        <option value="">{{ __('All Countries') }}</option>
                        @foreach($countryNames as $iso => $name)
                            <option value="{{ $iso }}" @selected($filterCountryIso === $iso)>{{ $name }} ({{ $iso }})</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Country') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Alt Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($admin1s as $admin1)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"><span class="font-mono text-xs text-muted">{{ $admin1->country_iso }}</span><span class="ml-1">{{ $admin1->country_name }}</span></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-mono text-ink">{{ $admin1->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $admin1->name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $admin1->alt_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $admin1->updated_at?->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-table-cell-x py-12 text-center"><p class="text-sm text-muted">{{ __('No admin1 divisions found.') }}</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $admin1s->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
