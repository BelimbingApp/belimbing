# File Structure Conventions

**Document Type:** Architecture Specification
**Purpose:** Define the file and directory structure for Belimbing framework
**Based On:** Project Brief v1.0.0, Ousterhout's "A Philosophy of Software Design"
**Last Updated:** 2026-06-10

---

## Overview

This document defines the file structure that supports Belimbing's core principles:
- **Customizable Framework** - Deep extension system with hooks across the stack
- **Git-Native Workflow** - Development → Staging → Production branches
- **AI-Native Architecture** - AI integration points throughout
- **Quality-Obsessed** - Deep modules with simple interfaces
- **Extension Management** - Registry, validation, runtime safety

---

## Directory Structure

The main repo hosts Belimbing's required core. Pluggable capability uses two distribution shapes: each non-Core domain module on its own, or licensee modules grouped under one nested-git repo.

**Required — main repo**

```text
app/Base/{Module}/...                        # Framework infrastructure (shallow)
app/Modules/Core/{Module}/...                # Application Core — integrated business foundations
```

**Pluggable — one module at a time**

```text
app/Modules/{Domain}/{Module}/...            # Commerce, Operation, People, … — each module is independently pluggable (nested-git checkout and/or composer package)
```

**Extension — licensee nested-git repo**

```text
extensions/{licensee}/{Module}/...           # `{licensee}` is one nested-git repository; modules inside it version together under that licensee
```

The **Module** directory is the full-stack ownership boundary: code, database artifacts, config, routes, tests, and any module-owned views or assets below that directory are module internals.

### Term Definitions
| Term | Meaning | Examples |
|------|---------|----------|
| **`app/Base/`** | Required framework-owned infrastructure. Shallow — no domain grouping. Not pluggable. | `Database`, `AI`, `Cache`, `Queue` |
| **`app/Modules/`** | Application-owned code, grouped by domain. | (the directory itself) |
| **`app/Modules/Core/`** | Required application Core — integrated business foundations that ship with Belimbing. Not pluggable. Shared shell UI may still live in `resources/core`. | `Company`, `User`, `Geonames`, `Employee`, `Workflow` |
| **Domain** | A business domain under `app/Modules`. It groups modules by business area. `Core` is required in the main repo; modules in other domains are individually pluggable. | `Core`, `Commerce`, `Operation`, `People` |
| **Licensee repo** | A nested-git checkout at `extensions/{licensee}/`. It owns one or more extension modules under that path. | `extensions/sb-group/`, `extensions/ham/` |
| **Extension module** | A module inside a licensee repo. It mirrors the internal module structure so code, views, config, and tests stay colocated. | `extensions/sb-group/QAC`, `extensions/ham/auto-parts` |
| **Module** | The ownership boundary for a cohesive capability. Labeling stops here; everything below the module directory is module internals. | `Database`, `AI`, `Geonames`, `Inventory`, `IT`, `Attendance` |
| **Entity** | A domain object or relation owned by a module. Entities appear in models, tables, routes, UI features, factories, and seeders, but they are not ownership boundaries. | `Country`, `Part`, `Ticket`, `Role` |

> **Note on "domain":** used here in the general business-area sense, not in the strict Domain-Driven Design sense. Modules within a domain are cohesive capabilities, not formally enforced bounded contexts. Where the DDD mapping helps: Domain ≈ DDD *subdomain*; Module ≈ DDD *bounded context*.

### Path Examples

| Path | Shape | Notes |
|------|-------|-------|
| `app/Base/Database` | `Base/Module` | Required Base module; no domain |
| `app/Base/AI` | `Base/Module` | Required Base module; no domain |
| `app/Modules/Core/Geonames` | `Modules/Core/Module` | Required Core module |
| `app/Modules/Commerce/Inventory` | `Modules/Domain/Module` | Pluggable domain module (nested-git and/or composer) |
| `app/Modules/People/Attendance` | `Modules/Domain/Module` | Pluggable domain module (nested-git and/or composer) |
| `extensions/sb-group/QAC` | `extensions/licensee/Module` | Extension module inside licensee nested-git repo |

### Module directory and config file naming

- **Directory names** inside a module use **PascalCase**: `Database/`, `Models/`, `Config/`, `Services/`, `Migrations/`, `Seeders/`, `Factories/`, etc. This aligns with PHP namespace segments and keeps module structure consistent.
- **Config directory**: Use `Config/` (PascalCase). **Config file names** inside `Config/` use **lowercase** (e.g. `company.php`, `workflow.php`) to match the Laravel config key and framework convention. The module's ServiceProvider registers them with `mergeConfigFrom(__DIR__.'/Config/company.php', 'company')` so `config('company')` works.

---

## Root Structure

```
belimbing/
├── .belimbing/              # Belimbing-specific configuration (git-ignored)
│   ├── branches/            # Git branch management (dev/staging/prod)
│   ├── extensions/          # Installed extensions (symlinks or copies)
│   ├── ai/                  # AI model cache, generated code templates
│   └── deployment/          # Deployment state, rollback points
│
├── app/                     # Application code (Base/ and Modules/)
│   ├── Base/                # Framework infrastructure (AI, Cache, Database, etc.)
│   └── Modules/             # Application modules grouped by domain
│
├── bootstrap/               # Framework bootstrapping
│   ├── app.php              # Application configuration (middleware, exceptions)
│   └── providers.php        # Service provider registration
│
├── config/                  # Configuration files (minimal, mostly in DB)
│   ├── app.php             # Application bootstrap config
│   ├── database.php        # Database connection only
│   ├── redis.php           # Redis connection only
│   └── extensions.php      # Extension registry and discovery
│
├── database/                # Database schema and migrations
│   ├── migrations/         # Core and module migrations
│   ├── seeders/            # Core seeders
│   ├── schemas/            # Schema definitions (for extensions)
│   └── scripts/            # Database scripts (config updates, etc.)
│
├── extensions/             # Extension packages: {licensee}/{Module}
│   └── sb-group/           # Example private nested repo licensee
│       ├── QAC/            # Extension module
│       └── IBP/            # Extension module
│
├── resources/               # Framework frontend resources
│   ├── core/               # Framework CSS, JS, Blade views
│   └── app.css             # Vite CSS entry point
│
├── routes/                  # Route definitions
│   ├── web.php             # Web routes
│   ├── callback.php        # API/Callback routes
│   ├── console.php         # Artisan commands (replaces Console/Kernel)
│   └── extensions.php      # Extension routes (auto-loaded)
│
├── storage/                 # Storage (logs, cache, sessions)
│   ├── logs/               # Application logs
│   ├── cache/              # File cache
│   ├── sessions/           # Session files
│   ├── ai/                 # AI model cache, generated code
│   └── git/                # Git repository state
│
├── tests/                   # Test suite
│   ├── Unit/               # Unit tests
│   ├── Feature/            # Feature tests
│   ├── Integration/       # Integration tests
│   ├── Performance/        # Performance benchmarks
│   ├── AI/                 # AI-generated code tests
│   └── Pest.php            # Pest configuration
│
├── docs/                    # Documentation
│   ├── architecture/       # Architecture docs (this file)
│   │   └── ai/             # AI architecture docs and current-state reference
│   ├── modules/            # Module documentation
│   ├── extensions/         # Extension development guide
│   └── deployment/         # Deployment guides
│
├── scripts/                 # Utility scripts
│   ├── install.sh          # Installation script
│   ├── deploy.sh           # Deployment script
│   ├── migrate.sh          # Migration runner
│   └── ai/                 # AI-related scripts
│
└── public/                  # Public web root
    ├── index.php           # Application entry point
    ├── assets/              # Compiled assets
    └── .well-known/         # Well-known paths (health checks, etc.)
```

---

## Core Application Structure (`app/`)

The `app/` directory has two roots: `Base/` for framework infrastructure and `Modules/` for application code. See [Directory Structure](#directory-structure) for the canonical path shapes and distribution model.

### `app/Base/` - Framework Infrastructure

**Path shape:** `app/Base/{Module}/` — subdirectories are modules (e.g., `Database`, `Events`, `Security`).

`app/Base/` provides framework infrastructure, extension points, and core abstractions. Modules here are foundational and cannot depend on `app/Modules/`.

```
app/Base/
├── Foundation/             # Base classes and interfaces
│   ├── Model.php           # Base model with extension hooks
│   ├── Controller.php      # Base controller
│   ├── Service.php         # Base service class
│   └── ExtensionPoint.php  # Base extension point interface
│
├── Events/                 # Core event system
│   ├── EventDispatcher.php
│   ├── EventListener.php
│   └── hooks/              # Hook registration system
│
├── Configuration/           # Configuration management
│   ├── ConfigManager.php    # Scope-based config with hierarchical fallback
│   ├── ScopeResolver.php    # Resolves config scope (company/department/etc.)
│   └── ConfigStore.php      # Database + Redis storage
│
├── Extension/               # Extension system core
│   ├── Registry.php         # Extension registry
│   ├── Validator.php        # Pre-installation validation
│   ├── Loader.php           # Runtime extension loader
│   ├── Sandbox.php          # Runtime safety (resource limits, isolation)
│   └── Contracts/           # Extension contracts/interfaces
│
├── Workflow/                 # Workflow engine (status-centric)
│   ├── WorkflowEngine.php
│   ├── StatusManager.php
│   ├── TransitionValidator.php
│   └── Hooks/               # Workflow hooks (before/after transitions)
│
├── AI/                      # AI infrastructure (stateless)
│   ├── Config/ai.php        # LLM defaults, provider overlay
│   ├── Contracts/            # Tool interface
│   ├── Enums/                # ToolCategory, ToolRiskClass
│   ├── Tools/                # AbstractTool, AbstractActionTool, ToolResult, ToolSchemaBuilder
│   │   ├── Concerns/         # FormatsProcessResult trait
│   │   └── Schema/           # ToolSchemaBuilder
│   ├── Services/             # ModelCatalogService, LlmClient, ProviderDiscoveryService
│   ├── Console/Commands/     # blb:ai:catalog:sync
│   └── DTO/                  # Value objects
│
├── Database/                # Database abstraction
│   ├── MigrationManager.php
│   ├── SchemaBuilder.php    # Dynamic schema extensions
│   └── QueryBuilder.php     # Extended query builder
│
└── Security/                  # Security foundation
    ├── AuthManager.php
    ├── PermissionManager.php
    └── AuditLogger.php
```

### `app/Modules/` - Application Modules

**Path shape:** `app/Modules/{Domain}/{Module}/` — domains (`Core`, `Commerce`, `Operation`, `People`) contain modules. `People` anchors HR-domain modules (Attendance, Leave, Claim, Payroll, Settings); it should not own the canonical Employee module, which lives in `Core` because employee records can belong to any company.

**Module naming:** use a singular PascalCase capability/domain name for new module directories (`Claim`, `Leave`, `Payroll`, `Employee`) even when the user-facing menu label or route path is plural (`Claims`, `people/claims`). Use plural module directory names only when the domain term is inherently plural or an established aggregate surface already uses it (`Settings`, the People-facing `Employees` workbench).

Each module is a self-contained capability. Subdirectories are module internals (see [Module Structure Template](#module-structure-template-for-appmodulesdomainmodule) for the full list). Modules include only the internals they need. Outside `app/Modules/Core`, new module-owned Blade views belong under the module's `Views/` directory so the module can become a nested-git or composer plugin without leaving UI behind in `resources/`.

```
app/Modules/
├── Core/                    # Required business foundations
│   ├── Company/             # Company management module
│   │   ├── Config/          # Module config (e.g. company.php)
│   │   ├── Database/
│   │   │   ├── Migrations/
│   │   │   ├── Seeders/
│   │   │   └── Factories/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Controllers/
│   │   ├── Livewire/
│   │   └── Hooks/
│   │
│   ├── Geonames/            # Database/, Models/
│   ├── Employee/            # Employment records and employee types for any company
│   ├── User/                # Database/, Models/, Services/, Controllers/, Livewire/, Hooks/
│   └── Workflow/            # Database/, Models/, Services/, Livewire/
│
├── People/                  # Licensee-scoped people navigation anchor
│   └── Config/
│       └── menu.php
│
├── Commerce/                # Commerce modules
│   └── Inventory/           # Sellable inventory module
│
└── Operation/               # Operational modules
    ├── IT/                  # IT support module
    └── Quality/             # NCR / SCAR / CAPA workflows
```

**Module Structure Template (for `app/Modules/{Domain}/{Module}/`):**

All subdirectories within a module are **module internals** — they are not assigned additional ownership labels.

```
app/Modules/{Domain}/{Module}/
├── Database/                 # Module internals: database layer
│   ├── Migrations/           # Module migrations (auto-discovered)
│   ├── Seeders/              # Production seeders (reference/config data)
│   │   └── Dev/              # Development seeders (Dev* prefix, fake test data)
│   └── Factories/            # Module factories
├── Models/                   # Module internals: Eloquent models
├── Services/                 # Module internals: business logic
├── Controllers/              # Module internals: HTTP controllers
├── Livewire/                 # Module internals: Livewire component classes
├── Events/                   # Module internals: events
├── Listeners/                # Module internals: event listeners
├── Hooks/                    # Module internals: extension hooks
├── Routes/                   # Module internals: routes
├── Views/                    # Module internals: views
├── Assets/                   # Optional module-owned frontend source
├── Config/                   # Module internals: config files (PascalCase dir; filenames lowercase, e.g. company.php)
└── Tests/                    # Module internals: tests
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

## Extension Structure (`extensions/`)

Extensions follow the same two-level layout: `extensions/{licensee}/{Module}/`. The `{licensee}` segment names the licensee-owned extension namespace.

```
extensions/
├── {licensee}/                # Licensee-owned extensions (e.g. sb-group)
│   ├── QAC/                   # One module
│   │   ├── Config/
│   │   │   ├── menu.php       # Menu items (auto-discovered)
│   │   │   ├── authz.php
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
│   │   ├── Assets/             # Optional, reviewed host build entry points only
│   │   ├── Tests/
│   │   └── ServiceProvider.php
│   └── IBP/                   # Another module (scales to many)
│       └── ...
```

This layout matches the menu discovery glob (`extensions/*/*/Config/menu.php`) and mirrors BLB's internal module structure. Extension modules include only the internals they need. Module-owned views live under `extensions/{licensee}/{Module}/Views/` and are registered by the module provider; do not create a companion `resources/extensions` tree.

For nested private repository workflow, see
`docs/guides/extensions/private-extension-repositories.md`.

---

## Database Structure (`database/`)

```
database/
├── migrations/               # Laravel built-in migrations only (cache, jobs, etc.)
│   ├── 0001_01_01_000001_create_cache_table.php
│   └── 0001_01_01_000002_create_jobs_table.php
│
├── seeders/                  # Global database seeders
│   └── DatabaseSeeder.php
│
└── .gitignore

# ALL module migrations are auto-discovered from {Module}/Database/Migrations/
# by App\Base\Database\ServiceProvider:
#
# Base modules:     app/Base/Database/Database/Migrations/*
# Core modules:     app/Modules/Core/Geonames/Database/Migrations/*
#                   app/Modules/Core/Company/Database/Migrations/*
#                   app/Modules/Core/User/Database/Migrations/*
# Commerce modules: app/Modules/Commerce/Inventory/Database/Migrations/*
# Operation modules: app/Modules/Operation/IT/Database/Migrations/*
#                    app/Modules/Operation/Quality/Database/Migrations/*
```

---

## Configuration System

### Scope-Based Configuration

Configuration is stored in database + Redis with hierarchical fallback:

```
Scope Hierarchy:
- Global (default)
  └── Company
      └── Department
          └── User
```

**Configuration Storage:**
- Database: Persistent configuration
- Redis: Runtime cache (fast lookup)
- Environment: Only bootstrap (DB, Redis connections)

---

## Git-Native Workflow Structure

```
.belimbing/branches/
├── development/             # Development branch
│   ├── .git/
│   └── state.json           # Branch state
│
├── staging/                 # Staging branch
│   ├── .git/
│   └── state.json
│
└── production/              # Production branch
    ├── .git/
    └── state.json

.belimbing/deployment/
├── history/                 # Deployment history
│   └── {timestamp}/
│       ├── commit.json
│       ├── migrations.json
│       └── rollback.sql
│
└── rollback/                # Rollback points
    └── {timestamp}/
```

---

## Testing Structure

```
tests/
├── Unit/                     # Unit tests
│   ├── Core/
│   ├── Modules/
│   └── Extensions/
│
├── Feature/                  # Feature tests
│   ├── Core/
│   ├── Modules/
│   └── Extensions/
│
├── Integration/              # Integration tests
│   ├── Modules/
│   └── Extensions/
│
├── Performance/              # Performance benchmarks
│   ├── Api/
│   ├── Database/
│   └── Frontend/
│
└── AI/                       # AI-generated code tests
    ├── Generated/
    └── Templates/
```

---

## Framework Frontend Structure (`resources/`)

Resources under `resources/core/` are framework-owned shared presentation: shell layouts, auth layouts, reusable Blade components, design tokens, and JavaScript used by the framework shell. Module-owned pages for pluggable domains do not belong here; they live under `app/Modules/{Domain}/{Module}/Views/` or `extensions/{licensee}/{Module}/Views/`.

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

### 2. Extension Points

Hooks available at:
- **Events**: Before/after model operations
- **Middleware**: Request/response pipeline
- **Services**: Service method overrides
- **Workflows**: Workflow transition hooks
- **UI**: Component injection points
- **Database**: Schema extensions

### 3. Git-Native Workflow

- All code changes tracked in git
- Branch-based deployment (dev → staging → prod)
- Rollback capability at every level
- AI-generated code starts in dev, requires review

### 4. Single Source of Truth

- Configuration: Database + Redis
- Environment: Only bootstrap values
- Code: Git repository
- State: Database + Redis cache

### 5. Quality Assurance

- Tests at every level (unit, feature, integration, performance)
- AI-generated code must pass tests before promotion
- Code review required for production
- Performance benchmarks enforced

---

## Extension Development Workflow

1. **Development**: Create extension in `extensions/{licensee}/{Module}/`
2. **Validation**: Run pre-installation validation
3. **Testing**: Write and run tests
4. **Installation**: Install via admin panel
5. **Configuration**: Configure via admin panel
6. **Usage**: Extension hooks activated automatically

---

## Migration Path

This structure supports incremental adoption:
- Start with core framework
- Add modules as needed
- Install extensions for additional features
- Customize with business-specific extensions

---

**Related Documents:** `docs/brief.md`, `docs/modules/*/`
