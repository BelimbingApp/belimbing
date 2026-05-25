<?php

namespace App\Modules\Operation\IT;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'operation-it');
    }
}
