<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannelProvider;
use App\Modules\Commerce\Marketplace\DTO\MarketplaceChannelDescriptor;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;

class EbayMarketplaceChannelProvider implements MarketplaceChannelProvider
{
    public function registerMarketplaceChannel(MarketplaceChannelRegistry $registry): void
    {
        $registry->register(new MarketplaceChannelDescriptor(
            key: EbayConfiguration::CHANNEL,
            label: 'eBay',
            channelClass: EbayMarketplaceChannel::class,
            capabilities: [
                'pull_listings' => true,
                'pull_orders' => true,
                'create_listing' => false,
                'revise_listing' => false,
                'end_listing' => false,
            ],
            routes: [
                'index' => 'commerce.marketplace.ebay.index',
                'oauth_callback' => 'commerce.marketplace.ebay.oauth.callback',
            ],
            settingsGroup: 'marketplace_ebay',
            icon: 'heroicon-o-globe-alt',
        ));
    }
}
