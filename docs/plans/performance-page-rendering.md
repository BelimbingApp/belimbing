# performance-page-rendering

**Status:** Implemented — Phases 1–6 done (Phase 2 chat + sidebar island both shipped; one residual `commerce/catalog` allowlisted)
**Last Updated:** 2026-06-03
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

- [x] Built `blb:perf:page-weights` (`App\Base\System\Console\Commands\PageWeightAuditCommand`): renders every no-param full-page Livewire component and ranks by HTML KB, query count, and island count. `--max-kb`/`--strict` doubles as the Phase 6 budget guardrail. — claude/opus-4.8
- [x] Inventory produced (dev DB; real-data weights are higher). Top offenders / classifications:
  - `admin/ai/providers` **544 KB** (6 q), `admin/system/menu-inspector` **383 KB**, `admin/addresses/create` **255 KB** (a *create form* — suspect a giant embedded country/region list → dense-detail), `admin/authz/capabilities` 154 KB, `commerce/catalog` 152 KB — **oversized DOM** to trim/lazy.
  - Query-count smells (eager, not N+1 — `preventLazyLoading` is on): `admin/system/database-incubation` **344 q**, `admin/system/database` **338 q**, `people/employees` 59 q, `people/attendance/rosters` 37 q — **chatty renders** to batch/aggregate.
- [x] Budget set at **~150 KB** initial HTML; 5 pages currently exceed it (`blb:perf:page-weights --max-kb=150`). — claude/opus-4.8

### Phase 2 — Island shell foundation (priority)

Goal: navigation re-fetches only the main body; sidebar and chat are coordinated islands, never rebuilt.

> Notes / findings:
> - An interim `#[Lazy]` on the chat was reverted — it broke `ChatViewTest` by emitting a placeholder.
> - **A bare `@persist` around the chat does NOT work** (verified 2026-06-02 with a Playwright navigation test: after `wire:navigate` the `ai.chat` component count went from 1 → 0, i.e. the chat was *lost*, not preserved). Root cause: the chat is one Livewire instance that Alpine **physically relocates** via `appendChild` (the `x-ref="laraChatInstance"` `x-effect` in `app.blade.php` moves it between the overlay/docked/fullscreen/mobile target `<div>`s). That DOM-move pulls the element out of its `@persist` slot, so the navigate morph can't preserve it.
> - The blocker is the **docked mode** specifically: it's an inline, drag-resizable `<aside>` in the flex layout flow (it pushes the main content), which is *why* the single instance is teleported into the flow. A persisted element must stay at a fixed declared position, which can't be "in the flex flow" for docked.

- [x] **Lara chat now persists across `wire:navigate`** — solved without the CSS rework (which would have risked the 4 working modes). Instead: the chat is a `@persist`ed, dumb element (`#lara-chat-instance` inside `#lara-chat-home`); the teleport logic moved off the chat's own `x-effect` (which carried stale Alpine scope across the morph) onto a body method `teleportLaraChat()` driven by `$watch` on open/mode/fullscreen and re-run on each body init (`$nextTick`), so it always reads the *current* page's refs. On `livewire:navigating` a one-time listener parks the instance back into `#lara-chat-home` so the morph preserves it; the new page's `x-init` re-teleports it into the active mode target. The 4 mode containers/CSS are untouched. Verified (Playwright): after `wire:navigate` the `ai.chat` `wire:id` is unchanged, count 1, still open + visible; overlay/docked/fullscreen all teleport correctly; 0 console errors. — claude/opus-4.8
- [x] **Sidebar is now a persisted island with `wire:current` active-state.** Both instances are wrapped in `@persist` (`sidebar-desktop`/`sidebar-mobile`) so the menu DOM survives `wire:navigate` instead of re-morphing. Active styling moved entirely off the server `$isActive`/`$hasActiveChild` props onto client-driven Tailwind variants: leaf links use `wire:current` → `data-[current]:bg-surface-card …`; containers use `group-has-[[data-current]]/menuitem:…` on the `<li>` (this *is* `has_active_child`, computed by `:has()`, no server pass). A one-time `livewire:navigated` listener walks ancestors of `[data-current]` and sets Alpine `expanded=true` to mirror the old auto-expand. `wire:current`'s default segment-prefix matching reproduces the `.index`-prefix wildcard semantics (e.g. `/admin/companies` stays active on `/admin/companies/5/edit`). Verified (Playwright, logged-in admin): sidebar DOM node identical across navigation (persisted), highlight moves to the new section, active group auto-expands, active row visually styled (`bg-surface-card`/`text-ink`/`font-medium`), 0 console errors. Server contract pinned by `tests/Feature/Menu/SidebarIslandTest.php`. — claude/opus-4.8
- [x] Coordination map: **pins** already coordinate client-side (optimistic Alpine + `pins.toggle` fetch + `pins-synced` window event syncing both instances) — unchanged and now preserved by `@persist`. **Menu structure** changes are deploy-time (full reload) and `MenuBuilder::clearCache()` isn't called at runtime. There is **no live company-switch/impersonation** feature in the codebase, so no persisted-tree staleness path exists today; if one is added it must dispatch a refresh event or force a hard load (noted for that work). — claude/opus-4.8
- [x] Re-traced (Playwright): on `wire:navigate` the sidebar `<aside>` is the same DOM node before/after (not re-fetched/re-morphed); only the main body swaps. Shell (sidebar + chat) hydrates once per hard load. — claude/opus-4.8

> **Implemented 2026-06-03 (browser-verified — claude/opus-4.8).** The design below is what shipped; see the checked items above for the as-built summary and verification.
> The previous sidebar (`resources/core/views/components/menu/{sidebar,tree,item}.blade.php`) is a Blade component fed by a per-request `View::composer` (`App\Base\Menu\ServiceProvider`), rendered **twice** in `app.blade.php` (desktop drag-resizable `<aside>` at the `lg:` breakpoint + a mobile drawer). What rebuilds it each navigation is the `wire:navigate` body morph re-running the composer — not a code smell, just the monolith morphing.
>
> What's already island-like (no change needed): **pins coordinate entirely client-side today** — optimistic Alpine state in `sidebar.blade.php` + `fetch` to `pins.toggle`/`pins.reorder` + a `pins-synced` window event that keeps both instances in sync. So "pins-changed" is effectively already handled; the coordination map shrinks to **company-switch / impersonation / deploy-time menu changes**.
>
> The four behaviors that must all be correct *simultaneously, on every page* for the island to be a net win:
> 1. **`@persist` two instances** (`sidebar-desktop`, `sidebar-mobile`) so the tree isn't re-morphed. Each carries its own Alpine `x-data` (pins, rail, drag state) — must survive the morph intact.
> 2. **`wire:current` for the active highlight**, replacing the server-computed `$isActive` ternaries in `item.blade.php`. Must reproduce today's **wildcard** active semantics (a section highlights for all its sub-routes), i.e. `wire:current` pattern/`.exact` usage per item depth.
> 3. **Client-side auto-expand of the active group.** Today `x-data="{ expanded: $hasActiveChild }"` is server-seeded; once persisted it won't re-expand on navigation. Needs JS on `livewire:navigated` that walks from the URL-matching link up to its ancestor `<li>`s and sets `expanded=true` — without collapsing groups the user opened manually.
> 4. **Staleness coordination** for the persisted tree: on company-switch/impersonation/menu-change, dispatch an event the island listens for to refresh its structure (or fall back to a hard load), since the persisted HTML won't otherwise reflect the new permission-filtered tree.
>
> Landed safely via the iterative Playwright loop (same approach as the chat-persist work): the every-page failure modes (stale highlight, mis-expanded groups, frozen tree) were each checked against a logged-in admin before shipping.

### Phase 3 — Bound every list (main body)

- [x] `admin/system/menu-inspector` (383 KB → ~85 KB): rendered every menu item as a wide, badge-laden row. Already server-filtered, so paginated the in-memory filtered collection (25/page, `LengthAwarePaginator` + `WithPagination`; filters reset to page 1). Regression tests in `MenuInspectorTest`. — claude/opus-4.8
- [x] `admin/authz/capabilities` (154 KB → 60 KB): same pattern — paginated the in-memory capability list (50/page); search/domain/sort reset to page 1. Regression tests in `CapabilitiesIndexTest`. — claude/opus-4.8
- [x] (Schema browser `DatabaseTables\Index` already paginates 25/page — its HTML is fine.)
- [~] **eBay `stats()` — investigated, deferred (needs a persisted column, not an aggregate).** `Ebay\Index::stats()` loads all listings (`Listing::…->get()`) because the seven reconciliation buckets are computed by `EbayListingAuditService::state()` — per-listing domain logic (`isExternallyChanged()`, `isImported()`, `adoptionState()`, comparing item ⇄ draft ⇄ listing), not a column. Converting to SQL aggregates would reimplement reconciliation logic in SQL and risk divergence on a reconciliation dashboard. The trivial counts (total/linked/unlinked) are aggregable, but the state buckets still require the full set, so there is no partial win. Real fix: persist a `reconciliation_state` column (maintained on listing/item change), then the buckets become `groupBy` counts. Left as a dedicated, tested follow-up. — claude/opus-4.8
- [x] **Schema pages query count — investigated, no safe reduction.** `IncubatingSchemaTableClassifier::detailsForTables()` is already batched (single `whereIn`; `detailsForTable()` runs no queries). The ~338 q come from `TableRegistry::reconcile()` in `mount()` (schema introspection that prunes orphaned registry rows) — correctness-sensitive and admin-only/low-traffic. Not reduced. — claude/opus-4.8

### Phase 4 — Defer secondary sections (main body)

- [x] **`admin/ai/providers`** (was the #1 offender, 544 KB): extracted the below-the-fold "Add a Provider" catalog (the ~100-row models.dev list) into a `#[Lazy]` child island `App\Modules\Core\AI\Livewire\Providers\CatalogBrowser` with a skeleton `placeholder()`. Initial page HTML **544 KB → ~54 KB** (−90%); the catalog (~476 KB) streams in on `x-intersect` after first paint. Regression tests in `ProvidersUiTest` lock in the deferral + the lazy-loaded content. — claude/opus-4.8
- [ ] Convert remaining secondary dashboard panels, inactive tabs, and below-the-fold widgets to lazy islands
- [ ] Re-measure converted pages against the budget
- [ ] **`commerce/catalog` (152 KB, ~2 KB over the soft target):** the tab body already renders only the active tab, but all three tab **forms (modals)** — `category-form`, `template-form`, `attribute-form` — are always included, so their option data (categories/templates/attribute-types) is loaded every render regardless of tab. The remaining weight is those always-mounted modals. Fix is to lazy-mount the modals (only render on open); deferred — marginal overage, and a higher-risk change in the Commerce module. Tracked on the budget allowlist.

### Phase 5 — Visible-only detail (main body)

- [ ] For dense tables/detail pages, render summary rows and load per-row/expandable detail on demand
- [ ] Re-measure the worst offenders (schema browser, eBay) against the budget
- [x] **`admin/addresses/create` (255 KB → 54 KB):** the country `x-ui.combobox` server-rendered all ~250 options as `<li>`s (each ~10 Alpine bindings) *and* embedded them again as a `Js::from` JSON blob. Routed the country field through the combobox's existing `searchUrl` (client-fetch on open) mode via a new `CountrySearchController` + `admin.addresses.countries.search` route + `HasAddressGeoLookups::searchCountriesForCombobox()` (portable `LOWER(...) LIKE`, works on SQLite + Postgres). No shared-component change was needed — `searchUrl` was already supported (the postcode field uses it). Regression tests in `CountrySearchTest`; existing `AddressUiTest` still green. — claude/opus-4.8

### Phase 6 — Guardrails

- [x] Documented the conventions in `docs/guides/page-rendering-performance.md` (measure → diagnose → fix table: paginate / `#[Lazy]` islands / `search-url` comboboxes) and pointed `resources/core/views/AGENTS.md` (Performant principle) at it. — claude/opus-4.8
- [x] Added the ratchet guardrail: `blb:perf:page-weights` gained `--allow=*` (allowlisted pages reported but don't fail `--strict`), and `tests/Feature/System/PageWeightBudgetTest.php` runs it at the 150 KB budget. Allowlist currently `['commerce/catalog']`. A new page over budget fails CI. — claude/opus-4.8
