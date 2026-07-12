<?php

namespace App\Base\Perf\Http\Middleware;

use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerformanceCollector;
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
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('perf.enabled')) {
            return $next($request);
        }

        $this->collector->begin();
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // Requests that die uncaught still show up — a slow request that
            // then crashes is exactly the kind the log exists to explain.
            $this->record($request, $startedAt, status: 500, response: null);

            throw $exception;
        }

        $this->record($request, $startedAt, $response->getStatusCode(), $response);

        return $response;
    }

    private function record(Request $request, int|float $startedAt, int $status, ?Response $response): void
    {
        $totalMs = (hrtime(true) - $startedAt) / 1e6;
        $metrics = $this->collector->end();

        if ($totalMs < (float) config('perf.min_ms')) {
            return;
        }

        $content = $response?->getContent();

        $this->log->write([
            'ts' => now()->toIso8601String(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'status' => $status,
            'ms' => round($totalMs, 1),
            'db_ms' => round($metrics['db_ms'], 1),
            'queries' => $metrics['queries'],
            'cache_hits' => $metrics['cache_hits'],
            'cache_misses' => $metrics['cache_misses'],
            'cache_writes' => $metrics['cache_writes'],
            'procs' => $metrics['procs'],
            'proc_ms' => round($metrics['proc_ms'], 1),
            'resp_bytes' => is_string($content) ? strlen($content) : null,
            'navigate' => $request->hasHeader('X-Livewire-Navigate'),
            'livewire' => $request->hasHeader('X-Livewire'),
            // Under Octane this is the worker-lifetime peak, not this request's;
            // still useful as a leak ceiling.
            'mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
    }
}
