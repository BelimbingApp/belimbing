<?php

use App\Base\Foundation\Providers\ProviderRegistry;

return ProviderRegistry::resolve(
    appProviders: [
        App\Base\Htmx\ServiceProvider::class,
        App\Providers\AppServiceProvider::class,
    ]
);
