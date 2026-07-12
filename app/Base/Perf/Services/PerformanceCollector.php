<?php

namespace App\Base\Perf\Services;

/**
 * Per-request counters for the performance log.
 *
 * A container singleton so framework-level listeners (DB, cache, process)
 * can reach it cheaply; the middleware calls begin()/end() around each
 * request. Octane keeps the instance alive across requests in a worker,
 * which is why begin() resets every counter instead of relying on fresh
 * construction.
 */
final class PerformanceCollector
{
    private bool $active = false;

    private int $queries = 0;

    private float $dbMs = 0.0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    private int $cacheWrites = 0;

    private int $processes = 0;

    private float $processMs = 0.0;

    public function begin(): void
    {
        $this->active = true;
        $this->queries = 0;
        $this->dbMs = 0.0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheWrites = 0;
        $this->processes = 0;
        $this->processMs = 0.0;
    }

    /**
     * Snapshot the counters and deactivate until the next begin().
     *
     * @return array{queries: int, db_ms: float, cache_hits: int, cache_misses: int, cache_writes: int, procs: int, proc_ms: float}
     */
    public function end(): array
    {
        $this->active = false;

        return [
            'queries' => $this->queries,
            'db_ms' => $this->dbMs,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_writes' => $this->cacheWrites,
            'procs' => $this->processes,
            'proc_ms' => $this->processMs,
        ];
    }

    public function recordQuery(float $milliseconds): void
    {
        if (! $this->active) {
            return;
        }

        $this->queries++;
        $this->dbMs += $milliseconds;
    }

    public function recordCacheHit(): void
    {
        if ($this->active) {
            $this->cacheHits++;
        }
    }

    public function recordCacheMiss(): void
    {
        if ($this->active) {
            $this->cacheMisses++;
        }
    }

    public function recordCacheWrite(): void
    {
        if ($this->active) {
            $this->cacheWrites++;
        }
    }

    public function recordProcess(float $milliseconds): void
    {
        if (! $this->active) {
            return;
        }

        $this->processes++;
        $this->processMs += $milliseconds;
    }
}
