# Page-Rendering Performance

Keep each page's **initial HTML within ~150 KB**. Oversized initial HTML is the
main client-side cost in BLB: the browser parses and hydrates everything the
server renders, so the slow "pockets" are simply the pages that render the most.

This guide is the convention; the rationale and history live in
`docs/plans/performance-page-rendering.md`.

For shell/chrome blink during `wire:navigate`, see
`docs/guides/livewire-navigation-blink.md`.

## Measure first — `blb:perf:page-weights`

```bash
php artisan blb:perf:page-weights --max-kb=150           # ranked inventory, flags over-budget
php artisan blb:perf:page-weights --max-kb=150 --limit=5 # just the worst offenders
```

It renders every no-param full-page Livewire component and ranks by HTML KB,
query count, and island count. Use it before and after a change to confirm the
win. Dev-only (it logs in a user and renders pages; it refuses in production).

The same command guards regressions in CI via `tests/Feature/System/PageWeightBudgetTest.php`
(`--strict --allow=...`). When you bring a page under budget, remove it from
that test's `$allow` ratchet; never add a page to it without a reason + a plan entry.

## What makes a page heavy, and the fix

Diagnose with the harness, then apply the matching pattern:

| Symptom | Fix |
|---------|-----|
| **Unbounded list** — every row of a collection rendered at once (registry/config/DB lists) | Paginate. For Eloquent use `WithPagination` + the shared concerns. For in-memory collections (menu registry, capability config) wrap the filtered collection in a `LengthAwarePaginator` (see `MenuInspector\Index`, `Authz\Capabilities\Index`). Reset to page 1 when a filter changes. |
| **Eager secondary section** — a below-the-fold panel, discovery list, or inactive tab rendered up-front | Extract it into a `#[Lazy]` child component with a skeleton `placeholder()`; it streams in on `x-intersect` after first paint (see `AI\Providers\CatalogBrowser`). |
| **Independent expensive widgets / polled live region** — a dashboard whose several *independent* costly panels render in one blocking `render()`, or a region that polls the whole component | Defer each panel with `@island(defer)` (or `lazy`) fed by a `#[Computed]`, or poll only the region with `wire:poll` *inside* an `@island` (see `Ibp\…\ExecutiveDashboard` — 4 charts deferred). See the decision note below. |
| **Large lookup combobox** — a select/lookup with hundreds of options | Use `x-ui.combobox`'s `search-url` mode (client-fetches options on open) instead of `:options`, backed by a small JSON search controller (see `CountrySearchController` and the address postcode/country fields). Never embed hundreds of options in the page. |
| **Dense detail** — wide per-row detail or expandable content | Render summary rows; load per-row/expandable detail on demand. |
| **Chatty render** — high query count from a per-item loop in `render()`/stats | Batch the lookups or use SQL aggregates; never load a whole collection to count or roll it up. |

## `@island` vs `#[Lazy]` child vs `wire:poll`

- **`@island(defer|lazy)`** — defer a *region inside a component* off first paint without a separate component (no props/communication). Best for independent dashboard widgets and expensive read-only panels. `defer` loads right after paint (`wire:init`); `lazy` loads on scroll (`wire:intersect.once`).
- **`#[Lazy]` child component** — when the deferred section is genuinely its own component (own state/lifecycle) or already one. Don't convert a working `#[Lazy]` child to an island for its own sake.
- **`wire:poll` inside an `@island`** — refresh only a live region on an interval instead of re-rendering the whole component.

Gotchas (Livewire 4.3, verified):

- **Data isolation** — an island re-renders with only `$this` (public props/methods/computed), *not* template-local/`@php`/`@foreach`-loop vars. Lift the region's data into a `#[Computed]` and derive it with **block `@php … @endphp`** (the `@php(...)` shorthand is mangled to `<?php(` inside islands). Derive it *after* `@endplaceholder` so the expensive compute doesn't run during the placeholder render.
- **Stale islands** — a normal action *skips* island re-render; an action that mutates island state must carry `wire:island="name"` (or the island uses `always: true`), else it shows stale content.
- **No nested `<livewire:>`** inside an island (HTML/Blade only); an island can't sit inside `@foreach`/`@if` (put the loop/conditional *inside* it).
- **Octane** — after editing a Blade/island view, run `php artisan octane:reload`; the FrankenPHP worker otherwise serves the stale compiled view (500 referencing an old island cache).

## Notes

- A `#[Lazy]` component's tag name is derived from the **first** `view('livewire.…')`
  call in the class file — define `render()` before `placeholder()`, or the
  component registers under the placeholder's name.
- `search-url` lookups should use a portable `LOWER(col) LIKE ?` (works on SQLite
  for tests and Postgres in production) rather than `ilike`.
- This complements the "Performant" principle in `resources/core/views/AGENTS.md`
  (paginate by default, `wire:key` in lists, `wire:model.live.debounce`, skeleton
  placeholders over spinners).
