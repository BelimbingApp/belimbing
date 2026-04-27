# Migration Registry

**Document Type:** Database Registry
**Purpose:** Track migration file prefixes, module assignments, and dependencies
**Last Updated:** 2026-01-21

## Overview

This registry tracks the YYYY_MM_DD prefixes assigned to each module to prevent conflicts and document dependencies. Each module must have a unique MM_DD identifier within its architectural layer.

---

## Layer Definitions

| Layer | Year Range | Purpose | Location |
|-------|------------|---------|----------|
| Base | `0100` | Framework infrastructure | `app/Base/Database/Migrations/` |
| Core | `0200` | Core business modules | `app/Modules/Core/{Module}/Database/Migrations/` |
| Operation | `0300` | Operational modules | `app/Modules/Operation/{Module}/Database/Migrations/` |
| Commerce | `0310` | Commerce modules | `app/Modules/Commerce/{Module}/Database/Migrations/` |
| Extensions | `2026+` | Third-party extensions | `extensions/{vendor}/{module}/Database/Migrations/` |

---

## The Module Registry

This keeps track of all the migration files and their dependencies.


| Prefix | Layer | Module | Dependencies |
|--------|-------|--------|--------------|
| `0001_01_01_*` | Base | Database | None |
| `0100_01_01_*` | Base | Other module | None |
| `0200_01_03_*` | Modules/Core | Geonames | None |
| `0200_01_05_*` | Modules/Core | Address | Geonames |
| `0200_01_07_*` | Modules/Core | Company | Geonames, Address |
| `0200_01_09_*` | Modules/Core | Employee | Company, Address |
| `0200_01_20_*` | Modules/Core | User | Company, Employee |
| `0310_01_01_*` | Modules/Commerce | Inventory | Company |
| `0310_01_03_*` | Modules/Commerce | Catalog | Company, Inventory |

## [Fluid] Business Modules (0300+)

**Format:** `YYYY_MM_DD_HHMMSS_description.php`

Years are grouped by business domain category.

### Registered Categories

| Year Range | Category | Reserved For | Status |
|------------|----------|--------------|--------|
| `0300` | ERP | Enterprise Resource Planning | 📂 Available |
| `0400` | CRM | Customer Relationship Management | 📂 Available |
| `0500` | HR | Human Resources | 📂 Available |
| `0600` | Finance | Financial Management | 📂 Available |
| `0700` | Inventory | Inventory Management | 📂 Available |
| `0800` | Manufacturing | Manufacturing/Production | 📂 Available |
| `0900` | Logistics | Shipping/Logistics | 📂 Available |
| `0910` | Analytics | Business Intelligence | 📂 Available |
| `0920` | Marketing | Marketing Automation | 📂 Available |
| `0930+` | Custom | Custom Business Modules | 📂 Available |


---

## [Fluid] Extensions (2026+)

**Format:** `YYYY_MM_DD_HHMMSS_description.php`

Extensions use real calendar years. The MM_DD can be the actual date or a module identifier.

**Location:** `extensions/{vendor}/{module}/Database/Migrations/`

**Discovery:** Loaded via extension service providers (not `ModuleMigrationServiceProvider`)

| Vendor | Module | Year | Example Prefix | Status |
|--------|--------|------|----------------|--------|
| (none) | - | 2026+ | `2026_01_15_*` | 📂 Available |

---

## [Fluid] Dependency Graph

```bash
Base Layer (0100)
  └─ cache, jobs (no dependencies)

Core Layer (0200)
  ├─ Geonames (01_03) → [no dependencies, runs first]
  ├─ Address (01_05) → [depends on: Geonames]
  ├─ Company (01_07) → [depends on: Address]
  ├─ User (01_20) → [depends on: Company]
  └─ Workflow (01_21) → [to do depends on: User]

Business Layer (0300+)
  └─ (modules depend on Core modules)
```
---

## Adding New Modules

### Process

1. **Choose Layer**
   - Core business logic → Layer `0200`
   - Business process → Layer `0300+`
   - Extension → Real year (e.g., `2026`)

2. **Select MM_DD**
   - Check this registry for available codes
   - Consider dependencies (dependent modules need higher MM_DD)
   - Update this registry with your assignment

3. **Create Migrations**
   - Use format: `YYYY_MM_DD_HHMMSS_description.php`
   - Place in `app/Modules/{Layer}/{Module}/Database/Migrations/`

4. **Document**
   - Add module to this registry
   - List dependencies
   - Document which tables are created

---

## Conflict Resolution

### If Two Modules Need Same MM_DD

1. Check dependencies - dependent module must have higher MM_DD
2. If no dependencies, assign first-come-first-served
3. Update this registry immediately to prevent conflicts

### If Module Dependencies Change

1. May need to renumber migrations
2. Use `migrate:fresh --seed` in development (destructive evolution; --seed required)
3. Update registry with new MM_DD assignment

---

## Related Documentation

- `docs/architecture/database.md` - Complete database architecture, naming conventions, and seeding behaviors.
- `docs/development/creating-module-migrations.md` - Guide for creating migrations
- `docs/architecture/file-structure.md` - Module structure reference
- `app/Base/Database/AGENTS.md` - Database migration guidelines
---
