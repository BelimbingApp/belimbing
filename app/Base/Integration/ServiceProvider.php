<?php
namespace App\Base\Integration;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\OAuth2Client;
use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Integration\Services\OutboundExchangePruner;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntegrationGateway::class);
        $this->app->singleton(OAuth2Client::class);
        $this->app->singleton(OAuthTokenStore::class);
        $this->app->singleton(OutboundExchangePruner::class);
    }
}
