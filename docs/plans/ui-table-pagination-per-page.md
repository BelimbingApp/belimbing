# ui-table-pagination-per-page

**Status:** Complete
**Last Updated:** 2026-07-02
**Sources:** None — spawned from review of `x-ui.table` and the pagination surface across 233 callers.
**Agents:** amp/glm-5.2

## Problem Essence

`x-ui.table` is a pure visual shell used in 233 Blade files, but its companion pagination story is fragmented and dishonest: every list renders `{{ $paginator->links() }}` by hand inside a local `<div class="mt-4">`, there is no first-class per-page selector anywhere, and the shared pagination chrome (the published `vendor/pagination/tailwind.blade.php`) ships raw inline SVG chevrons and concatenates `__('Showing')`/`__('to')`/`__('of')`/`__('results')` fragments — both violations of `resources/core/views/AGENTS.md` ("no raw SVG → use `x-icon`"; "translate whole sentences with placeholders; do not concatenate fragments"). List state (`search`, `sortBy`, `sortDir`, `perPage`) is also not URL-persistent, so refresh/back/share lose it, contradicting the DESIGN.md principle that meaningful list state belongs in the URL.

A second architectural mismatch: most list Livewire components do **not** extend `SearchablePaginatedList` — they compose concerns (`ResetsPaginationOnSearch`, `TogglesSort`) onto `Livewire\Component` directly and hardcode their own `->paginate(N)`. Any per-page solution must follow that concern-composition pattern, not live only inside `SearchablePaginatedList`.

## Desired Outcome

One blessed, accessible pagination surface for every list in BLB: a `x-ui.pagination` primitive that owns the row-count summary, the page links, and an optional per-page selector; a `SelectsPerPage` concern that any list component can compose to get URL-persistent, clamped per-page state; and the shared chrome upgraded to `x-icon` + whole-sentence translations so all 233 lists improve with **zero per-caller migration** for the chrome and **opt-in** adoption for the per-page selector.

## Top-Level Components

- **`x-ui.pagination`** — Blade component owning summary + links + optional per-page selector. The blessed pagination surface; new lists use it, existing lists migrate opportunistically.
- **`pagination-links` / `pagination-simple-links`** — pure-chrome Blade views (paginator-in, no Livewire coupling) registered as `Paginator::defaultView` / `simpleView` so **every** `->links()` call auto-upgrades to `x-icon` + whole-sentence i18n.
- **`Concerns\SelectsPerPage`** — trait: public `$perPage` (URL-persistent via `#[Url]`), `perPageOptions()` hook, `updatedPerPage()` clamp + `resetPage()`, `clampedPerPage()` helper. Composable by both `SearchablePaginatedList` subclasses and standalone `Component` lists.
- **`SearchablePaginatedList`** — adopts `SelectsPerPage`; gains `#[Url]` on `$search`/`$sortBy`/`$sortDir`; `perPage()` reads the clamped value.
- **`x-ui.th` / `x-ui.sortable-th`** — gain `numeric` (tabular-nums + right alignment), `width`, `nowrap` props to retire repeated per-call hand-rolling.
- **`x-ui.select`** — gains opt-in `block` prop (default true) so `x-ui.pagination` can render an inline per-page selector without forcing a full-width form field.

## Design Decisions

**Where the per-page selector lives.** Options: (a) bolt onto `x-ui.table`; (b) a free-standing `x-ui.pagination` component; (c) a vendor pagination-view slot. **Recommend (b).** `x-ui.table` is explicitly a Livewire-agnostic shell ("Caller owns sorting, pagination…"); injecting `wire:model` state there would leak Livewire coupling into a pure-presentational component and break its 233 existing callers' contract. A vendor-view slot cannot synthesize per-list `$perPage` state. A dedicated `x-ui.pagination` is the honest home: deep module, simple surface, opt-in, and it owns exactly the pagination region's concerns (summary, links, per-page).

**How the chrome upgrades without migrating 233 callers.** Options: (a) edit the published `vendor/pagination/tailwind.blade.php` in place; (b) create `components/ui/pagination-links` and register it as `Paginator::defaultView`. **Recommend (b).** The vendor path is framework-convention scaffolding; `x-ui.*` primitives are the owned, reviewed surface (AGENTS.md: "create it under components/ui/"). Registering `defaultView` upgrades all `->links()` callers uniformly with one registration, and the old `tailwind.blade.php` / `simple-default.blade.php` are deleted to avoid dead-code drift. No split-world entropy: the chrome is identical everywhere; only the per-page selector is opt-in and only where wired.

**Per-page state and persistence.** Options: (a) per-list session/cookie preference; (b) `#[Url]` URL query param via the trait; (c) component-local only. **Recommend (b).** URL persistence is the DESIGN.md-stated contract, makes per-page shareable/back-safe, and lives in one place (the trait) so all adopters get it. Session/cookie persistence is deferred — speculative against the current need and adds a storage contract. The trait declares `$perPage` with `#[Url]`; consumers override the default value and `PER_PAGE_OPTIONS` const per class. `updatedPerPage()` clamps to the option list (or a sane max when options are empty) and calls `resetPage()` so an out-of-range `?perPage=` from a stale link cannot blow up the query.

**`x-ui.select` inline mode.** Rather than hand-roll a local `<select>` in `x-ui.pagination` (which would invite a "use `x-ui.select`" flag), add an opt-in `block` prop to `x-ui.select`: default behavior unchanged; `block=false` drops `w-full` and the label-scaffolding wrapper so the per-page selector renders compactly. Additive, reusable beyond pagination, zero regression for existing callers.

## Public Contract

- `x-ui.pagination` props: `paginator` (LengthAwarePaginator|Cursor|AbstractPaginator), `perPageOptions` (array, `[]` hides selector), `perPage` (int|null, bound via `wire:model`), `summary` (bool, default true).
- `Concerns\SelectsPerPage`: `public int $perPage = 25` (`#[Url]`); `protected const array PER_PAGE_OPTIONS = [10, 25, 50, 100]`; `public function perPageOptions(): array`; `public function updatedPerPage(mixed $value): void` (clamp + resetPage); `protected function clampedPerPage(?int $value = null): int`.
- `SearchablePaginatedList`: now `use SelectsPerPage`; `$search`/`$sortBy`/`$sortDir` carry `#[Url]`; `perPage()` returns `$this->clampedPerPage()`.
- `x-ui.th` / `x-ui.sortable-th`: new optional props `numeric` (bool), `width` (string|null), `nowrap` (bool); `numeric` forces right alignment + `tabular-nums`.
- `x-ui.select`: new optional `block` prop (default true); `block=false` → inline `w-auto` control without label-scaffold wrapper.
- `Paginator::defaultView` = `ui.pagination-links`; `Paginator::simpleView` = `ui.pagination-simple-links`.

## Phases

### Phase 1 — Per-page concern + URL persistence

- [x] Add `App\Base\Foundation\Livewire\Concerns\SelectsPerPage` trait (clamped `$perPage`, `#[Url]`, `updatedPerPage` reset+clamp, `clampedPerPage`, `perPageOptions`, `PER_PAGE_OPTIONS` const) {amp/glm-5.2}
- [x] Wire `SearchablePaginatedList` to `use SelectsPerPage`, add `#[Url]` to `$search`/`$sortBy`/`$sortDir`, return `clampedPerPage()` from `perPage()` {amp/glm-5.2}
- [x] Add `tests/Unit/Base/Foundation/Livewire/Concerns/SelectsPerPageTest.php` (clamp to options, clamp to max when empty, resetPage on update) {amp/glm-5.2}

### Phase 2 — Shared chrome + defaultView registration

- [x] Create `resources/core/views/components/ui/pagination-links.blade.php` (pure chrome: whole-sentence summary, `x-icon` chevrons, page links, mobile simple variant) {amp/glm-5.2}
- [x] Create `resources/core/views/components/ui/pagination-simple-links.blade.php` (prev/next-only variant) {amp/glm-5.2}
- [x] Register `Paginator::defaultView`/`simpleView` in `App\Base\Foundation\ServiceProvider::boot()` {amp/glm-5.2}
- [x] Delete superseded `resources/core/views/vendor/pagination/tailwind.blade.php` and `simple-default.blade.php` {amp/glm-5.2}

### Phase 3 — `x-ui.pagination` component + inline `x-ui.select`

- [x] Add opt-in `block` prop to `x-ui.select` (default true; `block=false` → inline `w-auto`, no label scaffold) {amp/glm-5.2}
- [x] Create `resources/core/views/components/ui/pagination.blade.php` (`x-ui.pagination`: wraps `pagination-links`, renders per-page `x-ui.select` when `perPageOptions` non-empty) {amp/glm-5.2}

### Phase 4 — `x-ui.th` / `x-ui.sortable-th` numeric helpers

- [x] Add `numeric`, `width`, `nowrap` props to `x-ui.th` {amp/glm-5.2}
- [x] Add `numeric`, `nowrap` props to `x-ui.sortable-th` {amp/glm-5.2}

### Phase 5 — Representative caller migration (proof + validation)

- [x] Migrate `SearchablePaginatedList`-backed list views (failed-jobs, job-batches, integration-parameters) to `x-ui.pagination` with per-page selector {amp/glm-5.2}
- [x] Migrate one standalone `Component` list (audit mutations) to `x-ui.pagination` with per-page selector to validate the concern-composition path {amp/glm-5.2}
- [ ] Leave remaining ~225 `->links()` callers on the auto-upgraded chrome; per-page adoption is opt-in per list (tracked inline, not a forced mass migration)

Validation

- `php artisan test tests/Unit/Base/Foundation/Livewire/Concerns/SelectsPerPageTest.php`
- `php artisan test tests/Unit/Base/Foundation/Livewire/Concerns/TogglesSortTest.php` (guard no regression in sibling concern)
- `vendor/bin/pint --test` on touched files
- Manual: open `/admin/system/failed-jobs`, change per-page, refresh — URL retains `?perPage=50`; back/forward restores state; sort/search persist in URL.

## Notes

- The ~225 unmigrated lists are **not** entropy: their chrome is identical to migrated lists (same `defaultView`); only the per-page selector is an opt-in feature where a list wires it. Split-world entropy would only arise if chrome diverged — it does not.
- `#[Url]` on `SearchablePaginatedList.$search` is a real behavior change for its subclasses: search terms now appear in the address bar and share links. Desired per DESIGN.md; flagged here so reviewers see it.
