# Database Module (app/Base/Database)

Migration-file-aware infrastructure on top of Laravel. Provides source-declared incubating schema, automatic seeder discovery, and module migration auto-loading.

## Canonical References

Use [docs/architecture/database.md](../../../docs/architecture/database.md) as the source of truth for:

- migration filename prefixes and execution order
- module manifest dependency checks (`extra.blb.requires-modules`)
- table naming conventions
- migration registry assignments and dependency graph
- registry architecture (`base_database_tables`, `base_database_seeders`, `base_database_migration_sources`)
- PostgreSQL identifier guard architecture

For extension authoring rules, use:

- [docs/guides/extensions/database-migrations.md](../../../docs/guides/extensions/database-migrations.md)
- [docs/guides/extensions/backup-encryption-modes.md](../../../docs/guides/extensions/backup-encryption-modes.md)

## Migration Conventions

- Use `id()` or `bigIncrements('id')` for standard tables.
- Use `uuid` or `ulid` only when there is a specific reason, such as externally generated IDs or a domain requirement.
- Use `foreignId()` for foreign-key columns.
- Include `$table->timestamps()` for created/updated timestamps.
- Consider `$table->softDeletes()` when logical deletion is needed.

## Seeder Registration

Migrations register their seeders via `RegistersSeeders`:

```php
use App\Base\Database\Concerns\RegistersSeeders;

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

Production seeders are auto-discovered on `--seed` from all four roots, even without `registerSeeder()`:

- `app/Base/*/Database/Seeders/`
- `app/Core/*/Database/Seeders/`
- `app/Domains/*/*/Database/Seeders/` for enabled Domains
- `app/Extensions/*/*/Database/Seeders/`

Register a seeder from its owning migration with `RegistersSeeders` when migration lifecycle should control it explicitly. Plain `migrate` (no `--seed`) never runs seeders.

```bash
# Run all pending seeders
php artisan migrate --seed

# Run a single seeder (FQCN, or Core-module short form: Module[/Sub]/SeederClass)
php artisan migrate --seed --seeder=Company/RelationshipTypeSeeder
```

**App-level seeders** (non-module): same `RegistersSeeders` pattern. Migration in `database/migrations/`, seeder in `database/seeders/`. Do not add to `DatabaseSeeder::run()`.

### Production vs. Development Seeders

| Category | Location | Naming | Auto-registered? |
|----------|----------|--------|-----------------|
| **Production** | `Database/Seeders/` | `{Entity}Seeder` | Yes — via `registerSeeder()` or discovery on `--seed` |
| **Development** | `Database/Seeders/Dev/` | `Dev{Description}Seeder` | No — discovered only for `--dev`, or run directly with `--seeder` |

Dev seeders extend `App\Base\Database\Seeders\DevSeeder`, implement `seed()` (not `run()`), and only run when `APP_ENV=local`.

## Daily Workflow

**`php artisan migrate --dev` is the primary local tool.** Use it for almost everything.

| Situation | Command |
|-----------|---------|
| New migration or schema change | `migrate --dev` |
| Compare migration source with the default database | `blb:schema:drift` |
| Apply pending migrations in the full local dev flow | `migrate --dev` |
| Run a specific dev seeder | `migrate --seed --seeder=Company/Dev/DevCompanyAddressSeeder` |
| Disposable local or test database full reset | `migrate:fresh` |
| Production / staging deploy | `migrate` or `migrate --seed` when you intentionally need production seeders |

`--dev` is local-only, already implies `--seed`, and runs this flow:

1. rebuild incubating schema
2. run Laravel migrations
3. run production seeders
4. provision framework primitives
5. run dev seeders

The incubating rebuild is atomic at the planning boundary: BLB computes the full foreign-key dependency closure before dropping anything. It may cascade into a dependent table only when that table's owning migration is also source-declared incubating. A stable or undeclared dependent refuses the command; mark the whole disposable dependency chain incubating, split the dependency, or use a forward migration. Registry ownership alone never authorizes deleting a stable table.

The same planning boundary protects schema maturity across migration history. If a later applied migration references a table in the rebuild scope, that later migration must also be source-declared incubating so its ledger row is cleared and the complete chain replays. A later stable migration refuses the command before any drop; otherwise the original create migration could recreate an obsolete table while Laravel continued to treat its later column, index, constraint, or data migration as already applied. The narrow exception is an idempotent, data-only migration using `ReplaysAfterIncubatingSchema`: its ledger row joins the replay, but the marker never authorizes schema creation, alteration, or deletion.

Use `migrate --seed --seeder=...` when you want one specific seeder class instead of the full `--dev` dev-seeder sweep.

Before any module-aware migration command registers paths, BLB scans installed module manifests. `extra.blb.module` is canonical when present; otherwise BLB falls back to the filesystem identity. `extra.blb.requires-modules` must point at modules that are installed and enabled; non-wildcard constraints require the required module to publish a compatible `extra.blb.version`. Because Laravel still sorts migration files by filename, every requiring module's earliest migration filename must sort after the latest migration filename in each required module that ships migrations. Duplicate migration names across module paths are blocked because Laravel would otherwise keep only one file. Explicit `--path` scopes do not bypass this global module dependency preflight. Fix dependency failures by installing/enabling the required module, adjusting the manifest constraint, or renaming migrations so the required module sorts first.

`migrate:fresh` keeps Laravel semantics, but BLB blocks it outside disposable environments. It is allowed only in `APP_ENV=local`, `APP_ENV=testing`, or SQLite `:memory:` connections. For ordinary local schema iteration, use `migrate --dev` instead.

`migrate:refresh`, `migrate:reset`, and `db:wipe` are blocked for normal databases because they bypass the incubating-schema preflight. The only allowed exception is the in-memory SQLite test database path used by automated tests.

Plain `migrate` **guards incubating schema outside `local`/`testing`.** Incubating migrations are edited in place and rebuilt only by the local-only `migrate --dev` flow; once recorded on a real database, later in-place edits silently never re-apply. So a `migrate` (or `migrate --force`) run on a non-disposable database — including the in-app **Admin > System > Update** deploy, which calls `migrate --force` — classifies source-declared incubating files before migrating:

- **Applied incubating migrations** (already present in Laravel's `migrations` table) are allowed and reported as schema debt. BLB records their source hash in `base_database_migration_sources`; later source-hash drift blocks deploy until the recorded source is restored or a forward migration carries the change.
- **Pending incubating migrations** are blocked by default and listed with their path, migration name, and SHA-256. Prefer graduating the migration before deployment.
- **Break-glass pending incubating migrations** require an instance-local approval created with `php artisan blb:schema:approve-incubating <migration> --backup=<backup-id-or-reference> --reason="<why>"` (add `--database=<connection>` when migrating a non-default connection). Approvals live under `storage/app/.devops/`, match the exact path/hash/environment/connection/driver/database, expire, and are consumed after a successful migrate. They are for rare production-only validation, not routine schema work.

A failed migration guard also halts the Update flow before workers reload.

## Schema Drift Inspection

Run `php artisan blb:schema:drift` to compare migration-declared table, column, and index presence with the default database connection. The command intentionally has no custom options so coding agents and humans share one blessed invocation. It reads migration source without executing it, replays supported `up()` operations in Laravel filename order, and prints deterministic records with the inspected connection, driver, and database identity.

Exit codes are part of the agent contract:

- `0`: the supported scope was fully checked and is clean.
- `1`: confirmed drift was found.
- `2`: inspection was incomplete, including unsupported or runtime-dependent schema source; do not infer a repair from a partial report.

The supported scope is table presence, column presence, and ordinary/unique/primary Laravel index presence (kind plus ordered columns). Raw SQL indexes are checked only for presence by their explicit name. It does not compare raw index definitions, specialized indexes, column types, defaults, foreign keys, check constraints, or extra tables. Extra tables belong to the Database Residue workflow because a table not claimed by current source is not proof that deletion is safe.

Use the `blb-schema-drift-repair` skill for a non-clean report. It preserves the schema maturity split: repair an incubating local schema through `migrate --dev`; repair stable history through a forward migration; never patch live DDL or migration ledger rows directly.

## Schema Editing

BLB uses source-local schema maturity. For schema changes, use `php artisan migrate --dev`.

### Declare a migration incubating

Keep the declaration in the migration file itself so coding agents can discover it beside the schema they are editing:

```php
use App\Base\Database\Concerns\IncubatingSchema;

return new class extends Migration
{
    use IncubatingSchema;
};
```

### Declare a stable data migration replayable

`ReplaysAfterIncubatingSchema` is the narrow companion marker for a stable, idempotent, data-only migration whose result must be recomputed after an incubating table it reads or updates is rebuilt:

```php
use App\Base\Database\Concerns\ReplaysAfterIncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use ReplaysAfterIncubatingSchema;

    public function up(): void
    {
        DB::table('example_table')->updateOrInsert(
            ['stable_key' => 'default'],
            ['derived_value' => 'canonical'],
        );
    }

    public function down(): void
    {
        // Remove only derived state, or remain an intentional no-op.
    }
};
```

The marker is declared when the migration is authored; it is not permission to edit stable migration behavior after application. Do not retrofit it into an applied stable migration merely to make `migrate --dev` pass. A retrofit requires an explicit recovery procedure or accepted ADR proving that the existing `up()` is already data-only and idempotent, identifying every database that may have applied it, and recording replay evidence. Any behavior change still belongs in a new forward migration.

Marker requirements:

- `up()` is safe to run repeatedly and contains no schema-mutating `Schema` calls or raw DDL. Read-only `Schema::hasTable()`, `hasColumn()`, `hasColumns()`, and `getColumnListing()` checks are allowed.
- Referenced incubating tables appear as literal names in source so preflight can discover the dependency; do not construct those table names dynamically.
- `down()` removes only replay-derived state or is an intentional no-op; it never restores obsolete topology or data identities.
- The marker affects only local/testing incubating replay. It does not make the migration incubating and does not cause production/staging `migrate` to rerun an applied migration.
- If an already-applied stable migration blocks a rebuild and has no approved replay contract, preserve its source and change the schema strategy or rebuild the disposable database from scratch.

## Practical Guardrails

### PostgreSQL identifier limit

BLB guards PostgreSQL identifier length during migration execution. For the architecture and scope of that guard, refer to [docs/architecture/database.md](../../../docs/architecture/database.md).

Use explicit short names for long indexes and constraints:

```php
$table->unique(['long_column_a', 'long_column_b'], 'short_unique_name');
$table->foreignId('long_related_id')->constrained('related_table', indexName: 'short_fk_name');
```

- **Prefer `php artisan migrate --dev` for local schema iteration.** It is the agent-first path and keeps the workflow close to native Laravel.
- **If you need to change a migration file, declare that migration incubating in source** and rebuild with `migrate --dev`.
- **Prefer editing the source migration** over creating additive migrations during the initialization phase (no production data to preserve).
- **Treat `migrate:fresh` as a true full wipe.** Use it only when the database is disposable.
- **Use [docs/architecture/database.md](../../../docs/architecture/database.md) for dependency direction and migration ordering.** Do not invent new prefix ranges or treat old “Business” terminology as canonical.
- **Break circular dependencies with structure, not wishful ordering.** Prefer nullable foreign keys, split migrations, or pivot tables.
