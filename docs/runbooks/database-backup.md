# Database Backup Runbook

**Document Type:** Operational runbook
**Scope:** Self-hosted BLB deployments using `blb:db:backup`
**Last Updated:** 2026-07-23

This runbook covers how to choose an encryption tier, how the artifact format
works, how to run restore drills, key rotation, and what to do on suspected
compromise. The architecture decisions live in
[../plans/database-backup-security.md](../plans/database-backup-security.md);
this document is the operator-facing companion.

> ⚠️ **Critical**: `app-key` mode backups are irrecoverable without the APP_KEY
> that encrypted them. Back up your APP_KEY independently of the database backup.
> A restored database without its matching APP_KEY cannot decrypt Laravel-encrypted
> columns, auth tokens, or any other APP_KEY-derived secrets.

## When to use BLB backups, and when not to

| Deployment shape | Recommendation |
|---|---|
| Self-hosted Postgres | Use `blb:db:backup` on a schedule. |
| Self-hosted SQLite | Use `blb:db:backup` on a schedule. |
| Managed Postgres (RDS, Cloud SQL, Neon, Supabase, DigitalOcean) | Turn off **Application backups** in Administration → System → Database → Backups. Rely on the provider's encrypted snapshots. |
| Managed SQLite-replacement (Turso, LiteFS Cloud) | Turn off **Application backups** in Administration → System → Database → Backups. Rely on the provider. |

Running BLB backups *and* a managed-snapshot policy doubles complexity without
adding recovery capability. Pick one path per environment.

## Encryption tier selection

`blb:db:backup` selects its encryption mode from the global
`backup.encryption.mode` runtime setting. Its definition owns the `app-key`
default; the Backups page saves an override or restores the declared default.

| Mode | Use when | Implemented |
|---|---|---|
| `none` | Single-tenant, no sensitive data; the storage disk's access controls are the security boundary. | Yes |
| `app-key` (default) | All deployments. Envelope encryption keyed from APP_KEY using HKDF-SHA-256 + XChaCha20-Poly1305. No separate passphrase required. | Yes |
| `age-recipients` | Multi-operator teams with an escrow key. App server holds public keys only. | Reserved (Phase 4) |
| `kms` | Cloud deployments that prefer no human key custody. | Reserved (Phase 4) |

Selecting `none` is explicit. The command prints a warning at run time so the
choice is never silent. Do not use `none` if the database holds user PII,
provider credentials, AI runtime secrets, or anything regulated.

### APP_KEY as the encryption credential (`app-key` mode)

The `app-key` mode derives an encryption master key (KEK) from the application's
`APP_KEY` using HKDF-SHA-256. A fresh per-artifact data encryption key (DEK) is
generated and wrapped under the KEK. The wrapped DEK and a KEK fingerprint (8 bytes,
non-secret) are stored in the sidecar manifest.

**Backup your APP_KEY separately** — store it in your secret manager, password manager,
or other secure out-of-band store alongside the backup artifacts. If you lose the APP_KEY,
all `app-key`-mode backups encrypted under it are permanently irrecoverable.

The artifact format uses libsodium internally: secretstream XChaCha20-Poly1305 for
the artifact, secretbox for the DEK wrap. The format is documented in
[`AppKeyEncryption`](../../app/Base/Database/Services/Backup/Encryption/AppKeyEncryption.php).

## `APP_KEY` is also needed for a complete restore

The DB backup is not enough to recover Laravel-encrypted columns (provider
credentials, encrypted settings, etc.). You also need the application's `APP_KEY`.

- `APP_KEY` lives only in the deployment's secret store; it must never be
  written into the database backup or its manifest.
- Treat `APP_KEY` as a separate, stricter-access secret-backup artifact
  (e.g. an encrypted note in your password manager, sealed in your secret
  manager, or stored in a hardware token).
- A complete restore is: target database ← decrypted DB backup, plus the
  matching `APP_KEY` injected into the restored app's environment.

## Restore drill (recurring)

A backup that has not been restored is only an artifact, not a recovery
capability. Run this drill at least once per quarter, and after any change to
the backup pipeline or the DB schema.

1. Pick a recent successful backup ID from the manifest list.
2. Provision a fresh restore target:
   - Postgres: `CREATE DATABASE blb_restore_drill_YYYYMMDD;`
   - SQLite: pick a path like `/tmp/blb-restore-drill-YYYYMMDD.sqlite`.
3. Run the restore command:
   ```
   php artisan blb:db:backup:restore --backup-id={id} --target={path|dbname}
   ```
   The restore command reads the manifest, unwraps the DEK using the current APP_KEY,
   decrypts the artifact, and restores to the target. For `none` mode, proceed directly
   to step 4 without decryption.
4. Restore the plaintext artifact (if using `none` mode or after manual decryption):
   - **Postgres**: `pg_restore --no-owner --no-privileges --clean --if-exists --host={host} --port={port} --username={user} --dbname=blb_restore_drill_YYYYMMDD {artifact}`
   - **SQLite**: `cp {artifact} /tmp/blb-restore-drill-YYYYMMDD.sqlite`
5. Smoke checks against the restored target:
   - `php artisan migrate:status` (pointed at the restored DB) — no unexpected pending migrations.
   - Critical row counts for `users`, `companies`, settings, AI tables.
   - Framework primitives: authz roles populated, base seeds present.
6. Discard the restore database / file. Record date and result in your
   operations journal.

Always restore into a fresh, non-production target. Never restore over the live
application database directly — promote a restored database by reconfiguring
`DATABASE_URL` (or equivalent) and restarting the application.

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

## Key rotation (`app-key` mode)

### Safe rotation via `blb:key:rotate`

Use `blb:key:rotate` — **not** `php artisan key:generate` — to rotate APP_KEY on
deployments that use `app-key` backup encryption.

```bash
php artisan blb:key:rotate
```

This command:
1. Generates a new 32-byte random key and writes it to `.env`.
2. Updates the running process's `config('app.key')`.
3. Re-wraps every `app-key` manifest's DEK under the new KEK (`blb:db:backup:rekey --old-key=... --commit`).
4. Reports any stuck manifests (manifests that could not be re-wrapped).

**Before rotating:**
- Ensure you have the current APP_KEY stored in your secret manager.
- Run in a maintenance window or during low traffic — the process takes a few seconds per manifest.

**If rotation fails mid-way:**
- The command prints the old APP_KEY to stderr for manual recovery.
- Re-run: `php artisan blb:db:backup:rekey --old-key="<old_key>" --commit`

### Manual rotation (after a bare `key:generate`)

If you ran `php artisan key:generate` directly, the manifest KEK fingerprints no
longer match the new APP_KEY. `blb:db:backup` will refuse to run until you resolve this.

Run:
```bash
php artisan blb:db:backup:rekey --old-key="base64:<old_key>" --commit
```

The `--old-key` value is the previous APP_KEY (the full `base64:...` string or
the raw 32 bytes in base64). The command is idempotent — re-running it with the
same inputs is safe.

### Rotation drill (quarterly)

Run this drill alongside the restore drill to verify the rotation procedure works:

1. Record the current APP_KEY from `.env` or your secret manager.
2. Run: `php artisan blb:key:rotate`
3. Verify all manifests have the new fingerprint: inspect any manifest's `kek_fingerprint` field.
4. Run a restore drill with a post-rotation backup to confirm decryption works end-to-end.
5. Record the rotation date in your operations journal.

## Key rotation (other tiers)

| Tier | Rotation procedure |
|---|---|
| `none` | No keys to rotate. Rotate only the storage credentials on access changes. |
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
2. **Treat all reachable artifacts as compromised.** With `app-key` mode, an
   attacker with both the artifact and the APP_KEY can recover plaintext data.
3. **Rotate APP_KEY** using `blb:key:rotate` before producing the next backup.
4. **Treat the data as exposed** for compliance/notification purposes if the
   attacker plausibly held both the artifact and the credential.
5. **Audit access logs** on the storage backend (S3 access logs, local
   syslog) to bound the exposure window.
6. **File an incident record** in your operations journal with the affected
   artifact IDs, time window, response actions, and follow-ups.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `APP_KEY has changed since these backups were created` | APP_KEY was changed (e.g. via bare `key:generate`) without running `blb:key:rotate`. | Run `php artisan blb:db:backup:rekey --old-key="<old_key>" --commit`. |
| `DEK authentication failed; wrong APP_KEY or tampered manifest` | Decryption attempted with wrong APP_KEY, or manifest was tampered. | Check that APP_KEY matches the key that encrypted the artifact. If the SHA-256 in the manifest matches the artifact, the key is wrong; if it doesn't, the artifact is corrupt. |
| `app-key decryption requires manifest context` | `decryptFile()` called without passing the sidecar manifest. | Pass the `Manifest` DTO to the decrypt call. |
| `Required tool not available: pg_dump` | `pg_dump` is missing on the host running the backup. | `apt-get install postgresql-client` on Debian/Ubuntu, or the equivalent for your OS. The version should match (or exceed) the server major version. |
| `Cannot back up an in-memory SQLite database (:memory:)` | The active connection points at `:memory:`. | Configure a file-based SQLite database for any environment that needs backups. |
| Partial / corrupt restore target left on disk | Restore process interrupted. | Delete the partial target file or drop the partial database before retrying. |
| `Backup is disabled` from `blb:db:backup` | `backup.enabled` is `false`. | Intended on managed-DB deployments; otherwise turn on **Application backups** in the Backups page. |

## References

- Plan and design: [../plans/database-backup-security.md](../plans/database-backup-security.md)
- Database module guide: [../../app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md)
- Source: [`app/Base/Database/Services/Backup/`](../../app/Base/Database/Services/Backup/)
