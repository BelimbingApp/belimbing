# Module System

**Document Type:** Architecture Specification
**Scope:** Belimbing's module system — directory layout, Distribution Bundle model, lifecycle, variation (adapters and slots), and discovery contracts
**Based On:** Project Brief v1.0.0, Ousterhout's "A Philosophy of Software Design"
**Last Updated:** 2026-06-20
**Related:** `docs/brief.md`, `docs/architecture/database.md`, `docs/modules/*/`, `docs/guides/extensions/private-extension-repositories.md`, `docs/guides/extensions/database-migrations.md`

---

## Overview

This document defines the module system that supports Belimbing's core principles:

- **Modules as Ownership Boundaries** — the module directory owns its full stack; everything below it is module internals
- **Pluggable Where Exercised** — plug-in behavior is real at three points: domain Distribution Bundles, extension modules, and swappable slots (one of N variants fills a module path; see [Module Variation](#module-variation-adapters-and-slots)). `Base` and `Core` never plug — they are the framework
- **Extension Modules** — licensee-owned extension modules grouped under one licensee Distribution Bundle
- **Discovery by Convention** — provider discovery is glob-based; artifact discovery is path-contract based per artifact. A module that satisfies the relevant [discovery contracts](#discovery-contracts) is integrated with no central registration step
- **Quality-Obsessed** — deep modules with simple interfaces

### Vocabulary

These terms are adjacent and easily conflated. They are not synonyms.

| Term | Meaning |
|------|---------|
| **Module** | The *ownership boundary* — a filesystem path that owns its full stack (code, DB artifacts, config, routes, views, tests, discovery contract). |
| **Distribution Bundle** (operator-facing: **Bundle**) | The *delivery/versioning unit* — the installable, versioned code bundle (git remote/branch/tag/commit today, Composer later). One bundle may contain several modules; a licensee extension bundle is the common case. **Bundle** is the operator-facing short form, used by the admin UI in place of the retired word "plugin." See [Distribution Bundle Model](#distribution-bundle-model). |
| **Adapter** | A class that implements a provider/contract so variation registers through discovery — e.g. `MarketplaceChannelProvider` (Shopee, Lazada) or `CommerceReadinessContributor` (Ham auto-parts). This is [Mechanism 1](#mechanism-1--contract--adapters-the-default). An adapter is one *contribution*, not a registry. |
| **Extension seam (contribution registry)** | A module that *discovers and registers contributions from other modules* into a host domain. `Commerce/Plugins` + `CommercePluginRegistry` is the live example: it collects both adapter classes (channel providers, readiness contributors) **and** data/config contributions (catalog presets, template mappings, workbench panels, insight pages). It is an extension point, **not** an adapter, and the contributions it holds are broader than adapters. The historical `Plugins` directory name predates this glossary; read it as "Commerce's extension seam." |

> **Note on "plugin" (retired on operator surfaces):** the word was overloaded across the codebase, meaning three different things — (1) the inventory **dashboard** of installed *modules* plus an available-bundle catalog; (2) `Commerce/Plugins`, an **extension seam** (above); (3) colloquially, an installable unit, which this spec always calls a **Distribution Bundle** and never "plugin." None of these is an *adapter*. The operator-facing surfaces have dropped "plugin": the admin menu group is **Software**, its inventory/catalog screen is **Bundles**, and the pull/build/migrate/reload screen is **Updates**. The `extra.blb.role` field (which carried `source`/`plugin` values) was removed entirely as unused — a module's role is derivable from its dependency edges and was duplicated by the composer `"type"`. The remaining "plugin" tokens are internal: the `Commerce/Plugins` directory / `CommercePluginRegistry`, and the composer package `"type": "blb-plugin"`. When precision matters, prefer Module, Distribution Bundle (Bundle), adapter, or extension seam over "plugin."

---

## Directory Structure

The module boundary is the filesystem path plus its discovery contract. The current default delivery mechanism is nested git; Composer/package installation remains valid if it preserves the same path, namespace, and artifact contracts.

| Layer | Path shape | Distribution Bundle | Notes |
|------|------------|--------------|-------|
| Base | `app/Base/{Module}/` | Main repo | Framework infrastructure. Shallow; no domain grouping. Not pluggable. |
| Core | `app/Modules/Core/{Module}/` | Main repo | Required business foundations. Not pluggable. |
| Domain | `app/Modules/{Domain}/{Module}/` | One installable Distribution Bundle per non-Core domain | Domain-level `Config/` and `Tests/` may sit beside modules. A module can later become a slot. |
| Slot | `app/Modules/{Domain}/{Module}/` | One selected variant Distribution Bundle | Same path and namespace as the slot contract; only the provider Distribution Bundle changes. |
| Extension | `extensions/{licensee}/{module}/` | One licensee Distribution Bundle containing one or more modules | Licensee and module path segments use kebab-case and map to `Extensions\{Licensee}\{Module}`. |

The **module** directory is the full-stack ownership boundary: code, database artifacts, config, routes, tests, and module-owned views or assets below it are module internals. A directory containing `ServiceProvider.php` at one of the module path shapes is provider-discoverable; other peer directories are inert to provider discovery.

An **entity** is not an ownership boundary. It is a domain object or relation owned by a module and may appear in models, tables, routes, UI features, factories, and seeders.

> **Note on "domain":** this document uses domain in the business-area sense, not strict DDD. Where the DDD mapping helps: Domain ≈ DDD *subdomain*; Module ≈ DDD *bounded context*.

### Naming Conventions

- **Internal module directories** (`app/Base`, `app/Modules`) use **PascalCase**: `Database/`, `Models/`, `Config/`, `Services/`, `Migrations/`, `Seeders/`, `Factories/`, etc. This aligns with PHP namespace segments under `App\`.
- **Extension directories** (`extensions/`) use **kebab-case** for the licensee and module segments: `extensions/sb-group/qac`. The `Extensions\` namespace maps PascalCase segments to those kebab-case directories — `Extensions\SbGroup\Qac\ServiceProvider` resolves to `extensions/sb-group/qac/ServiceProvider.php`. The mapping is implemented by `App\Base\Foundation\ExtensionAutoloader` (namespace → path via `Str::pascalToKebab`) and `ProviderRegistry` (path → class via `Str::kebabToPascal`). Directories *below* the module segment use PascalCase, the same as internal modules.
- **Module naming:** use a singular PascalCase capability/domain name for new module directories (`Claim`, `Leave`, `Payroll`, `Employee`) even when the user-facing menu label or route path is plural (`Claims`, `people/claims`). Use plural module directory names only when the domain term is inherently plural or an established aggregate surface already uses it (`Settings`, the People-facing `Employees` workbench).
- **Config directory**: Use `Config/` (PascalCase). **Config file names** inside `Config/` use **lowercase** (e.g. `company.php`, `workflow.php`) to match the Laravel config key and framework convention. The module's ServiceProvider registers them with `mergeConfigFrom(__DIR__.'/Config/company.php', 'company')` so `config('company')` works.

---

## Distribution Bundle Model

A **Distribution Bundle** is BLB's installable, versioned code bundle. It lands in the repository at one of the path and namespace contracts in [Directory Structure](#directory-structure).

The Distribution Bundle is the delivery unit, not the ownership boundary. The module path owns the code, database artifacts, config, routes, views, assets, tests, and discovery contract; the Distribution Bundle records how that code is shipped and versioned.

Current BLB deployments use nested git repositories for non-Core domains, licensee extensions, and slot variants. `Base` and `Core` ship in the main Belimbing platform repository; installed Distribution Bundles keep their own history. Deployment composition is the platform repo plus the installed Distribution Bundles and their versions, recorded today by Git remotes, branches or tags, and commits.

A future Composer/package delivery path is valid if it preserves the same module identity, namespace, manifest, views/assets/config/tests, and discovery surface. The package manager may change; the path and discovery contract must not.

Repo boundaries follow swappability. A module is split out of its domain Distribution Bundle only when its path becomes a [slot](#module-variation-adapters-and-slots) — when the first real variant arrives. Do not pre-extract slots. When a module becomes a slot, the original domain Distribution Bundle stops owning that path and the selected variant Distribution Bundle fills it; discovery stays unchanged because the namespace and path stay identical.

For the full private-repo workflow (creating the repo, remotes, daily commands), see `docs/guides/extensions/private-extension-repositories.md`.

### Domain Lifecycle

A fresh Belimbing clone runs with Base + Core. Optional business domains can be installed, disabled, or uninstalled from **Administration → System → Software → Modules** or by equivalent deployment automation.

- **Installed:** the domain Distribution Bundle is present and participates in discovery.
- **Disabled:** the Distribution Bundle remains present, but its providers, routes, menus, settings, authz, migrations, tests, and UI surfaces are excluded from discovery; persistent data is retained.
- **Uninstalled:** the Distribution Bundle is removed. Persistent data is retained unless the operator explicitly chooses cleanup.

This separates code composition from durable database state: removing code is not the same decision as deleting data. Unclaimed database state — whether kept by an uninstall or left by schema drift during development — is listed and cleaned up under **Administration → System → Database → Database Residue**, which compares the database against what the code on disk claims (migration-created tables, declared settings).

---

## Module Variation: Adapters and Slots

Deployments differ — Malaysian payroll follows EPF/SOCSO/PCB rules, a Malaysian commerce deployment sells through Shopee and Lazada. Replaceability is a **contract problem first and a delivery problem second**: before anything moves between Distribution Bundles, the variation point needs a defined seam. There are two mechanisms; choose per module.

> **A note on "pluggable":** earlier versions of this document promised "independently pluggable modules." That phrase conflated two different promises — *removability* (a deployment has the code or doesn't; exercised by domain Distribution Bundles and extension modules) and *replaceability* (a deployment chooses among implementations; exercised by slots). This document keeps the mechanics that make both possible everywhere (discovery contracts, contract-only dependencies) but does not claim per-module replaceability until a real variant converts that module into a slot. A module inside a domain Distribution Bundle is *potentially* swappable; domains, slots, and extensions are what *actually* plug.

### Mechanism 1 — Contract + adapters (the default)

Use when variants share an engine and differ in rules or integrations. The module owns the stable engine (lifecycle, schema, UI) and publishes contracts; variation ships as adapter classes that register through discovery. The engine is written once; each country or licensee adds only its rules.

This pattern is already live in Commerce:

- `Commerce/Marketplace` defines `MarketplaceChannelProvider`; adapters register channels through `MarketplaceChannelRegistry`. Shopee and Lazada are channel adapters contributed by a Malaysian module or extension — the Marketplace engine (listings, orders, readiness) does not change.
- `Commerce/Plugins` is the seam module: `CommercePluginRegistry` accepts channel providers, readiness contributors, catalog presets, workbench panels, and insight pages. Ham's `AutoPartsReadinessContributor` (in `extensions/ham/auto-parts`) is a working example.

Payroll variation is usually this shape too: a shared pay-run/payslip engine with statutory rules (contribution tables, tax formulas) as policy adapters selected by the company's country.

### Mechanism 2 — Slot replacement

Use when variants diverge in lifecycle, consumers, or regulatory burden (see [Principle 1a](#1a-distinct-domains-earn-distinct-modules)) so much that sharing an engine harms both. The whole module is replaced: **the module path is the slot**, and a deployment chooses which implementation fills it.

Slot rules:

1. **Fixed identity.** Every variant mounts at the same path (`app/Modules/People/Payroll/`), declares the same namespace (`App\Modules\People\Payroll\`), and carries the same manifest id (`extra.blb.module: people/payroll`). Variants differ by Distribution Bundle identity/version (Git remote today, package identity later). Dependents' imports and `requires-modules` declarations never change.
2. **Contract-only dependencies.** Other modules may consume the slot's events, call its service contracts, and link its routes — they must never query its tables or import classes outside its contract surface. This is what makes the swap invisible to the rest of the system.
3. **Variant-owned path.** A slot path is not owned by the parent domain Distribution Bundle; it is always supplied by the selected variant Distribution Bundle. The default implementation is itself a variant. A slot is never a tracked default that some deployments overlay.
4. **Deploy-time choice.** Each variant owns its migrations and tables. Picking a variant is a deployment decision; switching variants on a live database is a data-migration project, not a toggle.
5. **Documented surface.** Before a second variant exists, document the slot's public surface: events published and consumed, service contracts implemented, menu and route surface provided. Keep that contract in `docs/modules/` or in the slot Distribution Bundle's own module docs and link it from the owning domain docs. The goal is that another team can build a variant without reading the default implementation.

### Choosing between them

Prefer adapters — they keep one engine maintained instead of N. Convert a module into a slot only when a real whole-module variant arrives, using the [Distribution Bundle model](#distribution-bundle-model). The boundary that matters is the swappable seam, not the domain: `People/Payroll` may become a slot while `People/Settings` — which nothing replaces — stays in the domain Distribution Bundle beside it.

---

## Module Communication Contracts

Use the smallest public surface that keeps module internals hidden:

- **Events for published facts.** When one module produces a fact another module may consume, publish a producer-domain event. Payloads use the producer's language; do not leak consumer-specific concepts into the producer. If Payroll consumes an Attendance allowance, the Attendance event carries attendance facts such as employee, date, rule, and amount — not a payroll pay-item code.
- **Service contracts for direct calls.** When another module must ask for state or invoke behavior synchronously, depend on a contract owned by the provider of that behavior, not on its models, tables, or internal services.
- **No listener means no failure.** A source module must keep working when no consumer is installed. Missing consumers mean no listener runs; they do not make the producer invalid.
- **Public payloads are stable API.** Once shipped, event fields and service contract meanings may be added to but not silently removed or renamed. Breaking changes require a versioned event/contract and a migration path for consumers.

---

## Discovery Contracts

These path contracts are the pluggability contract: a module that satisfies the artifact contracts relevant to it is integrated without central registration. New artifact discovery should be path-based across every root that supports that artifact; exceptions must be explicit here. Provider order is deterministic for bootstrapping and override seams, not a substitute for module independence.

| What | Patterns | Discovered by |
|------|----------|---------------|
| Service providers | `app/Base/*/ServiceProvider.php` · `app/Modules/*/*/ServiceProvider.php` · `extensions/*/*/ServiceProvider.php` | `App\Base\Foundation\Providers\ProviderRegistry` (order: priority → Base → Modules → extensions → app) |
| Migrations | `app/Base/*/Database/Migrations` · `app/Modules/*/*/Database/Migrations` · `database/migrations` · `extensions/*/*/Database/Migrations` | `App\Base\Database` migration commands |
| Menus | `Config/menu.php` under `app/Base/*`, `app/Modules/*` (domain anchors), `app/Modules/*/*`, `extensions/*`, `extensions/*/*` | `App\Base\Menu\Services\MenuDiscoveryService` |
| Routes | `app/Base/*/Routes` · `app/Modules/*/*/Routes` · `extensions/*/*/Routes` | `App\Base\Routing\RouteDiscoveryService` |
| Settings | `Config/settings.php` under `app/Base/*`, `app/Modules/*/*`, `extensions/*/*` | `App\Base\Settings\ServiceProvider` |
| Authz | `Config/authz.php` under `app/Base/*`, `app/Modules/*/*` — **not** extensions | `App\Base\Authz\ServiceProvider`; extensions merge their own authz config from their discovered provider when they need capabilities |
| Views | Not glob-discovered — each module provider calls `loadViewsFrom(__DIR__.'/Views', '<namespace>')` | module `ServiceProvider` |
| Tailwind classes | `@source` entries in `resources/app.css`: `./core/views`, `../app/Modules/*/*/Views`, `../extensions/*/*/Views` | Vite/Tailwind build |
| Blade hot reload | `resources/core/views/**` · `app/Modules/*/*/Views/**` · `extensions/*/*/Views/**` | `vite.config.js` |
| Tests | testsuites `Modules` (`app/Modules/*/Tests`, `app/Modules/*/*/Tests`) and `Extensions` (`extensions/*/*/Tests`) | `phpunit.xml` + `tests/Pest.php` |
| Module manifests | `composer.json` with an `extra.blb` block at the module root (optional) | `App\Base\Foundation\ModuleManifest\ModuleManifestReader`; used by the Modules screen and database migration dependency preflight |

---

## Module Internals

All module roots use the same internal vocabulary. A module includes only the directories it needs.

| Internal path | Contract |
|---------------|----------|
| `ServiceProvider.php` | Marks the directory as a module and registers behavior not covered by scanners. |
| `Database/Migrations/` | Schema owned by the module; centrally loaded by BLB migration commands. Laravel core tables stay in `database/migrations/`. |
| `Database/Seeders/`, `Database/Factories/` | Module data fixtures and factories; see `app/Base/Database/AGENTS.md` for seeding rules. |
| `Config/` | Module config. Files are lowercase and are either discovered by a framework scanner or merged by the module provider. |
| `Routes/` | Module web/API routes; discovered by the routing service. |
| `Views/` | Module-owned Blade views; registered by the module provider. Non-Core domains and extensions do not create companion `resources/*` trees. |
| `Assets/` | Optional module-owned frontend source. It is never auto-injected; the host build must explicitly import reviewed entry points. |
| `Models/`, `Services/`, `Livewire/`, `Events/`, `Listeners/`, `Hooks/`, `Controllers/` | Module implementation internals. |
| `Tests/` | Module-owned tests that travel with the module. |
| `composer.json` | Optional `extra.blb` manifest for module identity, version, dependencies, published/consumed events, and coarse schema defaults. |

`Foundation` is the Base module that carries cross-cutting module-system plumbing: `ProviderRegistry`, the `Extensions\` autoloader, and `ModuleManifest` parsing for the Modules screen. Current filesystem contents are authoritative for Base/Core inventory; this document does not try to maintain a duplicate module list.

Module manifests are metadata for installed-module UI, dependency-health warnings, and database migration dependency preflight. They should remain compatible with future Composer Distribution Bundles, but they do not replace Composer's PHP dependency resolution or provider independence.

`extra.blb.module` is the canonical module identity used by slots, dependencies, and the Modules screen. `extra.blb.requires-modules` declares hard dependencies by module identity. A required module must be installed and enabled before module-aware migration commands run. A conventional path identity (`base/database`, `core/company`, `people/payroll`, `vendor/module`) can satisfy availability only for modules that have no manifest yet; once a manifest declares `extra.blb.module`, the manifest identity is authoritative and the filesystem identity is not an alias. Non-wildcard version constraints require the required module to publish `extra.blb.version`.

Nested-git modules and future Composer packages publish schema state through the same module-root manifest. Per-migration schema maturity remains source-local in the migration file (`IncubatingSchema`). The optional `extra.blb.schema` block is only a coarse package default for future composerized plugins, such as `{ "default": "incubating" }` before first release; it must not list individual migration files because that would duplicate the migration-local source of truth.

Extension providers remain the integration point for extension behavior not covered by framework scanners, such as module config, views, commands, schedules, and extension authz. Extension migrations are not provider boilerplate: they are discovered by the Base database layer from `Database/Migrations/`. For private nested-git workflow, see `docs/guides/extensions/private-extension-repositories.md`.

---

## Testing Structure

Module-owned tests live inside the module; the root `tests/` tree hosts framework-level and cross-module tests.

- Tests for a specific module belong in that module's `Tests/` directory so they travel with the module. `Tests/Feature/` files receive the application `TestCase` and `RefreshDatabase` via `tests/Pest.php`.
- Tests that span a whole domain (e.g. the domain menu shape) live in the domain-level `Tests/` directory (`app/Modules/Commerce/Tests/`).
- The root `tests/Unit/Modules` and `tests/Feature/Modules` trees contain Core module tests only; non-Core domain tests live in their domain Distribution Bundles.
- PHPUnit and Pest discover module and extension tests from the path contracts in [Discovery Contracts](#discovery-contracts).
- Test policy and assertion-strength guidance: `tests/AGENTS.md`.

---

## Framework Frontend Structure (`resources/`)

`resources/core/` is framework-owned shared presentation: shell layouts, auth layouts, reusable Blade components, design tokens, and JavaScript used by the framework shell. Module-owned pages for non-Core domains and extensions live in the owning module's `Views/` directory, not under `resources/core/views`.

Shared components promoted out of a module belong under `resources/core/views/components/`. The Vite entry point (`resources/app.css`) imports framework tokens/components and scans Core, module, and extension view paths for Tailwind classes.

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

A module declares nothing centrally. Placing a conforming Distribution Bundle in a discovery root installs its code surface; disabling or removing it changes discovery immediately. Persistent state cleanup is a separate lifecycle decision, not a side effect of provider discovery. Consequences:

- Every framework mechanism that consumes module artifacts must use the [discovery contracts](#discovery-contracts) for each artifact and every root where that artifact is supported (`app/Base`, `app/Modules`, `extensions`).
- Provider load order is part of the contract: priority → Base → Modules → extensions → app providers, alphabetical within each group. Treat this as deterministic bootstrapping and override order, not as permission to create hidden provider-order dependencies.

### 3. Single Source of Truth

- Code: versioned Distribution Bundles (main repo plus installed domains, slots, and extensions)
- Runtime settings: database (`base_settings`)
- Environment: only bootstrap values
