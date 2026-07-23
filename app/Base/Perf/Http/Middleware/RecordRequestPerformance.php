<?php

namespace App\Base\Perf\Http\Middleware;

use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerformanceCollector;
use App\Base\Perf\Services\PerfRuntimeSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records one JSON line per web request to the perf log. Prepended to the
 * web group so its wall time wraps the whole middleware stack. Query with
 * `php artisan perf:slowest` / `perf:requests`.
 */
final class RecordRequestPerformance
{
    public function __construct(
        private readonly PerformanceCollector $collector,
        private readonly PerfLog $log,
        private readonly PerfRuntimeSettings $runtimeSettings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $settings = $this->runtimeSettings->snapshot();

        if (! $settings->enabled || ! $this->collector->begin($settings->slowSqlMinimumDurationMs)) {
            return $next($request);
        }

        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // Requests that die uncaught still show up — a slow request that
            // then crashes is exactly the kind the log exists to explain.
            $this->record(
                $request,
                $startedAt,
                status: 500,
                response: null,
                minimumDurationMs: $settings->minimumDurationMs,
            );

            throw $exception;
        }

        $this->record(
            $request,
            $startedAt,
            $response->getStatusCode(),
            $response,
            $settings->minimumDurationMs,
        );

        return $response;
    }

    private function record(
        Request $request,
        int|float $startedAt,
        int $status,
        ?Response $response,
        float $minimumDurationMs,
    ): void {
        $totalMs = (hrtime(true) - $startedAt) / 1e6;
        $metrics = $this->collector->end();

        if ($totalMs < $minimumDurationMs) {
            return;
        }

        $content = $response?->getContent();

        $this->log->write([
            'ts' => now()->toIso8601String(),
            'type' => 'http',
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $this->routeLabel($request),
            'status' => $status,
            'ms' => round($totalMs, 1),
            'db_ms' => round($metrics['db_ms'], 1),
            'queries' => $metrics['queries'],
            'cache_hits' => $metrics['cache_hits'],
            'cache_misses' => $metrics['cache_misses'],
            'cache_writes' => $metrics['cache_writes'],
            'procs' => $metrics['procs'],
            'proc_ms' => round($metrics['proc_ms'], 1),
            'top_sql' => $metrics['top_sql'] ?: null,
            'resp_bytes' => is_string($content) ? strlen($content) : null,
            'navigate' => $request->hasHeader('X-Livewire-Navigate'),
            'livewire' => $request->hasHeader('X-Livewire'),
            // Under Octane this is the worker-lifetime peak, not this request's;
            // still useful as a leak ceiling.
            'mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
    }

    /**
     * Livewire update requests all share one route name, which hides the
     * slowest interactions. Attribute them to the component instead.
     */
    private function routeLabel(Request $request): ?string
    {
        $route = $request->route()?->getName();

        if ($route === null || ! str_ends_with($route, 'livewire.update')) {
            return $route;
        }

        $snapshot = $request->json('components.0.snapshot');

        if (! is_string($snapshot)) {
            return $route;
        }

        $name = json_decode($snapshot, true)['memo']['name'] ?? null;

        return is_string($name) ? 'lw:'.$name : $route;
    }
}
