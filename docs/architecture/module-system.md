# Module System

**Document Type:** Architecture Specification
**Purpose:** Define Belimbing's module system — directory layout, nested-git distribution, variation (adapters and slots), and discovery contracts
**Based On:** Project Brief v1.0.0, Ousterhout's "A Philosophy of Software Design"
**Last Updated:** 2026-06-10
**Related:** `docs/architecture/pluggable-modules.md` — vision and phasing for full-stack downstream plugins (events, manifests); this document is the structural/mechanical contract it builds on.

---

## Overview

This document defines the module system that supports Belimbing's core principles:

- **Modules as Ownership Boundaries** — the module directory owns its full stack; everything below it is module internals
- **Pluggable Where Exercised** — plug-in behavior is real at three points: domain repos (each non-Core domain is a nested-git checkout), extension modules (present or absent per deployment), and swappable slots (one of N variant repos fills a module path; see [Module Variation](#module-variation-adapters-and-slots)). `Base` and `Core` never plug — they are the framework
- **Extension Modules** — licensee-owned extension modules grouped under one nested-git repo per licensee
- **Discovery by Convention** — the framework finds providers, migrations, menus, routes, settings, views, and tests through path globs; a module that satisfies the [discovery contracts](#discovery-contracts) is fully integrated, with no registration step
- **Quality-Obsessed** — deep modules with simple interfaces

---

## Directory Structure

The main repo tracks Belimbing's required core — `app/Base`, `app/Modules/Core`, and the framework directories. Every non-Core domain is a nested-git checkout. A module path is in one of four states:

**Required — main repo**

```text
app/Base/{Module}/...                        # Framework infrastructure (shallow)
app/Modules/Core/{Module}/...                # Application Core — integrated business foundations
```

**Domain — nested-git repo**

```text
app/Modules/{Domain}/...                     # Commerce, Operation, People, … — one nested repo per domain; its modules (and the domain Config/ and Tests/) version together
```

**Slot — variant nested-git repo**

```text
app/Modules/{Domain}/{Module}/...            # Same path, ignored by the domain repo; one of N variant repos fills it per deployment
```

**Extension — licensee nested-git repo**

```text
extensions/{licensee}/{module}/...           # `{licensee}` is one nested-git repository; modules inside it version together under that licensee
```

The **Module** directory is the full-stack ownership boundary: code, database artifacts, config, routes, tests, and any module-owned views or assets below that directory are module internals.

**The module rule:** a second-level directory containing a `ServiceProvider.php` is a module. Other directories at the same level are inert to provider discovery — domains may own a `Config/` directory (menu anchors), and licensee repos may carry non-module directories such as `docs/` or `scripts/`.

### Term Definitions

| Term | Meaning | Examples |
|------|---------|----------|
| **`app/Base/`** | Required framework-owned infrastructure. Shallow — no domain grouping. Not pluggable. | `Database`, `AI`, `Menu`, `Settings` |
| **`app/Modules/`** | Application-owned code, grouped by domain. | (the directory itself) |
| **`app/Modules/Core/`** | Required application Core — integrated business foundations that ship with Belimbing. Not pluggable. Shared shell UI may still live in `resources/core`. | `Company`, `User`, `Geonames`, `Employee` |
| **Domain** | A business domain under `app/Modules`. It groups modules by business area. `Core` is required in the main repo; every other domain is one nested-git repo whose modules version together. A module inside a domain repo can still be carved out as a slot. | `Core`, `Commerce`, `Operation`, `People` |
| **Licensee repo** | A nested-git checkout at `extensions/{licensee}/`. It owns one or more extension modules under that path. | `extensions/sb-group/`, `extensions/ham/` |
| **Extension module** | A module inside a licensee repo. It mirrors the internal module structure so code, views, config, and tests stay colocated. | `extensions/sb-group/qac`, `extensions/ham/auto-parts` |
| **Module** | The ownership boundary for a cohesive capability. Labeling stops here; everything below the module directory is module internals. | `Database`, `Geonames`, `Inventory`, `IT`, `Attendance` |
| **Entity** | A domain object or relation owned by a module. Entities appear in models, tables, routes, UI features, factories, and seeders, but they are not ownership boundaries. | `Country`, `Part`, `Ticket`, `Role` |

> **Note on "domain":** used here in the general business-area sense, not in the strict Domain-Driven Design sense. Modules within a domain are cohesive capabilities, not formally enforced bounded contexts. Where the DDD mapping helps: Domain ≈ DDD *subdomain*; Module ≈ DDD *bounded context*.

### Path Examples

| Path | Shape | Notes |
|------|-------|-------|
| `app/Base/Database` | `Base/Module` | Required Base module; no domain |
| `app/Base/AI` | `Base/Module` | Required Base module; no domain |
| `app/Modules/Core/Geonames` | `Modules/Core/Module` | Required Core module |
| `app/Modules/Commerce/Inventory` | `Modules/Domain/Module` | Module inside the Commerce domain repo (nested-git) |
| `app/Modules/People/Attendance` | `Modules/Domain/Module` | Module inside the People domain repo (nested-git) |
| `extensions/sb-group/qac` | `extensions/licensee/module` | Extension module inside licensee nested-git repo (kebab-case) |

### Naming Conventions

- **Internal module directories** (`app/Base`, `app/Modules`) use **PascalCase**: `Database/`, `Models/`, `Config/`, `Services/`, `Migrations/`, `Seeders/`, `Factories/`, etc. This aligns with PHP namespace segments under `App\`.
- **Extension directories** (`extensions/`) use **kebab-case** for the licensee and module segments: `extensions/sb-group/qac`. The `Extensions\` namespace maps PascalCase segments to those kebab-case directories — `Extensions\SbGroup\Qac\ServiceProvider` resolves to `extensions/sb-group/qac/ServiceProvider.php`. The mapping is implemented by `App\Base\Foundation\ExtensionAutoloader` (namespace → path via `Str::pascalToKebab`) and `ProviderRegistry` (path → class via `Str::kebabToPascal`). Directories *below* the module segment use PascalCase, the same as internal modules.
- **Module naming:** use a singular PascalCase capability/domain name for new module directories (`Claim`, `Leave`, `Payroll`, `Employee`) even when the user-facing menu label or route path is plural (`Claims`, `people/claims`). Use plural module directory names only when the domain term is inherently plural or an established aggregate surface already uses it (`Settings`, the People-facing `Employees` workbench).
- **Config directory**: Use `Config/` (PascalCase). **Config file names** inside `Config/` use **lowercase** (e.g. `company.php`, `workflow.php`) to match the Laravel config key and framework convention. The module's ServiceProvider registers them with `mergeConfigFrom(__DIR__.'/Config/company.php', 'company')` so `config('company')` works.

---

## Nested-Git Distribution

Every nested unit — a domain repo, a licensee extension repo, or a slot variant — is its own git repository checked out *inside* the framework working tree. This is plain nested git, not git submodules.

How the two repositories coexist:

- Each `.git` directory is independent. The nested repo tracks its own files and never reads the outer repo's `.gitignore`; the outer repo's ignore rules only control what the *outer* repo sees.
- The outer repo cannot track files inside a nested checkout anyway — git records at most a bare "gitlink" pointer, which is broken noise without submodule config. The ignore rule below prevents that from ever being staged.
- Developing framework and extension code in one working tree therefore works with no special tooling: run `git` commands at the repo root for framework changes, and inside `extensions/{licensee}/` for extension changes.

**Guard rules (committed in `.gitignore`):**

```gitignore
/extensions/*
!/extensions/README.md

/app/Modules/*
!/app/Modules/Core
```

The blanket rules ship with every clone, keep nested-repo code (private licensee extensions, domain repos) out of the framework repo, and avoid naming licensees publicly. Do not use per-machine `.git/info/exclude` entries for this.

**Repo boundaries:** the framework repo tracks `Base` and `Core`; each non-Core domain is one nested repo whose modules version together (cross-module changes within a domain stay atomic). Below that, repo boundaries follow swappability: a single module is carved out of its domain repo only when its path becomes a [slot](#module-variation-adapters-and-slots) — its first real variant arrives. Do not pre-extract slots.

**How to extract a slot from a domain repo:** in a single domain-repo commit, remove the module's files from the index and add an ignore line for its path (e.g. `/Payroll/` in the People repo's `.gitignore`); push the files to the variant's new repository (`git subtree split --prefix=Payroll` inside the domain repo produces a branch carrying just that module's history); then clone the chosen variant back into the same path. Discovery picks the module up again immediately — nothing else changes, because the namespace and path stay identical.

**Mounting on another machine:** clone the framework repo, then clone each nested repo into its path — for a slot, the variant chosen for that deployment. There is no registration step; the [discovery contracts](#discovery-contracts) do the rest.

For the full private-repo workflow (creating the repo, remotes, daily commands), see `docs/guides/extensions/private-extension-repositories.md`.

---

## Module Variation: Adapters and Slots

Deployments differ — Malaysian payroll follows EPF/SOCSO/PCB rules, a Malaysian commerce deployment sells through Shopee and Lazada. Replaceability is a **contract problem first and a git problem second**: before anything moves between repos, the variation point needs a defined seam. There are two mechanisms; choose per module.

> **A note on "pluggable":** earlier versions of this document promised "independently pluggable modules." That phrase conflated two different promises — *removability* (a deployment has the code or doesn't; exercised by domain repos and extension modules) and *replaceability* (a deployment chooses among implementations; exercised by slots). This document keeps the mechanics that make both possible everywhere (discovery contracts, contract-only dependencies) but does not claim per-module replaceability until a real variant converts that module into a slot. A module inside a domain repo is *potentially* swappable; domains, slots, and extensions are what *actually* plug.

### Mechanism 1 — Contract + adapters (the default)

Use when variants share an engine and differ in rules or integrations. The module owns the stable engine (lifecycle, schema, UI) and publishes contracts; variation ships as adapter classes that register through discovery. The engine is written once; each country or licensee adds only its rules.

This pattern is already live in Commerce:

- `Commerce/Marketplace` defines `MarketplaceChannelProvider`; adapters register channels through `MarketplaceChannelRegistry`. Shopee and Lazada are channel adapters contributed by a Malaysian module or extension — the Marketplace engine (listings, orders, readiness) does not change.
- `Commerce/Plugins` is the seam module: `CommercePluginRegistry` accepts channel providers, readiness contributors, catalog presets, workbench panels, and insight pages. Ham's `AutoPartsReadinessContributor` (in `extensions/ham/auto-parts`) is a working example.

Payroll variation is usually this shape too: a shared pay-run/payslip engine with statutory rules (contribution tables, tax formulas) as policy adapters selected by the company's country.

### Mechanism 2 — Slot replacement

Use when variants diverge in lifecycle, consumers, or regulatory burden (see [Principle 1a](#1a-distinct-domains-earn-distinct-modules)) so much that sharing an engine harms both. The whole module is replaced: **the module path is the slot**, and a deployment chooses which implementation fills it.

Slot rules:

1. **Fixed identity.** Every variant mounts at the same path (`app/Modules/People/Payroll/`), declares the same namespace (`App\Modules\People\Payroll\`), and carries the same manifest id (`extra.blb.module: people/payroll`). Variants differ only by git remote (`blb-people-payroll`, `blb-people-payroll-my`). Dependents' imports and `requires-modules` declarations never change.
2. **Contract-only dependencies.** Other modules may consume the slot's events, call its service contracts, and link its routes — they must never query its tables or import classes outside its contract surface. This is what makes the swap invisible to the rest of the system.
3. **All-repo.** A slot path is permanently ignored by the main repo and always filled by a nested-git checkout; the default implementation is itself a variant repo. A slot is never a tracked default that some deployments overlay — that would require per-machine exclude rules.
4. **Deploy-time choice.** Each variant owns its migrations and tables. Picking a variant is a deployment decision; switching variants on a live database is a data-migration project, not a toggle.
5. **Documented surface.** Before a second variant exists, document the slot's public surface: events published and consumed, service contracts implemented, menu and route surface provided. That document is what lets another team build a variant without reading the default's source.

### Choosing between them

Prefer adapters — they keep one engine maintained instead of N. Convert a module into a slot only when a real whole-module variant arrives, using the [extraction procedure](#nested-git-distribution). The boundary that matters is the swappable seam, not the domain: `People/Payroll` may become a slot while `People/Settings` — which nothing replaces — stays tracked in the main repo beside it.

---

## Discovery Contracts

These path globs are the pluggability contract: a module that satisfies them is fully integrated. Any new discovery mechanism must be path-glob based across all three roots, or it breaks the model.

| What | Patterns | Discovered by |
|------|----------|---------------|
| Service providers | `app/Base/*/ServiceProvider.php` · `app/Modules/*/*/ServiceProvider.php` · `extensions/*/*/ServiceProvider.php` | `App\Base\Foundation\Providers\ProviderRegistry` (order: priority → Base → Modules → extensions → app) |
| Migrations | `app/Base/*/Database/Migrations` · `app/Modules/*/*/Database/Migrations` · `database/migrations` · `extensions/*/*/Database/Migrations` | `App\Base\Database` services |
| Menus | `Config/menu.php` under `app/Base/*`, `app/Modules/*` (domain anchors), `app/Modules/*/*`, `extensions/*`, `extensions/*/*` | `App\Base\Menu\Services\MenuDiscoveryService` |
| Routes | `app/Base/*/Routes` · `app/Modules/*/*/Routes` · `extensions/*/*/Routes` | `App\Base\Routing\RouteDiscoveryService` |
| Settings | `Config/settings.php` under `app/Base/*`, `app/Modules/*/*`, `extensions/*/*` | `App\Base\Settings\ServiceProvider` |
| Authz | `Config/authz.php` under `app/Base/*`, `app/Modules/*/*` — **not** extensions; extension capabilities register through the framework-supported authz path | `App\Base\Authz\ServiceProvider` |
| Views | Not glob-discovered — each module provider calls `loadViewsFrom(__DIR__.'/Views', '<namespace>')` | module `ServiceProvider` |
| Tailwind classes | `@source` entries in `resources/app.css`: `./core/views`, `../app/Modules/*/*/Views`, `../extensions/*/*/Views` | Vite/Tailwind build |
| Blade hot reload | `resources/core/views/**` · `app/Modules/*/*/Views/**` · `extensions/*/*/Views/**` | `vite.config.js` |
| Tests | testsuites `Modules` (`app/Modules/*/Tests`, `app/Modules/*/*/Tests`) and `Extensions` (`extensions/*/*/Tests`) | `phpunit.xml` + `tests/Pest.php` |
| Module manifests | `composer.json` with an `extra.blb` block at the module root (optional) | `App\Base\Foundation\ModuleManifest\ModuleManifestReader` |

---

## Root Structure

```
belimbing/
├── app/                     # Application code
│   ├── Base/                # Framework infrastructure modules
│   ├── Modules/             # Core/ tracked here; other domains are nested-git repos
│   └── Providers/           # App-level providers (AppServiceProvider)
│
├── bootstrap/
│   ├── app.php              # Application configuration (middleware, exceptions)
│   └── providers.php        # ProviderRegistry::resolve() — provider discovery entry
│
├── config/                  # Laravel bootstrap config only (app, database, cache, …)
│                            # Module config lives in each module's Config/
│
├── database/
│   ├── migrations/          # Laravel built-in migrations only (cache, jobs, …)
│   └── seeders/             # Global DatabaseSeeder
│
├── extensions/              # Licensee nested-git repos: {licensee}/{module}
│
├── resources/
│   ├── core/                # Framework-owned shared presentation (see below)
│   └── app.css              # Vite CSS entry point
│
├── routes/                  # web.php, console.php, channels.php
│                            # Module routes live in each module's Routes/
│
├── scripts/                 # Setup, start/stop, and maintenance scripts
├── storage/                 # Logs, cache, sessions, app storage
├── tests/                   # Framework-level test suite (see Testing Structure)
├── docs/                    # Architecture docs, guides, module docs
└── public/                  # Web root
```

---

## Core Application Structure (`app/`)

### `app/Base/` — Framework Infrastructure

**Path shape:** `app/Base/{Module}/` — subdirectories are modules.

`app/Base/` provides framework infrastructure, extension points, and core abstractions. Modules here are foundational and cannot depend on `app/Modules/`.

Current Base modules: `AI`, `Audit`, `Authz`, `Cache`, `Database`, `DateTime`, `Foundation`, `Integration`, `Livewire`, `Locale`, `Log`, `Media`, `Menu`, `Pdf`, `Queue`, `Routing`, `Schedule`, `Session`, `Settings`, `Support`, `System`, `Workflow`.

`Foundation` carries the cross-cutting plumbing: `ProviderRegistry` (provider discovery), `ExtensionAutoloader` (the `Extensions\` namespace), and `ModuleManifest` (parsed `extra.blb` manifests for the plugin catalog).

### `app/Modules/` — Application Modules

**Path shape:** `app/Modules/{Domain}/{Module}/` — domains contain modules; a domain may also own `Config/` (menu anchor) and `Tests/` (domain-spanning tests) directories. Each non-Core domain directory is the root of its own nested-git repo. `People` anchors HR-domain modules (Attendance, Leave, Claim, Payroll, Settings); it should not own the canonical Employee module, which lives in `Core` because employee records can belong to any company.

```
app/Modules/
├── Core/                    # Required business foundations (main repo)
│   ├── AI/                  # AI application surfaces
│   ├── Address/
│   ├── Company/
│   ├── Employee/            # Employment records for any company
│   ├── Geonames/
│   └── User/
│
├── Commerce/                # Nested-git domain repo
│   ├── Config/              # Domain menu anchor
│   ├── Catalog/
│   ├── Inventory/
│   ├── Marketplace/
│   ├── Plugins/             # Commerce plugin seams
│   ├── Sales/
│   └── Settings/
│
├── Operation/               # Nested-git domain repo
│   ├── Config/
│   ├── IT/
│   └── Quality/             # NCR / SCAR / CAPA workflows
│
└── People/                  # Nested-git domain repo
    ├── Config/
    ├── Attendance/  Benefits/  Claim/  Employees/  Leave/
    ├── Payroll/  Performance/  Recruitment/  Settings/  Training/
```

Each module is a self-contained capability and includes only the internals it needs. Outside `app/Modules/Core`, new module-owned Blade views belong under the module's `Views/` directory so the module can become a nested-git checkout without leaving UI behind in `resources/`.

**Module Structure Template (for `app/Modules/{Domain}/{Module}/`):**

All subdirectories within a module are **module internals** — they are not assigned additional ownership labels.

```
app/Modules/{Domain}/{Module}/
├── Database/                 # Module internals: database layer
│   ├── Migrations/           # Module migrations (auto-discovered)
│   ├── Seeders/              # Production seeders (reference/config data)
│   │   └── Dev/              # Development seeders (Dev* prefix, fake test data)
│   └── Factories/            # Module factories
├── Models/                   # Eloquent models
├── Services/                 # Business logic
├── Controllers/              # HTTP controllers
├── Livewire/                 # Livewire component classes
├── Events/                   # Events
├── Listeners/                # Event listeners
├── Hooks/                    # Extension hooks
├── Routes/                   # Routes (web.php / api.php, auto-discovered)
├── Views/                    # Views (registered by the module provider)
├── Assets/                   # Optional module-owned frontend source
├── Config/                   # Config files (PascalCase dir; filenames lowercase)
├── Tests/                    # Module tests (auto-discovered; Feature/ gets the app TestCase)
├── ServiceProvider.php       # Makes the directory a module
└── composer.json             # Optional module manifest (extra.blb block)
```

For `app/Modules/Core/{Module}`, framework-wide or shared UI may still live in
`resources/core`. For every other domain, `Views/` is the default home for
module-owned Blade templates. Shared components promoted out of a module belong
in `resources/core/views/components/`.

`Assets/` is optional and only for module-owned frontend source that cannot be
expressed through shared components, Tailwind tokens, Livewire, or small Alpine
expressions in Blade. `Assets/css` and `Assets/js` are not auto-injected; the
host app must explicitly add or import reviewed entry points. Framework-wide
tokens and JavaScript remain in `resources/core`.

**Module manifests:** a module may carry a `composer.json` whose `extra.blb`
block declares its identity, role, version, and module dependencies
(`requires-modules`, `optional-modules`, published/consumed events). The
manifest is metadata for the plugin catalog — it does nothing by itself, and
distribution remains nested-git.

---

## Extension Structure (`extensions/`)

Extensions follow the same two-level layout with kebab-case directories: `extensions/{licensee}/{module}/`. The `{licensee}` segment names the licensee-owned extension namespace and is the root of that licensee's nested-git repo.

```
extensions/
├── {licensee}/                # Licensee nested-git repo (e.g. sb-group)
│   ├── qac/                   # One module → Extensions\SbGroup\Qac\
│   │   ├── Config/
│   │   │   ├── menu.php       # Menu items (auto-discovered)
│   │   │   ├── settings.php   # Settings (auto-discovered)
│   │   │   └── qac.php        # Module config (lowercase)
│   │   ├── Database/
│   │   │   ├── Migrations/
│   │   │   └── Seeders/
│   │   ├── Livewire/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Routes/
│   │   │   └── web.php
│   │   ├── Views/
│   │   ├── Assets/            # Optional, reviewed host build entry points only
│   │   ├── Tests/
│   │   └── ServiceProvider.php
│   ├── ibp/                   # Another module (scales to many)
│   └── docs/                  # Non-module dirs are allowed and ignored by discovery
```

Extension modules include only the internals they need. Module-owned views live under `extensions/{licensee}/{module}/Views/` and are registered by the module provider; do not create a companion `resources/extensions` tree.

For nested private repository workflow, see
`docs/guides/extensions/private-extension-repositories.md`.

---

## Database Structure (`database/`)

```
database/
├── migrations/               # Laravel built-in migrations only (cache, jobs, etc.)
└── seeders/                  # Global DatabaseSeeder
```

All module migrations are auto-discovered from `{Module}/Database/Migrations/` across `app/Base`, `app/Modules`, and `extensions` (see [Discovery Contracts](#discovery-contracts)).

---

## Testing Structure

Module-owned tests live inside the module; the root `tests/` tree hosts framework-level and cross-module tests.

```
tests/                        # Framework-level suite
├── Unit/                     # testsuite "Unit"
├── Feature/                  # testsuite "Feature"
├── Support/                  # Shared fixtures and helpers
├── Pest.php                  # Pest configuration (binds TestCase to Feature dirs)
├── TestCase.php
└── TestingBaselineSeeder.php

app/Modules/{Domain}/{Module}/Tests/    # testsuite "Modules"
extensions/{licensee}/{module}/Tests/   # testsuite "Extensions"
```

- Tests for a specific module belong in that module's `Tests/` directory so they travel with the module. `Tests/Feature/` files automatically get the application `TestCase` and `RefreshDatabase` via `tests/Pest.php`.
- Tests that span a whole domain (e.g. the domain menu shape) live in the domain-level `Tests/` directory (`app/Modules/Commerce/Tests/`).
- The root `tests/Unit/Modules` and `tests/Feature/Modules` trees contain Core module tests only; non-Core domain tests live in their domain repos.
- Test policy and assertion-strength guidance: `tests/AGENTS.md`.

---

## Framework Frontend Structure (`resources/`)

Resources under `resources/core/` are framework-owned shared presentation: shell layouts, auth layouts, reusable Blade components, design tokens, and JavaScript used by the framework shell. Module-owned pages for non-Core domains do not belong here; they live under `app/Modules/{Domain}/{Module}/Views/` or `extensions/{licensee}/{module}/Views/`.

```
resources/core/
├── views/
│   ├── layouts/             # Layout templates
│   │   ├── app.blade.php
│   │   └── auth.blade.php
│   │
│   ├── livewire/            # Core/shared Livewire view templates
│   │   ├── admin/           # Administration menu items
│   │   │   ├── addresses/
│   │   │   ├── ai/
│   │   │   ├── authz/
│   │   │   ├── companies/
│   │   │   ├── geonames/
│   │   │   ├── roles/
│   │   │   ├── setup/
│   │   │   ├── system/
│   │   │   ├── users/
│   │   │   └── workflows/
│   │   ├── auth/            # Guest authentication flow
│   │   ├── people/          # Core-owned people workbench views only
│   │   └── profile/         # Current user's own settings
│   │
│   └── components/          # Blade components
│       ├── menu/            # Sidebar navigation components
│       └── ui/              # Reusable UI primitives (x-ui.*)
│
├── css/
│   ├── tokens.css           # Design tokens (colors, spacing)
│   └── components.css       # Component-level styles
│
└── js/                      # JavaScript assets
```

The Vite CSS entry point is `resources/app.css`; it imports the framework token
and component styles from `resources/core/css/` and scans Core, module, and
extension view paths for Tailwind classes.

Non-Core views should not live under `resources/core/views`. If one appears,
move it to its owning module root; new People, Commerce, Operation, Finance,
Sales, Procurement, and extension screens start in module-owned `Views/`
directories.

---

## Configuration System

- `config/` holds only Laravel bootstrap configuration (database, cache, session, …).
- Module configuration lives in each module's `Config/` directory, merged by its provider.
- Runtime settings are stored in the database through `App\Base\Settings` (`base_settings`), scoped to global, company, or employee level. Secrets, OAuth tokens, and API keys belong there — never in a repository.
- Environment variables cover bootstrap values only (DB and cache connections, app URL).

---

## Key Design Principles

### 1. Deep Modules, Simple Interfaces

Each module/extension is self-contained with:
- Clear public API
- Hidden implementation complexity
- Extension hooks at strategic points

### 1a. Distinct Domains Earn Distinct Modules

When two areas share a noun ("item," "order," "document") but differ in **lifecycle, valuation, consumers, or regulatory burden**, they are different domains and earn separate modules — not rows in a shared table behind a `purpose` / `type` / `kind` flag.

Concrete example: sales inventory, maintenance MRO, and production raw materials all involve "things we hold," but:

- Sales inventory has a `draft → listed → sold` lifecycle, sale-price valuation, marketplace listings, buyer-facing copy.
- Maintenance MRO has an `on-hand → issued → consumed` lifecycle, cost valuation, reorder points, suppliers, work-order links.
- Production raw materials are lot/batch-tracked, BOM-linked, FIFO/FEFO, with quality holds and traceability.

Forcing them into one schema yields either an anemic table (lowest common denominator, none happy) or a polluted one (every query steps around fields it doesn't use, every cross-domain change risks breaking the others). Both outcomes shallow the module.

The rule:

- Each subject lives in its own module under the appropriate domain (`Commerce/Inventory`, `Maintenance/MRO`, `Production/RawMaterials`, …) with its own tables, lifecycle, and consumers.
- Cross-module reporting is a thin Insights query that joins what it needs, **not** a fact about a shared table.
- Do not pre-build modules speculatively. The principle is about *placement when the second domain arrives*, not about scaffolding empty modules now.

A lightweight scope flag (`purpose`, `kind`, etc.) is acceptable only when the variants share the same lifecycle, valuation, consumers, and queries — i.e. when they are genuinely the same concept with a label.

### 2. Discovery Over Registration

A module declares nothing centrally. Dropping a conforming directory into a discovery root is the entire installation; removing it is the entire uninstall. Consequences:

- Every framework mechanism that consumes module artifacts must discover them through the [contract globs](#discovery-contracts) across all three roots (`app/Base`, `app/Modules`, `extensions`).
- Provider load order is part of the contract: priority → Base → Modules → extensions → app providers, alphabetical within each group.

### 3. Single Source of Truth

- Code: git repositories (main repo plus nested repos)
- Runtime settings: database (`base_settings`)
- Environment: only bootstrap values

---

**Related Documents:** `docs/brief.md`, `docs/modules/*/`, `docs/guides/extensions/private-extension-repositories.md`
