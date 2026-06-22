# software-modules-screen

**Status:** Complete (2026-06-22). Implemented on branch `feat/software-modules-screen`. {claud/opus-4.8}
**Last Updated:** 2026-06-22
**Sources:**
- `docs/architecture/module-system.md` — Distribution Bundle / domain / module model the screen surfaces.
- `app/Base/Foundation/Livewire/BundleManager.php` — the read-only inventory + BelimbingApp catalog being merged in.
- `app/Base/Foundation/Livewire/DomainManager.php` + `App\Base\Foundation\Services\DomainInstaller` — domain install/enable/disable/uninstall being merged in.
- `app/Base/Foundation/ModuleManifest/ModuleManifestReader.php`, `BelimbingAppCatalogService` — existing data sources to compose.
- `DESIGN.md`, `docs/modules/menu-prd.md` — UX and menu conventions (siblings sort ascending by label).

**Agents:** claud/opus-4.8

---

## Problem Essence

System → Software has two screens that both read as "manage installed code" with fuzzy, overlapping boundaries: **Bundles** (read-only module inventory + BelimbingApp catalog) and **Business Domains** (domain install / enable / disable / uninstall). An operator cannot tell which to use. Separately, the **Updates** item still says `deployment` in its route, URL, and page title.

## Desired Outcome

One coherent **Modules** screen under Software — the single place to see what is installed (domain-grouped, drill down to per-module manifest detail), manage domain lifecycle, and install from the BelimbingApp catalog. The **Updates** screen reads "Updates" at every layer. The Software group icon reflects "software," not "download."

After this, the Software group has three label-sorted items: **GitHub Access**, **Modules**, **Updates**.

## Top-Level Components

| Component | Responsibility |
|---|---|
| **Modules screen** (Livewire, `admin/system/software/modules`) | Merges `BundleManager` + `DomainManager`. Composes existing services — no new backend engines. |
| Installed view | Domain-grouped tree. Each **domain** row carries lifecycle actions (enable / disable / uninstall) and a dependency-health badge; **drill down** reveals its **modules** with manifest detail (version, requires/optional, published/consumed events, path). Base/Core render as a read-only "Framework" group (never removable). |
| Available view | The BelimbingApp catalog (`BelimbingAppCatalogService`), with the install affordance backed by `DomainInstaller` (clone + migrate) rather than only a copy-CLI command. |
| Authz consolidation | Collapse `admin.system.software.bundles.*` and `admin.system.software.business-domain.*` into `admin.system.software.modules.{view,manage}`. |
| Updates rename | `admin.system.software.deployment` → `admin.system.software.updates` at route name, URL, authz, menu id, and page title. The internal `Deployment` Livewire component and `DeploymentService` keep their names (accurate implementation terms). |
| Group icon | `heroicon-o-arrow-down-circle` → `heroicon-o-cube`. |

## Design Decisions

**One screen, domain-first hierarchy.** Domains are the installable/removable unit; modules live under them; the catalog is installable domains/bundles. The same dataset at two zoom levels becomes one screen with a drill-down, not two competing menu items. This is the operator's "show bundles under domains" intuition.

**Name it "Modules."** It spans Core/Base framework modules, business domains, and extensions, so "Business Domains" undersells it and "Software → Software" is circular. The menu loses the separate "Bundles" and "Business Domains" items.

**Compose existing services; build no new backend.** `ModuleManifestReader` already yields per-module manifests (group them by the domain segment of `extra.blb.module`); `DomainInstaller` already does install/enable/disable/uninstall; `BelimbingAppCatalogService` already yields the catalog. The Modules screen is presentation over these three.

**Pre-release hard rename, no aliases.** Consistent with the rest of this effort: drop the old routes/authz/menu ids outright; no back-compat. Keep `DeploymentService`/`Deployment\Index`/the `software/deployment/` blade dir as internal names (avoids another Windows blade-dir move; "deployment" is accurate for the engine).

**Lifecycle stays domain-scoped.** Enable/disable/uninstall apply to domains, not to Base/Core or to individual modules within a domain — the Framework group is read-only. Per-module rows are informational (manifest detail), not action targets.

## Open Questions

- **Catalog → install mapping.** `DomainInstaller::install()` installs known domains; the catalog lists arbitrary BelimbingApp bundles. The Available tab must map a catalog entry to an installable action (domain install vs. "copy CLI" fallback for bundles `DomainInstaller` does not handle). Resolve at Phase 3 start; default to the copy-CLI fallback for anything `DomainInstaller` cannot install directly, so nothing regresses.
- **Uninstall confirmation.** Preserve `DomainManager`'s typed-phrase confirmation ("uninstall commerce [and drop all tables]") in the merged screen.

## Public Contract

- Route `admin.system.software.modules.index` at `admin/system/software/modules`, gated by `admin.system.software.modules.view`; the refresh/install/lifecycle actions gated by `admin.system.software.modules.manage`.
- `admin/system/software/bundles` and `admin/system/software/business-domains` (and their route names, authz, menu ids) are removed.
- Updates: `admin/system/software/updates`, route `admin.system.software.updates.index`, authz `admin.system.software.updates.manage`, page title "Updates". The `online` maintenance-lift action is renamed to match if cheap; otherwise left.
- Software group renders three items, label-sorted: GitHub Access, Modules, Updates.

## Phases

### Phase 1 — Updates rename + group icon

- [x] Rename route/URL/authz/menu-id `admin.system.software.deployment` → `…updates` and `admin/system/software/deployment` → `…/updates`; update `Software/Routes/web.php`, `Software/Config/menu.php`, `Software/Config/authz.php`, the `Deployment\Index` authorize call, `bootstrap/app.php` maintenance `except` paths, and `DeploymentUpdateTest`.
- [x] Change the deployment page title "Deployment" → "Updates" (blade `x-slot`/`:title`).
- [x] Software group icon `arrow-down-circle` → `cube` in `app/Base/Menu/Config/menu.php`.

Validation: `admin/system/software/updates` loads titled "Updates"; old `/deployment` 404s; Update suite + Menu suite green.

### Phase 2 — Modules data layer

- [x] A read model that groups `ModuleManifestReader` output by domain, marks Base/Core as framework (read-only), folds in `DomainInstaller` installed/available/enabled state and dependency health, and exposes the `BelimbingAppCatalogService` catalog with per-entry installability.

Validation: unit/feature coverage that the read model groups modules under the right domains and reports lifecycle state + dependency health.

### Phase 3 — Modules Livewire screen

- [x] `Modules` Livewire component + blade at `admin/system/software/modules`: Installed tab (domain tree, drill-down to module manifest cards, lifecycle actions with the typed-phrase uninstall confirmation, dependency-health banner) and Available tab (catalog cards, install or copy-CLI per installability, "Refresh from GitHub").
- [x] Route + menu item ("Modules", icon `puzzle-piece`) + authz `admin.system.software.modules.{view,manage}`.

Affected pages: `admin/system/software/modules`.
Goal: a reviewer sees installed domains with drill-down to module detail, can enable/disable/uninstall a domain, and can browse + install from the catalog — all on one screen.

### Phase 4 — Retire Bundles + Business Domains

- [x] Remove `BundleManager` + its blade/route/menu/authz; remove `DomainManager`'s screen route/menu/authz (keep `DomainInstaller`). Migrate/rename the relevant tests onto the Modules screen.
- [x] Update docs that reference "System → Software → Business Domains" / "Bundles" (`module-system.md`, `plugin-manager-ui.md`).

Validation: repo-wide grep finds no `admin.system.software.bundles` / `…business-domain` route/authz/menu references; Modules feature tests cover inventory, drill-down, lifecycle, and catalog install; full Foundation + Menu suites green.
