<?php

namespace App\Modules\Commerce\Marketplace;

use App\Modules\Commerce\Marketplace\Console\Commands\EbayMetadataRefreshCommand;
use App\Modules\Commerce\Marketplace\Console\Commands\EbayPoliciesCommand;
use App\Modules\Commerce\Marketplace\Console\Commands\EbayPullCommand;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannelProvider;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannelProvider;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Plugins\Services\CommercePluginDiscoveryService;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketplaceChannelRegistry::class);
        $this->app->singleton(EbayMarketplaceChannelProvider::class);

        $this->app->bind(MarketplaceChannel::class, function (): MarketplaceChannel {
            return $this->app
                ->make(MarketplaceChannelRegistry::class)
                ->channel(EbayConfiguration::CHANNEL);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'commerce-marketplace');

        $commercePlugins = $this->app->make(CommercePluginRegistry::class);
        $this->app->make(CommercePluginDiscoveryService::class)->discoverInto($commercePlugins);

        $this->registerChannelProviders([
            EbayMarketplaceChannelProvider::class,
            ...$commercePlugins->marketplaceChannelProviders(),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EbayMetadataRefreshCommand::class,
                EbayPullCommand::class,
                EbayPoliciesCommand::class,
            ]);
        }
    }

    /**
     * @param  list<class-string<MarketplaceChannelProvider>>  $providers
     */
    private function registerChannelProviders(array $providers): void
    {
        $registry = $this->app->make(MarketplaceChannelRegistry::class);

        foreach ($providers as $providerClass) {
            $this->app
                ->make($providerClass)
                ->registerMarketplaceChannel($registry);
        }
    }
}
