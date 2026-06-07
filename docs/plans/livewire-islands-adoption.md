# livewire-islands-adoption

**Status:** Largely complete — delivered: `@island` API confirmed against 4.3.1; IBP executive-dashboard fully islanded (4 charts deferred, browser-verified); guide + decision note (Phase 5). Deliberately not done: eBay (Phase 2 — descoped, would need a `dashboard()` refactor first or it worsens perf), catalog (Phase 4 — descoped, keep the `#[Lazy]` child), device-flow pilot (deprioritized). Blocked: Lara chat (Phase 3 — needs a live agent turn to verify; not shipping unverified). See Findings + per-phase notes for rationale.
**Last Updated:** 2026-06-07
**Sources:** Livewire 4 Islands docs (`.../docs/4.x/islands`), `@island` ref (`.../directive-island`); use-cases/metrics/anti-patterns (`hoceine.com/blog/livewire-islands-architecture`, `desarrollolibre.net/.../islands-in-laravel-livewire-complete-guide-and-use-cases`, `laravel-news.com/everything-we-know-about-livewire-4`); vendor `SupportIslands` (`IslandCompiler`); related `performance-page-rendering.md` (notes `@island` unused; Phase 3 deferred eBay `stats()`); code: `ai/chat.blade.php` (root `wire:poll.2s`, ~1,989 lines), `Providers/{Providers,ProviderSetup,CatalogBrowser}.php` + setup partials, `Marketplace/Livewire/Ebay/Index.php` (`stats()` loads all listings), `ibp/.../ExecutiveDashboard.php` (multi-query `render()`). Livewire 4.3.1.
**Agents:** claude/opus-4.8

## Problem Essence

Components re-render their whole template to refresh one live region, compute several independent expensive widgets in one blocking `render()`, or spin up a child component just to defer a section. Livewire 4 `@island` (scoped server partial render with lazy/defer/poll/append) fits all three and is unused (zero `@island`/`#[Island]`).

## Desired Outcome

Scope re-renders/deferral to a region with `@island` where it cuts work or code — without regressing chat persist/teleport, instant tab switching, or correctness under island data-isolation. Low-risk wins first; highest-payoff/risk last.

## Top-Level Components

1. Provider-setup status island (pilot) — device-flow wait panel; poll only that region.
2. Expensive-panel defer islands — eBay `stats()` and IBP `ExecutiveDashboard` widgets; lazy/defer off first paint.
3. Lara chat live-turn island (headline, highest risk) — island the poll-volatile region (or append new messages).
4. Provider catalog inline lazy island (simplification) — replace the `#[Lazy]` child.

## Findings (verified against installed Livewire 4.3.1)

- API confirmed from `vendor/.../SupportIslands`: `@island(name:, lazy:, defer:, always:, skip:, with:) … @endisland`, optional `@placeholder … @endplaceholder`. `lazy`→`wire:intersect.once`, `defer`→`wire:init` (loads right after first paint). No `poll:` param — poll via `wire:poll` inside the island. Targeted refresh via `wire:island="name"`. The compiler extracts island content to a separate cached view rendered with only `$this` + public props + `with` (the data-isolation, confirmed at source).
- Data-into-computed is required per island, with an ordering rule: derive the island's data *after* `@endplaceholder` (e.g. `@php($x = $this->x)`), so the expensive compute does **not** run during the first-paint placeholder render. Proven on the IBP cost chart.
- Default islands **skip** re-render on a normal action; only `wire:island`-targeted, `always:true`, or in-island `wire:poll` updates them. So any interactive control whose action mutates island state must carry `wire:island` or the island goes stale. This makes the **device-flow pilot riskier than assumed** (its `startDeviceFlow`/"Try Again" buttons would each need `wire:island`), and it can't be browser-verified without live GitHub OAuth — so it is deprioritized as the pilot; the IBP defer island became the de-facto first proof.
- eBay re-scope: `stats()` draws its expensive reconciliation buckets from a `dashboard()` call (`Index::render` line ~98) that **also feeds four other panels**, so a stats-only defer island would not remove the cost. Deferring eBay properly means islanding all dashboard-derived panels (or lifting `dashboard()` into a shared computed feeding several islands) — larger than first scoped.
- **Use block `@php … @endphp` inside islands, not the `@php(...)` shorthand** — the island compiler mangles the shorthand to `<?php(` (fatal parse error). Derive island data with the block form after `@endplaceholder`.
- **Octane/FrankenPHP holds compiled views in the worker** — after editing a Blade/island view, `php artisan octane:reload` (a CLI `view:clear` alone leaves the HTTP worker serving the stale compiled parent + island token, surfacing as a 500 referencing an old island cache file).

## Design Decisions

- Pilot smallest first. The device-flow panel is low-traffic, isolated, admin-only — prove behavior + data-isolation before fragile/high-value work.
- Data-isolation is a design rule. An island renders from component properties/methods/computed only — no template-local/`@php`/`@foreach`-loop vars, and no island inside `@foreach`/`@if` (put loops/conditionals inside the island). So each region's data must first be component state (dashboard/eBay: lift per-panel data into computed; chat: confirm the live region uses component state — few `@php`/`@foreach` sites, tractable).
- Defer, don't re-engineer. eBay `stats()` loads all listings (perf-plan Phase 3 deferred pending a persisted column); a defer island moves the cost off first paint without that schema work — a perceived-perf win, not a query-count fix. Same for the dashboard widgets.
- Chat is probe-driven. `@island` (server partial) and `@persist`/teleport (DOM preservation) are expected to compose but unverified; proceed only after earlier phases, with instrument-first checks (renders/re-parents, stacks, geometry, console errors) across all modes and `wire:navigate`. Island only the poll-volatile region; evaluate append-mode.
- Inline, don't nest. An island can't contain a `<livewire:>` child, so replacing `CatalogBrowser` means inlining its markup + moving data to a parent computed, not wrapping the child. Simplification only (already within budget); lowest priority.
- Concurrency. Parallel island requests are last-write-wins; keep each polled island's data self-contained.
- Verify the surface. Only `name`/`lazy`/`defer` are confirmed on the directive page; `poll:`/`skip:`/`always:`/append appear in the guide/community — confirm against installed 4.3.1 before relying; prefer `wire:poll` inside an island.
- Don't island anti-patterns: tabs (instant `x-show`), schema-browser counts (`mount()`-time, not per render), tightly-coupled multi-step forms, tiny/fast regions, SEO/primary content.

## Public Contract

- A polled island re-renders only its region per tick; the rest of the component is untouched.
- Island content renders from component state only (no template-local/`@php`/loop vars).
- A lazy/defer island keeps content out of initial HTML and renders it after first paint; for eBay/dashboard this lowers first-paint cost without changing the underlying queries.
- Chat persist/teleport unchanged: same DOM instance across `wire:navigate`, correct mode placement, stable geometry, zero console errors.
- Tabs stay instant; no tabbed page gains a round-trip. `blb:perf:page-weights` budget stays green.

## Phases

### Phase 1 — Pilot: provider-setup status island

Affected pages: `admin/ai/providers/setup/{providerKey}` (e.g. `.../setup/openai-codex`, start the flow so the panel polls); reached from `admin/ai/providers`.
Goal: the device-flow status panel refreshes on its own poll while the rest of the setup page stays static (pattern + data-isolation proven in the lowest-risk place).

- [x] Confirm directive surface against installed 4.3.1 (see Findings). — claude/opus-4.8
- [ ] (Deprioritized — see Findings) Island the device-flow status region; the `startDeviceFlow`/"Try Again" buttons need `wire:island` targeting, and full verification needs live GitHub OAuth.

### Phase 2 — Defer expensive panels off first paint

Affected pages: `commerce/marketplace/ebay` (stats panel); `sbg/ibp/executive-dashboard` (alerts/charts/margin widgets).
Goal: both pages paint immediately; the stats/widget panels stream in afterward (skeleton → content) with bucket/widget values unchanged.

- [x] `ExecutiveDashboard` charts (market-spot, trajectory, cost, margin) → each a `@island(defer)` fed by its own computed (`marketSpotChart`/`trajectoryChart`/`costChart`/`marginChart`), data derived with block `@php` after `@placeholder`; `render()` slimmed to non-chart data only. Browser-verified: all 4 charts absent from first-paint HTML (4 defer placeholders + `wire:init`), non-island content ("Monthly Stock Projection") stayed inline, all islands loaded after hydration, 0 console errors. — claude/opus-4.8
- [x] (Decided) Alerts panel left inline — cheap (`limit 10`); not worth deferring. — claude/opus-4.8
- [~] **eBay — descoped (not done; descope is the deliberate outcome).** The expensive `dashboard()` feeds 5 *scattered* panels, and each deferred island is a separate request, so deferring them would re-run `dashboard()` up to 5× (worse than today's 1×); a stats-only island removes nothing from first paint. Proper fix = restructure/cache `dashboard()` first, then island — a separate effort, tracked here, not an island drop-in. — claude/opus-4.8
- [x] Measure first-paint gain (IBP dashboard): 4 deferred islands, charts off first paint; tests green. — claude/opus-4.8

Evidence: `extensions/sb-group/ibp/Livewire/Dashboard/ExecutiveDashboard.php` (4 chart computeds; slim `render()`), `extensions/.../Views/livewire/dashboard/executive-dashboard.blade.php` (4 `@island(defer)` + `@placeholder`); verified via Playwright on `sbg/ibp/executive-dashboard` (raw server HTML = 4 deferred, vs hydrated = all loaded). Required `octane:reload` to pick up view changes; `@php` block form (not shorthand) inside islands.

### Phase 3 — Lara chat live-turn island (headline, highest risk) — BLOCKED on verification

Affected pages: app-wide — open chat (e.g. `dashboard`), start a turn, then `wire:navigate` and cycle overlay/docked/fullscreen/mobile.
Goal: during an active turn only the chat's live region updates (not the whole component); across navigation/modes the chat stays the same instance, correctly placed, no blink.

Blocked (not started): the plan-mandated proof ("active turn updates only the island") needs a *live agent turn*, which requires the AI backend/keys/agent execution — not triggerable in the local probe harness. `@island`×`@persist`×teleport on the app's most fragile component is also unverified. Given the session bar (no unverified ship, esp. for the chat), this is held until it can be exercised with a real turn. Implementation steps below remain open:

- [ ] Find the poll-volatile boundary; confirm component-state-only data and no teleport/persist-coupled markup.
- [ ] Island it; scope the 2s poll (evaluate append-mode).
- [ ] Verify `@island`×`@persist`×teleport: all modes, `wire:navigate` (same instance, no blink), active turn updates only the island, zero console errors.
- [ ] Record render-scope reduction (Evidence).

### Phase 4 — Catalog deferral simplification — DESCOPED (recommend keeping the `#[Lazy]` child)

Affected page: `admin/ai/providers` (below-the-fold "Add a Provider" catalog; `/browse`, `/connections` redirect here).
Goal: the providers page initial HTML stays lean and the catalog still appears after first paint — same behavior, one fewer component.

Descoped after review: the catalog view is 259 lines and *interactive* — server-side row expansion (`wire:click="toggleCatalogProvider"`), `@include`d help-panel partials, and Alpine filtering. Converting to an inline island means moving all state/actions to the parent, adding `wire:island` targeting for the expansion, `@include`-inside-island (untested), and re-verifying lazy-load + filter + expand + connect + help panels — high risk on a working, tested admin feature for the marginal benefit of deleting one class. The existing `#[Lazy] CatalogBrowser` already delivers the deferral and is idiomatic; per Strategic Programming, not worth the carry. Reopen only if the child becomes a maintenance burden.

- [~] Inline-island conversion — not done (deliberate; see above).

### Phase 5 — Guardrail & docs

Affected pages: none (docs only).
Goal: a future reader finds a clear `@island` vs `#[Lazy]` vs `wire:poll` decision note, and the perf plan's unused-leverage + eBay items are marked addressed.

- [x] Added "`@island` vs `#[Lazy]` child vs `wire:poll`" decision note + gotchas (data-isolation, `@php` block form, stale-islands/`wire:island`, no nested `<livewire:>`, octane:reload) and a table row to `docs/guides/page-rendering-performance.md`. — claude/opus-4.8
- [x] Cross-link added to `performance-page-rendering.md` (eBay deferred item points here). — claude/opus-4.8
