<?php

namespace App\Base\Perf;

use App\Base\Perf\Console\PerfPruneCommand;
use App\Base\Perf\Console\PerfRequestsCommand;
use App\Base\Perf\Console\PerfSlowestCommand;
use App\Base\Perf\Process\CountingProcessFactory;
use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerformanceCollector;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PerformanceCollector::class);
        $this->app->singleton(PerfLog::class);

        // The Process facade resolves this binding, so every subprocess spawned
        // through it (git, deploys, PDF tooling, ...) is counted per request.
        $this->app->singleton(ProcessFactory::class, static fn ($app): CountingProcessFactory => new CountingProcessFactory(
            $app->make(PerformanceCollector::class),
        ));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerCollectorListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PerfSlowestCommand::class,
                PerfRequestsCommand::class,
                PerfPruneCommand::class,
            ]);
        }
    }

    /**
     * Forward DB and cache activity into the per-request collector. The
     * listeners are permanent; the collector no-ops outside an active
     * request window, so idle overhead is a property check per event.
     */
    private function registerCollectorListeners(): void
    {
        $collector = $this->app->make(PerformanceCollector::class);

        DB::listen(static function (QueryExecuted $query) use ($collector): void {
            $collector->recordQuery($query->time);
        });

        Event::listen(CacheHit::class, static fn () => $collector->recordCacheHit());
        Event::listen(CacheMissed::class, static fn () => $collector->recordCacheMiss());
        Event::listen(KeyWritten::class, static fn () => $collector->recordCacheWrite());
    }
}
