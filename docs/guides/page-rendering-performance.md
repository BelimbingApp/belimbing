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
| **Large lookup combobox** — a select/lookup with hundreds of options | Use `x-ui.combobox`'s `search-url` mode (client-fetches options on open) instead of `:options`, backed by a small JSON search controller (see `CountrySearchController` and the address postcode/country fields). Never embed hundreds of options in the page. |
| **Dense detail** — wide per-row detail or expandable content | Render summary rows; load per-row/expandable detail on demand. |
| **Chatty render** — high query count from a per-item loop in `render()`/stats | Batch the lookups or use SQL aggregates; never load a whole collection to count or roll it up. |

## Notes

- A `#[Lazy]` component's tag name is derived from the **first** `view('livewire.…')`
  call in the class file — define `render()` before `placeholder()`, or the
  component registers under the placeholder's name.
- `search-url` lookups should use a portable `LOWER(col) LIKE ?` (works on SQLite
  for tests and Postgres in production) rather than `ilike`.
- This complements the "Performant" principle in `resources/core/views/AGENTS.md`
  (paginate by default, `wire:key` in lists, `wire:model.live.debounce`, skeleton
  placeholders over spinners).
