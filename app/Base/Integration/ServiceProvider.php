<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration;

use App\Base\Integration\Services\IntegrationHttpClientFactory;
use App\Base\Integration\Services\OAuth2Client;
use App\Base\Integration\Services\OAuthTokenStore;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntegrationHttpClientFactory::class);
        $this->app->singleton(OAuth2Client::class);
        $this->app->singleton(OAuthTokenStore::class);
    }
}
