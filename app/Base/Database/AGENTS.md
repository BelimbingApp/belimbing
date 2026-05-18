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

## PostgreSQL Identifier Limit

BLB replaces Laravel's PostgreSQL connection with `App\Base\Database\Postgres\GuardedPostgresConnection`, which rejects SQL containing quoted identifiers over PostgreSQL's 63-byte limit before the statement reaches the database.

Use explicit short names for long indexes and constraints:

```php
$table->unique(['long_column_a', 'long_column_b'], 'short_unique_name');
$table->foreignId('long_related_id')->constrained('related_table', indexName: 'short_fk_name');
```

## Agent Guardrails

- **NEVER wipe the entire database as a shortcut** (local or otherwise). BLB enforces **table stability** so `migrate:fresh` only rebuilds tables explicitly marked unstable.
- **If you need to change a migration file, first mark the affected table(s) unstable** via `blb:table:unstable`, then rebuild with `migrate:fresh`. Follow the schema change workflow below.
- **Prefer editing the source migration** over creating additive migrations during the initialization phase (no production data to preserve).

## Local Development — Command Decision Guide

**`migrate:fresh --seed --dev` is the primary local tool.** Use it for almost everything.

| Situation | Command |
|-----------|---------|
| New migration or schema change (after marking unstable) | `migrate:fresh --seed --dev` |
| Apply pending migrations without wiping | `migrate --seed --dev` |
| Run a specific dev seeder | `migrate --seed --seeder=Company/Dev/DevCompanyAddressSeeder` |
| Production / staging deploy | `migrate` — never `migrate:fresh` |

`--dev` implies `--seed`, creates the licensee company (id=1) if absent, then runs all dev seeders in dependency order. `APP_ENV=local` only.

`migrate:refresh` and `migrate:reset` are **blocked** in Belimbing — they bypass table stability.

## Refactoring Dependencies

Migration load order: Base → Core → Business → Extensions. Foreign keys must respect this order. No circular dependencies.

If you need to break a circular dependency:

1. **Use nullable foreign keys** with deferred constraints
2. **Split into two migrations** (create table, then add constraint)
3. **Use pivot tables** for many-to-many relationships
4. **Redesign the relationship** if truly circular

## Backup — Extension Encryption Contract

Core ships two modes: `none` and `app-key`. Extensions may register additional modes by calling `EncryptionModeRegistry::register()` from a **service provider `boot()` method** (not `register()` — the singleton must be resolved after it is bound).

```php
// In your extension's ServiceProvider::boot()
public function boot(): void
{
    $this->app->make(\App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry::class)
        ->register('ext-acme-kms', function (array $config): EncryptionMode {
            return new AcmeKmsEncryption($config['encryption']['kms_key'] ?? '');
        });
}
```

### Rules every extension mode must follow

| Rule | Detail |
|------|--------|
| **Vendor-prefixed name** | Use `ext-{vendor}-{descriptor}` (e.g. `ext-acme-kms`). Names `none`, `app-key`, and any unprefixed string are reserved for core. |
| **Stable manifest identifier** | The string passed to `register()` is written verbatim to `manifest.encryption_mode`. Never change it after artifacts exist in the wild. |
| **No plaintext on the storage disk** | `encryptFile()` must not write plaintext to the configured backup disk, ever. Short-lived local temps in `sys_get_temp_dir()` are acceptable when a streaming dump API isn't available (matches core's pipeline) — they must be `chmod 0600` and unlinked in a `finally` regardless of outcome. |
| **Fail closed in `ensureReady()`** | Throw `BackupException::configurationInvalid()` or `BackupException::toolingMissing()` if key material or SDK is unavailable. Never silently fall back to plaintext. |
| **Configuration ownership** | Extension-specific keys (recipient lists, KMS key IDs, SDK config) live in `backup.encryption.*` sub-keys chosen by the extension author. Core does not interpret or validate them. |
| **Restore and rotation docs** | Extension authors own the operator-facing runbook for their modality: how to decrypt, how to rotate keys, and what the incident response looks like if key material is compromised. |

### When to use `app-key` vs. an extension mode

- **app-key** — right for most self-hosted deployments. No separate passphrase or key management ceremony. Rotation is handled via `blb:key:rotate`.
- **Extension (e.g. KMS, age recipients)** — right when your threat model requires IAM-bound decrypt, multi-operator key escrow, or hardware-backed key material. Extension authors own the operational complexity, dependency risk, and security maintenance burden for their modality.
