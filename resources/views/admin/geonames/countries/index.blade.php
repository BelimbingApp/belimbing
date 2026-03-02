<x-layouts.app :title="__('Countries')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Countries')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.geonames.countries.update') }}">
                    @csrf
                    <x-ui.button type="submit">
                        <x-icon name="heroicon-o-arrow-path" class="w-5 h-5 shrink-0" />
                        {{ __('Update') }}
                    </x-ui.button>
                </form>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))<x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>@endif
        @if (session('error'))<x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>@endif

        <x-ui.card>
            <form method="GET" action="{{ route('admin.geonames.countries.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by country name or ISO code...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('ISO') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Country') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Capital') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Phone') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Currency') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Population') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($countries as $country)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-medium text-ink tabular-nums">{{ $country->iso }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-ink">{{ $country->country }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $country->capital }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $country->phone }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted">{{ $country->currency_code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted text-right tabular-nums">{{ number_format($country->population) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $country->updated_at?->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-table-cell-x py-8 text-center text-muted">{{ __('No countries found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $countries->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
