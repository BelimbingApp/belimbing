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

        <x-ui.card>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm">
                        @if ($token)
                            <x-ui.badge variant="success">{{ __('Connected') }}</x-ui.badge>
                        @else
                            <x-ui.badge>{{ __('Not connected') }}</x-ui.badge>
                        @endif
                        <span class="text-muted">{{ __('eBay') }} · <span class="font-medium text-ink">{{ $marketplaceLabel }}</span></span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($token)
                        <x-ui.button type="button" variant="outline" wire:click="pullFromEbay" wire:loading.attr="disabled" wire:target="pullFromEbay">
                            <x-icon name="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                            <span wire:loading.remove wire:target="pullFromEbay">{{ __('Pull from eBay') }}</span>
                            <span wire:loading wire:target="pullFromEbay">{{ __('Pulling…') }}</span>
                        </x-ui.button>

                        <x-ui.button type="button" variant="ghost" wire:click="openImportModal" title="{{ __('Pick from your eBay store — including listings the normal pull cannot see') }}">
                            <x-icon name="mdi-import" class="h-4 w-4" />
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
    </div>
</div>

