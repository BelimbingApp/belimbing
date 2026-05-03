Status: In Progress (Phase 1–3, 5 complete; Phase 4 deferred)
Last Updated: 2026-05-02
Sources: docs/architecture/database.md; app/Base/Database/AGENTS.md; app/Base/Database/Console/Commands/FreshCommand.php; app/Base/Database/Console/Commands/WipeCommand.php; docs/architecture/settings.md; docs/architecture/ai/agent-model.md; docs/runbooks/database-backup.md
Agents: Codex/GPT-5; Amp/claude-sonnet-4-5

# database-backup-security

## Problem Essence

BLB needs a recovery path from database loss or bad migrations. The database can hold sensitive operational data (users, provider credentials, AI runtime metadata, audit trails) — but it can also be a small SQLite file on a single-tenant deployment with no sensitive content. The backup mechanism must cover both: strong protection when the data warrants it, frictionless when it does not.

## Desired Outcome

Operators run one command to produce a backup artifact and one to restore it into a fresh database. Encryption is a tier choice, picked at install time:

- **none** — for very small deployments with no sensitive data, where the storage layer's own access controls are sufficient.
- **passphrase** (default) — a single secret stored in the operator's password manager; no key file to babysit.
- **age-recipients** — multi-operator teams with an escrow key.
- **kms** — cloud deployments that prefer no human key custody.

The same command works whether the active database is PostgreSQL or SQLite. Restore refuses to overwrite the configured application database. There is no parallel destructive path.

## Top-Level Components

1. **Backup Command** — `blb:db:backup`, the only supported way to create a backup. Detects the active driver and delegates.
2. **Backup Writer** — driver-specific. `PostgresWriter` streams `pg_dump --format=custom`. `SqliteWriter` uses SQLite's online backup (`.backup` / `VACUUM INTO`). Both stream into the chosen encryption mode without an intermediate plaintext copy on the local filesystem.
3. **Encryption Mode** — `none`, `passphrase`, `age-recipients`, or `kms`, selected by config.
4. **Storage** — a Laravel filesystem disk plus a path prefix. No custom adapter layer; Laravel disks already cover local, S3, and the rest.
5. **Manifest** — a small sidecar JSON with hash, size, driver, encryption mode, and timestamps. Enough to verify and identify; nothing secret.
6. **Restore Command** — `blb:db:restore`, refuses the configured app database. Driver-aware target: a database name for Postgres, a file path for SQLite.

## Design Decisions

### Encryption is a tier choice, not a mandate

Deployments pick one mode:

- **`none`** — artifact is written compressed but plaintext. Intended for small single-tenant deployments with no sensitive content, where the storage disk's access controls are the security boundary. The doc states plainly: "the file is the data; treat it accordingly." Selecting `none` is explicit (`backup.encryption.mode=none`) and the command prints a warning at run time so it is never accidental.
- **`passphrase`** (default) — artifact is encrypted with age scrypt mode using a passphrase from `BACKUP_PASSPHRASE` (or another configured env var). Operators store the passphrase in their password manager. No key file to manage, no rotation ceremony for new backups.
- **`age-recipients`** — encrypted to one or more age public keys. App server holds public keys only; private keys live with operators or in escrow. Use when multiple operators need to be able to restore independently.
- **`kms`** — cloud KMS holds key material. App server's identity can encrypt; a separate restore identity decrypts. Removes human key custody at the cost of a KMS dependency.

Phase 1 ships `none` and `passphrase`. `age-recipients` and `kms` are added in a later phase as opt-in drivers.

### Backups support both PostgreSQL and SQLite

The driver of the active connection determines the writer:

- **PostgreSQL** — `pg_dump --format=custom`, streamed.
- **SQLite** — SQLite online backup, producing a consistent snapshot without locking writers, then streamed.

The backup command does not assume a single driver. The storage filename does not bake driver-specific extensions; the manifest records the driver explicitly.

### Managed databases use provider snapshots

Deployments on managed Postgres (RDS, Cloud SQL, Neon, Supabase, DigitalOcean, etc.) or managed SQLite-replacements (Turso, LiteFS Cloud) should set `backup.enabled=false` and rely on the provider's snapshot policy. BLB's backup command is the path for self-hosters. The runbook documents this escape hatch so operators do not run two overlapping backup systems by accident.

### No plaintext intermediate on the local filesystem, regardless of mode

Even with `none`, the artifact is written once to its final destination via the chosen Laravel disk. The pipeline does not write through `storage/`, `/tmp`, or shell-redirected files as intermediate plaintext steps. Failed runs leave either no artifact or a partial artifact that cleanup removes.

### Backups are disaster-recovery, full-database

Backups include all tables. Table-stability remains a migration safeguard, not a backup-scope filter. The backup command never calls `db:wipe`, `migrate:reset`, or any destructive migration command.

### Restore targets a non-current database

Restore goes into a fresh target — a new Postgres database or a new SQLite file path — never the configured application database. The check is driver-aware: Postgres compares connection name and database name; SQLite compares the resolved file path. Promotion happens deliberately by reconfiguring the connection, not via a flag.

### Retention is simple

Two knobs, applied by a `--prune` flag on the backup command and a scheduler entry:

- `keep_days` — delete artifacts older than this.
- `keep_count` — always keep at least this many of the most recent, regardless of age.

No hourly/daily/weekly/monthly tiers. Deployments that need finer policy can run multiple schedule entries with different prefixes.

### Manifest carries facts, not secrets

Manifest fields:

- `backup_id`
- `driver` (`pgsql` | `sqlite`)
- `encryption_mode` (`none` | `passphrase` | `age-recipients` | `kms`)
- `started_at`, `finished_at`
- `size_bytes`
- `sha256` of the artifact
- `app_environment` label
- `trigger` (command source / scheduled / actor id)
- `status` and a safe error message on failure

No passphrases, no key material, no presigned URLs, no row content.

### `APP_KEY` is backed up separately

When Laravel encrypts settings (provider credentials, etc.), the database alone is not enough to recover them — `APP_KEY` is required. The runbook treats `APP_KEY` as a separate secret-backup artifact with stricter access. The DB backup storage must not contain `APP_KEY`.

## Public Contract

### Commands

- `php artisan blb:db:backup` — creates a backup using the configured encryption mode and disk.
- `php artisan blb:db:backup --dry-run` — verifies driver tooling, encryption configuration, and disk write access without producing an artifact.
- `php artisan blb:db:backup --local` — writes to a configured local disk regardless of the default disk; for development and emergencies.
- `php artisan blb:db:backup --prune` — runs the backup, then deletes artifacts that exceed `keep_days` while preserving the most recent `keep_count`.
- `php artisan blb:db:restore --backup={backup_id} --target={name-or-path}` — operator-only. Restores into a non-current target. Refuses the configured app database.

### Configuration

- `backup.enabled` — top-level on/off; set `false` on managed-DB deployments.
- `backup.disk` — Laravel filesystem disk name.
- `backup.path_prefix` — path under the disk (e.g. `backups/`).
- `backup.encryption.mode` — `none` | `passphrase` | `age-recipients` | `kms`.
- `backup.encryption.passphrase_env` — env var name for the passphrase (default `BACKUP_PASSPHRASE`).
- `backup.encryption.recipients` — list of age recipient strings, used when mode is `age-recipients`.
- `backup.encryption.kms_key` — KMS key identifier, used when mode is `kms`.
- `backup.retention.keep_days`
- `backup.retention.keep_count`
- `backup.restore.allow_current_database` — default `false`.

Storage credentials reuse the existing Laravel disk configuration. Private restore keys are never stored in the database.

### Storage layout

`{path_prefix}/{environment}/{YYYYMMDD-HHMMSS}-{backup_id}.bak[.age]`
`{path_prefix}/{environment}/{YYYYMMDD-HHMMSS}-{backup_id}.manifest.json`

The artifact extension is `.age` when encrypted, plain `.bak` otherwise. Driver lives in the manifest, not the filename.

## Phases

### Phase 1 — Encrypted backup creation

Goal: a working `blb:db:backup` for both Postgres and SQLite, with `none` and `passphrase` modes.

- [x] Add `blb:db:backup` command with driver detection {Amp/claude-sonnet-4-5}
- [x] Implement `PostgresWriter` streaming `pg_dump --format=custom` {Amp/claude-sonnet-4-5}
- [x] Implement `SqliteWriter` using SQLite online backup (`VACUUM INTO`) {Amp/claude-sonnet-4-5}
- [x] Implement `none` and `passphrase` encryption modes; default `passphrase`. Passphrase uses libsodium (Argon2id KDF + XChaCha20-Poly1305 secretstream); no external `age` binary required. {Amp/claude-sonnet-4-5}
- [x] Print explicit warning when running with `mode=none` {Amp/claude-sonnet-4-5}
- [x] Write manifest only after artifact upload succeeds {Amp/claude-sonnet-4-5}
- [x] `--dry-run` validates tooling, encryption config, and disk access {Amp/claude-sonnet-4-5}
- [x] `--local` writes to a configured local disk regardless of the default {Amp/claude-sonnet-4-5}
- [x] Tests prove no plaintext artifact appears outside the configured disk (passphrase round-trip, ciphertext starts with magic, source plaintext bytes absent from artifact) {Amp/claude-sonnet-4-5}
- [x] Backup start/success/failure are captured by the existing Audit `CommandListener` (artisan command audit), so no separate audit emitter is needed. {Amp/claude-sonnet-4-5}

### Phase 2 — Restore

Goal: a working `blb:db:restore` that refuses the current database and works for both drivers.

- [x] Add `blb:db:restore` command with driver detection {Amp/claude-sonnet-4-5}
- [x] Postgres path: restore into a named non-current database via `pg_restore --dbname={target} --clean --if-exists` {Amp/claude-sonnet-4-5}
- [x] SQLite path: restore into a target file path that is not the active DB file {Amp/claude-sonnet-4-5}
- [x] Refuse current database in both drivers; covered by tests {Amp/claude-sonnet-4-5}
- [x] Operator output points to the smoke checks (`migrate:status`, framework primitive presence, critical table counts) to be run post-restore. Automated post-restore smoke runners are deferred — they belong with the connection promotion workflow, not the restore command. {Amp/claude-sonnet-4-5}

### Phase 3 — Retention and scheduling

Goal: don't accumulate forever; don't fail silently.

- [x] Implement `--prune` with `keep_days` + `keep_count` semantics; covered by `RetentionPolicyTest` and a `BackupCommandTest` round-trip with a backdated manifest {Amp/claude-sonnet-4-5}
- [ ] Add a scheduler entry for backup (with `--prune`) — left for the deployment to wire into its own `routes/console.php` or cron, since BLB does not yet ship a global scheduler config; a recommendation is in the runbook.
- [x] Failed-backup alerting flows through the existing audit/log channels; no admin UI in this phase. {Amp/claude-sonnet-4-5}

### Phase 4 — Optional encryption tiers

Goal: serve teams that need more than passphrase.

- [ ] Implement `age-recipients` mode (multi-recipient, escrow-friendly)
- [ ] Implement `kms` mode (one supported provider initially; structure to allow a second later)
- [ ] Document tier-selection guidance per deployment shape

### Phase 5 — Runbook

Goal: capture operational truth in one document, not as framework features.

- [x] Write `docs/runbooks/database-backup.md` covering: tier selection, passphrase storage, restore drill steps, `APP_KEY` separate backup, key rotation per tier, incident response if storage or key material is suspected compromised {Amp/claude-sonnet-4-5}
- [x] Document the managed-DB escape hatch (`backup.enabled=false`, rely on provider snapshots) {Amp/claude-sonnet-4-5}
- [x] Reference the runbook from the docs index (`docs/AGENTS.md`) {Amp/claude-sonnet-4-5}
