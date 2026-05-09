<?php
namespace App\Base\DateTime;

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\DateTime\Services\DateTimeDisplayService as DateTimeDisplayServiceImpl;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register DateTime display services.
     *
     * Binds the DateTimeDisplayService contract to its implementation
     * as a singleton.
     */
    public function register(): void
    {
        $this->app->singleton(DateTimeDisplayService::class, DateTimeDisplayServiceImpl::class);
    }
}
