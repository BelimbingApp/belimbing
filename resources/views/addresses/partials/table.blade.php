<div class="-mx-card-inner overflow-x-auto px-card-inner">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Label') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Address') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Locality') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Country') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($addresses as $address)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        <a href="{{ route('admin.addresses.show', $address) }}" class="text-sm font-medium text-accent hover:underline">
                            {{ $address->label ?: __('Unlabeled') }}
                        </a>
                    </td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        <div class="max-w-xl truncate">{{ $address->line1 ?: __('No line 1') }}</div>
                        @if ($address->line2)
                            <div class="max-w-xl truncate">{{ $address->line2 }}</div>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">
                        <div>{{ $address->locality ?: '—' }}</div>
                        <div class="tabular-nums">{{ $address->postcode ?: '—' }}</div>
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $address->country_iso ?: '—' }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">
                        <x-ui.badge :variant="match($address->verification_status) {
                            'verified' => 'success',
                            'suggested' => 'warning',
                            default => 'default',
                        }">{{ ucfirst($address->verification_status) }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                        <form method="POST" action="{{ route('admin.addresses.destroy', $address) }}" onsubmit="return confirm('{{ __('Are you sure you want to delete this address?') }}')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                {{ __('Delete') }}
                            </x-ui.button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No addresses found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-2">
    {{ $addresses->withQueryString()->links() }}
</div>
