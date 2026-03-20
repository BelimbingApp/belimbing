# FEAT-MODULE-SCHEMA

Intent: add or evolve module database schema and seeding using BLB module-aware migration and registry conventions.

## When To Use

- Creating or updating tables owned by a module.
- Adding production seeders tied to module migrations.
- Registering data bootstrap flow that should run through module-aware migrate + seed.

## Do Not Use When

- Change belongs to framework-wide non-module defaults in root config.
- Work is only runtime logic with no schema or seed impact.

## Minimal File Pack

- `app/Base/Database/Concerns/RegistersSeeders.php`
- `app/Modules/Business/IT/Database/Migrations/0300_01_01_000000_create_it_tickets_table.php`

## Reference Shape

- `InteractsWithModuleMigrations::loadAllModuleMigrations()` auto-loads Base and Modules migration directories.
- `MigrateCommand::runMigrations()` applies ordering: migrations -> production seeders -> framework primitives -> dev seeders.
- `RegistersSeeders::registerSeeder()` derives module provenance from migration file path.
- Migration `down()` should mirror `up()` including seeder unregister calls.

## Required Invariants

- Follow module-first placement from architecture docs.
- Use layered migration prefixes (`0100`, `0200`, `0300+`) for Base/Modules.
- Keep migration schema work and seeder data work separated.
- Use explicit return types and stable contracts.
- Run authz role-capability seeder when changing capability config.

## Implementation Skeleton

```php
return new class extends Migration
{
    use \App\Base\Database\Concerns\RegistersSeeders;

    public function up(): void
    {
        Schema::create('module_table', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->registerSeeder(ModuleTableSeeder::class);
    }

    public function down(): void
    {
        $this->unregisterSeeder(ModuleTableSeeder::class);
        Schema::dropIfExists('module_table');
    }
};
```

## Test Checklist

- Migration runs through module-aware discovery without manual path wiring.
- Seeder is registered, runnable, and reversible.
- `migrate --seed` executes expected production seeder path.
- Rollback path is clean and deterministic.

## Common Pitfalls

- Placing module migrations in root `database/migrations`.
- Using incorrect prefix or inconsistent module numbering.
- Forgetting to unregister seeders in `down()`.
- Embedding seeding logic inline in migration instead of seeder class.
