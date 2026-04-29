<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Commerce\Marketplace\Livewire\Ebay\Index $this */
?>

<div>
    <x-slot name="title">{{ __('eBay Marketplace') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('eBay Marketplace')" :subtitle="__('Official Sell API connection, listing/order sync, and reconciliation against Commerce inventory.')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.marketplace.ebay.settings') }}" wire:navigate>
                    <x-icon name="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                    {{ __('Settings') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-4">
            <x-ui.card>
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Connection') }}</h2>
                    @if ($token)
                        <x-ui.badge variant="{{ $token->isExpired() ? 'warning' : 'success' }}">
                            {{ $token->isExpired() ? __('Refresh needed') : __('Connected') }}
                        </x-ui.badge>
                    @else
                        <x-ui.badge>{{ __('Not connected') }}</x-ui.badge>
                    @endif
                </div>

                <dl class="space-y-3">
                    <div>
                        <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Environment') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ __(Illuminate\Support\Str::headline($config['environment'])) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Marketplace') }}</dt>
                        <dd class="mt-1 font-mono text-sm text-ink">{{ $config['marketplace_id'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Token expiry') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $token?->expires_at?->diffForHumans() ?? __('No token') }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <x-ui.button type="button" variant="primary" wire:click="connect">
                        <x-icon name="heroicon-o-link" class="h-4 w-4" />
                        {{ __('Connect eBay') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="outline" wire:click="pullListings" wire:loading.attr="disabled">
                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
                        {{ __('Pull Listings') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="outline" wire:click="pullOrders" wire:loading.attr="disabled">
                        <x-icon name="heroicon-o-shopping-bag" class="h-4 w-4" />
                        {{ __('Pull Orders') }}
                    </x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card class="xl:col-span-3">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Synced') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['totalListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Matched') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['linkedListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Unlinked') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['unlinkedListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Drifted') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['driftedListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Not Listed') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['unlistedItems'] }}</div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card>
            <div class="mb-4 grid gap-3 lg:grid-cols-[1fr_220px] lg:items-end">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search listings or inventory by SKU, title, listing ID, or status...') }}"
                />
                <x-ui.select wire:model.live="listingFilter" :label="__('Listing Filter')">
                    <option value="all">{{ __('All Listings') }}</option>
                    <option value="linked">{{ __('Linked Only') }}</option>
                    <option value="unlinked">{{ __('Unlinked Only') }}</option>
                </x-ui.select>
            </div>

            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Synced eBay Listings') }}</h2>
                <x-ui.badge>{{ $listings->total() }}</x-ui.badge>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Reconciliation') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('eBay Listing') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('BLB Item') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Price') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Synced') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default">
                        @forelse ($listings as $listing)
                            <tr wire:key="ebay-listing-{{ $listing->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->reconciliationVariant($listing)">
                                        {{ $this->reconciliationLabel($listing) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="font-mono text-xs text-muted">{{ $listing->external_sku ?? __('No SKU') }}</div>
                                    <div class="mt-1 text-sm font-medium text-ink">
                                        @if ($listing->listing_url)
                                            <a href="{{ $listing->listing_url }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
                                                {{ $listing->title ?? $listing->external_listing_id }}
                                            </a>
                                        @else
                                            {{ $listing->title ?? $listing->external_offer_id ?? __('Unpublished offer') }}
                                        @endif
                                    </div>
                                    <div class="mt-1 text-xs text-muted">
                                        {{ $listing->external_listing_id ?? $listing->external_offer_id ?? __('No external ID') }}
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @if ($listing->item)
                                        <a href="{{ route('commerce.inventory.items.show', $listing->item) }}" class="font-mono text-sm text-accent hover:underline" wire:navigate>
                                            {{ $listing->item->sku }}
                                        </a>
                                        <div class="mt-1 max-w-sm truncate text-xs text-muted">{{ $listing->item->title }}</div>
                                    @else
                                        <span class="text-sm text-muted">{{ __('No linked item') }}</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.badge :variant="$this->listingStatusVariant($listing->status)">
                                            {{ __(Illuminate\Support\Str::headline($listing->status ?? 'unknown')) }}
                                        </x-ui.badge>
                                        @if ($listing->item)
                                            <x-ui.badge :variant="$this->itemStatusVariant($listing->item->status)">
                                                {{ __(Illuminate\Support\Str::headline($listing->item->status)) }}
                                            </x-ui.badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm text-ink tabular-nums">
                                    <div>{{ $this->formatMoney($listing->price_amount, $listing->currency_code) }}</div>
                                    @if ($listing->item?->target_price_amount !== null)
                                        <div class="mt-1 text-xs text-muted">{{ __('BLB: :price', ['price' => $this->formatMoney($listing->item->target_price_amount, $listing->item->currency_code)]) }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $listing->last_synced_at?->diffForHumans() ?? __('Never') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No eBay listings have been synced yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $listings->links() }}
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Inventory Not Listed on eBay') }}</h2>
                <x-ui.badge>{{ $unlistedItems->total() }}</x-ui.badge>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('SKU') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Item') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Target Price') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Created') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default">
                        @forelse ($unlistedItems as $item)
                            <tr wire:key="ebay-unlisted-item-{{ $item->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('commerce.inventory.items.show', $item) }}" class="font-mono text-sm text-accent hover:underline" wire:navigate>
                                        {{ $item->sku }}
                                    </a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="text-sm font-medium text-ink">{{ $item->title }}</div>
                                    @if ($item->notes)
                                        <div class="mt-1 max-w-xl truncate text-xs text-muted">{{ $item->notes }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->itemStatusVariant($item->status)">
                                        {{ __(Illuminate\Support\Str::headline($item->status)) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm text-ink tabular-nums">
                                    {{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $item->created_at?->diffForHumans() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No active BLB inventory is missing from eBay.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $unlistedItems->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
