# performance-page-rendering

**Status:** Identified
**Last Updated:** 2026-06-02
**Sources:** `docs/installation/windows.md` (Performance §); Chrome traces `Trace-20260601T220533` and `Trace-20260602T111445` (analysed in session); `resources/core/views/components/layouts/app.blade.php`; `resources/core/views/components/menu/`; `app/Modules/Core/AI/Livewire/Chat.php`; `app/Base/Menu/ServiceProvider.php`; `app/Base/Database/Livewire/DatabaseTables/Index.php`; `app/Modules/Commerce/Marketplace/Livewire/Ebay/Index.php`; Livewire 4.3 features `SupportIslands`, `SupportWireCurrent`, `SupportNavigate` (`@persist`/`livewire:navigated`), `LazyLoading`, `Computed`; related plan `framework-modernization.md`
**Agents:** claude/opus-4.8

## Problem Essence

The server-side slowness is solved (Windows Defender file-scanning plus OPcache tuning). What remains is client-side and structural: the page is one monolith. Every `wire:navigate` re-fetches and re-morphs the **entire** body — rebuilding the sidebar and tearing down Lara chat — and data-dense pages ship oversized initial HTML (the schema browser is ~1 MB) that the browser must parse and hydrate. The slow "pockets" are simply the pages that render the most.

## Desired Outcome

The application renders as **coordinated Livewire islands**, not a monolith:

- A **persistent Lara chat** island that survives navigation untouched — open/closed state, scroll, and in-flight streaming continue while the user moves between pages.
- A **persistent sidebar** island whose structure is not rebuilt per navigation, with active-link state driven live from the URL, and which refreshes only when something real changes (new menu item, pin, permission, company switch).
- A **swappable main body** island — the only region that re-fetches on navigation — kept lean by pagination, lazy sections, and visible-only detail.

Targets: navigation feels instant because only the main body changes; the sidebar and chat are hydrated once per hard load, not per navigation; initial HTML per page within a budget (target ~150 KB, down from ~1 MB on the worst pages).

## Top-Level Components

1. **Coordinated Islands** — the shell is composed of independently-updating, event-coordinated regions: Lara chat (persisted, stateful), sidebar (persisted structure + live active-state), and main body (swapped on navigation). Built on Livewire 4's `Islands`, `WireCurrent`, `Navigate`/`@persist`, and `LazyLoading` rather than custom plumbing.
2. **Bounded Lists** *(within the main body)* — one pagination/search standard wherever a collection is rendered; no unbounded model loads in render or stats paths.
3. **Deferred Sections** *(within the main body)* — lazy islands for secondary panels, inactive tabs, and below-the-fold widgets.
4. **Visible-Only Detail** *(within the main body)* — dense tables render summary rows; per-row and expandable detail load on demand.
5. **Page-Weight Triage** — a repeatable harness that renders each page and reports HTML size / row counts, producing the ranked inventory and guarding against regression.

## Design Decisions

**Compose the shell as islands, not a monolith.** `wire:navigate` today morphs the whole body, so the sidebar re-renders and (without persistence) the chat is destroyed on every navigation. Instead: Lara chat lives in a persisted island (Livewire's `@persist`) and is never rebuilt; the sidebar's structure is persisted while its active highlight is driven by `wire:current`, which re-evaluates from the URL on `livewire:navigated` with no server round-trip; only the main body re-fetches per navigation. These are first-class Livewire 4.3 features (`SupportIslands`, `SupportWireCurrent`, `SupportNavigate`) — the framework ships a tested fixture combining `@persist` + `wire:current` for exactly a navbar/sidebar — so this is the intended pattern, not bespoke machinery.

**Coordinate by events, not by rebuilding.** Structural changes that must reach a persisted island — a newly added menu item, a pin toggle, a company switch, an impersonation start/stop, a permission change — are delivered as dispatched events the relevant island listens for and refreshes on. This is the answer to "what happens when a menu is added": the active highlight updates live via `wire:current`, and the structure refreshes when its triggering event fires (or on the next full load for deploy-time additions) — no whole-page rebuild and no staleness. The classic islands failure mode is a *missed* coordination event, so the event→island map is part of the Public Contract and is tested, not left implicit.

**The sidebar moves from a Blade component to an island.** It is currently `<x-menu.sidebar>`, a Blade component re-rendered by a per-request view composer; it must become an island/Livewire component to update independently. It is on every page, so this is done carefully behind the existing menu cache, preserving today's per-user pins and permission filtering.

**Lean the main body.** Within the swappable island the established patterns apply: paginate every list (reuse the existing `SearchablePaginatedList` / `ResetsPaginationOnSearch` concerns), lazy-load secondary sections, and render only visible detail. This is where the 1 MB pages are trimmed; the islands work makes navigation cheap, this work makes each page light.

**Measure to target.** The triage harness classifies pages by rendered size so effort lands where weight is and regressions stay visible.

These follow the project's Deep-Modules and Strategic-Programming principles: invest in a clean shell once (BLB is a multi-module framework with plurality on the roadmap), and reuse framework primitives and shared concerns rather than per-page bespoke code.

## Public Contract

- The shell is three coordinated islands; only the main-body island re-fetches on `wire:navigate`.
- Lara chat persists across navigation with its state intact.
- Sidebar active-state is driven by `wire:current`; sidebar structure refreshes only on its coordination events (e.g. `menu-changed`, `pins-changed`, `context-changed`) or a full load — never by a whole-page rebuild.
- The event→island coordination map is documented and tested; no island depends on a full-page morph for correctness.
- Within the main body: every list/table paginates via the shared concerns; no render path loads an unbounded collection of models (counts/rollups use SQL aggregates); secondary and below-the-fold sections are lazy; dense detail loads on demand.
- A per-page initial-HTML budget (target ~150 KB) is the guardrail; the triage harness reports violators.

## Phases

### Phase 1 — Triage & inventory

Goal: replace guesswork with a ranked list of what to fix.

- [ ] Build a repeatable harness that renders each Livewire page component (authenticated) and reports rendered HTML size, collection/row counts, and embedded-component count
- [ ] Produce a ranked page-weight inventory; classify each heavy page (unbounded list, eager-secondary, dense-detail)
- [ ] Record the initial-HTML budget (~150 KB) and the current worst offenders as the baseline

### Phase 2 — Island shell foundation (priority)

Goal: navigation re-fetches only the main body; sidebar and chat are coordinated islands, never rebuilt.

> Note: an interim `#[Lazy]` on the chat (committed earlier) was reverted — it broke `ChatViewTest` by emitting a placeholder. Chat deferral belongs here, delivered together with `@persist` and updated tests, not as a standalone attribute.

- [ ] Persist Lara chat as an independent island (with deferral) so it survives navigation; update `ChatViewTest` for the deferred/persisted behavior; verify open/closed state, scroll, and an in-flight stream survive navigation across several pages
- [ ] Convert the sidebar to an island (persisted structure) with `wire:current` active-state; verify the highlight updates on navigation and matches today's wildcard route semantics
- [ ] Define the event→island coordination map (`menu-changed`, `pins-changed`, `context-changed`, impersonation) and wire the dispatchers; verify a newly added menu item, a pin toggle, and a company switch each reflect without a full reload
- [ ] Re-trace: confirm `wire:navigate` re-fetches only the main body and shell hydration is paid once per hard load

### Phase 3 — Bound every list (main body)

- [ ] From the inventory, add pagination (shared concerns) to list/table views lacking it — the schema browser (`DatabaseTables\Index`) is the first target
- [ ] Replace "load all models" in stats/dashboard services with SQL aggregates or windowed queries (e.g. eBay `dashboard()` / `stats()`)

### Phase 4 — Defer secondary sections (main body)

- [ ] Convert secondary dashboard panels, inactive tabs, and below-the-fold widgets to lazy islands
- [ ] Re-measure converted pages against the budget

### Phase 5 — Visible-only detail (main body)

- [ ] For dense tables/detail pages, render summary rows and load per-row/expandable detail on demand
- [ ] Re-measure the worst offenders (schema browser, eBay) against the budget

### Phase 6 — Guardrails

- [ ] Document the conventions in a guide and point the relevant `AGENTS.md` at it
- [ ] Add a lightweight check (test or script, reusing the Phase 1 harness) that flags pages exceeding the HTML budget so regressions surface in review
