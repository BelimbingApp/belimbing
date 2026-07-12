<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request Performance Instrumentation
    |--------------------------------------------------------------------------
    |
    | When enabled, every web request is recorded as one JSON line in
    | storage/logs/perf-YYYY-MM-DD.jsonl: wall time, DB time and query count,
    | cache hits/misses/writes, subprocess spawns, and response size. Built to
    | be queried from the command line (`php artisan perf:slowest`,
    | `perf:requests`) rather than a web UI. Overhead is one array of counters
    | plus a single appended line per request.
    |
    */

    'enabled' => (bool) env('PERF_LOG_ENABLED', true),

    // Only record requests at least this slow (milliseconds). 0 records all,
    // which is the useful default: aggregate views need the fast requests too.
    'min_ms' => (float) env('PERF_LOG_MIN_MS', 0),

    // Directory for perf-*.jsonl files. Defaults to storage/logs.
    'path' => env('PERF_LOG_PATH'),

    // perf:prune deletes daily files older than this many days.
    'retention_days' => (int) env('PERF_LOG_RETENTION_DAYS', 14),

];
