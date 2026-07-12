<?php

namespace App\Base\Perf\Services;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Extends the performance log beyond HTTP: queue jobs and console commands
 * get the same one-JSON-line treatment (type "job" / "command"), so
 * perf:slowest can answer "why is the queue slow" too. The 2026-07-12
 * incident happened entirely in processes the log could not see.
 *
 * Windows are owned: a sync-queue job or an Artisan::call inside a web
 * request stays part of the enclosing request's window rather than writing
 * its own entry.
 */
final class BackgroundWorkRecorder
{
    /**
     * Long-running or per-tick wrapper commands whose own entries would be
     * noise; the work they host is recorded separately (jobs, spawned
     * commands).
     */
    private const SKIPPED_COMMANDS = [
        'queue:work',
        'queue:listen',
        'schedule:work',
        'schedule:run',
        'octane:start',
        'serve',
        'tinker',
    ];

    /**
     * Ownership per nesting level (Artisan::call inside a command, sync jobs
     * inside either): only the frame that opened the collector window writes.
     *
     * @var list<bool>
     */
    private array $ownershipStack = [];

    private float $startedAtNs = 0.0;

    private ?string $skippedCommand = null;

    public function __construct(
        private readonly PerformanceCollector $collector,
        private readonly PerfLog $log,
    ) {}

    public function jobStarting(JobProcessing $event): void
    {
        $this->openWindow();
    }

    public function jobFinished(JobProcessed $event): void
    {
        $this->closeWindow('job', $event->job->resolveName(), failed: false);
    }

    public function jobFailed(JobFailed $event): void
    {
        $this->closeWindow('job', $event->job->resolveName(), failed: true);
    }

    public function commandStarting(CommandStarting $event): void
    {
        if ($event->command === null || in_array($event->command, self::SKIPPED_COMMANDS, true)) {
            $this->skippedCommand = $event->command;

            return;
        }

        $this->openWindow();
    }

    public function commandFinished(CommandFinished $event): void
    {
        if ($event->command === null || $event->command === $this->skippedCommand) {
            $this->skippedCommand = null;

            return;
        }

        $this->closeWindow('command', $event->command, failed: $event->exitCode !== 0);
    }

    private function openWindow(): void
    {
        if (! config('perf.enabled')) {
            $this->ownershipStack[] = false;

            return;
        }

        $owns = $this->collector->begin();
        $this->ownershipStack[] = $owns;

        if ($owns) {
            $this->startedAtNs = hrtime(true);
        }
    }

    private function closeWindow(string $type, string $label, bool $failed): void
    {
        if (array_pop($this->ownershipStack) !== true) {
            return;
        }

        $totalMs = (hrtime(true) - $this->startedAtNs) / 1e6;
        $metrics = $this->collector->end();

        if ($totalMs < (float) config('perf.min_ms')) {
            return;
        }

        $this->log->write([
            'ts' => now()->toIso8601String(),
            'type' => $type,
            'method' => $type === 'job' ? 'JOB' : 'CMD',
            'path' => $label,
            'route' => ($type === 'job' ? 'job:' : 'cmd:').$label,
            // HTTP-shaped so every reader treats web and background rows alike.
            'status' => $failed ? 500 : 200,
            'ms' => round($totalMs, 1),
            'db_ms' => round($metrics['db_ms'], 1),
            'queries' => $metrics['queries'],
            'cache_hits' => $metrics['cache_hits'],
            'cache_misses' => $metrics['cache_misses'],
            'cache_writes' => $metrics['cache_writes'],
            'procs' => $metrics['procs'],
            'proc_ms' => round($metrics['proc_ms'], 1),
            'top_sql' => $metrics['top_sql'] ?: null,
            'mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
    }
}
