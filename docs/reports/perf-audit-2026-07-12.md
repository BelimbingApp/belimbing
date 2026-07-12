# Performance audit — 2026-07-12

First dogfooding pass of the request performance log (`docs/runbooks/performance.md`).
Method: exercised ~30 representative authenticated pages with curl, then read
`php artisan perf:slowest` / `perf:requests` and drilled into every route the
tool convicted with a per-component query trace. All numbers from the Windows
dev checkout, warm server, `?window=` as noted.

## Fixes applied in this audit

| Route | Queries before | Queries after | Cause |
|---|---|---|---|
| `admin.system.database.index` | **502** | **59** | `TableRegistry::reconcile()` ran `exists()` + unconditional `UPDATE` per declared table on every mount. Replaced with one batched `upsert` (`app/Base/Database/Models/TableRegistry.php`). |
| `investment.company-research.index` | **280** | **60** | Per-row latest advice note, trade call, annual report, estimate, close — each with its own `Schema::hasTable` probe. Batched in `CaseStudies::render` (kiat repo). |
| `investment.portfolio.index` | **244** | **84** | Same per-company `ValuationSignals` calls. |
| `investment.dashboard` | **236** | **76** | `OwnerActionQueue` called `effectiveEstimate` + `latestClose` + `marginOfSafety` per company (each pair re-queried), plus per-company `exists()` probes. |
| `investment.trade-calls.index` | **236** | **76** | Same shared cause. |
| `dashboard` worst case | 5.65 s (18 procs) | steady ~0.4 s | Inventory snapshot cache fully expired after 1 h and refreshed synchronously on whoever clicked next. New `blb:software:inventory:warm` runs every 10 min via the scheduler; `Cache::flexible` remains the fallback. |

The kiat fix is structural, not per-page: `ValuationSignals` is now request-scoped
with per-company memoization and a `primeFor()` batch loader (greatest-per-group
join for closes), `Schema::hasTable` results are memoized, and the two estimate/price
write sites bust the memo (`forgetCompany`). Every current and future caller
benefits; ~45 of each "after" number is the shared shell (menu + authz + status bar),
guarded separately by the query-budget test in `tests/Feature/Base/Perf`.

## Incident: silent FrankenPHP deaths (RESOLVED — friendly fire)

Three times (13:45, 14:07, 14:12 local) FrankenPHP and the queue worker died
with nothing on stderr, no WER crash event, and nothing in laravel.log. Initial
suspects — request bursts, the deferred git-scan refresh, PHP 8.5 tracing JIT
on ZTS — all failed controlled reproduction (forced-stale caches, cold routes,
exact request sequences, aggressive bursts: server survived every attempt).

The breakthrough was noticing that every crash fell inside the window of a
**background test-suite run**, and then catching the interference live: probing
the site every 2 s while `tests/Feature/Base/Software` ran showed the real dev
server returning **503 for ~35 s, twice per run**. The full causal chain:

1. The perf instrumentation's `CountingProcessFactory` (added earlier the same
   day) overrode `newPendingProcess()` without passing `withFakeHandlers`, so
   **`Process::fake()` silently stopped intercepting** — everywhere.
2. Deployment tests then executed their pipelines for real: real `git pull` on
   the working tree, real `bun install` + `bun run build` (one suite run took
   1593 s instead of ~55 s), real `artisan down`/`up` on the **shared**
   maintenance file (phpunit pinned the `file` driver — the 503 windows), and
   real **detached `blb:domain-runtime:reload` processes**.
3. The detached reload resolved the live admin endpoint (env, else the live
   server's own `octane-server-state.json`, which the testing env could read)
   and POSTed `/frankenphp/workers/restart` at the real server — restarting
   workers mid-request (the connection resets) and, with cache clearing in the
   mix, signalling the live queue worker to exit. The launcher then tore down
   the remaining children. Silent, because nothing crashed: the server was
   being administered.
4. The suite failures that would have exposed this were masked by piping pest
   through `tail`, which ate the exit code.

Fixes shipped, each closing one link:

- `CountingProcessFactory` forwards fake handlers, with a regression test that
  fails loudly if `Process::fake()` ever stops intercepting again.
- phpunit.xml: `APP_MAINTENANCE_DRIVER=cache` (array store → maintenance is
  process-local; tests can never 503 the live site) and
  `CADDY_SERVER_ADMIN_HOST/PORT` pinned to a dead port so even an escaped real
  reload cannot reach the live admin API. Tests that exercise endpoint
  resolution use scoped helpers that clear/restore all three env sources
  (`$_ENV`, `$_SERVER`, `getenv`).
- The perf query-budget test mocks the inventory service instead of spawning
  a real git scan storm from the test process.
- The launcher captures every child's stdout/stderr to
  `storage/logs/runtime-<name>.{out,err}.log` (kept — cheap and generally
  useful).

Verified after the fixes: full Perf + Software suites green (66 passed, real
exit code checked) in 52 s, with a concurrent 2 s probe loop recording **zero**
non-OK responses from the live server for the entire run.

Still worth doing (unchanged by the resolution): the launcher should restart a
dead child instead of tearing the stack down, and `git.exe` 2.54.0.1 logged an
access violation on 2026-07-11 — the scheduled warmer reduces web-worker git
load either way.

## Known and accepted

- `admin.system.software.modules.index` / `updates.index`: ~5 s, 18 git
  subprocesses, by design (live checks on the task pages). Candidate: render the
  shell fast and stream the inventory via Livewire lazy loading.
- Full-load HTML is ~1.3 MB/page (two sidebar copies, 587 inline SVGs, ~100 KB
  inline Alpine per copy) — background-task chip already filed; wire:navigate
  auto-routing (auto-navigate.js) makes full loads rare.
- First hit after a Vite restart pays a ~3–4 s Tailwind compile (`/resources/app.css`),
  then ~30 ms.
- `people.employees.index` at 104 queries is the next N+1 candidate (blb-people
  repo), same batching pattern applies.
- Scheduled `blb:ai:runs:reap-orphans` / `blb:ai:turns:sweep-stale` failed with
  exit 1 during the first crash window but pass when run manually; watch
  `runtime-scheduler.err.log` for recurrences. SQLite is already WAL with a 60 s
  busy timeout.

## Shell baseline

Every full page load costs ~45 queries before page content (menu tree,
per-item authz, settings, status bar) — the single most repeated statement is a
settings lookup executed 8×. Request-scoped memoization of settings reads is
the next system-level shaving if the baseline starts hurting; `wire:navigate`
requests skip nearly all of it today.
