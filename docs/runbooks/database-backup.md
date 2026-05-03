# Database Backup Runbook

**Document Type:** Operational runbook
**Scope:** Self-hosted BLB deployments using `blb:db:backup` / `blb:db:restore`
**Last Updated:** 2026-05-02

This runbook covers how to choose an encryption tier, how the artifact format
works, how to run restore drills, key rotation, and what to do on suspected
compromise. The architecture decisions live in
[../plans/database-backup-security.md](../plans/database-backup-security.md);
this document is the operator-facing companion.

## When to use BLB backups, and when not to

| Deployment shape | Recommendation |
|---|---|
| Self-hosted Postgres | Use `blb:db:backup` on a schedule. |
| Self-hosted SQLite | Use `blb:db:backup` on a schedule. |
| Managed Postgres (RDS, Cloud SQL, Neon, Supabase, DigitalOcean) | Set `BACKUP_ENABLED=false`. Rely on the provider's encrypted snapshots. |
| Managed SQLite-replacement (Turso, LiteFS Cloud) | Set `BACKUP_ENABLED=false`. Rely on the provider. |

Running BLB backups *and* a managed-snapshot policy doubles complexity without
adding recovery capability. Pick one path per environment.

## Encryption tier selection

`blb:db:backup` selects an encryption mode from `config('backup.encryption.mode')`.

| Mode | Use when | Implemented |
|---|---|---|
| `none` | Single-tenant, no sensitive data; the storage disk's access controls are the security boundary. | Yes |
| `passphrase` (default) | Solo developers and small teams. The passphrase lives in a password manager. No key file to manage. | Yes |
| `age-recipients` | Multi-operator teams with an escrow key. App server holds public keys only. | Reserved (Phase 4) |
| `kms` | Cloud deployments that prefer no human key custody. | Reserved (Phase 4) |

Selecting `none` is explicit. The command prints a warning at run time so the
choice is never silent. Do not use `none` if the database holds user PII,
provider credentials, AI runtime secrets, or anything regulated.

### Passphrase storage (default tier)

The passphrase is read from the env var named in
`backup.encryption.passphrase_env` (default `BACKUP_PASSPHRASE`).

- Generate a strong passphrase: 30+ characters, random.
- Store it in your password manager (1Password, Bitwarden, Keeper, ...) under
  an entry named "BLB backup — {environment}".
- Inject it into the scheduler/systemd unit/container that runs
  `php artisan blb:db:backup`. The passphrase must not be committed to
  `.env` or anywhere in version control.
- For team continuity, store the same entry in a shared vault accessible to
  the on-call rotation.

The artifact uses libsodium internally: Argon2id key derivation +
XChaCha20-Poly1305 secretstream. The format is documented in
[`PassphraseEncryption`](../../app/Base/Database/Services/Backup/Encryption/PassphraseEncryption.php).

## `APP_KEY` is a separate backup

The DB backup is not enough to recover Laravel-encrypted columns (provider
credentials, encrypted settings, etc.). You also need the application's
`APP_KEY`.

- `APP_KEY` lives only in the deployment's secret store; it must never be
  written into the database backup or its manifest.
- Treat `APP_KEY` as a separate, stricter-access secret-backup artifact
  (e.g. an encrypted note in your password manager, sealed in your secret
  manager, or stored in a hardware token).
- A complete restore is: target database <- decrypted DB backup, plus the
  matching `APP_KEY` injected into the restored app's environment.

## Restore drill (recurring)

A backup that has not been restored is only an artifact, not a recovery
capability. Run this drill at least once per quarter, and after any change to
the backup pipeline or the DB schema.

1. Pick a recent successful backup ID from the manifest list.
2. Provision a fresh restore target:
   - Postgres: `CREATE DATABASE blb_restore_drill_YYYYMMDD;`
   - SQLite: pick a path like `/tmp/blb-restore-drill-YYYYMMDD.sqlite`.
3. Set the same `BACKUP_PASSPHRASE` (or other tier credentials) you used for
   the original backup.
4. Run `php artisan blb:db:restore --backup={ID} --target={name-or-path}`.
5. Smoke checks against the restored target:
   - `php artisan migrate:status` — no unexpected pending migrations.
   - Critical row counts for `users`, `companies`, settings, AI tables.
   - Framework primitives: authz roles populated, base seeds present.
6. Discard the restore database / file. Record date and result in your
   operations journal.

The restore command refuses to write into the configured application database
unless `backup.restore.allow_current_database=true` is set explicitly. Do not
flip that flag during a drill — promote a restored database by reconfiguring
`DATABASE_URL` (or equivalent), not by overwriting in place.

## Retention and pruning

`blb:db:backup --prune` (and the scheduler job below) deletes artifacts older
than `backup.retention.keep_days` while always preserving the newest
`backup.retention.keep_count`. Defaults: 30 days, 7 newest.

A daily schedule is appropriate for most deployments:

```text
0 2 * * *  php artisan blb:db:backup --prune
```

For per-deployment policies, run multiple schedule entries with different
`backup.path_prefix` values (e.g. one for hourly retention with a small
window, one for daily long-tail).

## Key rotation per tier

| Tier | Rotation procedure |
|---|---|
| `none` | No keys to rotate. Rotate only the storage credentials on access changes. |
| `passphrase` | Generate a new passphrase, update the password manager and the deployment's secret. New backups use the new passphrase; older backups remain on the previous passphrase until they expire under retention. Keep the previous passphrase in the password manager until the last artifact encrypted with it has been pruned. |
| `age-recipients` | Add the new recipient to `backup.encryption.recipients`. Re-encrypt or expire previous-recipient artifacts on a schedule. (Phase 4.) |
| `kms` | Rotate the KMS key in the cloud console; KMS handles re-wrap of any data keys at rest if used. (Phase 4.) |

Always document the rotation date in your operations journal so a future
operator can map an artifact's encryption metadata to the active credential at
that time.

## Suspected compromise

If you suspect that a backup artifact, the storage bucket, or the
encryption credential has been exposed:

1. **Stop the bleeding.** Rotate storage credentials and revoke any tokens
   that could read the backup disk.
2. **Treat all reachable artifacts as compromised.** With `passphrase`, an
   attacker with the artifact and the passphrase can recover plaintext data.
3. **Rotate the encryption credential** (passphrase / age recipient / KMS
   key) before producing the next backup.
4. **Treat the data as exposed** for compliance/notification purposes if the
   attacker plausibly held both the artifact and the credential.
5. **Audit access logs** on the storage backend (S3 access logs, local
   syslog) to bound the exposure window.
6. **File an incident record** in your operations journal with the affected
   artifact IDs, time window, response actions, and follow-ups.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `Backup decryption failed: Authentication failed; wrong passphrase or tampered artifact` | Passphrase mismatch, or artifact corrupted in transit. | Re-check `BACKUP_PASSPHRASE`. If the SHA-256 in the manifest matches the artifact, the passphrase is wrong; if it doesn't match, the artifact is corrupt — restore from a different backup ID. |
| `Required tool not available: pg_dump` | `pg_dump` is missing on the host running the backup. | `apt-get install postgresql-client` on Debian/Ubuntu, or the equivalent for your OS. The version should match (or exceed) the server major version. |
| `Cannot back up an in-memory SQLite database (:memory:)` | The active connection points at `:memory:`. | Configure a file-based SQLite database for any environment that needs backups. |
| `Refusing to restore over the current application database.` | `--target` matches the configured app DB. | Use a different target name or path. Promote a restored DB by reconfiguring the connection, not by flipping `backup.restore.allow_current_database`. |
| `Backup is disabled` from `blb:db:backup` | `backup.enabled` is `false`. | Intended on managed-DB deployments; otherwise set `BACKUP_ENABLED=true`. |

## References

- Plan and design: [../plans/database-backup-security.md](../plans/database-backup-security.md)
- Database module guide: [../../app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md)
- Source: [`app/Base/Database/Services/Backup/`](../../app/Base/Database/Services/Backup/)
