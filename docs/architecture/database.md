# Database Architecture

**Document Type:** Architecture Specification
**Purpose:** Define the architectural standards for database migrations, seeding, and schema conventions in Belimbing.
**Last Updated:** 2026-06-20

## Overview

Belimbing (BLB) uses a **module-first database architecture**. Unlike standard Laravel applications where all migrations live in a single directory, BLB keeps database assets with the module that owns them.

To manage this complexity, the framework enforces:
1.  **Layered Naming Conventions**: To ensure correct execution order (Base → Core → Operation/Commerce).
2.  **Auto-Discovery**: To load migrations dynamically without manual registration.
3.  **Manifest Dependency Preflight**: To fail missing, disabled, incompatible, or misordered module dependencies before migrations run.
4.  **Registry-Based Seeding**: To orchestrate seeding across modules without a monolithic `DatabaseSeeder`.
5.  **Source-Declared Schema Incubation**: To let local/test databases rebuild in-progress schema while production/staging only run explicit, forward-safe changes.
6.  **Migration-Scoped PostgreSQL Identifier Guarding**: To fail schema changes before PostgreSQL silently truncates overlong identifiers.

---

## 1. Migration Architecture

Migrations are **auto-discovered** from Base, enabled application module, and extension module directories when migration commands run. Laravel core tables in `database/migrations/` are always included.

This document keeps only the high-level design, naming spec, registry table, and directory layout. For operational details — including discovery paths, command behavior, `migrate --dev` for development, and the RegistersSeeders trait — see [app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md).

At a high level, `php artisan migrate --dev` means: incubating rebuild -> migrate -> prod seed -> framework primitives -> dev seed.

Before module migration paths are registered, BLB scans installed module manifests from `composer.json` `extra.blb` blocks and validates `requires-modules`. Required modules must be installed and enabled. `extra.blb.module` is canonical when present; otherwise a conventional filesystem identity such as `core/company`, `people/payroll`, `base/database`, or `vendor/module` satisfies availability. Version constraints require the required module to publish `extra.blb.version`. Because Laravel sorts migration files by filename after path discovery, BLB also verifies that every requiring module's earliest migration filename sorts after the latest migration filename in each required module that ships migrations. Duplicate migration names across module paths are blocked because Laravel would otherwise keep only one file for that migration name. The manifest graph is therefore the dependency contract, and filename prefixes remain the deterministic ordering mechanism Laravel can execute. Explicit `--path` scopes choose what Laravel runs, but they do not bypass the global module dependency preflight.

---

## 2. Naming & Execution Order

### Timestamp Conventions

Migration filenames use the timestamp prefix to encode execution order. The year-like segment maps to either the `Base/` root or an application domain; the `MM_DD` segment identifies the module within that group.

**Format:** `YYYY_MM_DD_HHMMSS`

| Prefix Range | Owner | Purpose |
| :--- | :--- | :--- |
| `0001` | Laravel | Native Laravel tables such as jobs, cache, and sessions. |
| `0100` | Base | Framework infrastructure modules. |
| `0200` | Modules/Core domain | Required business foundations loaded before operational and commerce workflows. |
| `0300` | Modules/Operation domain | Operational modules. |
| `0310` | Modules/Commerce domain | Commerce modules. |
| `0320` | Modules/People domain | People workflows that depend on Core employee/company foundations. |
| `2026+` | Extensions | Licensee or vendor extensions using real calendar years. |

### Manifest Dependency Ordering

`extra.blb.requires-modules` declares module availability and migration-order dependencies. Use canonical module identifiers such as `core/company`, `people/settings`, or `vendor/module`. A required module must be present and not disabled before any module-aware migration command starts. If the requirement uses anything other than `*`, the required module must also publish a compatible `extra.blb.version`. BLB accepts common Composer-style constraints such as exact versions, comparison ranges, caret/tilde ranges, wildcards, and `||` alternatives.

Migration filenames must still make the dependency executable. For any two modules with migrations, the requiring module's earliest migration filename must sort after the required module's latest migration filename. If that is not true, BLB fails before running Laravel's migrator and the fix is to rename migrations or change the manifest so the required module sorts first. New cross-module foreign-key dependencies should be represented both in the manifest graph and in filename prefixes/timestamps. Files under `Database/Migrations` that do not match Laravel's `*_*.php` migration pattern are ignored by this ordering check, just as Laravel ignores them.

### Module Identification (MM_DD)

Within each prefix range, the `MM_DD` component identifies the module. Additional migrations for the **same** module reuse that `YYYY_MM_DD` prefix and differ only in the trailing **`HHMMSS`** segment (for example `000000`, then `000001`) — do not advance `MM_DD` as if it were a calendar day.
*   **Database infrastructure (0001):** `0001_01_01` (Database registry tables)
*   **Base (0100):** `0100_01_03` (Events), `0100_01_11` (Authz)
*   **Core foundations (0200):** `0200_01_03` (Geonames), `0200_01_09` (Employee), `0200_01_20` (User)

**Example ordering:**
1.  `0001_01_01_000000_create_base_database_tables_table.php` (Base: table registry)
2.  `0001_01_01_000001_create_base_database_seeders_table.php` (Base: seeder registry)
3.  `0200_01_03_000000_create_geonames_countries_table.php` (Core: Geonames)
4.  `0200_01_20_000000_create_users_table.php` (Core: User)
5.  Root `database/migrations/` (cache, jobs, sessions) is always included.

### Table Naming Conventions

Table names use owner, module, and entity names to prevent ownership conflicts. Core intentionally omits the domain prefix so foundational tables align with Laravel conventions such as `users`.

| Owner | Pattern | Example |
| :--- | :--- | :--- |
| Base modules | `base_{module}_{entity}` | `base_database_tables`, `base_authz_roles` |
| Core modules | `{entity}` or `{module}_{entity}` when needed for clarity; no `core_` prefix | `companies`, `users`, `employees`, `geonames_countries` |
| Application domain modules | `{domain}_{module}_{entity}` | `commerce_inventory_items`, `operation_it_tickets` |
| Extensions | `{vendor}_{module}_{entity}` | `sbg_quality_ncr_ext` |

`entity` is the table-row noun represented by the table. It is not a filesystem layer. Existing tables that predate the finalized domain convention should be renamed during initialization rather than documented as exceptions.

---

## 3. Registry Architecture

BLB uses database registries to track module-owned database assets.

`base_database_tables` records tables created by migrations, including the owning module, module path, and migration file. This powers migration provenance, source-declared incubating-schema rebuilds, and the admin database table browser.

`base_database_seeders` records seeders registered by migrations via `registerSeeder()` in `up()` and `unregisterSeeder()` in `down()`. Seeders can also be discovered from module `Database/Seeders/` when seeding runs. States: `pending` → `running` → `completed` | `failed` | `skipped`; completed seeders are skipped on later runs.

`base_database_migration_sources` records source fingerprints for applied migrations whose source state must stay aligned with a non-disposable database. It is populated for applied source-declared incubating migrations after successful `migrate` runs. The migration name remains the ledger identity; the source fingerprint lets BLB distinguish "already applied but still incubating" from "already applied and source-edited in a way production will not rerun."

For registry implementation details, code examples, execution flow, dev vs production seeders, and CLI, see [app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md) (Table Registry, SeederRegistry, RegistersSeeders, Seeding Behavior, Development vs. Production Seeders, Development Workflow).

---

## 4. Incubating Schema Across Environments

Migration files can declare in-progress schema with `IncubatingSchema`. The source marker has different operational meaning by environment:

- **Local/testing:** `migrate --dev` may drop and rebuild the migration's declared tables, clear the affected migration ledger rows, then run Laravel's normal migrator. This is the schema-noise reduction path for development.
- **Production/staging:** plain `migrate` never performs incubating rebuilds. It classifies source-declared incubating files before native migration. Applied incubating migrations are allowed, warned, and fingerprinted. Pending incubating migrations are blocked unless the deployment has an instance-local approval.

The production/staging guard uses the Laravel `migrations` table as the applied/pending source of truth. A live table without a matching migration ledger row is not considered applied. Applied incubating migrations with no previous fingerprint are baselined on the first successful guarded migrate. If the file hash later changes while the migration is still applied, the guard blocks because Laravel will not rerun that source file on the live database; restore the recorded source or carry the change in a new forward migration.

Instance-local approvals are a break-glass mechanism for rare production-only validation. They are stored under `storage/app/.devops/`, are not committed, and match the exact migration name, relative path, source SHA-256, environment, connection, driver, and database identifier. The approval command requires a backup ID/reference and reason, writes an expiring one-time approval, and the migration command consumes it after a successful run. Approvals are exact records, not glob patterns or owner-wide exception lists. When `migrate --database=<connection>` targets a non-default connection, the approval must be created with the same `--database` option so PostgreSQL, SQLite, and future drivers remain isolated from each other.

---

## 5. PostgreSQL Identifier Guard

PostgreSQL limits identifiers to 63 bytes and silently truncates overlong names. BLB treats that as a migration-time schema error rather than allowing truncated table, column, index, or constraint names to be created.

The guard is scoped to migration commands, where durable schema objects are created or changed. `App\Base\Database\Postgres\GuardedPostgresConnection` registers a pre-execution callback through Laravel's connection hook, but it is active only inside BLB's migration command wrappers. Normal application browsing queries do not execute the identifier guard path.

The guarded path covers Laravel schema builder SQL and raw DDL executed with `DB::statement()` during `migrate`, `migrate:rollback`, and `migrate:reset`. `migrate:fresh` delegates its rebuild phase to `migrate`, so migrations run through BLB's primary fresh workflow are covered without guarding the selective drop phase. Developers should use explicit short names for generated indexes and constraints when Laravel's default names would exceed PostgreSQL's limit.

---

## 6. Directory Structure

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

## 7. Migration Registry

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
| `0200_02_01_*` | AI | Company, Employee |

### Operation

| Prefix | Module | Dependencies |
|--------|--------|--------------|
| `0300_01_01_*` | IT | Company, User |
| `0300_01_03_*` | Quality | Company, Employee, User, Workflow |

### Commerce

| Prefix | Module | Dependencies |
|--------|--------|--------------|
| `0310_01_01_*` | Inventory | Company, Media |
| `0310_01_03_*` | Catalog | Company, Inventory |
| `0310_01_05_*` | Marketplace | Company, Inventory, Integration |
| `0310_01_07_*` | Sales | Company, Inventory, Marketplace |

### People

People modules are grouped into three semantic tiers using the `MM` component of the prefix. The tier expresses the direction of dependency between People modules; it is not a separate layer in the Base/Core/domain sense.

| Tier (`MM`) | Meaning | Migration order |
|-------------|---------|-----------------|
| `_01_*` | **Foundation** — referenced by every other People module (Settings, Employee). | First within People. |
| `_02_*` | **Operational producers** — workflows that generate operational facts (leave taken, claim approved, attendance recorded, hire confirmed, course completed, etc.). Producers do not depend on consumer modules. | After foundation, before consumers. |
| `_03_*` | **Consumers** — modules that read producer facts and synthesize derived state (payroll, self-service, reports). Consumers must not be referenced by producers via FK. | Last within People. |

When introducing a new People module, choose the tier first based on dependency direction, then pick the next available `DD` slot within that tier.

| Prefix | Module | Tier | Dependencies |
|--------|--------|------|--------------|
| `0320_01_01_*` | Settings | Foundation | Company, Employee, User |
| `0320_01_03_*` | Employee | Foundation | Company, Employee, User, Settings |
| `0320_02_01_*` | Leave | Producer | Company, Employee, User, Settings, Workflow |
| `0320_02_03_*` | Claim | Producer | Company, Employee, User, Settings, Workflow |
| `0320_02_05_*` | Attendance | Producer | Company, Employee, User, Settings, Workflow |
| `0320_02_07_*` | Recruitment | Producer | Company, Employee, User, Settings, Workflow |
| `0320_02_09_*` | Onboarding | Producer | Company, Employee, User, Settings, Recruitment, Workflow |
| `0320_02_11_*` | Performance | Producer | Company, Employee, User, Settings, Workflow |
| `0320_02_13_*` | Training | Producer | Company, Employee, User, Settings |
| `0320_02_15_*` | Disciplinary | Producer | Company, Employee, User, Settings, Workflow |
| `0320_03_01_*` | Payroll | Consumer | Company, Employee, User, Settings, **Leave, Claim, Attendance**, Workflow |
| `0320_03_03_*` | Report | Consumer | Company, Employee, User, Settings, Payroll, Leave, Claim, Attendance, Recruitment, Onboarding, Performance, Training, Disciplinary |

Self-service is **not** a module in BLB. Every People module exposes its own employee, manager, HR/Finance, and admin surfaces inside one Livewire workbench, gated by capabilities. The iPayroll "ESS/MSS" portal pattern was a workaround for admin-first products; BLB is authz-gated from the start, so adding a user and granting the right capability is the self-service mechanism.

Payroll is a consumer of Leave/Claim/Attendance facts via the Payroll-owned `PayrollContributionIntake` contract (see `docs/plans/people/10_payroll-intake-dependency-inversion.md`). Producer modules must not import Payroll models; only Payroll's intake DTO and status query are part of the public contract.

The registry table is the dependency graph. Do not duplicate module dependencies in a separate diagram; update the table when ownership, prefix, dependency direction, or tier assignment changes.

### Extensions (2026+)

**Format:** `YYYY_MM_DD_HHMMSS_description.php`

Extensions use real calendar years. The MM_DD can be the actual date or a module identifier.

**Location:** `extensions/{vendor}/{module}/Database/Migrations/`

**Discovery:** Loaded by BLB's Base database migration commands using the same `Database/Migrations/` path contract as Base and application-domain modules.

| Owner | Module | Example Prefix |
|--------|--------|----------------|
| `{vendor}` | `{module}` | `2026_01_15_*` |

### Maintaining The Registry

When adding a module, choose the owner first, reserve the next `MM_DD` that preserves dependency order, and add the row before creating migrations. If dependencies change during initialization, renumber the affected migrations, update this registry, and rebuild with `migrate --dev`.

---

## 8. Related Documentation

-   **[app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md)** — Single source for migrate/seeding CLI, RegistersSeeders trait, discovery paths, dev vs production seeders, development workflow, and database portability.
-   **docs/architecture/module-system.md** — Full project directory layout.
