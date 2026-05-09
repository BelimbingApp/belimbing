<?php
namespace App\Modules\Commerce\Marketplace\Contracts;

use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;

interface MarketplaceChannelProvider
{
    public function registerMarketplaceChannel(MarketplaceChannelRegistry $registry): void;
}
