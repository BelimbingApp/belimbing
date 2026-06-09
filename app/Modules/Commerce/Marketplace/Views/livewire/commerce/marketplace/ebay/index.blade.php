<?php

use App\Modules\Commerce\Marketplace\Livewire\Ebay\Index;

/** @var Index $this */
?>

<div>
    <x-slot name="title">{{ __('eBay Marketplace') }}</x-slot>

    @php
        // One eBay account; auto parts list under eBay Motors. Show a friendly name, not the raw enum.
        $marketplaceLabel = match ($config['marketplace_id']) {
            'EBAY_US' => __('United States'),
            'EBAY_MOTORS', 'EBAY_MOTORS_US' => __('eBay Motors'),
            default => $config['marketplace_id'],
        };
        // Item links must use the environment's web host — sandbox listings 404 on www.ebay.com.
        $ebayItemUrlBase = rtrim($config['web_base_url'] ?? 'https://www.ebay.com', '/').'/itm/';
        $ebayTabs = [
            ['id' => 'listings', 'label' => __('Listings').' ('.$stats['totalListings'].')', 'icon' => 'heroicon-o-tag'],
            ['id' => 'unlisted', 'label' => __('Ready to list').' ('.$stats['unlistedItems'].')', 'icon' => 'heroicon-o-plus-circle'],
        ];
    @endphp

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('eBay Marketplace')" :subtitle="__('Sync your eBay store into Belimbing, then list inventory and keep it in step.')">
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

        {{-- Connection + sync: one status line, the sync actions, and what they do. --}}
        <x-ui.card>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    @if ($token)
                        <x-ui.badge variant="{{ $token->isExpired() ? 'warning' : 'success' }}">
                            {{ $token->isExpired() ? __('Refresh needed') : __('Connected') }}
                        </x-ui.badge>
                    @else
                        <x-ui.badge>{{ __('Not connected') }}</x-ui.badge>
                    @endif
                    <span class="text-muted">{{ __('eBay') }} · <span class="font-medium text-ink">{{ $marketplaceLabel }}</span></span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($token)
                        <x-ui.button type="button" variant="outline" wire:click="pullFromEbay" wire:loading.attr="disabled" wire:target="pullFromEbay">
                            <x-icon name="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                            <span wire:loading.remove wire:target="pullFromEbay">{{ __('Pull from eBay') }}</span>
                            <span wire:loading wire:target="pullFromEbay">{{ __('Pulling…') }}</span>
                        </x-ui.button>

                        <x-ui.button type="button" variant="ghost" wire:click="openMigrateModal" title="{{ __('Adopt listings created in Seller Hub / the legacy API by eBay listing ID') }}">
                            <x-icon name="heroicon-o-arrow-up-on-square-stack" class="h-4 w-4" />
                            {{ __('Import existing listings') }}
                        </x-ui.button>
                    @else
                        <x-ui.button variant="primary" as="a" href="{{ route('commerce.marketplace.ebay.settings') }}" wire:navigate>
                            <x-icon name="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                            {{ __('Set up connection') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>

            <p class="mt-3 text-xs text-muted">
                @if ($token)
                    {{ __('eBay is the remote, Belimbing your working copy. Pull from eBay brings your live listings and recent sales in — changed listings are flagged, never overwritten. Sending changes the other way (push) happens per item when you list or update.') }}
                @else
                    {{ __('Connect your eBay store in Settings, then pull your listings and orders here.') }}
                @endif
            </p>
        </x-ui.card>

        <x-ui.tabs :tabs="$ebayTabs" default="listings">
            {{-- Listings: your eBay store as Belimbing sees it. Unlinked rows still need a Belimbing item. --}}
            <x-ui.tab id="listings">
                <x-ui.card>
                    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div class="flex items-center gap-3">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Your eBay Listings') }}</h2>
                            <x-ui.badge>{{ $listings->total() }}</x-ui.badge>
                        </div>
                        <div class="grid w-full gap-3 sm:grid-cols-[1fr_200px] lg:w-auto lg:grid-cols-[320px_200px]">
                            <x-ui.search-input
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('Search by SKU, title, or listing ID...') }}"
                            />
                            <x-ui.select wire:model.live="listingFilter">
                                <option value="all">{{ __('All') }}</option>
                                <option value="linked">{{ __('Linked to inventory') }}</option>
                                <option value="unlinked">{{ __('Not linked yet') }}</option>
                            </x-ui.select>
                        </div>
                    </div>

                    <x-ui.table container="flush" :caption="__('Your eBay listings')">
                        <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('eBay Listing') }}</x-ui.th>
                                <x-ui.th>{{ __('Belimbing Item') }}</x-ui.th>
                                <x-ui.th>{{ __('Status') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Price') }}</x-ui.th>
                                <x-ui.th>{{ __('Synced') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                            </tr>
                        </x-slot>

                        @forelse ($listings as $listing)
                            <tr wire:key="ebay-listing-{{ $listing->id }}">
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="font-mono text-xs text-muted">{{ $listing->external_sku ?? __('No SKU') }}</div>
                                    <div class="mt-1 text-sm font-medium text-ink">
                                        @if ($listing->external_listing_id)
                                            <a href="{{ $ebayItemUrlBase . $listing->external_listing_id }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
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
                                        <x-ui.badge variant="warning">{{ __('Not linked') }}</x-ui.badge>
                                        <div class="mt-1 text-xs text-muted">{{ __('Create a Belimbing item for this listing.') }}</div>
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
                                    {{ $this->formatMoney($listing->price_amount, $listing->currency_code) }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $listing->last_synced_at?->diffForHumans() ?? __('Never') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    @if ($listing->item)
                                        <x-ui.button type="button" size="sm" variant="outline" wire:click="openListingModal({{ $listing->id }})">
                                            <x-icon name="heroicon-o-pencil-square" class="h-4 w-4" />
                                            {{ __('Edit & push') }}
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                    {{ $stats['totalListings'] === 0
                                        ? __('No listings yet. Use “Pull from eBay” above to import your eBay store.')
                                        : __('No listings match your search.') }}
                                </td>
                            </tr>
                        @endforelse
                    </x-ui.table>

                    <div class="mt-4">
                        {{ $listings->links() }}
                    </div>
                </x-ui.card>
            </x-ui.tab>

            {{-- Ready to list: Belimbing inventory that is not on eBay yet — the next things to list. --}}
            <x-ui.tab id="unlisted">
                <x-ui.card>
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div class="flex items-center gap-3">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Ready to List') }}</h2>
                            <x-ui.badge>{{ $unlistedItems->total() }}</x-ui.badge>
                        </div>
                        <div class="w-full sm:w-80">
                            <x-ui.search-input
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('Search inventory by SKU, title, or status...') }}"
                            />
                        </div>
                    </div>

                    <x-ui.table container="flush" :caption="__('Inventory ready to list on eBay')">
                        <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('SKU') }}</x-ui.th>
                                <x-ui.th>{{ __('Item') }}</x-ui.th>
                                <x-ui.th>{{ __('Status') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Target Price') }}</x-ui.th>
                                <x-ui.th>{{ __('Added') }}</x-ui.th>
                            </tr>
                        </x-slot>

                        @forelse ($unlistedItems as $item)
                            <tr wire:key="ebay-unlisted-item-{{ $item->id }}">
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
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('Nothing waiting — all active inventory is already on eBay.') }}</td>
                            </tr>
                        @endforelse
                    </x-ui.table>

                    <div class="mt-4">
                        {{ $unlistedItems->links() }}
                    </div>
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>

        {{-- Per-listing quick edit-and-push. Title/price are the item's canonical
             content; quantity is inventory-driven and shown read-only. --}}
        <x-ui.modal wire:model="showListingModal">
            <div class="space-y-4">
                <div>
                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Edit & push listing') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Update the listing content and push it to eBay. Quantity is managed by inventory and synced automatically.') }}</p>
                </div>

                <x-ui.input id="ebay-modal-title" wire:model="modalTitle" :label="__('Title')" :error="$errors->first('modalTitle')" />
                <x-ui.input id="ebay-modal-price" wire:model="modalPrice" :label="__('Price')" inputmode="decimal" :error="$errors->first('modalPrice')" />

                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" wire:click="closeListingModal">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="saveAndPushListing" wire:loading.attr="disabled" wire:target="saveAndPushListing">
                        <x-icon name="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="saveAndPushListing">{{ __('Save & push to eBay') }}</span>
                        <span wire:loading wire:target="saveAndPushListing">{{ __('Pushing…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>

        {{-- Adopt existing eBay listings (Seller Hub / legacy Trading API) by ID.
             Migrates them into the Inventory API so they appear and become manageable. --}}
        <x-ui.modal wire:model="showMigrateModal">
            <div class="space-y-4">
                <div>
                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Import existing eBay listings') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Listings created in Seller Hub or before connecting Belimbing are not visible to a normal pull. Paste their eBay listing IDs to adopt them into the Inventory API — then they appear below and can be revised, ended, and stock-synced.') }}</p>
                </div>

                <x-ui.textarea
                    id="ebay-migrate-listing-ids"
                    wire:model="migrateListingIds"
                    :label="__('eBay listing IDs')"
                    rows="4"
                    :placeholder="__('110589612524, 110589612525')"
                    :help="__('One per line or comma-separated. Find the ID in the listing URL (…/itm/THIS_NUMBER).')"
                    :error="$errors->first('migrateListingIds')"
                />

                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" wire:click="closeMigrateModal">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="migrateLegacyListings" wire:loading.attr="disabled" wire:target="migrateLegacyListings">
                        <x-icon name="heroicon-o-arrow-up-on-square-stack" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="migrateLegacyListings">{{ __('Import listings') }}</span>
                        <span wire:loading wire:target="migrateLegacyListings">{{ __('Importing…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</div>
