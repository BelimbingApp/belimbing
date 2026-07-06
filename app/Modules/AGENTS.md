# Module Agent Guidelines

Applies to all domain and Core modules under `app/Modules/`. Read with root `AGENTS.md` (Module-First Placement) and `docs/architecture/module-system.md`.

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

The dashboard (`/dashboard`) renders module-contributed widgets filtered per user by authz, in a per-user order stored in `users.prefs['dashboard']`. Reference implementations: `app/Modules/People/Leave/` (`people.leave.pending-approvals`) and `app/Modules/Core/AI/` (`ai.operations-status`).

### Declare

`Config/dashboard.php` returns `['widgets' => [...]]`. Each entry:

- `id` — stable, module-namespaced (`people.leave.pending-approvals`). Persisted in user prefs; renaming orphans saved layouts silently.
- `label`, optional `description` — plain English; views translate with `__()`.
- `icon` — a name registered in `resources/core/views/components/icon.blade.php`; add missing icons there, never rely on the fallback glyph.
- `permission` — capability gating visibility. Must exist in a `Config/authz.php` vocabulary or the authz service denies it for everyone. Omit only for widgets every authenticated user may see.
- `component` — the Livewire component name that renders the widget.
- `size` — column-span hint, 1–3 of a 3-column grid.

Discovery order sets the default dashboard order; duplicate ids follow last-definition-wins (extensions can override a shipped widget).

### Implement

- Extend `App\Base\Dashboard\Widget`. Do not add `#[Lazy]` — the dashboard page mounts every widget lazily and the base class provides the shared skeleton placeholder.
- Widgets are self-contained: no mount parameters, own queries, company-scoped data (`auth()->user()->company_id`).
- Render an honest inline empty state when there is nothing to show; a widget must never surface a page error.
- Keep first render cheap — one or two aggregate queries. Widgets load on every dashboard visit.
- Views: Core modules render `resources/core/views/livewire/{area}/widgets/*.blade.php`; non-Core modules render namespaced views under the module's `Views/`. The component name is derived from that view path by Livewire discovery.
- Markup: one `x-ui.card`, widget-label header (`text-[11px] uppercase tracking-wider font-semibold text-muted`), `x-ui.stat-strip`/`x-ui.stat` for figures, `x-ui.link` for navigation. Semantic tokens only; all rules in `resources/core/views/AGENTS.md` apply.

### Test

In the module's `Tests/Feature/`, per `tests/AGENTS.md`:

- `Livewire::test('{component-name}')` with distinctive included **and** excluded fixtures (wrong status, other company) asserting via `assertViewHas`, not ambient `assertSee`.
- Cover the empty state.
- Visibility gating (capability present/absent) is covered centrally in `tests/Feature/Dashboard/DashboardPageTest.php`; do not re-test the registry per widget.
