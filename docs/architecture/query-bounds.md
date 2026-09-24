# Query Bounds

**Document Type:** Architecture
**Scope:** Bounding how many rows application code loads into PHP: the runtime hydration guard and the static growing-table rule
**Last Updated:** 2026-09-24

## Overview

Belimbing bounds row loading at two layers, both owned by `app/Base/Database`:

- **Runtime:** `HydrationGuard` counts the Eloquent models each unit of work hydrates and reacts once the count crosses a limit. It throws in `local` and `testing`, so an unbounded load fails its test, and logs one structured warning per unit of work everywhere else, so production never fails a request because of the guard itself.
- **Static:** `GrowingTableUnboundedLoadRule` reports code that loads every row of a model marked `GrowingTable` unless the query is limited, paginated, chunked, or cursored. It runs in the custom PHPStan ruleset (`phpstan-feature-flags.neon`) across all four application roots, and in the main Larastan pass (`phpstan.neon`) over `app/Base`.

The same shape applies in every environment; only the runtime mode differs. The origin is the 2026-09 production Schedule page failure: one `get()` over 111,785 `base_schedule_runs` rows exhausted PHP memory while finding the latest run per task. Retention and pruning are a separate concern owned by each growing table's module.

This doc describes implemented behavior.

## The habit

Never load a whole growing table into PHP. Page (`paginate`, `forPage`), bound (`limit`, `take`), or ask the database for the answer (an aggregate, a `groupBy`, a latest-per-key subquery) and then load only the identified rows. A deliberate bulk pass streams (`chunk`, `lazy`, `cursor`) inside `HydrationGuard::suspend()`: streaming bounds memory, but the guard counts every streamed row, so streaming alone does not satisfy it.

## Runtime: hydration guard

Owner: `App\Base\Database\Services\HydrationGuard`, registered in `App\Base\Database\ServiceProvider`. Configuration: `app/Base/Database/Config/hydration_guard.php` (`HYDRATION_GUARD_LIMIT`). The guard is always on and its mode follows the environment.

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
- `chunk()`, `lazy()` and `cursor()` fire `retrieved` per row and are counted like everything else. The guard measures work per unit, not peak memory, so a streamed bulk pass must also declare itself with `suspend()` (below).
- `Model::withoutEvents()` and an unset model dispatcher are not counted; those paths already declare themselves as bulk.
- Query-builder results (`DB::table()->get()`) are plain rows, not models, and are not counted. The static rule does not cover them either; keep such loads bounded by hand.

### Modes

| Environment | Mode | Crossing the limit |
|-------------|--------------|--------------------|
| `local`, `testing` | `throw` | `HydrationLimitExceededException` (`hydration_limit_exceeded`) from inside the load |
| everything else | `log` | one `warning` per window: `Hydration guard: {unit} hydrated more than {limit} Eloquent models (...)` |

Both carry the unit (`http` with method, path and route; `job` with class; `command` with name; `process`), the Livewire component when one is on the stack, the enclosing unit when nested, the limit, and the top five hydrated model classes with counts. In log mode the reporting is wrapped so a logging failure cannot become a request failure. The mode is not configurable: production never throws.

### Deliberate bulk passes

Stream the pass (`chunk`, `chunkById`, `lazy`, `lazyById`, `cursor`) and wrap it in the guard rather than raising the global limit:

```php
app(HydrationGuard::class)->suspend(fn () => OutboundExchange::query()->chunkById(200, $prune));
```

Rows hydrated inside `suspend()` are not counted. Nested calls stack, and the count resumes in `finally`. `suspend()` is the only escape; it does not make an unstreamed `get()` safe, because the memory problem remains. Raise `HYDRATION_GUARD_LIMIT` only when an instance's ordinary units of work legitimately exceed the default, and say why in the deployment's environment file.

## Static: growing-table rule

Owner: `App\Base\Database\PHPStan\GrowingTableUnboundedLoadRule`, identifier `blb.growingTableUnboundedLoad`. Run it across all roots with the custom ruleset:

```bash
vendor/bin/phpstan analyse -c phpstan-feature-flags.neon --memory-limit=2G
```

### Marking a growing table

A model whose table grows with use and is never bounded by the size of the business — logs, history, runs, audit trails, event streams, ledgers, sessions — implements `App\Base\Database\Contracts\GrowingTable`. The marker is explicit rather than inferred from a name so the contract is reviewable; apply it when creating such a model. Reference data (roles, types, settings) and business entities bounded by the organisation (companies, employees) are not growing tables.

### What is reported

`->get()`, `->all()`, `->pluck()`, `->getModels()` and `::all()` on a growing-table model, whether reached through `Model::query()`, a static forwarder such as `Model::where()`, or a relation (`$task->runs()->get()`), unless the same call chain is limited by `limit`, `take`, `forPage`, `forPageAfterId` or `forPageBeforeId`, or restricted to known ids with `whereKey`.

`paginate()`, `chunk()`, `lazy()`, `lazyById()`, `cursor()`, `first()`, `count()` and other non-loading terminals are never reported, and neither are collection calls made after one of them.

### Reviewed exceptions

Every other bound is invisible to the rule and is reported: one parent's rows (a run's events, a trace's audit entries), an aggregate that reduces the result (`groupBy`, a latest-per-key ranked subquery), a bound applied on another statement, or a subset bounded by live state (running jobs, active sessions). After review, suppress such a call inline, on the line above the statement, with the reason it is bounded:

```php
// @phpstan-ignore blb.growingTableUnboundedLoad (one ranked run per requested task key)
$latest = ScheduleRun::query()->fromSub($ranked->toBase(), 'ranked')->where('run_rank', 1)->get();
```

Each such exception is a reviewed claim about the data. Prefer restating the bound in the query (`limit`, `whereKey`) whenever one exists.

Lazy relation properties (`$task->runs`) and `DB::table()` access are outside the rule; the runtime guard is the backstop for those.

## Related

- `app/Base/Database/AGENTS.md` — module guide
- `docs/architecture/database.md` — schema, registries, migrations
- `docs/guides/feature-flag-ownership.md` — the other rules in the same custom ruleset
