<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Commerce\Marketplace\Livewire\Ebay\Index $this */
?>

<div>
    <x-slot name="title">{{ __('eBay Marketplace') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('eBay Marketplace')" :subtitle="__('Official Sell API connection, listing sync, and SKU linking for Commerce inventory.')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.settings.index') }}" wire:navigate>
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

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
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
                </div>
            </x-ui.card>

            <x-ui.card class="lg:col-span-2">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Synced Listings') }}</h2>
                    <x-ui.badge>{{ $listings->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border-default">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('SKU') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Listing') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Price') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Linked Item') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default">
                            @forelse ($listings as $listing)
                                <tr wire:key="ebay-listing-{{ $listing->id }}">
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $listing->external_sku ?? __('No SKU') }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                        @if ($listing->listing_url)
                                            <a href="{{ $listing->listing_url }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
                                                {{ $listing->external_listing_id }}
                                            </a>
                                        @else
                                            {{ $listing->external_offer_id ?? __('Unpublished offer') }}
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-ui.badge>{{ $listing->status ?? __('Unknown') }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">
                                        {{ $listing->price_amount !== null && $listing->currency_code !== null ? \App\Base\Foundation\ValueObjects\Money::format($listing->price_amount, $listing->currency_code) : __('n/a') }}
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                        {{ $listing->item?->sku ?? __('Unlinked') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No eBay listings have been synced yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $listings->links() }}
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
