<?php

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
                'create_listing' => true,
                'revise_listing' => true,
                'end_listing' => true,
            ],
            routes: [
                'index' => 'commerce.marketplace.ebay.index',
                'settings' => 'commerce.marketplace.ebay.settings',
                'oauth_callback' => 'commerce.marketplace.ebay.oauth.callback',
            ],
            settingsGroup: 'marketplace_ebay',
            icon: 'heroicon-o-globe-alt',
        ));
    }
}
