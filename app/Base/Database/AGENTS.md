# Database Module (app/Base/Database)

Migration-file-aware infrastructure on top of Laravel. Provides source-declared incubating schema, automatic seeder discovery, and module migration auto-loading.

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

Every migration that creates a table registers it here via `RegistersSeeders`. Each row links back to the exact migration file so BLB can map live tables back to source migrations for seeder runs, admin browsing, and incubating-schema rebuilds.

| Column | Purpose |
|--------|---------|
| `table_name` | Unique table name |
| `module_name` | Owning module (e.g. `Geonames`) |
| `module_path` | Module path (e.g. `app/Modules/Core/Geonames`) |
| `migration_file` | Migration filename that created this table — the key for per-migration scoping |

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

## Incubating Schema

BLB is moving away from local table-stability toggles toward source-local schema maturity. The primary workflow is:

```bash
php artisan migrate --dev
```

`--dev` means:

- local environment only
- production seeders still run
- dev seeders still run
- source-declared incubating migrations are dropped and rerun before Laravel's native migrator continues

### Declare a migration incubating

Keep the declaration in the migration file itself so coding agents can discover it beside the schema they are editing:

```php
use App\Base\Database\Concerns\IncubatingSchema;

return new class extends Migration
{
    use IncubatingSchema;
};
```

Equivalent constant form is also supported:

```php
public const BLB_SCHEMA_STABLE = false;
```

### Schema change workflow

To edit an existing migration's schema (add/remove/rename columns, change indexes):

```bash
# 1. Mark the source migration incubating

# 2. Edit the migration file

# 3. Rebuild locally
php artisan migrate --dev
```

The old `blb:table:unstable` command and admin UI stability toggle are retired. Do not use local toggles as the source of truth for new schema work.

### Deprecated compatibility bridge

`scripts/unstable-table-list.sh` is still honored by `php artisan migrate --dev` as a temporary git-tracked compatibility list for existing under-development tables. Treat it as deprecated. Use it as an operator checklist for updating other installations and extension repos. The real destination is migration-local `use IncubatingSchema;` in each owning migration file.

## PostgreSQL Identifier Limit

BLB rejects PostgreSQL migration DDL containing identifiers over PostgreSQL's 63-byte limit before the statement reaches the database. The guard is enabled only while BLB migration execution runs (`migrate`, `migrate:rollback`, `migrate:reset`; `migrate:fresh` delegates its rebuild phase to `migrate`), so normal application queries do not pay identifier-inspection overhead.

The guard covers both Laravel schema builder SQL and raw `DB::statement()` DDL executed inside those migration commands.

Use explicit short names for long indexes and constraints:

```php
$table->unique(['long_column_a', 'long_column_b'], 'short_unique_name');
$table->foreignId('long_related_id')->constrained('related_table', indexName: 'short_fk_name');
```

## Agent Guardrails

- **Prefer `php artisan migrate --dev` for local schema iteration.** It is the agent-first path and keeps the workflow close to native Laravel.
- **If you need to change a migration file, declare that migration incubating in source** and rebuild with `migrate --dev`.
- **Prefer editing the source migration** over creating additive migrations during the initialization phase (no production data to preserve).
- **Treat `migrate:fresh` as a true full wipe.** Use it only when the database is disposable.

## Local Development — Command Decision Guide

**`migrate --dev` is the primary local tool.** Use it for almost everything.

| Situation | Command |
|-----------|---------|
| New migration or schema change | `migrate --dev` |
| Apply pending migrations without wiping | `migrate --seed --dev` |
| Run a specific dev seeder | `migrate --seed --seeder=Company/Dev/DevCompanyAddressSeeder` |
| Disposable local database full reset | `migrate:fresh` |
| Production / staging deploy | `migrate` — never `migrate:fresh` |

`--dev` implies `--seed`, creates the licensee company (id=1) if absent, then runs all dev seeders in dependency order. `APP_ENV=local` only.

`migrate:refresh`, `migrate:reset`, and `db:wipe` are **blocked** in Belimbing — they bypass the incubating-schema preflight.

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
