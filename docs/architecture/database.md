# Database Architecture

**Document Type:** Architecture Specification
**Purpose:** Define the architectural standards for database migrations, seeding, and schema conventions in Belimbing.
**Last Updated:** 2026-04-26

## Overview

Belimbing (BLB) uses a **module-first database architecture**. Unlike standard Laravel applications where all migrations live in a single directory, BLB keeps database assets with the module that owns them.

To manage this complexity, the framework enforces:
1.  **Layered Naming Conventions**: To ensure correct execution order (Base → Core → Operation/Commerce).
2.  **Auto-Discovery**: To load migrations dynamically without manual registration.
3.  **Registry-Based Seeding**: To orchestrate seeding across modules without a monolithic `DatabaseSeeder`.

---

## 1. Migration Architecture

Migrations are **auto-discovered** from Base and Module directories when migration commands run. Laravel core tables in `database/migrations/` are always included.

This document keeps only the high-level design, naming spec, registry table, and directory layout. For operational details — including discovery paths, command behavior, `migrate:fresh --dev --seed` for development, and the RegistersSeeders trait — see [app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md).

---

## 2. Naming & Execution Order

### Timestamp Conventions

Migration filenames use the timestamp prefix to encode execution order. The year-like segment maps to a Layer0 or Layer1 group; the `MM_DD` segment identifies the module within that group.

**Format:** `YYYY_MM_DD_HHMMSS`

| Prefix Range | Owner | Purpose |
| :--- | :--- | :--- |
| `0001` | Laravel | Native Laravel tables such as jobs, cache, and sessions. |
| `0100` | Base Layer0 | Framework infrastructure modules. |
| `0200` | Modules/Core Layer1 | Required business foundations. |
| `0300` | Modules/Operation Layer1 | Operational modules. |
| `0310` | Modules/Commerce Layer1 | Commerce modules. |
| `2026+` | Extensions | Licensee or vendor extensions using real calendar years. |

### Module Identification (MM_DD)

Within each prefix range, the `MM_DD` component identifies the module. Additional migrations for the **same** module reuse that `YYYY_MM_DD` prefix and differ only in the trailing **`HHMMSS`** segment (for example `000000`, then `000001`) — do not advance `MM_DD` as if it were a calendar day.
*   **Base (0100):** `0100_01_01` (Database), `0100_01_03` (Events)
*   **Core (0200):** `0200_01_03` (Geonames), `0200_01_20` (User)

**Example ordering:**
1.  `0100_01_01_000000_create_base_database_seeders_table.php` (Base: seeder registry)
2.  `0200_01_03_000000_create_geonames_countries_table.php` (Core: Geonames)
3.  `0200_01_20_000000_create_users_table.php` (Core: User)
4.  Root `database/migrations/` (cache, jobs, sessions) is always included.

### Table Naming Conventions

Table names use owner, module, and entity names to prevent ownership conflicts. Core intentionally omits the Layer1 prefix so foundational tables align with Laravel conventions such as `users`.

| Owner | Pattern | Example |
| :--- | :--- | :--- |
| Base modules | `base_{module}_{entity}` | `base_database_tables`, `base_authz_roles` |
| Core modules | `{entity}` or `{module}_{entity}` when needed for clarity; no `core_` prefix | `companies`, `users`, `geonames_countries` |
| Application Layer1 modules | `{layer1}_{module}_{entity}` | `commerce_inventory_items`, `operation_it_tickets` |
| Extensions | `{vendor}_{module}_{entity}` | `sbg_quality_ncr_ext` |

`entity` is the domain object or relation represented by the table. It is not a filesystem layer. Existing tables that predate the finalized Layer1 convention should be renamed during initialization rather than documented as exceptions.

---

## 3. Registry Architecture

BLB uses database registries to track module-owned database assets.

`base_database_tables` records tables created by migrations, including the owning module, module path, migration file, and stability state. This powers migration provenance, stability-aware fresh rebuilds, and the admin database table browser.

`base_database_seeders` records seeders registered by migrations via `registerSeeder()` in `up()` and `unregisterSeeder()` in `down()`. Seeders can also be discovered from module `Database/Seeders/` when seeding runs. States: `pending` → `running` → `completed` | `failed` | `skipped`; completed seeders are skipped on later runs.

For registry implementation details, code examples, execution flow, dev vs production seeders, and CLI, see [app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md) (Table Registry, SeederRegistry, RegistersSeeders, Seeding Behavior, Development vs. Production Seeders, Development Workflow).

---

## 4. Directory Structure

All database assets live within their module to support portability.

```text
app/Modules/Core/Geonames/
├── Database/
│   ├── Migrations/
│   │   ├── 0200_01_03_000000_create_countries.php
│   │   └── 0200_01_03_000001_create_cities.php
│   ├── Seeders/
│   │   ├── CountrySeeder.php          # Production: reference data
│   │   └── Dev/
│   │       └── DevCitySeeder.php      # Development: fake test data
│   └── Factories/
│       └── CityFactory.php
└── Models/
    └── City.php
```

---

## 5. Migration Registry

This registry tracks the `YYYY_MM_DD` prefixes assigned to each module to prevent conflicts and document dependencies. Each module must have a unique `MM_DD` identifier within its owner range.

### Base

| Prefix | Module | Dependencies |
|--------|--------|--------------|
| `0001_01_01_*` | Database | None |
| `0100_01_01_*` | Other module | None |
| `0100_01_11_*` | Authz | Database |
| `0100_01_13_*` | Settings | Database |
| `0100_01_15_*` | Workflow | None |
| `0100_01_17_*` | Audit | Database |
| `0100_01_19_*` | Integration | Database, Settings |
| `0100_01_21_*` | Media | Database |

### Core

| Prefix | Module | Dependencies |
|--------|--------|--------------|
| `0200_01_03_*` | Geonames | None |
| `0200_01_05_*` | Address | Geonames |
| `0200_01_07_*` | Company | Geonames, Address |
| `0200_01_09_*` | Employee | Company, Address |
| `0200_01_20_*` | User | Company, Employee |
| `0200_01_25_*` | Quality | Company, Employee, User, Workflow |
| `0200_02_01_*` | AI | Company, Employee |

### Operation

| Prefix | Module | Dependencies |
|--------|--------|--------------|
| `0300_01_01_*` | IT | Company, User |

### Commerce

| Prefix | Module | Dependencies |
|--------|--------|--------------|
| `0310_01_01_*` | Inventory | Company |
| `0310_01_03_*` | Catalog | Company, Inventory |
| `0310_01_05_*` | Marketplace | Company, Inventory, Integration |
| `0310_01_07_*` | Sales | Company, Inventory, Marketplace |

The registry table is the dependency graph. Do not duplicate module dependencies in a separate diagram; update the table when ownership, prefix, or dependency order changes.

### Extensions (2026+)

**Format:** `YYYY_MM_DD_HHMMSS_description.php`

Extensions use real calendar years. The MM_DD can be the actual date or a module identifier.

**Location:** `extensions/{vendor}/{module}/Database/Migrations/`

**Discovery:** Loaded via extension service providers (not `ModuleMigrationServiceProvider`)

| Owner | Module | Example Prefix |
|--------|--------|----------------|
| `{vendor}` | `{module}` | `2026_01_15_*` |

### Maintaining The Registry

When adding a module, choose the owner first, reserve the next `MM_DD` that preserves dependency order, and add the row before creating migrations. If dependencies change during initialization, renumber the affected migrations, update this registry, and rebuild with `migrate:fresh --dev --seed`.

---

## 6. Related Documentation

-   **[app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md)** — Single source for migrate/seeding CLI, RegistersSeeders trait, discovery paths, dev vs production seeders, development workflow, and database portability.
-   **docs/architecture/file-structure.md** — Full project directory layout.
