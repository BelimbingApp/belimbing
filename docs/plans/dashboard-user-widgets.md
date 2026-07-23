# dashboard-user-widgets

**Status:** Phases 1–2 implemented and tested; Phase 3 (breadth) open
**Last Updated:** 2026-07-23
**Sources:** `resources/core/views/dashboard.blade.php`; `app/Modules/Core/User/Routes/web.php`; `app/Base/Foundation/Services/LandingPageResolver.php`; `app/Base/Menu/`; `docs/architecture/settings.md`; `docs/plans/livewire-islands-adoption.md`; user discussion on replacing the placeholder dashboard
**Agents:** Amp/claude-fable-5; Codex/GPT-5

## Problem Essence

The dashboard is a static placeholder — three empty cards rendered by a bare `Route::view()` — so the platform's default landing screen shows nothing about the modules a user actually works with, and modules have no way to surface their key signals there.

## Desired Outcome

The dashboard renders real, module-owned widgets filtered by the user's authorization, in a per-user order the user can edit. A fresh user with no saved layout gets a useful default derived from what is installed and visible to them; a user who customizes keeps their layout across sessions; a widget whose module is uninstalled or whose capability is revoked disappears silently instead of erroring. Modules and extensions contribute widgets through discovery with no central registration step.

## Top-Level Components

- Widget registry (`app/Base/Dashboard/`): framework infrastructure, sibling in shape to `app/Base/Menu/`. Modules register widget definitions from their ServiceProviders; the registry exposes the authz-filtered widget set for a user.
- Widget definition contract: stable id, label, icon, required authz capability, size hint, and the Livewire component that renders it.
- Dashboard Livewire page (Core-owned, `resources/core/views/livewire/dashboard/`): replaces the static Blade view; resolves the user's layout, intersects it with visible widgets, renders each widget as a lazy Livewire component.
- Per-user layout storage: user-scoped `ui.dashboard.layout` in
  `base_settings` — an ordered list of widget ids with optional size overrides.
- Edit mode: add/remove/reorder UI on the dashboard page persisting to prefs.
- Initial widgets: one or two real widgets from existing modules proving the contract end to end.

## Design Decisions

### Where the contribution mechanism lives

A Core `Dashboard` module was considered, but the registry is contribution infrastructure — modules across all domains and extensions feed it, exactly like menu items feed `Base/Menu`. Placing the registry in `app/Base/Dashboard/` keeps the contract framework-owned and lets any module contribute via its ServiceProvider with no cross-module coupling. The dashboard *screen* stays Core presentation under `resources/core/views/livewire/dashboard/`.

Recommendation: registry and contracts in `Base/Dashboard`; screen in Core views. Mirrors the proven `Base/Menu` split.

Implementation refinement: widgets are contributed via `Config/dashboard.php` files discovered by glob (the menu-discovery pattern), not ServiceProvider registration as originally drafted. Config-file discovery routes through `DomainState::filterPaths`, so widgets of disabled domains vanish automatically, and extensions contribute (or override, last-definition-wins) with no code hook.

### Layout storage: user settings vs dedicated table

- **Dedicated table** — buys cross-user queryability, FKs, and partial updates, none of which apply. Costs a migration, model, and second lifecycle for data with one reader and one writer. The switch trigger is layouts ceasing to be purely personal (shared/team dashboards, admin-audited layouts); migrating prefs blobs to rows at that point is cheap and mechanical.
- **`Base/Settings`** — now has user scope, so the whole-list preference uses
  `ui.dashboard.layout`. Users without employee records have the same behavior,
  and reset deletes the user override.

Implemented decision: user-scoped `base_settings`. A dedicated table remains
unnecessary unless layouts become shared, relational, or independently queryable.

### Widget rendering: server-composed Blade partials vs lazy Livewire components

Blade partials composed in one request are simplest but couple page latency and weight to the slowest widget, and every widget query runs even below the fold. Lazy Livewire components render each widget as an independent island: the shell paints immediately, module queries run per-widget, a slow or failing widget cannot block the page, and initial HTML stays inside the ~150 KB budget. This also aligns with `docs/plans/livewire-islands-adoption.md`.

Recommendation: each widget is a lazy Livewire component with a skeleton placeholder sized by its size hint.

### Reordering UX

A full drag-grid/packing library is expensive to carry and exceeds current need. Explicit edit mode with add/remove from a catalog of visible widgets plus simple reorder controls (move up/down or lightweight Alpine drag on the existing stack) covers personalization without new dependencies.

Recommendation: edit mode with catalog + simple reorder; no grid-packing library.

## Public Contract

- A widget definition declares: `id` (stable, namespaced by module, e.g. `people.leave.pending-approvals`), `label` and optional `description` (translatable), `icon` (registry icon name), `permission` (authz capability string required to see it; null means visible to all authenticated users), `size` (column-span hint: 1, 2, or 3 of a 3-column grid), and `component` (the Livewire component name that renders it).
- Modules contribute widgets via `Config/dashboard.php` returning `['widgets' => [...]]`, discovered by glob at Base, domain, module, and extension levels (`App\Base\Dashboard\Services\WidgetDiscoveryService`). Discovery order defines default layout order; duplicate ids follow last-definition-wins.
- The registry exposes, for a given user, the ordered set of widget definitions whose capability the user holds — the same authz-filter-then-intersect approach `LandingPageResolver` uses for menu options.
- `ui.dashboard.layout` holds the personal layout at user scope. Ids not
  present in the user's visible set are skipped silently on read; saving
  rewrites the whole list. (Per-widget size overrides are Phase 3; until then
  size comes from the definition.)
- No saved layout means the default layout: visible widgets in registry order, capped to a sane count.
- Widgets render as lazy Livewire components; each widget owns its own queries and empty/error states. A widget must degrade to an inline empty state, never a page error.
- Widget UI uses `x-ui.card`, `x-ui.stat-strip`/`x-ui.stat`, and other `x-ui.*` primitives per `resources/core/views/AGENTS.md`; semantic tokens only.

## Phases

### Phase 1 — end-to-end skeleton

Affected pages: `/dashboard`
Goal: placeholder gone; the dashboard shows real authz-filtered widgets in default order; a user lacking a widget's capability does not see it.
Validation: Pest feature tests for registry filtering and page render; `php artisan blb:perf:page-weights` on `/dashboard`.
Evidence: `app/Base/Dashboard/` (DTO, discovery, registry, `DashboardLayout`, `Widget` base class, Livewire page, routes); `resources/core/views/livewire/dashboard/`; `tests/Feature/Dashboard/DashboardPageTest.php` (6 passed); `app/Modules/People/Leave/Tests/Feature/PendingApprovalsWidgetTest.php` (2 passed); page weight `/dashboard` 7.2 KB, 0 queries at first paint, lazy widgets confirmed.

- [x] Create `app/Base/Dashboard/` with widget definition DTO, discovery service, registry, and ServiceProvider (shape mirrors `Base/Menu`) {Amp/claude-fable-5}
- [x] `DashboardLayout::visibleFor()` resolves visible widgets per user via `AuthorizationService` and `Actor::forUser()` {Amp/claude-fable-5}
- [x] Replace `Route::view('dashboard', …)` (moved from `Core/User` routes to `app/Base/Dashboard/Routes/web.php`) with a Livewire page rendering `resources/core/views/livewire/dashboard/index.blade.php`; old static `dashboard.blade.php` deleted {Amp/claude-fable-5}
- [x] Dashboard page renders default layout (visible widgets, registry order, capped at 6) as lazy Livewire components (`<livewire:is … lazy>`) with a shared skeleton placeholder {Amp/claude-fable-5}
- [x] Two real widgets: `ai.operations-status` (dispatch ledger counts by status, `admin.ai.agent.view`, size 2) and `people.leave.pending-approvals` (submitted requests count, company-scoped, `people.leave.approve`, size 1) with honest empty states {Amp/claude-fable-5}
- [x] Feature tests: widget hidden without capability, shown with it; dashboard renders with zero visible widgets without erroring {Amp/claude-fable-5}

### Phase 2 — personalization

Affected pages: `/dashboard`
Goal: a user can add, remove, and reorder widgets; the layout persists across sessions; a revoked/uninstalled widget in a saved layout is skipped silently.
Validation: Pest tests for prefs round-trip and stale-id fall-through.

- [x] Layout read/write in user-scoped `ui.dashboard.layout` with whole-list
  saves and silent skip of unknown/invisible ids; an explicitly emptied layout
  is respected, not replaced by the default {Amp/claude-fable-5; Codex/GPT-5}
- [x] Edit mode on the dashboard page: catalog of visible widgets to add, remove control per widget, move up/down reorder controls {Amp/claude-fable-5}
- [x] Reset-to-default action clearing the pref (shown only when a custom layout exists) {Amp/claude-fable-5}
- [x] Tests: pref round-trip (remove/add/reorder), stale widget id skipped, reset clears pref, invisible id never persists {Amp/claude-fable-5}

### Phase 3 — breadth

Goal: each installed business domain offers at least one genuinely useful widget; size variants render correctly.
Scope: widget authoring by domain modules; no registry contract changes expected. Widget authoring is agent-driven: the authoring contract lives in `app/Modules/AGENTS.md` (auto-loads for module work; `extensions/AGENTS.md` points there for extension authors), so any agent building a domain feature can add its widget without reading this plan.

- [x] Widget authoring guide for AI coding agents: declare/implement/test contract with reference implementations, in `app/Modules/AGENTS.md`; pointer added to `extensions/AGENTS.md` {Amp/claude-fable-5}
- [ ] Per-widget size overrides in the saved layout honored by the grid
- [ ] Additional domain widgets as real operational needs are identified (add rows here per widget as they are agreed)
- [ ] Revisit company/role default layouts via `Base/Settings` cascade only if that lands on the roadmap (see Design Decisions)
