# Query Bounds

**Document Type:** Architecture
**Scope:** Bounding how many rows application code loads into PHP: the runtime hydration guard and the static growing-table rule
**Last Updated:** 2026-09-24

## Overview

Belimbing bounds row loading at two layers, both owned by `app/Base/Database`:

- **Runtime:** `HydrationGuard` counts the Eloquent models each unit of work hydrates and reacts once the count crosses a limit. It throws in `local` and `testing`, so an unbounded load fails its test, and logs one structured warning per unit of work everywhere else, so production never fails a request because of the guard itself.
- **Static:** `GrowingTableUnboundedLoadRule` reports code that loads every row of a model marked `GrowingTable` unless the query is visibly bounded. It runs in the custom PHPStan ruleset (`phpstan-feature-flags.neon`) across all four application roots, and in the main Larastan pass (`phpstan.neon`) over `app/Base`.

The same shape applies in every environment; only the runtime mode differs. The origin is the 2026-09 production Schedule page failure: one `get()` over 111,785 `base_schedule_runs` rows exhausted PHP memory while finding the latest run per task. Retention and pruning are a separate concern owned by each growing table's module.

This doc describes implemented behavior.

## The habit

Never load a whole growing table into PHP. Page (`paginate`, `forPage`), bound (`limit`, `take`), stream (`chunk`, `lazy`, `cursor`), or ask the database for the answer (an aggregate, a `groupBy`, a latest-per-key subquery) and then load only the identified rows (`whereKey`).

## Runtime: hydration guard

Owner: `App\Base\Database\Services\HydrationGuard`, registered in `App\Base\Database\ServiceProvider`. Configuration: `app/Base/Database/Config/hydration_guard.php` (`HYDRATION_GUARD_ENABLED`, `HYDRATION_GUARD_LIMIT`, `HYDRATION_GUARD_MODE`).

### Units of work

The guard counts against the innermost open window:

| Unit | Opened by | Closed by |
|------|-----------|-----------|
| HTTP request, including Livewire updates | `GuardRequestHydration`, first global middleware | its `terminate()` |
| Queued job (any driver, including `sync`) | `JobProcessing` | `JobProcessed`, `JobExceptionOccurred`, `JobFailed` |
| Console command, including `Artisan::call` | `CommandStarting` | `CommandFinished` |
| Process root | always present | never |

Windows nest: a sync job inside a request or an `Artisan::call` inside a command is its own unit, and the report names the enclosing unit too. A `Livewire::test()` or plain unit test lands in the process root; the report still names the Livewire component on the stack. Opening a request discards every window, which is what keeps Octane workers clean between requests.

Laravel bridges Symfony console events to `CommandStarting` only outside unit tests, so under Pest a command's loads count against the enclosing test's window, not a command window.

### Counting

Counting happens on the Eloquent `retrieved` event: one array increment per model, which is the whole hot path. Consequences:

- Eager loads and relation loads count, because they hydrate models.
- `chunk()`, `lazy()` and `cursor()` fire `retrieved` per row and are counted like everything else. The guard measures work per unit, not peak memory; a deliberate bulk pass declares itself (below).
- `Model::withoutEvents()` and an unset model dispatcher are not counted; those paths already declare themselves as bulk.
- Query-builder results (`DB::table()->get()`) are plain rows, not models, and are not counted. The static rule does not cover them either; keep such loads bounded by hand.

### Modes

| Environment | Default mode | Crossing the limit |
|-------------|--------------|--------------------|
| `local`, `testing` | `throw` | `HydrationLimitExceededException` (`hydration_limit_exceeded`) from inside the load |
| everything else | `log` | one `warning` per window: `Hydration guard: {unit} hydrated more than {limit} Eloquent models (...)` |

Both carry the unit (`http` with method, path and route; `job` with class; `command` with name; `process`), the Livewire component when one is on the stack, the enclosing unit when nested, the limit, and the top five hydrated model classes with counts. In log mode the reporting is wrapped so a logging failure cannot become a request failure. `HYDRATION_GUARD_MODE` overrides the default; an unknown value is a configuration error at boot.

### Deliberate bulk passes

Wrap the pass in the guard rather than raising the global limit:

- `app(HydrationGuard::class)->suspend(fn () => ...)` — not counted at all. Nested calls stack.
- `app(HydrationGuard::class)->withLimit(50_000, fn () => ...)` — a different threshold for the pass; the count already accrued in the window still applies.

Both restore state in `finally`. Raise `HYDRATION_GUARD_LIMIT` only when an instance's ordinary units of work legitimately exceed the default, and say why in the deployment's environment file.

## Static: growing-table rule

Owner: `App\Base\Database\PHPStan\GrowingTableUnboundedLoadRule`, identifier `blb.growingTableUnboundedLoad`. Run it across all roots with the custom ruleset:

```bash
vendor/bin/phpstan analyse -c phpstan-feature-flags.neon --memory-limit=2G
```

### Marking a growing table

A model whose table grows with use and is never bounded by the size of the business — logs, history, runs, audit trails, event streams, ledgers, sessions — implements `App\Base\Database\Contracts\GrowingTable`. The marker is explicit rather than inferred from a name so the contract is reviewable; apply it when creating such a model. Reference data (roles, types, settings) and business entities bounded by the organisation (companies, employees) are not growing tables.

A growing table that is read one parent at a time, such as a run's events, a session's artifacts, or a process run's work items, also declares that parent column with `#[App\Base\Database\Attributes\PartitionedBy('run_id')]`. A load filtered to one partition is bounded by that parent's activity, not by table growth. Declare only columns whose partitions stay small enough to hold in memory; a tenant or company id is the whole table, not a partition.

### What is reported

`->get()`, `->all()`, `->pluck()`, `->getModels()` and `::all()` on a growing-table model, whether reached through `Model::query()`, a static forwarder such as `Model::where()`, or a relation (`$task->runs()->get()`), unless the same call chain is bounded by one of:

- a bounding call: `limit`, `take`, `forPage`, `forPageAfterId`, `forPageBeforeId`, `whereKey`, `groupBy`, `groupByRaw`, `distinct`, `fromSub` (a ranked subquery such as `ROW_NUMBER() ... <= n`);
- an equality `where()` on a column the model declares in `#[PartitionedBy]`, or `whereBelongsTo()` a growing parent;
- a relation declared on a growing parent (`$run->events()->get()`), which is one parent's partition.

`paginate()`, `chunk()`, `lazy()`, `lazyById()`, `cursor()`, `first()`, `count()` and other non-loading terminals are never reported, and neither are collection calls made after one of them.

The blessed latest-per-key shape ranks rows in the database and reads only the top of each key, as `ScheduleBoard::latestSchedulerRuns()` does:

```php
$ranked = ScheduleRun::query()->whereIn('key', $keys)->select('*')
    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY key ORDER BY started_at DESC, id DESC) AS run_rank');
$latest = ScheduleRun::query()->fromSub($ranked->toBase(), 'ranked')->where('run_rank', 1)->get();
```

Wrap identifiers through the grammar in real code; the sketch leaves that out.

### Limits and exceptions

The rule walks one call chain. A bound applied on another statement, or a subset bounded by live state rather than by a query clause (running jobs, active sessions), is reported. After review, suppress such a call inline, on the line above the statement, with the reason it is bounded:

```php
// @phpstan-ignore blb.growingTableUnboundedLoad (running runs are bounded by worker concurrency)
```

Each such exception is a reviewed claim about the data. Prefer restating the bound in the query (`limit`, `whereKey`) whenever one exists.

Lazy relation properties (`$task->runs`) and `DB::table()` access are outside the rule; the runtime guard is the backstop for those.

## Related

- `app/Base/Database/AGENTS.md` — module guide
- `docs/architecture/database.md` — schema, registries, migrations
- `docs/guides/feature-flag-ownership.md` — the other rules in the same custom ruleset
