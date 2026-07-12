# Performance instrumentation

BLB records every web request, **queue job, and console command** as one JSON
line in `storage/logs/perf-YYYY-MM-DD.jsonl`: wall time, DB time and query
count, the top slow SQL statements, cache hits/misses/writes, subprocess
spawns (anything through the `Process` facade — git, deploys, PDF tooling),
response size, and whether the request was a `wire:navigate` partial.
Livewire update requests are attributed to their component (`lw:<name>`), so
the slowest interactions are visible, not just the slowest pages. It is on by
default (`PERF_LOG_ENABLED`, `PERF_LOG_MIN_MS`, `PERF_LOG_SLOW_SQL_MIN_MS`,
`PERF_LOG_RETENTION_DAYS` in `.env`); the test environment disables it in
phpunit.xml so suites never write into the live log.

The log is built to be queried from the command line — agents are its
first-class consumers. **Start every "why is X slow" investigation here** —
do not hand-roll curl timing loops or tinker microtime scripts first.

For humans there is a read-only demonstration page at Administration →
System → Diagnostics → Performance (`admin.system.perf.view` capability):
stat strip, latency scatter, per-route DB/subprocess/other composition bars,
and the slowest requests. It renders the same jsonl and never writes; treat
it as a showcase of the log, not a second source of truth.

```bash
# Slowest routes/jobs/commands, aggregated (hits, p50/p95/max, avg DB ms, queries, subprocesses)
php artisan perf:slowest --since=24h
php artisan perf:slowest --type=job          # queue jobs only (also: command, http)

# Individual entries, newest first; filter by route-name/path substring
php artisan perf:requests --since=1h --route=dashboard --min-ms=500
php artisan perf:requests --route=payroll --sql   # print the slowest captured SQL per entry

# Delete files past the retention window
php artisan perf:prune
```

Reading a row:

- High `ms`, low `DB ms`, `Procs > 0` → subprocess cost (usually git); see the
  stale-while-revalidate guidance in [windows-runtime.md](windows-runtime.md).
  For a page whose own job is the expensive scan (Modules, Updates, GitHub
  Access), mark the component `#[Defer]` with the shared
  `placeholders.page` skeleton — the shell renders in ~300 ms and the work
  streams in as an attributed `lw:` entry. Use `#[Defer]`, not `#[Lazy]`:
  a full page is always in view, and the intersect trigger does not fire on
  full-page component roots.
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

## Degradation announces itself

A status-bar diagnostic (`Base/Perf/Services/PerfRegressionStatusDiagnosticProvider`)
compares each route's p95 over the last day against its own p95 over the prior
six days and warns when a route with real traffic (≥10 hits in both windows)
gets ≥2× slower and lands above 750 ms. Whoever is signed in with
`admin.system.perf.view` — human or agent — sees the warning without anyone
remembering to run `perf:slowest`. The snapshot is cached (scalars only) with
stale-while-revalidate semantics.

## Budgets

`tests/Feature/Base/Perf/PerfInstrumentationTest.php` asserts the dashboard
render (shared chrome: menu tree, per-item authorization, status bar) stays
within a query budget. If it fails after your change, you introduced an N+1
or new per-request work on the shared path — fix that rather than raising
the budget, and raise the budget only when the growth is intentional, in the
same change that adds it. Add equivalent budget tests for new hot paths you
build.
