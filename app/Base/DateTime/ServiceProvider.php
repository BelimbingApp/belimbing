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
     * as a request-scoped instance (flushed per request under Octane so the
     * injected LocaleContext memo never leaks across requests).
     */
    public function register(): void
    {
        $this->app->scoped(DateTimeDisplayService::class, DateTimeDisplayServiceImpl::class);
    }
}
