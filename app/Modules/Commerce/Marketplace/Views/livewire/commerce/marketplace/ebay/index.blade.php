<?php

use App\Modules\Commerce\Marketplace\Livewire\Ebay\Index;

/** @var Index $this */
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

                @unless ($token)
                    <div class="mt-5 rounded-lg border border-border-default bg-surface-subtle/70 p-3 text-sm text-muted">
                        {{ __('Set up the eBay connection in') }}
                        <a href="{{ route('commerce.marketplace.ebay.settings') }}" class="font-medium text-accent hover:underline" wire:navigate>
                            {{ __('eBay settings') }}</a>{{ __(', then return here to pull listings and orders.') }}
                    </div>
                @endunless

                <div class="mt-5 flex flex-wrap gap-2">
                    <x-ui.button type="button" variant="outline" wire:click="pullListings" wire:loading.attr="disabled">
                        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
                        {{ __('Pull Listings') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="outline" wire:click="pullOrders" wire:loading.attr="disabled">
                        <x-icon name="heroicon-o-shopping-bag" class="h-4 w-4" />
                        {{ __('Pull Orders') }}
                    </x-ui.button>
                </div>

                @if($recentExchanges->isNotEmpty())
                    <div class="mt-5 border-t border-border-default pt-4">
                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Recent Exchanges') }}</div>
                        <div class="space-y-1.5">
                            @foreach($recentExchanges as $exchange)
                                <a href="{{ route('admin.integration.outbound-exchanges.show', $exchange) }}" class="flex items-center justify-between gap-3 text-xs text-accent hover:underline" wire:navigate>
                                    <span class="truncate">{{ $exchange->operation }}</span>
                                    <span class="font-mono">{{ $exchange->response_status ?? $exchange->outcome }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card class="xl:col-span-3">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Synced') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['totalListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Linked') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['linkedListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Unlinked') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['unlinkedListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Externally Changed') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['externallyChangedListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Ready to Adopt') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['readyToAdoptListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Missing Fitment') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['missingFitmentListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Conflicting IDs') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['conflictingIdentifierListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Relist Required') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['legacyRelistRequiredListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Missing Identifiers') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['missingIdentifierListings'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Not Listed') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $stats['unlistedItems'] }}</div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Store Progress') }}</h2>
                <x-ui.badge>{{ $stats['linkedListings'] }}</x-ui.badge>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Improved') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $qualitySummary['improved'] }}</div>
                </div>
                <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Unchanged') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $qualitySummary['unchanged'] }}</div>
                </div>
                <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Regressed') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $qualitySummary['regressed'] }}</div>
                </div>
                <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Strong') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $qualitySummary['strong'] }}</div>
                </div>
                <div class="rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Needs Work') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-ink tabular-nums">{{ $qualitySummary['needs_work'] }}</div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Cleanup Queue') }}</h2>
                <x-ui.badge>{{ $cleanupQueue->count() }}</x-ui.badge>
            </div>

            <x-ui.table container="flush" :caption="__('Cleanup queue')">


                <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Priority') }}</x-ui.th>
                            <x-ui.th>{{ __('Listing / Item') }}</x-ui.th>
                            <x-ui.th>{{ __('Import Audit') }}</x-ui.th>
                            <x-ui.th>{{ __('Quality') }}</x-ui.th>
                            <x-ui.th>{{ __('Recommendations') }}</x-ui.th>
                            <x-ui.th>{{ __('Performance') }}</x-ui.th>
                        </tr>
                    </x-slot>

                        @forelse ($cleanupQueue as $row)
                            @php($listing = $row['listing'])
                            <tr>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="flex flex-col gap-2">
                                        <x-ui.badge :variant="$row['state_variant']">{{ $row['state_label'] }}</x-ui.badge>
                                        <div class="font-mono text-xs text-muted">{{ __('Impact :score', ['score' => $row['impact_score']]) }}</div>
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="font-mono text-xs text-muted">{{ $listing->external_sku ?? __('No SKU') }}</div>
                                    <div class="mt-1 text-sm font-medium text-ink">{{ $listing->title ?? $listing->external_listing_id }}</div>
                                    @if ($listing->item)
                                        <a href="{{ route('commerce.inventory.items.show', $listing->item) }}" class="mt-1 inline-block font-mono text-xs text-accent hover:underline" wire:navigate>
                                            {{ $listing->item->sku }}
                                        </a>
                                    @endif
                                    @if ($row['issues'] !== [])
                                        <div class="mt-2 space-y-1">
                                            @foreach ($row['issues'] as $issue)
                                                <div class="text-xs text-muted">{{ $issue }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($row['import_audit'] as $audit)
                                            <x-ui.badge :variant="$audit['variant']">
                                                {{ $audit['label'] }}: {{ $audit['value'] }}
                                            </x-ui.badge>
                                        @endforeach
                                    </div>
                                    @if ($row['specific_alignment'] !== [])
                                        <div class="mt-2 space-y-1">
                                            @foreach (collect($row['specific_alignment'])->where('status', 'conflict')->take(2) as $alignment)
                                                <div class="text-xs text-status-danger">{{ $alignment['label'] }}: {{ $alignment['summary'] }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.badge :variant="$row['current_quality']['variant']">
                                            {{ $row['current_quality']['label'] }}
                                        </x-ui.badge>
                                        <span class="font-mono text-xs text-muted">{{ __('Now :score', ['score' => $row['current_quality']['score']]) }}</span>
                                    </div>
                                    <div class="mt-2 text-xs text-muted">
                                        {{ __('Imported baseline :score', ['score' => $row['baseline_quality']['score']]) }}
                                    </div>
                                    <div class="mt-1 text-xs {{ $row['quality_delta'] >= 0 ? 'text-status-success' : 'text-status-danger' }}">
                                        {{ __('Delta :delta', ['delta' => $row['quality_delta'] >= 0 ? '+' . $row['quality_delta'] : $row['quality_delta']]) }}
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    @if ($row['recommendations'] !== [])
                                        <div class="space-y-1.5">
                                            @foreach ($row['recommendations'] as $recommendation)
                                                <div class="text-xs text-ink">{{ $recommendation }}</div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-xs text-muted">{{ __('No immediate cleanup recommendation.') }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="space-y-1 text-xs text-muted">
                                        <div>{{ __('Sales: :count', ['count' => $row['performance']['sale_count']]) }}</div>
                                        <div>{{ __('Last sold: :value', ['value' => $row['performance']['last_sold_at']?->diffForHumans() ?? __('Never')]) }}</div>
                                        <div>{{ __('Inventory age: :days days', ['days' => $row['performance']['inventory_age_days'] ?? 0]) }}</div>
                                        @if ($row['performance']['listed_age_days'] !== null)
                                            <div>{{ __('Listed age: :days days', ['days' => $row['performance']['listed_age_days']]) }}</div>
                                        @endif
                                        @if ($row['performance']['buyer_signal_count'] > 0)
                                            <div class="text-status-danger">{{ __('Buyer signals: :count', ['count' => $row['performance']['buyer_signal_count']]) }}</div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No cleanup queue rows yet.') }}</td>
                            </tr>
                        @endforelse


            </x-ui.table>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <x-ui.card>
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Trust Signals') }}</h2>
                    <x-ui.badge>{{ $trustSignals->count() }}</x-ui.badge>
                </div>

                <div class="space-y-3">
                    @forelse ($trustSignals as $signal)
                        <div class="border-b border-border-default pb-3 last:border-0 last:pb-0">
                            <div class="flex items-center gap-2">
                                <x-ui.badge :variant="$signal['severity']">{{ $signal['label'] }}</x-ui.badge>
                                <span class="text-xs text-muted">{{ $signal['ordered_at']?->diffForHumans() ?? __('Unknown date') }}</span>
                            </div>
                            <div class="mt-2 text-sm font-medium text-ink">{{ $signal['listing_title'] }}</div>
                            <div class="mt-1 text-xs text-muted">{{ $signal['detail'] }}</div>
                            @if ($signal['buyer'])
                                <div class="mt-1 text-xs text-muted">{{ __('Buyer: :buyer', ['buyer' => $signal['buyer']]) }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-muted">{{ __('No buyer-question or return signals are currently linked to eBay listings.') }}</div>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card>
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Fitment Reuse') }}</h2>
                    <x-ui.badge>{{ $fitmentBatchCandidates->count() }}</x-ui.badge>
                </div>

                <div class="space-y-3">
                    @forelse ($fitmentBatchCandidates as $candidate)
                        <div class="border-b border-border-default pb-3 last:border-0 last:pb-0">
                            <a href="{{ route('commerce.inventory.items.show', $candidate['target_item']) }}" class="font-mono text-sm text-accent hover:underline" wire:navigate>
                                {{ $candidate['target_item']->sku }}
                            </a>
                            <div class="mt-1 text-sm text-ink">{{ $candidate['target_item']->title }}</div>
                            <div class="mt-1 text-xs text-muted">
                                {{ __('Copy fitment from :sku (:count entries).', ['sku' => $candidate['source_item']->sku, 'count' => $candidate['fitment_count']]) }}
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-muted">{{ __('No same-template fitment reuse candidates are waiting right now.') }}</div>
                    @endforelse
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

            <x-ui.table container="flush" :caption="__('Synced eBay listings')">


                <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Reconciliation') }}</x-ui.th>
                            <x-ui.th>{{ __('eBay Listing') }}</x-ui.th>
                            <x-ui.th>{{ __('Belimbing Item') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Price') }}</x-ui.th>
                            <x-ui.th>{{ __('Synced') }}</x-ui.th>
                        </tr>
                    </x-slot>

                        @forelse ($listings as $listing)
                            <tr wire:key="ebay-listing-{{ $listing->id }}">
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
                                    @if ($listing->drift_summary)
                                        <div class="mt-1 text-xs text-status-danger">{{ $listing->drift_summary }}</div>
                                    @endif
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
                                        <x-ui.badge :variant="$this->managementStateVariant($listing->management_state)">
                                            {{ __(Illuminate\Support\Str::headline(str_replace('_', ' ', $listing->management_state))) }}
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
                                        <div class="mt-1 text-xs text-muted">{{ __('Belimbing: :price', ['price' => $this->formatMoney($listing->item->target_price_amount, $listing->item->currency_code)]) }}</div>
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


            </x-ui.table>

            <div class="mt-4">
                {{ $listings->links() }}
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Inventory Not Listed on eBay') }}</h2>
                <x-ui.badge>{{ $unlistedItems->total() }}</x-ui.badge>
            </div>

            <x-ui.table container="flush" :caption="__('Inventory not listed on eBay')">


                <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('SKU') }}</x-ui.th>
                            <x-ui.th>{{ __('Item') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Target Price') }}</x-ui.th>
                            <x-ui.th>{{ __('Created') }}</x-ui.th>
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
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No active Belimbing inventory is missing from eBay.') }}</td>
                            </tr>
                        @endforelse


            </x-ui.table>

            <div class="mt-4">
                {{ $unlistedItems->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
