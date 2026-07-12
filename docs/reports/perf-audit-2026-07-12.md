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

## Incident: silent FrankenPHP deaths (open)

Three times today (13:45, 14:07, 14:12) FrankenPHP and the queue worker died
mid-burst while curling pages; the launcher then tore the rest down. Observed
around rapid sequential requests that included kiat pages; **not** reproducible
with 1 s pacing (12+ requests fine). FrankenPHP exits with nothing on stderr
(no Go panic, no PHP fatal), no WER crash event, nothing in laravel.log.
FrankenPHP runs PHP 8.5.6 ZTS with 32 threads.

Done now: the launcher captures every child's stdout/stderr to
`storage/logs/runtime-<name>.{out,err}.log`, so the next occurrence leaves
evidence. Recommended next:

1. Enable WER LocalDumps for `frankenphp.exe` (registry, needs operator) so a
   native fault produces a minidump.
2. Make the launcher restart a dead child (at minimum FrankenPHP) instead of
   tearing down the whole stack — one silent exit currently takes the site down
   until a human relaunches.
3. If a dump implicates PHP 8.5 ZTS or an extension, report upstream to
   FrankenPHP with it.

Related observation while investigating: `git.exe` 2.54.0.1 logged an access
violation on 2026-07-11 (Event Log) — the git subprocess load from inventory
scans is heavy; the new warmer reduces how often web workers spawn git at all.

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
