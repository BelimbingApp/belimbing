# Adopter Separation Strategy

**Document Type:** Architecture Guide
**Purpose:** How licensees develop custom code on top of BLB without merge conflicts or architectural drift
**Audience:** BLB framework team, licensee developers, implementation partners
**Last Updated:** 2026-03-22

---

## 1. Problem Statement

A licensee needs custom modules, branding, and business logic. BLB must keep evolving. The two codebases must coexist in the same repository without interfering.

---

## 2. Design Goals

1. **Simple** — standard Git, no special tooling.
2. **Flexible** — licensees can add unlimited modules with no artificial constraints.
3. **Scalable** — works for one module or fifty.
4. **Maintainable** — upstream sync (`git pull`) is near-conflict-free by directory design.
5. **Logical** — backend in `extensions/`, UI in `resources/extensions/{licensee}/`, same patterns as BLB core.

---

## 3. Model: Fork + Directory Convention

One fork. No branch lanes. No worktrees. Separation is enforced by **directory placement**, not by branching ceremony.

For private licensee work developed from a public framework checkout, use a
nested private extension repository instead. See
`docs/guides/extensions/private-extension-repositories.md`.

### 3.1 Repository Topology

```text
upstream:  BelimbingApp/belimbing         # BLB framework
origin:    {licensee}/belimbing (fork)    # Licensee's fork
```

### 3.2 How It Works

1. Licensee forks `BelimbingApp/belimbing`.
2. Adds upstream remote: `git remote add upstream <BelimbingApp/belimbing URL>`.
3. Develops on `main` (or feature branches — their choice).
4. Syncs upstream regularly: `git pull upstream main`.

Merge conflicts are structurally near-impossible because BLB never touches `extensions/{licensee}/` or `resources/extensions/{licensee}/`.

### 3.3 Why a Fork

BLB is a framework where `app/` IS the framework — not a Composer package. The licensee's custom code lives alongside it in the same tree. A fork is the simplest, most standard way to version-control this.

Alternatives considered and rejected:

| Alternative | Why it fails |
|---|---|
| Git subtree/submodule | Same two-location problem plus arcane git commands. |
| BLB as Composer package | Would require extracting all of `app/Base/` and `app/Modules/` into a package. Wrong abstraction for a framework-on-top-of-Laravel. |

Separate private extension repos are still valid when the framework remote is
public and the licensee code must not be pushed there. In that case the nested
repo is an operational privacy boundary, not the default distribution model.

---

## 4. File Placement Boundaries

BLB and licensee code are separated by directory, not by branch. Follow `docs/architecture/file-structure.md`.

### 4.1 Ownership Map

| Owner | Backend Code | UI / Presentation |
|---|---|---|
| **BLB framework** | `app/Base/`, `app/Modules/` | `resources/core/` |
| **Licensee** | `extensions/{licensee}/{module}/` | `resources/extensions/{licensee}/` |

Both follow the same structural patterns. The menu discovery glob (`extensions/*/*/Config/menu.php`) already supports this.

### 4.2 Extension Module Structure

Every extension module mirrors BLB's internal module structure (include only what's needed):

```
extensions/{owner}/{module}/
├── Config/
│   ├── menu.php              # Menu items (auto-discovered)
│   ├── authz.php             # Authorization rules
│   └── {module}.php          # Module config (lowercase filename)
├── Database/
│   ├── Migrations/
│   └── Seeders/
├── Livewire/
├── Models/
├── Services/
├── Routes/
│   └── web.php
├── Tests/
└── ServiceProvider.php
```

Tests live inside the extension module (`Tests/`) rather than under `tests/extensions/`. The module is the unit of deployment — deleting or moving a module should remove its tests too. Unlike `resources/`, where Blade and CSS resolution requires a centralized path that Vite and Laravel can scan, tests have no framework-imposed path constraint and benefit more from co-location.

### 4.3 ServiceProvider Discovery

BLB auto-discovers providers inside `app/Base/`, `app/Modules/`, and
`extensions/*/*/ServiceProvider.php` via `ProviderRegistry`. Routes, menus,
settings, authz config, and migrations follow the same convention-based
discovery model.

If an extension is not being discovered, verify that:

1. The provider lives at `extensions/{owner}/{module}/ServiceProvider.php`.
2. The namespace matches the directory structure, for example `Extensions\SbGroup\Quality`.
3. Config cache has been cleared with `php artisan config:clear`.

### 4.4 Database Table Naming

Use owner-prefixed table names to prevent collisions with BLB core and other extensions:

```
{owner}_{module}_{entity}
```

Examples: `sbg_quality_work_items`, `acme_reporting_dashboards`.

Reference: `docs/guides/extensions/database-migrations.md`.

### 4.5 UI Boundary in `resources/`

BLB uses a core/licensee split. Licensee overrides win by CSS cascade and Blade view resolution order.

| UI Concern | BLB Core | Licensee |
|---|---|---|
| Design tokens | `resources/core/css/tokens.css` | `resources/extensions/{licensee}/css/tokens.css` |
| Component styles | `resources/core/css/components.css` | `resources/extensions/{licensee}/css/components.css` |
| Blade components | `resources/core/views/components/` | `resources/extensions/{licensee}/views/components/` |
| Livewire page templates | `resources/core/views/livewire/` | `resources/extensions/{licensee}/views/livewire/` |

Rules:

1. Prefer token override and component override before copying full page templates.
2. Set `VITE_THEME_DIR={licensee}` in `.env` to activate the licensee layer.
3. Licensee-specific branding, terminology, and layout go in `resources/extensions/{licensee}/`. Improvements to BLB-wide usability go in `resources/core/` and are contributed upstream.

References: `docs/architecture/ui-layout.md`, `docs/guides/theming.md`.

---

## 5. Decision Rubric: BLB Core vs Licensee Extension

Before coding, ask four questions:

1. Is the naming domain-neutral (not licensee terminology)?
2. Is the behavior useful for at least one other adopter scenario?
3. Can it be exposed as a small, stable interface with hidden complexity?
4. Can licensee-specific policy be implemented as configuration, extension, or guard class outside core?

**All yes** → implement in `app/Base/` or `app/Modules/`, contribute upstream.
**Any no** → implement in `extensions/{licensee}/`.

---

## 6. Upstream Contributions

When a licensee builds something reusable:

1. Extract the generic behavior from `extensions/{licensee}/{module}/`.
2. Rename domain-specific terms to neutral language.
3. Implement in `app/Base/` or `app/Modules/` with tests.
4. Open PR to `BelimbingApp/belimbing`.
5. Once merged, remove the duplicate from licensee extension and use the upstream version.

No "promotion flow" ceremony — it's a standard PR.

---

## 7. Pull Request Discipline

1. **Upstream PRs** must not include `extensions/{licensee}/` or `resources/extensions/{licensee}/` paths.
2. **Licensee commits** should not modify BLB core (`app/Base/`, `app/Modules/`, `resources/core/`) unless patching an urgent blocker.
3. If an urgent licensee fix touches core, follow immediately with either:
   - a PR contributing the reusable part upstream, or
   - a revert-and-reimplement in extension space.

---

## 8. Third-Party Extensions

Third-party vendors use the same `extensions/{vendor}/{module}/` layout:

```
extensions/
├── sb-group/              # Licensee
│   ├── quality/
│   └── logistics/
├── acme-corp/             # Another licensee
│   └── hr/
└── some-vendor/           # Third-party vendor
    └── reporting/
```

### 8.1 Distribution Options

| Method | When to use | How it works |
|---|---|---|
| **Git (directory in fork)** | Licensee modules, private vendor code | Files live directly in `extensions/{owner}/{module}/`. Deployed with the fork. |
| **Composer package** | Open-source or commercially distributed extensions | Package installs into `vendor/` as usual. A post-install script or ServiceProvider publishes/symlinks assets into `extensions/{vendor}/{module}/` so that glob-based discovery (routes, menus) works unchanged. |

### 8.2 Composer Package Convention (Future)

When BLB supports Composer-distributed extensions, packages should:

1. Use the Composer type `belimbing-extension`.
2. Register their ServiceProvider via Laravel's `extra.laravel.providers` auto-discovery.
3. Publish routes, config, and menu files into `extensions/{vendor}/{module}/` so existing glob patterns discover them automatically.
4. Prefix database tables with `{vendor}_{module}_` per section 4.4.

This keeps the runtime directory layout identical regardless of distribution method.

---

## 9. Example: SBG Quality Extension

```text
extensions/sb-group/quality/
├── Config/
│   ├── menu.php
│   ├── authz.php
│   └── quality.php
├── Database/
│   ├── Migrations/
│   │   ├── 2026_03_22_000000_create_sbg_quality_work_items_table.php
│   │   └── 2026_03_22_000001_create_sbg_quality_status_history_table.php
│   └── Seeders/
│       └── QualityWorkflowSeeder.php
├── Livewire/
│   └── Quality/...
├── Models/
│   └── QualityWorkItem.php
├── Routes/
│   └── web.php
├── Tests/
└── ServiceProvider.php
```

UI overrides for SBG:

```text
resources/extensions/sb-group/
├── css/
│   └── tokens.css           # SBG brand colors
├── views/
│   ├── components/          # Component overrides
│   └── livewire/            # Page template overrides
└── js/
```

---

## 10. Operational Checklist

1. Fork `BelimbingApp/belimbing` and add upstream remote.
2. Place all licensee code in `extensions/{licensee}/` and `resources/extensions/{licensee}/`.
3. Use owner-prefixed table names (`{owner}_{module}_{entity}`).
4. Set `VITE_THEME_DIR={licensee}` in `.env`.
5. Sync upstream regularly: `git pull upstream main`.
6. Review file paths before commit — licensee code must not touch BLB core.
7. Contribute reusable improvements upstream via PR.

---

## 11. Related References

- `docs/architecture/file-structure.md` — directory layer convention and extension structure
- `docs/architecture/ui-layout.md` — core/licensee presentation split
- `docs/guides/theming.md` — token overrides and component resolution
- `docs/guides/extensions/config-overrides.md` — config override patterns
- `docs/guides/extensions/database-migrations.md` — migration and table naming
- `app/Base/Database/AGENTS.md` — database conventions
- `app/Base/Menu/Services/MenuDiscoveryService.php` — extension menu discovery glob
