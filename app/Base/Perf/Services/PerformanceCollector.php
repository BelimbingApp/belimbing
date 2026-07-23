<?php

namespace App\Base\Perf\Services;

/**
 * Per-unit-of-work counters for the performance log.
 *
 * A container singleton so framework-level listeners (DB, cache, process)
 * can reach it cheaply; the owner of a work window — the HTTP middleware, a
 * queue job, a console command — calls begin()/end() around it. begin() is
 * non-reentrant: a queue job on the sync driver or an Artisan::call inside a
 * web request stays part of the enclosing window instead of resetting it.
 * Octane keeps the instance alive across requests in a worker, which is why
 * begin() resets every counter instead of relying on fresh construction.
 */
final class PerformanceCollector
{
    /** Keep this many slowest SQL statements per window. */
    private const TOP_SQL_LIMIT = 3;

    private bool $active = false;

    private int $queries = 0;

    private float $dbMs = 0.0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    private int $cacheWrites = 0;

    private int $processes = 0;

    private float $processMs = 0.0;

    private float $slowSqlMinimumDurationMs = 0.0;

    /** @var list<array{ms: float, sql: string}> */
    private array $topSql = [];

    /**
     * Open a window. Returns false when one is already active — the caller
     * then does not own the window and must not end() or record it.
     */
    public function begin(float $slowSqlMinimumDurationMs): bool
    {
        if ($this->active) {
            return false;
        }

        $this->active = true;
        $this->queries = 0;
        $this->dbMs = 0.0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheWrites = 0;
        $this->processes = 0;
        $this->processMs = 0.0;
        $this->slowSqlMinimumDurationMs = max(0.0, $slowSqlMinimumDurationMs);
        $this->topSql = [];

        return true;
    }

    /**
     * Snapshot the counters and deactivate until the next begin().
     *
     * @return array{queries: int, db_ms: float, cache_hits: int, cache_misses: int, cache_writes: int, procs: int, proc_ms: float, top_sql: list<array{ms: float, sql: string}>}
     */
    public function end(): array
    {
        $this->active = false;

        $topSql = $this->topSql;
        usort($topSql, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return [
            'queries' => $this->queries,
            'db_ms' => $this->dbMs,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_writes' => $this->cacheWrites,
            'procs' => $this->processes,
            'proc_ms' => $this->processMs,
            'top_sql' => $topSql,
        ];
    }

    public function recordQuery(float $milliseconds, string $sql = ''): void
    {
        if (! $this->active) {
            return;
        }

        $this->queries++;
        $this->dbMs += $milliseconds;

        if ($sql !== '' && $milliseconds >= $this->slowSqlMinimumDurationMs) {
            $this->rememberSlowSql($milliseconds, $sql);
        }
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

    private function rememberSlowSql(float $milliseconds, string $sql): void
    {
        $this->topSql[] = [
            'ms' => round($milliseconds, 1),
            'sql' => mb_substr($sql, 0, 300),
        ];

        if (count($this->topSql) > self::TOP_SQL_LIMIT) {
            usort($this->topSql, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
            array_pop($this->topSql);
        }
    }
}
