<?php

namespace App\Base\Perf;

use App\Base\Perf\Console\PerfPruneCommand;
use App\Base\Perf\Console\PerfRequestsCommand;
use App\Base\Perf\Console\PerfSlowestCommand;
use App\Base\Perf\Process\CountingProcessFactory;
use App\Base\Perf\Services\BackgroundWorkRecorder;
use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerformanceCollector;
use App\Base\Perf\Services\PerfRegressionStatusDiagnosticProvider;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
        $this->app->singleton(PerfRuntimeSettings::class);
        $this->app->singleton(BackgroundWorkRecorder::class);
        $this->app->singleton(PerfRegressionStatusDiagnosticProvider::class);
        $this->app->tag(PerfRegressionStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);

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
            $collector->recordQuery($query->time, $query->sql);
        });

        Event::listen(CacheHit::class, static fn () => $collector->recordCacheHit());
        Event::listen(CacheMissed::class, static fn () => $collector->recordCacheMiss());
        Event::listen(KeyWritten::class, static fn () => $collector->recordCacheWrite());

        // Queue jobs and console commands get perf entries too (type
        // job/command); the recorder ignores nested work inside an already
        // open window (sync jobs in a request, Artisan::call in a command).
        $recorder = $this->app->make(BackgroundWorkRecorder::class);

        Event::listen(JobProcessing::class, static fn () => $recorder->jobStarting());
        Event::listen(JobProcessed::class, static fn (JobProcessed $event) => $recorder->jobFinished($event));
        Event::listen(JobFailed::class, static fn (JobFailed $event) => $recorder->jobFailed($event));
        Event::listen(CommandStarting::class, static fn (CommandStarting $event) => $recorder->commandStarting($event));
        Event::listen(CommandFinished::class, static fn (CommandFinished $event) => $recorder->commandFinished($event));
    }
}
