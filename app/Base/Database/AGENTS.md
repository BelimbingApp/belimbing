# Database Module (app/Base/Database)

Migration-file-aware infrastructure on top of Laravel. Provides table stability (`is_stable`), automatic seeder discovery, and module migration auto-loading.

**Full architecture:** [docs/architecture/database.md](../../../docs/architecture/database.md) — naming conventions, migration registry, table naming, dependency graph.

## Table Naming

| Layer | Pattern | Example |
|-------|---------|---------|
| **Base** | `base_{module}_{entity}` | `base_database_tables`, `base_authz_roles` |
| **Core/Business** | `{module}_{entity}` | `users`, `user_pins`, `companies` |
| **Vendor** | `{vendor}_{module}_{entity}` | `sbg_companies_ext` |

## Migration File Names

- **Format:** `YYYY_MM_DD_HHMMSS_description.php`
- **Layer prefixes (year):** `0001` Laravel core · `0100` Base · `0200` Core · `0300+` Business · `2026+` Extensions
- **Module id:** Within a layer, `MM_DD` identifies the module (e.g. `0200_01_03_*` = Geonames). See the **Migration Registry** in `docs/architecture/database.md` for assigned prefixes and dependencies.
- **Hard rule:** For `app/Base/*` and `app/Modules/*/*`, use layered prefixes (`0100`, `0200`, `0300+`) only. Real years (`2026+`) are for extensions only.

### Examples

- **Base module:** `0100_01_11_000000_create_base_authz_roles_table.php`
- **Core module:** `0200_01_20_000000_create_users_table.php`
- **Extension module:** `2026_01_15_000000_create_vendor_feature_table.php`

Before creating a new module migration series, reserve the `MM_DD` prefix in the **Migration Registry** at `docs/architecture/database.md`.

## Migration Auto-Discovery Paths

```
app/Base/*/Database/Migrations/
app/Modules/*/*/Database/Migrations/
```

## The `base_database_tables` Registry

Every migration that creates a table registers it here via `RegistersSeeders`. Each row links back to the exact migration file, enabling per-migration scoping for stability toggles, seeder runs, and selective rebuilds.

| Column | Purpose |
|--------|---------|
| `table_name` | Unique table name |
| `module_name` | Owning module (e.g. `Geonames`) |
| `module_path` | Module path (e.g. `app/Modules/Core/Geonames`) |
| `migration_file` | Migration filename that created this table — the key for per-migration scoping |
| `is_stable` | Whether `migrate:fresh` preserves this table (default: `true`) |
| `stabilized_at` / `stabilized_by` | Audit trail for stability changes |

## Seeder Registration

Migrations register their seeders via `RegistersSeeders`:

```php
use App\Base\Database\RegistersSeeders;

return new class extends Migration
{
    use RegistersSeeders;

    public function up(): void
    {
        Schema::create('geonames_countries', ...);
        $this->registerSeeder(CountriesSeeder::class);
    }

    public function down(): void
    {
        $this->unregisterSeeder(CountriesSeeder::class);
        Schema::dropIfExists('geonames_countries');
    }
};
```

Seeders under `app/Base/*/Database/Seeders/` and `app/Modules/*/*/Database/Seeders/` are also auto-discovered on `--seed` even without `registerSeeder()`. Plain `migrate` (no `--seed`) never runs seeders.

```bash
# Run all pending seeders
php artisan migrate --seed

# Run a single seeder (short form: Module/SeederClass)
php artisan migrate --seed --seeder=Company/RelationshipTypeSeeder
```

**App-level seeders** (non-module): same `RegistersSeeders` pattern. Migration in `database/migrations/`, seeder in `database/seeders/`. Do not add to `DatabaseSeeder::run()`.

### Production vs. Development Seeders

| Category | Location | Naming | Auto-registered? |
|----------|----------|--------|-----------------|
| **Production** | `Database/Seeders/` | `{Entity}Seeder` | Yes (`registerSeeder()`) |
| **Development** | `Database/Seeders/Dev/` | `Dev{Description}Seeder` | No — run explicitly |

Dev seeders extend `App\Base\Database\Seeders\DevSeeder`, implement `seed()` (not `run()`), and only run when `APP_ENV=local`.

## Table Stability

Every table defaults to `is_stable = true`. **Only `migrate:fresh` checks this flag** — all other commands ignore it.

| `is_stable` | `migrate:fresh` behaviour |
|-------------|----------------------------------|
| `true` | Table and its data are **preserved** |
| `false` | Table is **dropped and rebuilt** from its migration |

### Mark newly-created tables unstable

When you add new migrations that create new tables and you want the next `migrate:fresh` to rebuild them by default, run:

```bash
php artisan migrate --unstable
```

This keeps existing table stability unchanged and marks **only newly discovered/registered tables** as `is_stable=false` (in `base_database_tables`).

### Schema change workflow

To edit an existing migration's schema (add/remove/rename columns, change indexes):

```bash
# 1. Mark the table(s) unstable
php artisan blb:table:unstable ai_providers
php artisan blb:table:unstable ai_providers ai_provider_models  # multiple
php artisan blb:table:unstable ai_*  # trailing wildcard (prefix match)

# 2. Edit the migration file

# 3. Rebuild
php artisan migrate:fresh --seed --dev
```

The admin UI at `admin/system/database-tables` (local env only) also lets you toggle stability per-table.

## Local Development — Command Decision Guide

**`migrate:fresh --seed --dev` is the primary local tool.** Use it for almost everything.

| Situation | Command |
|-----------|---------|
| New migration or schema change (after marking unstable) | `migrate:fresh --seed --dev` |
| Apply pending migrations without wiping | `migrate --seed --dev` |
| Run a specific dev seeder | `migrate --seed --seeder=Company/Dev/DevCompanyAddressSeeder` |
| Nuclear reset — wipe everything including stable tables | `migrate:fresh --seed --dev --force-wipe` |
| Production / staging deploy | `migrate` — never `migrate:fresh` |

`--dev` implies `--seed`, creates the licensee company (id=1) if absent, then runs all dev seeders in dependency order. `APP_ENV=local` only.

`migrate:refresh` and `migrate:reset` are **blocked** in Belimbing — they bypass table stability. Pass `--force-wipe` to override intentionally.

## Refactoring Dependencies

Migration load order: Base → Core → Business → Extensions. Foreign keys must respect this order. No circular dependencies.

If you need to break a circular dependency:

1. **Use nullable foreign keys** with deferred constraints
2. **Split into two migrations** (create table, then add constraint)
3. **Use pivot tables** for many-to-many relationships
4. **Redesign the relationship** if truly circular
