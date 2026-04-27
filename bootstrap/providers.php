<?php

use App\Base\Foundation\Providers\ProviderRegistry;
use App\Providers\AppServiceProvider;

return ProviderRegistry::resolve(
    appProviders: [
        AppServiceProvider::class,
    ]
);
