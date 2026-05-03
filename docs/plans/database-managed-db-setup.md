Status: Identified
Last Updated: 2026-05-03
Sources: scripts/setup-steps/40-database.sh; scripts/shared/validation.sh; scripts/shared/config.sh; docs/plans/database-backup-security.md
Agents: Copilot/claude-sonnet-4-6

# database-managed-db-setup

## Problem Essence

`scripts/setup-steps/40-database.sh` assumes the database lives on the same machine — it tries to install or start a local PostgreSQL instance before falling back to "provide credentials for an existing database." Operators using a managed Postgres provider (Neon, Supabase, RDS, Cloud SQL, DigitalOcean, etc.) reach the right flow only if local admin detection fails first, which it won't on a machine that already has a local `psql` installation.

## Desired Outcome

An operator who supplies credentials for a remote database reaches `setup_existing_postgresql_connection()` directly, without the setup script first attempting a local PostgreSQL install. Self-hosted local setup is unchanged and remains the default. On the remote path, setup writes `BACKUP_ENABLED=false` to `.env` so the operator doesn't end up running BLB backups alongside the provider's own snapshots by accident.

## Design Decisions

### Remote credentials short-circuit the local-install chain

Today the flow is: detect local admin → install/start local PG → fall back to credential prompting if all else fails. The script does not assume the database is remote, and that assumption should stay. What needs fixing is the unnecessary friction when remote credentials are already present: if `.env` carries a fully-specified connection (host, port, database, username, password), verify it and skip the local-install steps entirely. For a fresh setup with no `.env` values, an interactive branch — "Connect to a remote database?" — lets the operator opt out of local install before it starts.

The existing `reuse_existing_postgresql_config_if_working()` function is almost the right primitive; it just fires too late in the call chain.

### `DATABASE_URL` as an alternative credential entry point

`scripts/shared/validation.sh` already parses a `postgresql://user:password@host:port/database` URL into its parts and tests the connection. This utility is not wired into the setup flow. Surfacing it in the interactive credential prompt saves operators from copy-pasting five separate fields from a provider dashboard.

### `backup.enabled` defaults to `false` on the managed path

When setup takes the managed DB branch, it writes `BACKUP_ENABLED=false` to `.env`. The operator must explicitly opt in to BLB backups if they want a secondary copy alongside the provider's own snapshots. This mirrors the runbook guidance in `database-backup-security.md` and prevents accidental double-backup setups.

## Phases

### Phase 1 — Early-exit on existing working config

Goal: operators who already have `.env` credentials skip all local install logic.

- [ ] In `main()`, call `reuse_existing_postgresql_config_if_working()` before the `check_postgresql` / install chain; return early if it succeeds
- [ ] Print a clear message distinguishing "reusing existing remote config" from "reusing existing local config" (the function currently prints neither)
- [ ] Covered by a manual smoke test: `.env` with valid Neon/Supabase credentials → `setup.sh` exits without touching `apt` or `pg_isready`

### Phase 2 — Interactive managed-DB branch

Goal: fresh setups can pick "remote/managed" without waiting for local detection to fail.

- [ ] Add `ask_yes_no "Connect to a managed or remote PostgreSQL database?"` before the `check_postgresql` block in `main()`
- [ ] Answering yes routes directly to `setup_existing_postgresql_connection()`; answering no proceeds with the existing local-install chain
- [ ] When the managed path is chosen, write `BACKUP_ENABLED=false` to `.env` and print a note explaining why

### Phase 3 — `DATABASE_URL` credential entry

Goal: operators can paste a single connection string instead of five prompts.

- [ ] In `setup_existing_postgresql_connection()`, offer `DATABASE_URL` as an alternative to individual field prompts (e.g. "Enter a DATABASE_URL, or press Enter to enter fields individually")
- [ ] Parse the URL using the existing utility in `validation.sh`; fall through to the individual prompts if the URL is blank or unparseable
- [ ] Save the parsed fields to `.env` individually (not the raw URL) to stay consistent with how all other paths write credentials
