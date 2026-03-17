# Agent Context: BLB Vision & Development Approach

**Document Type:** Agent Guidance
**Purpose:** Essential context for AI coding agents working on BLB
**Last Updated:** 2026-02-09

---

## What BLB Is

**Belimbing (BLB) is a FRAMEWORK, not an application.**

- A customizable, extensible framework built on Laravel 12+
- Adopters build their own business systems on top of BLB
- Think: WordPress for business processes, not a monolithic ERP
- Open-source, self-hosted only, AI-native architecture

**Key Distinction:** Laravel is Level 0 (foundation). BLB is Level 1 (framework on top). Adopter applications are Level 2.

---

## Current Development Stage: Early & Fluid

**Context:** Initialization phase. No external users. No production deployment.

**What This Means:**
- **Destructive evolution allowed:** Drop tables, refactor schemas, rewrite APIs freely
- **No backward compatibility needed:** If adding columns to a table, rollback and rework the original migration
- **Zero tolerance for technical debt:** Refactor immediately when design flaws discovered
- **Strategic over tactical:** Invest in design quality; resist quick fixes

**Development Commands:**
see `app/Base/Database/AGENTS.md` for the migration guide.


---

## Adopter Workflow & Git Strategy

### How Adopters Will Use BLB

1. **Fork BLB** repository as their starting point
2. **Add custom modules** in designated directories (e.g., `app/Modules/Business/Custom/`)
3. **Pull updates** from upstream BLB via git
4. **Git-native deployment:** Development → Staging → Production branches

### Customization Boundaries

**Adopters CAN:**
- Add new modules freely in designated custom directories
- Override behavior via event-observer pattern (OpenMage-style hooks)
- Extend core modules through extension points

**Adopters SHOULD NOT:**
- Modify BLB core files directly (creates merge conflicts on upgrades)
- Better approach: Create PR to upstream BLB if change benefits everyone

---

## Module Architecture: Self-Contained by Design

### Why Self-Contained Modules?

1. **Easy to maintain** as framework grows
2. **Easy to package** as plugin for distribution
3. **Easy to remove** - not spread all over codebase
4. **Clear boundaries** - follows Ousterhout's "deep modules" principle

### Module Structure (Per Ousterhout's Definition)

**Module Definition:** A unit with a simple interface hiding complex implementation.

**Directory Pattern:**
```
app/Modules/{Layer1}/{Module}/
├── Database/
│   ├── Migrations/     # Auto-discovered by BLB
│   ├── Seeders/        # Production seeders; auto-discovered with --seed
│   │   └── Dev/        # Dev seeders (Dev* prefix, fake test data only)
│   └── Factories/
├── Models/
├── Services/
├── Controllers/
├── Livewire/
├── Events/
├── Listeners/
├── Hooks/              # Extension points for adopters
├── Routes/             # Module routes
├── Config/             # PascalCase dir; files lowercase (e.g., company.php)
└── Tests/
```

**Views:** Centralized in `resources/core/views/` (not in modules) -- split into `core/` (BLB framework) and `{licensee}/` (customizations) directories.

### Layer Hierarchy

```
app/Base/{Module}/                    # Framework infrastructure (Layer0/Module)
app/Modules/Core/{Module}/            # Core modules (Layer0/Layer1/Module)
app/Modules/Business/{Module}/        # Business modules (Layer0/Layer1/Module)
```

**Key Principle:** Stop labeling at Module boundary. Subdirectories within module are implementation details, not architectural layers.

### Core vs Business Modules

**Behavioral Differences:**

| Aspect | Core | Business |
|--------|------|----------|
| **Loading** | First, always present | After core, can be disabled |
| **Dependencies** | Only `app/Base/` | Can use Core + other Business |
| **Lifecycle** | Cannot uninstall | Can install/uninstall |
| **Purpose** | Foundational (User, Company, Workflow) | Optional (ERP, CRM, HR, Custom) |

**Directory structure enforces this** - prevents mistakes, enables tooling, reduces cognitive load.

---

## Auto-Discovery Philosophy

### Current Auto-Discovery

**Migrations:** Auto-discovered from:
- `app/Base/*/Database/Migrations/`
- `app/Modules/*/*/Database/Migrations/`

**Seeders:** Auto-discovered when using `--seed` flag (see `app/Base/Database/AGENTS.md`)

### Future Auto-Discovery (TBD)

- **Service Providers:** Each module may have auto-registered `ServiceProvider.php`
- **Routes:** Module routes auto-loaded from `{Module}/Routes/`
- **Config:** Module config auto-merged

**Principle:** Auto-discover where possible, but evaluate trade-offs case-by-case.

---

## Laravel Customization: Embrace When Needed

**BLB is NOT a pure Laravel application.** It's a framework built on Laravel.

### When to Customize Laravel

**BLB will diverge from Laravel defaults when necessary to uphold architectural principles.**

**Example (Already Implemented):** Migration-file-aware migrations
- Laravel: Migrations in `database/migrations/` only
- BLB: Auto-discover from module directories, per-migration-file registry (`base_database_tables`), seeder registry, table stability system

### Agent Responsibility

**When you see opportunities to improve Laravel defaults for framework needs:**
1. **Flag it immediately** - Discuss with user before implementing
2. **Consider framework perspective** - How does this help adopters?
3. **Document the divergence** - Why BLB does it differently

**Areas to Watch:**
- Service provider registration (should be module-level auto-discoverable?)
- Migration history logging (enterprise-grade tracking needed?)
- Route registration (module-based routing?)
- Config management (already planned: scope-based, DB+Redis storage)
- Extension points (hooks, events, observers)

---

## Design Philosophy: Ousterhout's Principles

### Deep Modules, Simple Interfaces

- Modules provide powerful functionality through simple interfaces
- Hide complexity; don't leak implementation details

### Define Errors Out of Existence

- Directory structure prevents mistakes (Core can't depend on Business)
- Type safety, return type declarations
- Prefer design that makes errors impossible over error handling

### Strategic Programming

- Invest 10-20% in design upfront
- "Design it twice" - consider alternatives before committing
- Continuous refactoring to maintain architectural integrity
- Zero tolerance for technical debt

### Planning Approach

When creating implementation plans:
1. State the problem in one sentence (if you can't, design is fuzzy)
2. Define public interface first (what operations, what they promise)
3. Decompose into 2-4 major responsibilities
4. Sketch each component's contract (inputs, outputs, invariants)
5. Define module-level policies (error handling, retries, etc.)
6. Identify expected uses and call patterns
7. Spot complexity hotspots
8. **Stop before coding** - get approval first

---

## Key Decisions Made

### Views Placement
- **Decision:** Centralized in `resources/core/views/` (core/licensee split)
- **Rationale:** Clear ownership boundary; safe upgrades; licensee overrides via view path precedence
- **Not in modules** (too difficult for self-contained module ideal)

### Migration Philosophy

See **`app/Base/Database/AGENTS.md`** for the full migration philosophy, command decision guide, table stability system, and seeder registration patterns.

### String Literals
- **Single quotes** (`'`) for literals
- **Double quotes** (`"`) only for interpolation or escape sequences

### Magic Methods
- **Avoid Laravel magic methods** where possible
- Use `Model::query()->method()` instead of `Model::method()`
- Better IDE support, type safety, static analysis

### Return Types
- **Always declare return types** (`: void`, `: int`, `: array`, etc.)
- Improves type safety and IDE support

### PHPDoc
- **Double-space alignment** in `@param` annotations
- **Document overridden methods** that change parent behavior
- Use `{@inheritdoc}` only for abstract methods with identical behavior

---

## Development Resources

### Documentation Structure

- **`docs/architecture/`** - System architecture and design
- **`docs/development/`** - Development guides, plans, conventions
  - **`docs/development/{module}/`** - Module-specific implementation plans
- **`docs/modules/`** - Module documentation (overviews, APIs, usage)
- **`docs/tutorials/`** - How-to guides
- **`docs/brief.md`** - Project vision and principles
- **`AGENTS.md`** - Top-level agent guidance (this context)

### Nested AGENTS.md Files

**Specialized guidance for specific areas:**
- **`app/Base/Database/AGENTS.md`** - Database, migrations, seeding

**Pattern:** Read nearest `AGENTS.md` in directory tree for context-specific instructions.

---

## Working with This Codebase

### What to Do

1. **Think framework-first:** How does this help adopters build their systems?
2. **Self-contained modules:** Keep module code together where possible
3. **Auto-discovery:** Prefer convention over configuration
4. **Strategic design:** Invest in quality upfront
5. **Watch for Laravel customization opportunities:** Flag and discuss
6. **Read relevant docs:** Brief, architecture, module-specific docs as needed
7. **Build brick by brick:** Incremental, high-quality implementation

### What Not to Do

1. **Don't assume backward compatibility needed** (early stage)
2. **Don't spread module code across codebase** (defeats self-containment)
3. **Don't use magic methods** (prefer explicit calls)
4. **Don't skip return types** (type safety matters)
5. **Don't create technical debt** (fix design flaws immediately)
6. **Don't blindly follow Laravel conventions** (BLB is a framework on top)

---

## Future Areas (Not Yet Decided)

### Service Provider Auto-Discovery
- **Question:** Should each module have auto-registered `ServiceProvider.php`?
- **Status:** To be revisited when needed

### Migration History Logging
- **Question:** What to track beyond Laravel's default migrations table?
- **Status:** Similar to seeder registry, but specifics TBD

### Module Manifest
- **Question:** Do modules need `manifest.json` for metadata (version, dependencies)?
- **Status:** Not needed yet; directory structure provides enough information

---

## Summary: Essential Mental Model

**BLB is a customizable framework that adopters extend via git.**

- **Early stage:** Fluid, destructive evolution allowed
- **Self-contained modules:** Easy to maintain, package, remove
- **Auto-discovery:** Convention over configuration
- **Laravel customization:** Embrace when it serves framework needs
- **Strategic design:** Invest in quality; zero technical debt
- **Ousterhout's principles:** Deep modules, simple interfaces, define errors out of existence

**Agent Role:** Help build a high-quality framework that empowers adopters to build their own business systems while maintaining the ability to pull upgrades from upstream BLB.

---

**Related Documents:**
- `docs/brief.md` - Project vision
- `docs/architecture/file-structure.md` - Directory conventions
- `app/Base/Database/AGENTS.md` - Database-specific guidance
- Root `AGENTS.md` - PHP coding conventions
