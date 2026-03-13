<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ensure URL generation matches APP_URL when running behind a reverse proxy
        // (e.g. Caddy terminating TLS). This prevents incorrect ports like :0.
        $appUrl = config('app.url');
        if (is_string($appUrl) && $appUrl !== '') {
            URL::forceRootUrl($appUrl);
            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }

        $this->registerQueueMonitoring();
        $this->registerCacheWarming();
    }

    /**
     * Register queue monitoring and auto-recovery
     */
    protected function registerQueueMonitoring(): void
    {
        // Monitor queue job failures
        Queue::failing(function (JobFailed $event) {
            Log::warning('Queue job failed', [
                'job' => $event->job->getName(),
                'exception' => $event->exception->getMessage(),
            ]);

            // Track failure rate
            $failures = Cache::increment('queue_failures', 1);
            Cache::put('queue_failures', $failures, now()->addHour());

            // Alert if failure rate is high
            if ($failures > 10) {
                Log::error('High queue failure rate detected', [
                    'failures' => $failures,
                ]);
            }
        });

        // Monitor successful jobs
        Queue::after(fn () => Cache::decrement('queue_failures'));
    }

    /**
     * Register cache warming after deployments
     */
    protected function registerCacheWarming(): void
    {
        // Warm cache on application boot if needed
        if (config('app.cache_warm_on_boot', false)) {
            try {
                // Warm commonly used caches
                Cache::remember('app_version', 3600, function () {
                    return app()->version();
                });
            } catch (\Exception $e) {
                Log::warning('Cache warming failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
