# performance-page-rendering

**Status:** Identified
**Last Updated:** 2026-06-02
**Sources:** `docs/installation/windows.md` (Performance §); Chrome traces `Trace-20260601T220533` and `Trace-20260602T111445` (Downloads, analysed in session); `resources/core/views/components/layouts/app.blade.php`; `app/Modules/Core/AI/Livewire/Chat.php`; `app/Base/Database/Livewire/DatabaseTables/Index.php`; `app/Modules/Commerce/Marketplace/Livewire/Ebay/Index.php`; existing Livewire concerns `SearchablePaginatedList`, `ResetsPaginationOnSearch`
**Agents:** claude/opus-4.8

## Problem Essence

The server-side slowness is solved (Windows Defender file-scanning plus OPcache tuning). What remains is client-side: every `wire:navigate` rebuilds and re-hydrates the **entire** page DOM — including the unchanged app shell and Lara chat — and data-dense pages ship oversized initial HTML (the schema browser is ~1 MB for ~500 elements) that the browser must parse and wire up. `@persist` is used nowhere, lazy rendering is used once, and pagination/result-capping is applied inconsistently. The slow "pockets" are simply the pages that render the most.

## Desired Outcome

A small set of reusable rendering patterns, applied across classified page sets, so that:

- The stable shell — top bar, sidebar, status bar, and Lara chat — persists across navigation and is never rebuilt; chat open/closed state, scroll position, and in-flight streaming continue uninterrupted while the user moves between pages.
- Every list/table view is bounded by pagination; no render path hydrates an unbounded set of models.
- Secondary and below-the-fold sections load on demand rather than inflating the initial document.
- Data-dense rows render only a visible summary, with detail loaded on expand.

Concretely: initial HTML per page within a budget (target ~150 KB, down from ~1 MB on the worst pages), hydration well under ~150 ms, and navigation that feels instant because only the main content region swaps.

## Top-Level Components

1. **Persistent App Shell** — the layout chrome (top bar, sidebar, status bar) and the Lara chat live in persisted islands so `wire:navigate` swaps only the main content region.
2. **Bounded Lists** — a single pagination/search standard, reused everywhere a collection is rendered; no unbounded "load all" in render or stats paths.
3. **Deferred Sections** — lazy Livewire islands for dashboards' secondary panels, inactive tabs, and below-the-fold widgets.
4. **Visible-Only Detail** — dense tables render summary rows; per-row and expandable detail load on demand.
5. **Page-Weight Triage** — a repeatable harness that renders each page component and reports HTML size / row counts, producing the ranked inventory that classifies pages into the sets above and guards against regression.

## Design Decisions

**Persist the shell, swap the content.** Today the whole body re-morphs on every navigation, so the sidebar menu and the always-mounted Lara chat re-hydrate each time even though they did not change. Wrapping the chat and chrome in persisted islands (Livewire's `@persist` for `wire:navigate`) keeps them mounted: their hydration cost is paid once per hard load, not per navigation, and the chat keeps its state and any in-flight streaming as the user navigates. This directly answers the request that Lara chat be independent of the page. Recommended over the heavier alternative of isolating chat in an iframe or a separate SPA root — that also survives a hard browser reload but adds cross-context messaging and a second asset load; revisit only if hard-reload continuity becomes a firm requirement. Hard reload (F5) is handled separately by the chat's existing session restore from local storage, so the live-persist work targets `wire:navigate`, which is the common case.

**One pagination standard, everywhere a list renders.** The concerns already exist; the gap is coverage. Treat "a Livewire view that renders a collection without a paginator" as a defect to be fixed, not a judgement call. Equally, any service query that loads all rows to compute a number (dashboard stats, counts) should aggregate in SQL rather than hydrate models — the eBay dashboard and stats currently load every listing to render a capped view.

**Lazy by default for secondary content.** Anything not needed for first meaningful paint — secondary dashboard panels, inactive tab bodies, expensive widgets — becomes a lazy island so it neither inflates the initial HTML nor blocks hydration. Eager rendering is reserved for the primary content the user navigated to see.

**Render only what's visible.** Hidden tab bodies and per-row expanded detail should not exist in the initial DOM; they load on activation. This is the change that turns "1 MB for 500 elements" back into a lean document, because the weight there is embedded detail the user cannot see yet.

**Measure to target.** Effort goes where weight is. The triage harness classifies pages by rendered size so we apply each pattern to the set that needs it, and so regressions are visible later.

These follow the project's Deep Modules and Low-Entropy principles: reuse the existing shared concerns rather than per-page bespoke code, and fix the pattern across the set rather than one page at a time.

## Public Contract

Conventions every page is expected to follow once the patterns land:

- The app shell (top bar, sidebar, status bar, Lara chat) renders once and persists across `wire:navigate`; page components render only into the main content region.
- Every view that renders a list or table paginates through the shared concerns; page size is defined centrally; search resets pagination via `ResetsPaginationOnSearch`.
- No render path loads an unbounded collection of Eloquent models; counts and rollups use SQL aggregates.
- Secondary and below-the-fold sections are lazy islands, not eager markup.
- A per-page initial-HTML budget (target ~150 KB) is the guardrail; the triage harness reports violators.

## Phases

### Phase 1 — Triage & inventory

Goal: replace guesswork with a ranked list of what to fix.

- [ ] Build a repeatable harness that renders each Livewire page component as an authenticated user and reports rendered HTML size, collection/row counts, and embedded-component count
- [ ] Produce a ranked page-weight inventory and classify each heavy page into a set (shell, unbounded list, eager-secondary, dense-detail)
- [ ] Record the initial-HTML budget (~150 KB) and the current worst offenders as the baseline to beat

### Phase 2 — Persistent app shell, including Lara chat (priority)

Goal: navigation swaps only main content; the shell and chat are never rebuilt.

- [ ] Move the shell chrome and the Lara chat into persisted islands so `wire:navigate` preserves them
- [ ] Verify chat open/closed state, scroll position, and an in-flight stream survive navigation between several pages
- [ ] Re-trace to confirm shell + chat hydration is paid once per hard load, not per navigation

### Phase 3 — Bound every list

Goal: no page renders or loads an unbounded collection.

- [ ] From the inventory, add pagination (shared concerns) to list/table views lacking it — the schema browser (`DatabaseTables\Index`) is the first target
- [ ] Replace "load all models" in stats/dashboard services with SQL aggregates or windowed queries (e.g. eBay `dashboard()` / `stats()`)

### Phase 4 — Defer secondary sections

Goal: initial document carries only primary content.

- [ ] Convert secondary dashboard panels, inactive tabs, and below-the-fold widgets to lazy islands
- [ ] Re-measure converted pages against the budget

### Phase 5 — Visible-only detail

Goal: dense pages emit summaries, not hidden detail.

- [ ] For dense tables/detail pages, render summary rows and load per-row/expandable detail on demand
- [ ] Re-measure the worst offenders (schema browser, eBay) against the budget

### Phase 6 — Guardrails

Goal: keep the wins from eroding.

- [ ] Document the conventions in a guide and point the relevant `AGENTS.md` at it
- [ ] Add a lightweight check (test or script, reusing the Phase 1 harness) that flags pages exceeding the HTML budget so regressions surface in review
