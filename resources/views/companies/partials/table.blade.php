<div id="companies-list">
    <div class="-mx-card-inner overflow-x-auto px-card-inner">
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Company') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Parent') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Jurisdiction') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-default bg-surface-card">
                @forelse($companies as $company)
                    <tr class="transition-colors hover:bg-surface-subtle/50">
                        <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                            <a href="{{ route('admin.companies.show', $company) }}" class="text-sm font-medium text-accent hover:underline">{{ $company->name }}</a>
                            <div class="text-xs text-muted tabular-nums">{{ $company->code }}</div>
                        </td>
                        <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $company->parent?->name ?? __('None') }}</td>
                        <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                            <x-ui.badge :variant="match($company->status) { 'active' => 'success', 'suspended' => 'danger', 'pending' => 'warning', default => 'default' }">
                                {{ ucfirst($company->status) }}
                            </x-ui.badge>
                        </td>
                        <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $company->jurisdiction ?? '-' }}</td>
                        <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                            @if ($company->isLicensee())
                                <x-ui.badge>{{ __('Licensee') }}</x-ui.badge>
                            @else
                                <form method="POST" action="{{ route('admin.companies.destroy', $company) }}" class="inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this company?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                        {{ __('Delete') }}
                                    </x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No companies found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2">
        {{ $companies->withQueryString()->links() }}
    </div>
</div>
