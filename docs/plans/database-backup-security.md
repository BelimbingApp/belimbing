Status: Identified
Last Updated: 2026-04-30
Sources: docs/architecture/database.md; app/Base/Database/AGENTS.md; app/Base/Database/Console/Commands/FreshCommand.php; app/Base/Database/Console/Commands/WipeCommand.php; docs/architecture/settings.md; docs/architecture/ai/agent-model.md
Agents: Codex/GPT-5

# database-backup-security

## Problem Essence

BLB needs reliable database backups, but the database contains sensitive operational data, user records, provider credentials, AI runtime metadata, audit trails, and potentially customer content. A backup is therefore equivalent to production data access and must be protected more strongly than a normal export file.

## Desired Outcome

Operators can recover BLB from database loss or bad migrations without creating plaintext dumps, weakening table-stability safeguards, or allowing agents to exfiltrate sensitive data. Backups are encrypted before leaving the database host, stored with least-privilege access, tested through restore drills, and auditable end to end.

## Top-Level Components

1. **Backup Command** — a BLB-owned Artisan command such as `blb:db:backup` that is the only supported way to create database backups.
2. **Backup Writer** — a service that streams `pg_dump` output directly into compression and client-side encryption without writing plaintext backups to disk.
3. **Key Policy** — a small operational contract for backup encryption recipients, restore keys, app keys, and rotation.
4. **Storage Adapter** — a target abstraction for local secure staging, S3-compatible object storage, or another backup vault.
5. **Manifest and Audit Trail** — metadata that records what was backed up, where it went, integrity hashes, expiry, and who triggered it without storing secrets.
6. **Restore Workflow** — a separate operator-only workflow that restores into a new database first, validates schema and data, then promotes deliberately.

## Design Decisions

### Backups are encrypted before they leave the host

The backup command should stream `pg_dump --format=custom` through compression only if useful, then through client-side encryption. The plaintext stream must not be written to `storage/`, `/tmp`, object storage, logs, or shell history. A failed run should leave either no artifact or only an encrypted partial artifact that is deleted by cleanup.

Recommended encryption shape: age-compatible public-key encryption for human/operator restore recipients, with optional KMS envelope encryption for hosted deployments. The app server should have encryption public keys only. Restore private keys should live outside the app server, ideally in an operator secret vault or hardware-backed key store.

### DB backup encryption is separate from Laravel app encryption

Laravel-encrypted settings and provider credentials are still recoverable from a database backup only if the Laravel `APP_KEY` is also available. That means:

- Database backup storage must not store `APP_KEY`.
- `APP_KEY` backup must be handled as a separate secret-backup process with stricter access and fewer recipients.
- Restore runbooks must explicitly require both a database backup and the correct app secret material.

This separation prevents a compromised backup bucket from immediately revealing provider API keys and encrypted settings.

### Backups are full-database, not table-stability exports

Backups serve disaster recovery. They should include stable and unstable tables because partial backups are hard to trust during recovery. Table-stability remains a migration/development safeguard, not a backup scope filter.

The backup command must not call `db:wipe`, `migrate:reset`, or any destructive migration command. Restore is intentionally separate and should target a new database name by default so a backup workflow cannot become a hidden destructive path.

### Restore is a drillable operator workflow

A backup that has not been restored is only an artifact, not a recovery capability. The plan should include a recurring restore drill into an isolated database:

- decrypt backup with restore-only key access
- restore into a fresh database
- run `php artisan migrate:status`
- run application smoke checks
- verify critical row counts and integrity checks
- discard the restore database after evidence is captured

### Retention is tiered and explicit

Default recommendation:

- Hourly backups for 24 hours
- Daily backups for 30 days
- Weekly backups for 12 weeks
- Monthly backups for 12 months, only if business/legal requirements justify it

Retention should be configurable by environment. Sensitive local development backups should default to short retention or disabled scheduling.

### Access is least-privilege and append-oriented

The application runtime identity may create backup objects but should not be able to list, read, or delete all backups unless the deployment explicitly requires it. Object storage should use versioning and delete protection where available. Restore credentials should be separate from write credentials.

### Backup metadata is safe to inspect

The manifest should include safe operational facts:

- backup ID
- environment label
- database connection name and database name
- dump format and PostgreSQL version
- started/finished timestamps
- encrypted artifact size
- SHA-256 hash of encrypted artifact
- encryption recipient fingerprints
- storage URI or object key
- retention expiry
- trigger actor and command source
- success/failure status and safe error message

The manifest must not include database passwords, encryption private keys, app keys, presigned URLs, or plaintext table content.

## Public Contract

### Commands

- `php artisan blb:db:backup`
  Creates a new encrypted backup using configured recipients and storage.

- `php artisan blb:db:backup --dry-run`
  Verifies configuration, recipient keys, target write access, and disk-space estimates without producing a backup.

- `php artisan blb:db:backup --local`
  Writes an encrypted artifact to a configured local secure directory for development or emergency use.

- `php artisan blb:db:backup:verify {backup_id}`
  Checks manifest presence, encrypted artifact hash, retention state, and decryptability if a restore key is available in the current environment.

- `php artisan blb:db:restore --backup={backup_id} --target-database={name}`
  Operator-only. Restores into a named target database. It must reject the currently configured application database unless an explicit human-only emergency override is designed later.

### Configuration

Expected config surface:

- `backup.enabled`
- `backup.storage.driver`
- `backup.storage.bucket`
- `backup.storage.prefix`
- `backup.encryption.recipients`
- `backup.retention.hourly_days`
- `backup.retention.daily_days`
- `backup.retention.weekly_weeks`
- `backup.retention.monthly_months`
- `backup.local.secure_directory`
- `backup.restore.allow_current_database` default `false`

Secrets such as storage credentials should use the existing encrypted settings pattern or deployment secret manager. Private restore keys should not be stored in the database.

### Storage

Encrypted artifacts should be named predictably without revealing tenant data:

`blb/{environment}/database/{YYYY}/{MM}/{DD}/{backup_id}.pgdump.age`

Manifests may live beside artifacts:

`blb/{environment}/database/{YYYY}/{MM}/{DD}/{backup_id}.manifest.json`

## Phases

### Phase 1 — Threat Model and Contract

Goal: define what is protected, who can create backups, who can restore, and what operational guarantees BLB makes.

- [ ] Classify backup contents and identify tables with especially sensitive data
- [ ] Decide backup encryption mechanism: age-only, KMS envelope, or both
- [ ] Decide where restore private keys live for local, staging, and production deployments
- [ ] Define backup manifest schema and safe error contract
- [ ] Add backup command names and config keys to architecture docs

### Phase 2 — Encrypted Backup Creation

Goal: create encrypted backups without plaintext artifacts.

- [ ] Add `blb:db:backup` command
- [ ] Implement streaming `pg_dump` execution with safe argument handling
- [ ] Stream dump through client-side encryption before storage
- [ ] Write manifest only after encrypted artifact upload succeeds
- [ ] Add audit event for backup start, success, and failure
- [ ] Add tests proving no plaintext dump path is written by default

### Phase 3 — Storage, Retention, and Pruning

Goal: keep backups long enough to recover, but not indefinitely.

- [ ] Add storage adapter for local encrypted artifacts
- [ ] Add S3-compatible object storage adapter with least-privilege credentials
- [ ] Add retention policy service
- [ ] Add `blb:db:backup:prune` command that deletes only expired encrypted artifacts with matching manifests
- [ ] Add schedule entries for backup and prune commands
- [ ] Add alert surface for failed backup or prune jobs

### Phase 4 — Restore and Verification

Goal: make recovery repeatable and safe.

- [ ] Add `blb:db:backup:verify` command
- [ ] Add restore command that restores only into an explicitly named non-current database
- [ ] Add restore drill checklist in docs
- [ ] Add smoke checks after restore: migration status, framework primitive presence, critical table counts
- [ ] Add test coverage for refusing to restore over the configured application database

### Phase 5 — Operational Hardening

Goal: make backup operations auditable and resilient.

- [ ] Add admin read-only page for backup manifests and last successful backup status
- [ ] Add health check for backup freshness
- [ ] Add key-rotation runbook
- [ ] Add quarterly restore drill requirement and evidence template
- [ ] Add incident procedure for suspected backup-key or storage compromise
