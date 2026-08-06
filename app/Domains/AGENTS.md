# Domains Agent Guide

Applies to optional Domain Modules under `app/Domains/{Domain}/{Module}`. A Domain is an installable, enableable, disableable, and updateable enterprise boundary; it contains one or more Modules. Core is the required Domain but lives separately under `app/Core/{Module}`; Extension Modules live under `app/Extensions/{Extension}/{Module}`. Read with root `AGENTS.md` (Four-Root Application Placement) and `docs/architecture/module-system.md`.

## Contribution Surfaces

A module plugs into shared platform surfaces through convention-discovered files — no central registration:

| File | Contributes | Authority |
|------|-------------|-----------|
| `Config/menu.php` | Navigation items | `app/Base/Menu/` |
| `Config/authz.php` | Capability vocabulary and role grants | `app/Base/Authz/AGENTS.md` |
| `Config/dashboard.php` | Dashboard widgets | `app/Base/Dashboard/` (rules below) |
| `Routes/web.php`, `Routes/api.php` | Routes | `app/Base/Routing/` |
| `Livewire/` | Livewire components (names derived from the `view(...)` call) | `app/Base/Livewire/` |

## Dashboard Widgets

The dashboard (`/dashboard`) renders module-contributed widgets filtered per user by authz, in a per-user order stored by the user-scoped `ui.dashboard.layout` setting. Reference implementations: `app/Domains/People/Leave/` (`people.leave.pending-approvals`) and `app/Core/AI/` (`ai.operations-status`).

### Declare

`Config/dashboard.php` returns `['widgets' => [...]]`. Each entry:

- `id` — stable, module-namespaced (`people.leave.pending-approvals`). Persisted in `ui.dashboard.layout`; renaming orphans saved layouts silently.
- `label`, optional `description` — plain English; views translate with `__()`.
- `icon` — a name registered in `resources/core/views/components/icon.blade.php`; add missing icons there, never rely on the fallback glyph.
- `permission` — capability gating visibility. Must exist in a `Config/authz.php` vocabulary or the authz service denies it for everyone. Omit only for widgets every authenticated user may see.
- `component` — the Livewire component name that renders the widget.
- `size` — which lane the widget lives in: `1` for the narrow trailing rail (one column of three), `2` for the wide column (two of three). The two lanes are independent stacks, so a tall widget only pushes down its own lane. Values outside 1–2 clamp.

Discovery order sets the default dashboard order; duplicate ids follow last-definition-wins (extensions can override a shipped widget).

### Implement

- Extend `App\Base\Dashboard\Widget`. Do not add `#[Lazy]` — the dashboard page mounts every widget lazily and the base class provides the shared skeleton placeholder.
- Widgets are self-contained: no mount parameters, own queries, company-scoped data (`auth()->user()->company_id`).
- Render an honest inline empty state when there is nothing to show; a widget must never surface a page error.
- Keep first render cheap — one or two aggregate queries. Widgets load on every dashboard visit.
- Views: Domain Modules render namespaced views under the owning Module's `Views/`. A reusable framework component belongs in `resources/core`; keep Domain-specific widget presentation with the Domain Module. The component name is derived from that view path by Livewire discovery.
- Markup: one `x-ui.card`, widget-label header (`text-[11px] uppercase tracking-wider font-semibold text-muted`), `x-ui.stat-strip`/`x-ui.stat` for figures, `x-ui.link` for navigation. Semantic tokens only; all rules in `resources/core/views/AGENTS.md` apply.

### Test

In the module's `Tests/Feature/`, per `tests/AGENTS.md`:

- `Livewire::test('{component-name}')` with distinctive included **and** excluded fixtures (wrong status, other company) asserting via `assertViewHas`, not ambient `assertSee`.
- Cover the empty state.
- Visibility gating (capability present/absent) is covered centrally in `tests/Feature/Dashboard/DashboardPageTest.php`; do not re-test the registry per widget.
