# File Structure Conventions

**Document Type:** Architecture Specification
**Purpose:** Define the file and directory structure for Belimbing framework
**Based On:** Project Brief v1.0.0, Ousterhout's "A Philosophy of Software Design"
**Last Updated:** 2026-03-20

---

## Overview

This document provide a guide for file structure that supports Belimbing's core principles:
- **Customizable Framework** - Deep extension system with hooks at every layer
- **Git-Native Workflow** - Development → Staging → Production branches
- **AI-Native Architecture** - AI integration points throughout
- **Quality-Obsessed** - Deep modules with simple interfaces
- **Extension Management** - Registry, validation, runtime safety

---

## Directory Layer Convention

BLB uses a layered directory naming convention to define architectural boundaries within `app/`. Layers are numbered from the root, and labeling stops at the **Module** boundary—everything within a module is considered module internals.

### Layer Hierarchy

```
app/{Layer0}/{Module}/...                    # For Base (shallow)
app/{Layer0}/{Layer1}/{Module}/...           # For Core and Business modules
```

**Key Principle:** Stop labeling at the Module boundary. Subdirectories within a module are implementation details, not architectural layers.

### Layer Definitions

| Path Component | Layer | Examples |
|----------------|-------|----------|
| `app/Base` | Layer0 | Framework infrastructure |
| `app/Modules` | Layer0 | Business process modules |
| `app/Base/AI` | Module | `Layer0/Module` |
| `app/Base/Database` | Module | `Layer0/Module` |
| `app/Base/Events` | Module | `Layer0/Module` |
| `app/Modules/Core` | Layer1 | Core module category |
| `app/Modules/Business` | Layer1 | Business module category |
| `app/Modules/Core/Geonames` | Module | `Layer0/Layer1/Module` |
| `app/Modules/Business/IT` | Module | `Layer0/Layer1/Module` |

### Module Internals (Not Layered)

Everything within a Module directory follows standard conventions but is not assigned layer numbers:

```
app/Modules/Core/Geonames/
├── Database/                    # Module internals (NOT Layer2)
│   └── Migrations/              # Module internals
├── Models/                      # Module internals
└── Services/                    # Module internals
```

### Module directory and config file naming

- **Directory names** inside a module use **PascalCase**: `Database/`, `Models/`, `Config/`, `Services/`, `Migrations/`, `Seeders/`, `Factories/`, etc. This aligns with PHP namespace segments and keeps module structure consistent.
- **Config directory**: Use `Config/` (PascalCase). **Config file names** inside `Config/` use **lowercase** (e.g. `company.php`, `workflow.php`) to match the Laravel config key and framework convention. The module's ServiceProvider registers them with `mergeConfigFrom(__DIR__.'/Config/company.php', 'company')` so `config('company')` works.

### Namespace Alignment

The layer convention maps directly to PHP namespaces:

```php
App\Base\Database\MigrationManager
└┬─┘└─┬─┘└──┬────┘└──────┬───────┘
root Layer0 Module     Class

App\Modules\Core\Geonames\Models\Country
└┬─┘└──┬──┘└─┬──┘└───┬───┘└──┬──┘└──┬──┘
root Layer0 Layer1 Module Internal Class

App\Modules\Business\IT\Models\Ticket
└┬─┘└──┬──┘└──┬───┘└─┬┘└──┬──┘└──┬──┘
root Layer0  Layer1 Module Internal Class
```

### Rationale

1. **Cognitive Simplicity**: Developers understand hierarchy down to the module level; standard conventions apply within.
2. **Tooling-Friendly**: Static analysis can enforce rules like "Layer1=Core cannot depend on Layer1=Business."
3. **Deep Modules**: The module is the architectural unit (per Ousterhout). Internal structure is hidden complexity.

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
├── app/                     # Core application code (Layer0 directories)
│   ├── Base/                # Framework infrastructure (AI, Cache, Database, etc.)
│   └── Modules/             # Business process modules (Core, Business)
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
├── extensions/             # Extension packages (vendor or custom)
│   ├── vendor/             # Third-party extensions
│   └── custom/             # Custom business-specific extensions
│
├── resources/               # Frontend resources
│   ├── views/              # Blade/Livewire templates
│   ├── js/                 # JavaScript (with WebAssembly support)
│   ├── css/                # Stylesheets
│   ├── wasm/               # WebAssembly modules (performance-critical)
│   └── ai/                 # AI-generated UI components
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

Directories under `app/` are Layer0. See [Directory Layer Convention](#directory-layer-convention) for the full hierarchy.

**Fluidity Status:**
- **Less Fluid** (`Base/`, `Modules/`): Active implementation; structure is stabilizing but still subject to refinement per `AGENTS.md`.

---

### `app/Base/` - Framework Infrastructure (Layer0)

**Fluidity:** Less Fluid — under active development.

**Layer Pattern:** `app/Base/{Module}/` — subdirectories are modules (e.g., `Database`, `Events`, `Security`).

The Base layer provides framework infrastructure, extension points, and core abstractions. Modules here are foundational and cannot depend on `app/Modules/`.

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

### `app/Modules/` - Business Process Modules (Layer0)

**Fluidity:** Less Fluid — under active development.

**Layer Pattern:** `app/Modules/{Layer1}/{Module}/` — Layer1 categories (`Core`, `Business`) contain modules.

Each module is a self-contained business process. Subdirectories are **module internals** (see [Module Structure Template](#module-structure-template-for-appmoduleslayer1module) for the full list). Modules include only the internals they need.

```
app/Modules/
├── Core/                    # Core framework modules
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
│   ├── User/                # Database/, Models/, Services/, Controllers/, Livewire/, Hooks/
│   ├── Workflow/            # Database/, Models/, Services/, Livewire/
│   └── Admin/               # Admin panel module
│       ├── Git/
│       ├── Extensions/
│       ├── Configuration/
│       └── Deployment/
│
└── Business/                # Business process modules (examples)
    ├── IT/                  # IT support module
    ├── HR/                  # HR module
    ├── Finance/             # Finance module
    ├── Sales/               # Sales / CRM module
    └── Logistics/           # Logistics / shipping module
```

**Module Structure Template (for `app/Modules/{Layer1}/{Module}/`):**

All subdirectories within a module are **module internals** — they are not assigned layer numbers.

```
app/Modules/{Layer1}/{Module}/
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
├── Config/                   # Module internals: config files (PascalCase dir; filenames lowercase, e.g. company.php)
└── Tests/                    # Module internals: tests
```

### Core vs Business: Layer1 Directory Structure Rationale

The separation of modules into `Core/` and `Business/` Layer1 directories is a **behavioral distinction**, not just organizational. This design aligns with Ousterhout's principles from "A Philosophy of Software Design."

#### Behavioral Differences

Core and Business (Layer1 categories) differ in fundamental ways:

1. **Loading Order**
   - Core modules: Loaded first, always present
   - Business modules: Loaded after core, can be disabled

2. **Dependency Rules**
   - Core modules: Can depend on `app/Base/` base layer only
   - Business modules: Can depend on core modules + other business modules

3. **Lifecycle Management**
   - Core modules: Cannot be uninstalled, always active
   - Business modules: Can be installed/uninstalled via admin panel

4. **Extension Points**
   - Core modules: Provide foundational extension points
   - Business modules: Extend core modules, provide business-specific hooks

5. **Namespace Organization** (Layer0/Layer1/Module)
   - Core modules: `App\Modules\Core\User\` → `Layer0\Layer1\Module`
   - Business modules: `App\Modules\Business\IT\` → `Layer0\Layer1\Module`

#### Why Directory Structure Over Metadata?

**1. Define Errors Out of Existence**

Directory structure prevents mistakes:
- Can't accidentally put business module in Core (filesystem enforces)
- Can't treat business module as core (path makes it explicit)
- Tooling can validate: "Core modules can't depend on Business modules"
- Autoloader can enforce loading order

**Example:**
```php
// With directory structure - compiler/static analysis can catch:
namespace App\Modules\Business\IT;

use App\Modules\Core\User\Models\User; // ✅ Valid
use App\Modules\Core\Workflow\Models\StatusConfig; // ✅ Valid

// But this would be caught:
namespace App\Modules\Core\User;

use App\Modules\Business\IT\Models\Ticket; // ❌ Error: Core can't depend on Business
```

**2. Reduce Cognitive Load**

Directory structure makes categorization immediately discoverable:
- Developer sees `app/Modules/Core/User/` → knows it's foundational
- Developer sees `app/Modules/Business/ERP/` → knows it's optional
- No need to read metadata files to understand structure
- File browser/navigator shows the distinction

**3. Enable Tooling**

Directory structure enables:
- **Module Loader**: `loadModules('app/Modules/Core')` then `loadModules('app/Modules/Business')`
- **Dependency Validator**: Can check paths to enforce rules
- **Code Generation**: AI can understand structure from paths
- **Static Analysis**: Tools can enforce architectural rules

**4. Deep Modules, Simple Interface**

The directory structure IS the interface:
- Simple: "Core modules go in Core/, Business modules go in Business/"
- Deep: Hides complexity of loading order, dependencies, lifecycle

**5. Strategic Programming**

Investing in directory structure upfront:
- Makes architecture explicit
- Prevents future mistakes
- Enables better tooling
- Reduces long-term complexity

**Note:** The directory structure provides all the behavioral distinction needed. If additional metadata (version, description, etc.) is required in the future, it can be added via `manifest.json` files similar to extensions, but this is not prescribed until there's a concrete need.

### `app/Extensions/` - Extension Integration Layer (Layer0)

**Fluidity:** Fluid — not yet implemented.

```
app/Extensions/
├── Manager.php               # Extension lifecycle management
├── Loader.php                # Extension autoloader
├── Validator.php             # Extension validation
├── Registry.php              # Extension registry (from database)
├── Contracts/                # Extension contracts
│   ├── ModuleExtension.php
│   ├── ServiceExtension.php
│   └── HookExtension.php
└── Marketplace/              # Extension marketplace integration
    ├── Client.php
    └── Installer.php
```

### `app/Admin/` - Admin Panel (Layer0)

**Fluidity:** Fluid — not yet implemented.

```
app/Admin/
├── Git/                     # Git workflow management
│   ├── BranchManager.php    # Dev/staging/prod branch management
│   ├── DeploymentManager.php # CI/CD pipeline
│   ├── RollbackManager.php  # Rollback capability
│   └── Livewire/
│       ├── Branches/
│       ├── Deployments/
│       └── Rollbacks/
│
├── Extensions/              # Extension management
│   ├── ExtensionManager.php
│   └── Livewire/
│       ├── Index.php        # Extension list
│       ├── Install.php      # Installation UI
│       ├── Configure.php    # Configuration UI
│       └── Uninstall.php
│
├── Configuration/           # Configuration management UI
│   ├── ConfigManager.php
│   └── Livewire/
│       ├── Scopes/
│       ├── Settings/
│       └── Hierarchy/
│
├── AI/                      # AI code generation UI
│   ├── CodeGenerator.php
│   └── Livewire/
│       ├── Generate/
│       ├── Templates/
│       └── Review/
│
└── System/                  # System management
    ├── Health/
    ├── Logs/
    ├── Cache/
    └── Backup/
```

---

## Extension Structure (`extensions/`)

All extensions — licensee or third-party — follow the same two-level layout: `extensions/{owner}/{module}/`. The `{owner}` is the licensee name or vendor name.

```
extensions/
├── {licensee}/                # Licensee-owned extensions (e.g. sb-group)
│   ├── quality/               # One module
│   │   ├── Config/
│   │   │   ├── menu.php       # Menu items (auto-discovered)
│   │   │   ├── authz.php
│   │   │   └── quality.php    # Module config (lowercase)
│   │   ├── Database/
│   │   │   ├── Migrations/
│   │   │   └── Seeders/
│   │   ├── Livewire/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Routes/
│   │   │   └── web.php
│   │   ├── Tests/
│   │   └── ServiceProvider.php
│   └── logistics/             # Another module (scales to many)
│       └── ...
│
└── {vendor}/                  # Third-party vendor extensions (same structure)
    └── {module}/
        └── [same structure]
```

This layout matches the menu discovery glob (`extensions/*/*/Config/menu.php`) and mirrors BLB's internal module structure. Extension modules include only the internals they need.

Reference: `docs/guides/licensee-development-guide.md` for the full development model.

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
# Business modules: app/Modules/Business/ERP/Database/Migrations/*
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

## Frontend Structure (`resources/`)

Resources live under `resources/core/` (the Core view namespace). Livewire view folders mirror the sidebar navigation domains — see `resources/core/views/AGENTS.md` for the full placement rules.

```
resources/core/
├── views/
│   ├── layouts/             # Layout templates
│   │   ├── app.blade.php
│   │   └── auth.blade.php
│   │
│   ├── livewire/            # Livewire view templates (mirrors navigation)
│   │   ├── admin/           # Administration menu items
│   │   │   ├── addresses/
│   │   │   ├── ai/
│   │   │   ├── authz/
│   │   │   ├── companies/
│   │   │   ├── employee-types/
│   │   │   ├── employees/
│   │   │   ├── geonames/
│   │   │   ├── roles/
│   │   │   ├── setup/
│   │   │   ├── system/
│   │   │   ├── users/
│   │   │   └── workflows/
│   │   ├── auth/            # Guest authentication flow
│   │   ├── it/              # Business Operations → IT
│   │   └── profile/         # Current user's own settings
│   │
│   └── components/          # Blade components
│       ├── menu/            # Sidebar navigation components
│       └── ui/              # Reusable UI primitives (x-ui.*)
│
├── css/
│   ├── app.css              # Main stylesheet (imports tokens)
│   ├── tokens.css           # Design tokens (colors, spacing)
│   └── components.css       # Component-level styles
│
└── js/                      # JavaScript assets
```

---

## Key Design Principles

### 1. Deep Modules, Simple Interfaces

Each module/extension is self-contained with:
- Clear public API
- Hidden implementation complexity
- Extension hooks at strategic points

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

1. **Development**: Create extension in `extensions/{owner}/{module}/`
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

**Document Status:** Proposal
**Next Steps:** Review and refine based on implementation experience
**Related Documents:** `docs/brief.md`, `docs/modules/*/`
