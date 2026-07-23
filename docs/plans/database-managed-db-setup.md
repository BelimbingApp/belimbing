Status: Identified — scope expanded to the Windows `setup.ps1` surface
Last Updated: 2026-07-23
Sources: scripts/setup-steps/40-database.sh; scripts/setup.ps1; scripts/shared/validation.sh; scripts/shared/config.sh; app/Base/Database/Config/backup.php; app/Base/Database/Services/Backup/BackupRuntimeSettings.php; docs/plans/database-backup-security.md
Agents: Copilot/claude-sonnet-4-6; Claude Code/claude-opus-4-8

# database-managed-db-setup

## Problem Essence

Neither setup surface routes cleanly to a managed Postgres provider (Neon,
Supabase, RDS, Cloud SQL, DigitalOcean, ...). The POSIX flow
(`scripts/setup-steps/40-database.sh`) assumes the database lives on the same
machine — it tries to install or start a local PostgreSQL instance and only
falls through to "provide credentials for an existing database" after local
admin detection fails, which it won't on a host that already has `psql`. The
Windows flow (`scripts/setup.ps1`) is worse: it hardcodes
`DB_CONNECTION=sqlite`, creates `database/database.sqlite`, and has **no
PostgreSQL branch at all** — even though the FrankenPHP `php.ini` it writes
already loads `pdo_pgsql`/`pgsql`, so the runtime can talk to a managed
Postgres today; only setup can't configure it.

## Desired Outcome

An operator who supplies remote database credentials reaches a verify-then-save
path directly, on either OS, without setup first attempting a local PostgreSQL
install (POSIX) or silently pinning SQLite (Windows). Self-hosted/local remains
the default on each surface — local PostgreSQL on POSIX, SQLite on Windows — and
is unchanged when no managed credentials are supplied. On the managed path, once
migrations make `base_settings` available, both surfaces persist a global
`backup.enabled=false` override through the settings contract so the operator
does not run BLB backups alongside provider snapshots by accident. Re-running
setup on an already-managed host must not revert it to the local default.

## Setup Surfaces

Two entry points implement database setup and must reach parity on the managed
path while keeping their distinct local defaults:

- **POSIX** — `scripts/setup-steps/40-database.sh`, sourcing `shared/config.sh`
  (env read/write, defaults), `shared/validation.sh` (`DATABASE_URL` parser),
  and `shared/interactive.sh` (`ask_yes_no`, `ask_input`). Default connection is
  `pgsql`; verification uses `psql`.
- **Windows** — `scripts/setup.ps1`, self-contained with `Get-EnvValue` /
  `Set-EnvValue` / `Set-EnvValueBatch` helpers and the bundled FrankenPHP
  `php.exe`. Default connection is `sqlite`; there is no Postgres path yet.

## Design Decisions

### Remote credentials short-circuit the POSIX local-install chain

Today the POSIX flow is: detect local admin → install/start local PG → fall
back to credential prompting. That ordering should stay for genuine local
setups, but present remote credentials should not have to wait for it to fail.
If `.env` already carries a fully-specified connection (host, port, database,
username, password), verify it and skip the local-install steps entirely; for a
fresh setup, an interactive "Connect to a managed or remote database?" branch
lets the operator opt out before local detection runs. The existing
`reuse_existing_postgresql_config_if_working()` is almost the right primitive —
it just fires too late in the call chain (inside `configure_postgresql_database`,
after `check_postgresql`).

### Windows `setup.ps1` gains an opt-in managed-DB branch

The recommended shape is a single opt-in signal rather than reworking the
default: a `-DatabaseUrl <url>` parameter (and/or `-Database sqlite|managed`)
that, when present, switches the connection to `pgsql` and takes the managed
path. When absent, SQLite stays the Windows default exactly as today — a
zero-dependency local dev choice we are not changing.

The critical subtlety is idempotency. `setup.ps1` currently applies
`DB_CONNECTION=sqlite` and `DB_DATABASE=<sqlite path>` in the "infrastructure
values — always applied" batch and unconditionally creates the SQLite file. The
managed branch must (a) skip that SQLite batch and file creation, and (b) treat
an existing verifying `pgsql` connection in `.env` as authoritative so a bare
re-run on a managed host is not silently reverted to SQLite. This mirrors the
POSIX short-circuit: existing-working-config wins over the local default.

Rejected alternative: auto-detecting "managed vs local" from the host. There is
no reliable signal on Windows, and guessing wrong either pins SQLite when the
operator wanted managed or breaks the common local-dev case. An explicit
parameter keeps the module boundary honest and the default predictable.

### `DATABASE_URL` as an alternative credential entry point

`shared/validation.sh` already parses `postgresql://user:password@host:port/database`
and tests the connection (`test_database_connection`), but this utility is not
wired into the setup flow. Surfacing it in the POSIX credential prompt saves
operators from copy-pasting five fields from a provider dashboard. Windows has no
equivalent parser; the plan adds a small `ConvertFrom-DatabaseUrl` helper in
`setup.ps1` (right-to-left split so passwords may contain `:`/`@`, matching the
bash parser's contract). Neither surface persists the raw URL — both decompose
it into the individual `DB_*` keys to stay consistent with every other write
path. `DATABASE_URL` is not currently present in `.env.example`.

### Verify with `psql` on POSIX, PHP PDO on Windows

POSIX already depends on `psql` and should keep using it. Windows should **not**
grow a `psql.exe` dependency just for setup — the FrankenPHP `php.ini` already
loads `pdo_pgsql`, so the managed branch verifies by running a one-line
`SELECT 1` through the bundled `php.exe` with PDO. This reuses a component the
setup already guarantees and keeps the Windows prerequisite surface unchanged.

### `backup.enabled` defaults to `false` on the managed path

`backup.enabled` is settings-backed: `BackupRuntimeSettings::configuration()`
reads `settings->get('backup.enabled')`, and `config/backup.php` documents that
managed deployments should disable BLB's parallel backup. When setup takes the
managed branch, after `php artisan migrate` it persists a global
`backup.enabled=false` override through `SettingsService::set()` — invoked via
`php artisan tinker --execute`, the same post-migrate pattern
`scripts/setup-steps/60-migrations.sh` already uses. It does **not** write an
`.env` runtime parameter. The operator opts back in through the Backups UI if
they want a secondary copy alongside provider snapshots.

## Public Contract

Shared invariants both surfaces satisfy on the managed path:

- The connection is verified (`SELECT 1`) with the exact credentials that will
  be saved, before anything is written to `.env`.
- Credentials are written as individual keys — `DB_CONNECTION=pgsql`, `DB_HOST`,
  `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — never as a raw
  `DATABASE_URL`.
- No local database server is installed, started, or provisioned on the managed
  path (no `apt`/`brew`/`pg_isready` on POSIX; no `database.sqlite` on Windows).
- After migrations, a global `backup.enabled=false` override is persisted via
  the settings contract, and setup prints a one-line note explaining why.
- An existing, verifying managed connection in `.env` is authoritative: a
  re-run neither reverts to the local default nor re-provisions.

Local defaults are unchanged: POSIX still defaults to local PostgreSQL, Windows
still defaults to SQLite, whenever no managed credentials/parameter are present.

## Phases

### Phase 1 — POSIX: early-exit on existing working config

Goal: operators who already have `.env` credentials skip all local install logic.

- [ ] In `main()`, call `reuse_existing_postgresql_config_if_working()` before the `check_postgresql` / install chain; return early if it succeeds
- [ ] Print a message distinguishing "reusing existing remote config" from "reusing existing local config" (the function currently prints neither)
- [ ] Validation: `.env` with valid Neon/Supabase credentials → `setup.sh` exits without touching `apt` or `pg_isready`

### Phase 2 — POSIX: interactive managed-DB branch

Goal: fresh setups can pick "remote/managed" without waiting for local detection to fail.

- [ ] Add `ask_yes_no "Connect to a managed or remote PostgreSQL database?"` before the `check_postgresql` block in `main()`
- [ ] Answering yes routes directly to `setup_existing_postgresql_connection()`; answering no proceeds with the existing local-install chain
- [ ] When the managed path is chosen, persist `backup.enabled=false` through the settings service after migrations and print a note explaining why

### Phase 3 — POSIX: `DATABASE_URL` credential entry

Goal: operators can paste a single connection string instead of five prompts.

- [ ] In `setup_existing_postgresql_connection()`, offer `DATABASE_URL` as an alternative to individual prompts ("Enter a DATABASE_URL, or press Enter to enter fields individually")
- [ ] Parse the URL using `test_database_connection`'s parsing in `validation.sh`; fall through to individual prompts if blank or unparseable
- [ ] Save the parsed fields to `.env` individually (not the raw URL)

### Phase 4 — Windows: managed-DB branch in `setup.ps1`

Goal: `setup.ps1 -DatabaseUrl <url>` (or `-Database managed` with fields) configures a managed Postgres instead of SQLite, and a re-run on a managed host stays managed.

- [ ] Add a `-DatabaseUrl` string parameter and/or a `-Database` `ValidateSet('sqlite','managed')` parameter to the `param()` block
- [ ] Add `ConvertFrom-DatabaseUrl` (right-to-left split; passwords may contain `:`/`@`) mirroring the bash parser contract
- [ ] Add `Test-ManagedDatabaseConnection` that runs `SELECT 1` via the bundled `php.exe` + PDO (`pdo_pgsql` is already enabled in the generated `php.ini`) using the exact credentials to be saved
- [ ] When managed is selected or an existing `.env` `pgsql` connection verifies, write `DB_CONNECTION=pgsql` and the individual `DB_*` keys, and **skip** the SQLite infrastructure batch and `database.sqlite` creation
- [ ] Reflect the resolved connection (`sqlite` vs `pgsql` + host) in `install-state.json` instead of the hardcoded SQLite line
- [ ] After the existing `artisan migrate` step, persist global `backup.enabled=false` via `php artisan tinker --execute` (`SettingsService::set`) and print the note
- [ ] Validation: `setup.ps1 -DatabaseUrl <neon-url>` on a clean checkout → `.env` has `DB_CONNECTION=pgsql`, no `database.sqlite` created, migrations run against the managed DB; a second bare `setup.ps1` run leaves the managed config intact

### Phase 5 — Windows: parity smoke tests and docs

Goal: the two surfaces are demonstrably equivalent on the managed path.

- [ ] Document the managed-DB invocation for both OSes wherever setup is documented (README/setup docs), including the `backup.enabled=false` behavior
- [ ] Add `DATABASE_URL` guidance (commented example) so operators know the accepted format; keep `.env` writes decomposed into `DB_*`
- [ ] Validation: side-by-side run notes (POSIX `setup.sh` vs Windows `setup.ps1 -DatabaseUrl`) confirming identical `.env` `DB_*` output and a persisted `backup.enabled=false` override
