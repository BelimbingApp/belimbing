<div>
    <x-slot name="title">{{ __('Address Management') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Address Management')">
            <x-slot name="actions">
                <x-ui.button
                    variant="primary"
                    as="a"
                    href="{{ route('admin.addresses.create') }}"
                    wire:navigate
                >
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Create Address') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by label, address line, city, postcode, or country code...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="label"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('label')"
                                :label="__('Label')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Locality') }}</th>
                            <x-ui.sortable-th
                                column="country_iso"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('country_iso')"
                                :label="__('Country')"
                            />
                            <x-ui.sortable-th
                                column="verificationStatus"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('verificationStatus')"
                                :label="__('Status')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($addresses as $address)
                            <tr wire:key="address-{{ $address->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.addresses.show', $address) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $address->label ?: __('Unlabeled') }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                    <div class="max-w-xl truncate">{{ $address->line1 ?: __('No line 1') }}</div>
                                    @if($address->line2)
                                        <div class="max-w-xl truncate">{{ $address->line2 }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <div>{{ $address->locality ?: '-' }}</div>
                                    <div class="tabular-nums">{{ $address->postcode ?: '-' }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->country_iso ?: '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <x-ui.badge :variant="$this->statusVariant($address->verificationStatus)">{{ ucfirst($address->verificationStatus) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="delete({{ $address->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this address?') }}"
                                        >
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </x-ui.button>
                                    </div>
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
                {{ $addresses->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
