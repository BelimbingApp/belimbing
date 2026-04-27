<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Commerce\Inventory\Livewire\Items\Index $this */
?>

<div>
    <x-slot name="title">{{ __('Inventory Workbench') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Inventory Workbench')" :subtitle="__('Create and track sellable inventory items before AI assist and marketplace sync are connected.')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('commerce.inventory.items.create') }}" wire:navigate>
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('New Item') }}
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
                    placeholder="{{ __('Search by SKU, title, description, or status...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('SKU') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Title') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Unit Cost') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Target Price') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($items as $item)
                            <tr wire:key="item-{{ $item->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                    <a href="{{ route('commerce.inventory.items.show', $item) }}" class="text-accent hover:underline" wire:navigate>
                                        {{ $item->sku }}
                                    </a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="text-sm font-medium text-ink">{{ $item->title }}</div>
                                    @if ($item->description)
                                        <div class="mt-1 max-w-xl truncate text-xs text-muted">{{ $item->description }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm text-muted tabular-nums">{{ $this->formatMoney($item->unit_cost_amount, $item->currency_code) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm text-muted tabular-nums">{{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $item->created_at?->diffForHumans() }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action
                                            icon="heroicon-o-eye"
                                            :label="__('View item')"
                                            :href="route('commerce.inventory.items.show', $item)"
                                            wire:navigate
                                        />
                                        <x-ui.icon-action
                                            icon="heroicon-o-pencil-square"
                                            :label="__('Edit item')"
                                            :href="route('commerce.inventory.items.edit', $item)"
                                            wire:navigate
                                        />
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No items found. Create the first item to begin the workbench.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $items->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
