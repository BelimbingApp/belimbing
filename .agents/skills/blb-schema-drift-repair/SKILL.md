---
name: blb-schema-drift-repair
description: Use when a BLB migration-source-versus-database schema drift report identifies a discrepancy in a local or testing database, or when asked to reconcile reported database schema drift. Diagnose the report, choose the safe migration-based repair, and prove the result; never patch the database directly or repair a production/staging database.
---

# BLB Schema Drift Repair

Treat a drift report as evidence, not as authorization to mutate a database. Reconcile source and database through BLB's migration workflow, then prove the report is clean.

## Load first

- `app/Base/Database/AGENTS.md`
- `docs/architecture/database.md`
- The relevant migration files and their module `AGENTS.md`, if any
- `tests/AGENTS.md` when the repair changes migration source or application code

## Preconditions

1. Run `php artisan blb:schema:drift`. The command intentionally has no custom options and inspects the default database connection. Treat exit `0` as fully checked and clean, `1` as confirmed drift, and `2` as incomplete or unsupported analysis. On exit `2`, stop and report that no automatic repair decision is safe.
2. Confirm both `APP_ENV` and the `SCHEMA_DRIFT` database identity before changing anything. Mutate only `local` or `testing` databases. For production, staging, external, or ambiguous databases, make no database change; explain the required forward migration or operator action.
3. Never use raw SQL/DDL, `db:wipe`, `migrate:reset`, `migrate:refresh`, or direct edits to the `migrations` ledger as a repair. Do not drop Database Residue from a schema-drift finding.

## Repair workflow

1. Read every reported migration and inspect the live schema and migration ledger. Decide whether the report identifies a real mismatch, source drift, residue, or an unsupported source construct. Do not treat a parser limitation as database damage.
2. Check schema maturity at the source:
   - **Source-declared incubating migration:** in `local`, preserve the migration as the source of truth and run `php artisan migrate --dev`. Let its preflight choose the dependency closure; do not drop individual tables or clear ledger rows yourself. In `testing`, use the owning test's disposable-database setup; `migrate --dev` is local-only.
   - **Stable migration:** preserve migration history. Restore an accidentally edited historical migration if necessary, then carry the intended schema change in a new forward migration. Run the normal migration flow.
   - **Unclaimed table or stale ledger record:** hand off to the Database Residue workflow. It is not proof that deletion is safe.
3. Use `migrate:fresh` only after confirming the database is disposable and the user has authorized the full wipe. It is a recovery path, not the default repair.
4. Re-run `php artisan blb:schema:drift` after the migration succeeds. Require exit `0`; verify every original finding is resolved and the report contains no newly unreadable source. Run focused migration or feature tests when source changed.

## Stop conditions

Stop instead of guessing when the report is incomplete, a migration uses runtime-dependent schema logic, the correct source state is unclear, the repair would need a production/staging mutation, or the proposed action would discard data outside an explicitly authorized disposable database.

## Handoff

Report the database identity and environment, original findings, chosen maturity path, commands run, migrations changed, validation output, and any remaining unchecked constructs. State clearly when no mutation was made.
