<?php

namespace App\Base\Perf\Process;

use App\Base\Perf\Services\PerformanceCollector;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

/**
 * Drop-in Process factory that reports every spawned subprocess to the
 * performance collector. Bound in place of Illuminate\Process\Factory, so
 * all Process-facade call sites (git, deploy, PDF tooling, ...) are counted
 * without touching them. Process::fake() keeps working — fakes are state on
 * this same instance and short-circuit inside PendingProcess::run().
 */
final class CountingProcessFactory extends Factory
{
    public function __construct(private readonly PerformanceCollector $collector) {}

    public function newPendingProcess(): PendingProcess
    {
        // withFakeHandlers is load-bearing: omitting it silently disabled
        // Process::fake() app-wide, and deployment tests then ran their
        // pipelines for real — including detached runtime reloads that
        // restarted the live dev server's workers (the 2026-07-12 "silent
        // FrankenPHP deaths"). See tests/Feature/Base/Perf.
        return (new CountingPendingProcess($this, $this->collector))
            ->withFakeHandlers($this->fakeHandlers);
    }
}
