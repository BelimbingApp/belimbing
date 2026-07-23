# Performance instrumentation

BLB records every web request, **queue job, and console command** as one JSON
line in `perf-YYYY-MM-DD.jsonl`, under `storage/logs` by default: wall time,
DB time and query count, the top slow SQL statements,
cache hits/misses/writes, subprocess spawns (anything through the `Process`
facade — git, deploys, PDF tooling), response size, and whether the request was
a `wire:navigate` partial.
Livewire update requests are attributed to their component (`lw:<name>`), so
the slowest interactions are visible, not just the slowest pages. Recording is
on by its declared code default. Its enabled state, minimum duration, slow-SQL
threshold, log directory, and retention period are global runtime settings in
`base_settings`; they do not read from `.env` or Laravel config. Automated
feature tests seed an explicit disabled database override, and Perf-focused
tests enable it only against isolated test directories.

The log is built to be queried from the command line — agents are its
first-class consumers. **Start every "why is X slow" investigation here** —
do not hand-roll curl timing loops or tinker microtime scripts first.

For humans there is a page at Administration → System → Diagnostics →
Performance. Operators with `admin.system.perf.view` can inspect the stat
strip, latency scatter, per-route DB/subprocess/other composition bars, the
slowest requests, and the active recording values. Operators with
`admin.system.perf.manage` can save or restore those global values. The page
renders the same jsonl as the commands; it is not a second telemetry store.

The optional log directory is an installation-wide, machine-specific setting.
Leave it blank to use `storage/logs`. Under the current
single-compatible-runtime invariant, every runtime host connected to one
installation database must understand the configured path. Add a node scope
before operating heterogeneous hosts with incompatible local paths.
Console recording stays off until `base_settings` exists, and migration/wipe
commands are excluded, so first-run and database-recovery work does not depend
on the table it may be creating or replacing.

Upgrade note: deployments that previously set `PERF_LOG_ENABLED`,
`PERF_LOG_MIN_MS`, `PERF_LOG_SLOW_SQL_MIN_MS`, `PERF_LOG_PATH`, or
`PERF_LOG_RETENTION_DAYS` must enter the corresponding values in Recording
settings. Those environment names are no longer read and can then be removed
from the deployment environment.

```bash
# Slowest routes/jobs/commands, aggregated (hits, p50/p95/max, avg DB ms, queries, subprocesses)
php artisan perf:slowest --since=24h
php artisan perf:slowest --type=job          # queue jobs only (also: command, http)

# Individual entries, newest first; filter by route-name/path substring
php artisan perf:requests --since=1h --route=dashboard --min-ms=500
php artisan perf:requests --route=payroll --sql   # print the slowest captured SQL per entry

# Delete files past the retention window
php artisan perf:prune
# One-off override without changing the saved retention setting
php artisan perf:prune --days=30
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

The plumbing lives in `app/Base/Perf`: definition-backed runtime settings,
middleware prepended to the `web` group, a per-request collector fed by
DB/cache/process listeners, and the three artisan commands. The recorder reads
the five global values as one coherent, batched snapshot and reuses it for the
request. Under Octane the `mem_mb` field is the worker-lifetime peak, not
per-request.

## Shell payload

Full page loads carry the app shell once: an icon sprite
(`partials/icon-sprite.blade.php`, generated dynamically from
`IconRegistry::PATHS` — new registry entries appear automatically, no build
step) that lets `<x-icon>` emit a ~250-byte `<use>` instead of inline path
data on authenticated pages (guests and mail still inline, keyed off
`auth()->check()`); a single `#blb-menu-data` JSON blob feeding both sidebar
copies; and sidebar behavior in `resources/core/js/sidebar-menu.js` instead
of ~100 KB of inline `x-data` per copy. The mobile drawer skips rail-variant
markup entirely (`showRail=false`). Dashboard full load measured 1,265 KB →
883 KB. If the icon registry grows several-fold, the next step is serving the
sprite as a hash-versioned external file so the browser caches it once.

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
