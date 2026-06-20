# Database Schema Rebuild Contract

**Status:** In progress
**Last Updated:** 2026-06-20
**Sources:** `AGENTS.md`, `docs/architecture/database.md`, `app/Base/Database/AGENTS.md`, `app/Base/Database/Console/Commands/FreshCommand.php`, `app/Base/Database/Console/Commands/MigrateCommand.php`, `app/Base/Database/Models/TableRegistry.php`, `docs/architecture/module-system.md`, `docs/guides/extensions/database-migrations.md`, 2026-05-24 schema workflow discussion
**Agents:** {codex/gpt-5}, {amp}

## Problem Essence

BLB customized Laravel's `migrate:fresh` into a selective rebuild flow, so the command name now promises Laravel's "drop everything and rerun" behavior while the implementation preserves some tables. The deeper issue is that "destructive evolution" no longer describes the intended workflow: under-development schemas may change, but stable schemas and local/user data need explicit, predictable evolution.

## Desired Outcome

Laravel migration commands should stay familiar, with the fewest possible BLB-specific commands. A developer should normally run `php artisan migrate`, and in local development run `php artisan migrate --dev` to let BLB drop source-declared incubating schema scopes before continuing through Laravel's normal migrator.

## Top-Level Components

**Progressive evolution guidance** replaces the broad destructive-evolution rule. BLB still optimizes for best-current design, but schema maturity is explicit: incubating schemas can be rewritten in place; stable schemas evolve through new migrations or intentional data ports.

**Laravel-compatible migration flow** keeps `migrate` as the main command. BLB-specific behavior lives behind the existing local-only `--dev` flag rather than a separate command family.

**Source-declared schema maturity** records which migration files are incubating in git-tracked migration metadata. The database can cache or display this state, but source is authoritative. A deprecated git-tracked table-pattern bridge may exist temporarily while older under-development tables are moved into migration-local declarations.

**Development migrate preflight** runs before the native migrator when `--dev` is present. It reads source-declared incubating migrations, computes affected tables, drops those tables safely, clears affected migration records, then lets Laravel migrate normally.

**Module dependency graph** moves toward manifest-declared dependencies for pluggable modules, with timestamp prefixes remaining an ordering aid rather than the only dependency contract.

**Docs and agent guidance** teach agents that source-local `use IncubatingSchema;` means "this schema is still under development" rather than "feel free to wipe arbitrary data."

**Agent-first ergonomics** treats coding agents as primary users of the workflow. Schema state must be discoverable from source, editable in the same place as the schema change, and routed through familiar Laravel commands.

## Design Decisions

### D1: Replace destructive evolution with progressive evolution

The root guidance should drop "Destructive Evolution." Recommended replacement:

> **Progressive Evolution:** build the best current design, but make schema maturity explicit. Under-development schemas may be rewritten in place; stable schemas evolve through normal Laravel migrations or explicit data ports. Local/user data is protected by default unless a development schema is intentionally marked incubating.

This keeps BLB's design freedom without training agents to treat destructive changes as the default move.

### D2: Keep custom BLB migrate commands near zero

The primary developer workflow should stay inside Laravel's migration command:

```bash
php artisan migrate
php artisan migrate --dev
```

`migrate` remains the production/staging path. `migrate --dev` becomes the local development path that handles incubating schemas before native migration. Avoid adding `blb:db:rebuild`, `blb:schema:plan`, or `blb:db:seed-dev` unless a later phase proves a separate diagnostic command is truly needed.

### D3: Make incubation migration-local

`use IncubatingSchema;` should mean: the owning migration file is incubating, its schema may be rewritten in place, and `migrate --dev` may drop and rebuild its tables.

The recommended source declaration is migration-local metadata, not a parallel module manifest. This keeps the state beside the schema it describes and avoids drift from duplicated migration filenames.

```php
use App\Base\Database\Concerns\IncubatingSchema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        // ...
    }
};
```

The important part is the contract: the migration file declares whether its schema is stable or incubating.

`Database/schema.php` should be optional and coarse-grained only, for example to say "this whole module defaults to incubating until release." It should not be the normal place to list individual migration filenames, because that creates a second source of truth. Future composerized plugins can mirror coarse package defaults under `composer.json` `extra.blb.schema` if needed.

If only one table inside a multi-table migration is incubating, the correct fix is usually to split the migration. The rebuild unit should be a migration file unless the system has stronger proof that a narrower split is safe.

Stable schemas may become incubating again while a module is still before a release/public contract. That flexibility is important: if a supposedly stable table turns out to have the wrong shape, the author can mark its migration file incubating in source and let every preview install rebuild it consistently. After release/public use, stable-to-incubating should be treated as an explicit breaking/incubation branch; normal work should use additive migrations or data ports.

The extra compute cost is acceptable. BLB already scans migration files for declared tables; reading a constant, trait, or attribute during `migrate --dev` is negligible beside Laravel boot, database I/O, migrations, and seeders. If this ever becomes measurable, cache scan results by migration file path plus mtime/content hash.

### D4: Treat the legacy local stability column as transitional

Once schema maturity is source-declared, the old local stability column is YAGNI as an editable source of truth. Keeping it as a local override would reintroduce the original confusion: one machine could silently disagree with the branch.

Recommended direction: stop treating the column as policy and remove it after the new flow lands. The current installations are few and operator-controlled, so BLB does not need a long compatibility shim for the old local flag. If later UI needs to display schema maturity, add cached/observed fields derived from source, such as `schema_state`, `schema_declared_at`, or migration-file hash metadata. The UI may still display whether a table is stable or incubating, but it should not let users toggle source-defined schema maturity from the database browser.

A local-only unstable override can still exist as a diagnostic escape hatch, but it must be named and surfaced as local override state, not as schema truth. The command output should make the difference visible: "source-declared incubating" versus "local override." Local overrides should not be required for another installation to receive the latest schema.

### D5: `migrate --dev` drops incubating scopes, then carries on natively

The core algorithm should be simple:

1. Ensure the app is local/development.
2. Discover module and extension migrations.
3. Read migration-local incubating metadata and optional module/package defaults.
4. Resolve those files to owned tables through the registry and source scanning.
5. Expand to a safe dependency closure or refuse with a clear conflict.
6. Drop the affected tables and clear their migration records.
7. Run Laravel's normal migrator.
8. Run production seeders and dev seeders according to the existing `--seed`/`--dev` policy.

This makes `migrate --dev` feel like Laravel migrate with a development preflight, not a separate database product.

### D6: Restore honesty around `migrate:fresh`

`migrate:fresh` should return to Laravel's full-fresh meaning, or be blocked outside disposable/test databases with a clear message pointing developers to `migrate --dev`. It should not remain the selective rebuild entry point.

The normal local loop should become `php artisan migrate --dev`, not `php artisan migrate:fresh --dev --seed`.

### D7: Pluggable modules need manifest dependency ordering

Timestamp prefixes work for today's in-repo modules, but pluggable modules and extensions need a manifest-level dependency graph. `extra.blb.requires-modules` should become the durable source for install and migration ordering once module manifests are introduced.

Prefixes still matter inside a module and as a deterministic fallback, but they should not carry the full burden of cross-repo dependency resolution.

### D8: Optimize for coding agents as first-class citizens

All BLB coding work is expected to be carried out by coding agents, so the schema workflow should be agent-native rather than merely human-operable. The design should favor conventions agents can inspect with normal source tools: migration-local constants, traits, enums, or attributes; grep-friendly names; deterministic command output; and error messages that state the next command or source edit.

This strengthens the choice to keep the normal path on `php artisan migrate` and `php artisan migrate --dev`. Every extra BLB-only command or local UI toggle is another hidden ritual an agent can miss. If BLB needs a helper, it should guide or edit the same source-local metadata rather than becoming a second policy surface.

### D9: Production incubation is stateful, not globally blocked

The first production guard blocked every source-declared incubating migration, even when the migration was already recorded in Laravel's `migrations` table. That protects against mutable source drift but also traps ordinary deploys after the guard is introduced to a database that already has incubating schema.

The implemented policy is stateful: pending incubating migrations remain blocked outside local/testing; applied incubating migrations are allowed with a warning and a source fingerprint baseline. If the applied source hash later changes, production blocks because Laravel will not rerun that migration; restore the recorded source or ship a new forward migration. Rare production-only validation uses an instance-local, exact-hash approval stored under `storage/`, requiring a backup reference and reason. Approvals bind to the selected Laravel connection, driver, and database identifier so SQLite, PostgreSQL, and future drivers remain isolated. This avoids committed allow-lists or broad owner/module globs that would affect other licensee productions.

## Public Contract

`php artisan migrate` runs pending migrations with BLB module discovery. It is the production/staging command.

Outside local/testing, `php artisan migrate` classifies source-declared incubating migrations before native migration. Applied incubating migrations are allowed and fingerprinted; pending incubating migrations are blocked unless an exact local approval exists; applied hash drift is blocked.

`php artisan migrate --dev` is local-only. Before native migration, it drops and rebuilds source-declared incubating migration scopes, then continues through Laravel's migrator and development seeding behavior.

`php artisan migrate:fresh` means Laravel fresh: full drop and rerun, or it is blocked with a clear explanation when the environment is not disposable.

Migration files declare their own schema maturity. Optional `Database/schema.php` or `composer.json extra.blb.schema` metadata may provide coarse module/package defaults, but should not duplicate per-file state.

`base_database_tables` remains useful for table provenance, admin browsing, and mapping tables back to migration files. Any legacy local stability column should not remain the policy source once source declarations exist.

A migration file can move from stable back to incubating in source before release. That is the supported way to make a stable table unstable again across every developer and preview install. Local unstable overrides are allowed only for diagnostics and must not be confused with source-declared maturity.

The workflow is agent-first: source search should reveal the schema state, and migration errors should name the source file or command the agent should touch next.

## Phases

### Phase 1 - Document the philosophy and command boundary

Goal: make the policy honest before changing behavior.

- [x] Replace `AGENTS.md` destructive-evolution language with progressive-evolution guidance.
- [x] Update `docs/architecture/database.md` to describe `migrate --dev` as the development schema workflow.
- [x] Update `app/Base/Database/AGENTS.md` so agents stop treating `migrate:fresh --dev --seed` as the primary command.
- [x] Add agent-first wording to database guidance: coding agents should use familiar Laravel commands and source-local schema metadata.
- [x] Decide whether `migrate:fresh` is restored or blocked outside disposable/test databases.

### Phase 2 - Add migration-local schema maturity

Goal: move schema state into git-tracked module files.

- [x] Define migration-local metadata for stable/incubating state using a constant, trait, enum, or attribute.
- [x] Choose the metadata form with agent readability as a first-order criterion: grep-friendly, obvious beside `up()`, and easy to edit without extra docs.
- [x] Add parser/resolver support for stable and incubating migration states.
- [x] Support stable-to-incubating changes in source and document when that is acceptable before release versus after public use.
- [x] Decide whether optional `Database/schema.php` module defaults are needed, and keep them coarse only. {amp}
- [ ] Document the future `composer.json extra.blb.schema` mirror for coarse pluggable-module defaults.

Evidence: Deferred — no module has needed coarse module-level defaults; migration-local `use IncubatingSchema;` covers every current case. If a module later needs a whole-module default, add `Database/schema.php` as a coarse fallback only, never a per-file list (per D3). The `extra.blb.schema` doc item stays open under Phase 2 until a composerized plugin actually consumes the field.

### Phase 3 - Teach `migrate --dev` the incubating-schema preflight

Goal: keep one familiar command while making development schema refresh automatic.

- [x] Before native migration, resolve incubating migration files to affected tables.
- [x] Drop affected incubating tables and clear their migration records.
- [x] Preserve stable tables unless dependency closure requires a rebuild or the command refuses.
- [x] Make `migrate --dev` output deterministic and agent-readable: affected files, affected tables, refused dependencies, and next source edit.
- [x] Keep dev seeding local-only and tied to `--dev`.

### Phase 4 - Restore or block `migrate:fresh`

Goal: remove the semantic trap.

- [x] Remove selective rebuild behavior from `migrate:fresh`.
- [x] Remove `--dev` from `migrate:fresh` once `migrate --dev` owns the development path.
- [x] Adjust tests and docs that currently assert selective behavior through `migrate:fresh`.
- [x] Keep `migrate:refresh` and `migrate:reset` blocked unless a separate disposable-database policy is introduced. {amp}

Evidence: `tests/Feature/Database/RefreshResetCommandTest.php` asserts the `migrate:refresh` and `migrate:reset` blocks on persistent databases, mirroring the existing `WipeCommandTest.php`. The dead `--dev` passthrough was also removed from `RefreshCommand` (the guard already restricts it to in-memory SQLite, where `migrate --dev` is the blessed path).

### Phase 5 - Dependency-aware dev migration

Goal: avoid unsafe partial rebuilds.

- [x] Build table dependency discovery from database foreign keys and declared migration effects. {amp}
- [x] Expand incubating rebuild plans to include dependent tables or refuse unsafe partial plans. {amp}
- [x] Handle multi-table migrations as a single coherent rebuild unit unless the migration is split. {amp}
- [x] Verify SQLite behavior around dependent ordering and cyclic foreign-key fallback. {amp}
- [ ] Verify PostgreSQL and MySQL behavior separately, especially around constraint preservation and rerun ordering.

Evidence: `tests/Feature/Database/IncubatingSchemaPreflightTest.php` covers direct dependent-table cascade, the multi-table dependent-migration rerun unit, and mutually-referencing table drops on the SQLite-backed test database. The rebuild-scope fixpoint caches per-table foreign keys once per expansion so the iteration reuses metadata instead of re-querying every live table on each pass; the test file now shares the `writeIncubatingTestMigration` helper and cleans all known test tables in `afterEach` so setup failures cannot leak. Verified with `vendor/bin/pest tests/Feature/Database`. PostgreSQL/MySQL verification remains open.

### Phase 6 - Pluggable module schema ordering

Goal: make preview installs of under-development modules reliable across repos.

- [ ] Define how `extra.blb.requires-modules` maps to migration ordering and module availability.
- [ ] Update extension migration docs so extension authors know how BLB detects dependencies, changed files, and incubating schema scope.
- [ ] Decide how nested-git plugins and future composer plugins publish schema state to the host app.
- [ ] Add checks that missing or incompatible required modules fail before migrations run.

### Phase 7 - Retire local stability policy

Goal: finish the conceptual migration.

- [x] Rename user-facing "Stable" labels to schema maturity language.
- [x] Remove the legacy local stability column after source declarations become authoritative.
- [x] Remove `blb:table:unstable` from the steady-state workflow and keep only a deprecated git-tracked compatibility list while other installations adopt migration-local incubating markers.
- [x] Audit existing plans that mention destructive evolution or `migrate:fresh --dev --seed` and update wording where it would mislead future work. {amp}

Evidence: `docs/brief.md` "Where Detail Lives" table updated from "destructive evolution" to "progressive evolution" — the only live (non-historical) doc that still used the retired term. The remaining mentions live in Completed plans (payroll-intake-dependency-inversion, database-backup-security, ai-control-plane-unified-timeline, module-domain-alignment, base-audit-subject-index) as historical evidence of work done under the prior policy; rewriting completed-plan history would falsify the handoff, so they are deliberately left intact.

### Phase 8 - Make production incubation stateful

Goal: keep production deploys safe without blocking already-applied incubating schema on every future run.

- [x] Allow applied source-declared incubating migrations on non-disposable databases while warning that production will not rebuild them. {amp}
- [x] Record source fingerprints for applied incubating migrations and block later hash drift. {amp}
- [x] Keep pending incubating migrations blocked by default outside local/testing. {amp}
- [x] Add instance-local, exact-hash, expiring approvals for rare pending incubating production runs. {amp}
- [x] Update database architecture and agent guidance to describe the new production guard contract. {amp}
