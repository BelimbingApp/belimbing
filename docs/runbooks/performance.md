# Performance instrumentation

BLB records every web request as one JSON line in
`storage/logs/perf-YYYY-MM-DD.jsonl`: wall time, DB time and query count,
cache hits/misses/writes, subprocess spawns (anything through the `Process`
facade — git, deploys, PDF tooling), response size, and whether the request
was a `wire:navigate` partial. It is on by default (`PERF_LOG_ENABLED`,
`PERF_LOG_MIN_MS`, `PERF_LOG_RETENTION_DAYS` in `.env`).

The log is built to be queried from the command line — agents are its
first-class consumers. **Start every "why is X slow" investigation here** —
do not hand-roll curl timing loops or tinker microtime scripts first.

For humans there is a read-only demonstration page at Administration →
System → Diagnostics → Performance (`admin.system.perf.view` capability):
stat strip, latency scatter, per-route DB/subprocess/other composition bars,
and the slowest requests. It renders the same jsonl and never writes; treat
it as a showcase of the log, not a second source of truth.

```bash
# Slowest routes, aggregated (hits, p50/p95/max, avg DB ms, queries, subprocesses)
php artisan perf:slowest --since=24h

# Individual requests, newest first; filter by route-name/path substring
php artisan perf:requests --since=1h --route=dashboard --min-ms=500

# Delete files past the retention window
php artisan perf:prune
```

Reading a row:

- High `ms`, low `DB ms`, `Procs > 0` → subprocess cost (usually git); see the
  stale-while-revalidate guidance in [windows-runtime.md](windows-runtime.md).
- High `Queries` per request (three digits) → N+1; find it with
  `perf:requests --route=...`, fix with eager loading.
- Large `Resp KB` on full loads but not navigate requests → shell payload;
  content links should be reaching pages via `wire:navigate`
  (auto-navigate.js makes that the default — check for `data-no-navigate`
  or non-anchor navigation if a page keeps doing full loads).

The plumbing lives in `app/Base/Perf`: middleware prepended to the `web`
group, a per-request collector fed by DB/cache/process listeners, and the
three artisan commands. Under Octane the `mem_mb` field is the
worker-lifetime peak, not per-request.

## Budgets

`tests/Feature/Base/Perf/PerfInstrumentationTest.php` asserts the dashboard
render (shared chrome: menu tree, per-item authorization, status bar) stays
within a query budget. If it fails after your change, you introduced an N+1
or new per-request work on the shared path — fix that rather than raising
the budget, and raise the budget only when the growth is intentional, in the
same change that adds it. Add equivalent budget tests for new hot paths you
build.
