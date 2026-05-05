Status: Phase 7 complete
Last Updated: 2026-05-05
Sources: docs/architecture/database.md; app/Base/Database/AGENTS.md; app/Base/Database/Console/Commands/FreshCommand.php; app/Base/Database/Console/Commands/WipeCommand.php; docs/architecture/settings.md; docs/architecture/ai/agent-model.md; docs/runbooks/database-backup.md
Agents: Codex/GPT-5; Amp/claude-sonnet-4-5; Copilot/claude-sonnet-4-6

# database-backup-security

## Problem Essence

BLB needs a recovery path from database loss or bad migrations. The database can hold sensitive operational data (users, provider credentials, AI runtime metadata, audit trails) — but it can also be a small SQLite file on a single-tenant deployment with no sensitive content. The backup mechanism must cover both: strong protection when the data warrants it, frictionless when it does not.

## Desired Outcome

Operators run one command to produce a backup artifact. **Restore** is a deliberate, manual procedure (runbook-driven CLI steps), not an in-framework `artisan` restore command — that path was intentionally removed to avoid one-click writes to a database.

Encryption is a tier choice, picked at deploy time. **Core Belimbing** ships and maintains only:

- **none** — for very small deployments with no sensitive data, where the storage layer's own access controls are sufficient.
- **app-key** (default) — envelope encryption keyed from `APP_KEY`; no separate passphrase required.

**Optional modalities** that need multi-recipient keys, cloud KMS, or org-specific crypto (examples: age-style public recipients, AWS or GCP KMS envelopes) are **not** committed work in core. They belong in **licensee extensions** under `extensions/`, registered against a small, documented extension contract (Phase 4). That keeps operational and compliance complexity with the party that needs it.

The same backup command works whether the active database is PostgreSQL or SQLite. Manual restore always targets a **non-current** database or file path; promotion is by reconfiguring the app, not by a framework flag.

## Top-Level Components

1. **Backup Command** — `blb:db:backup`, the only supported way to create a backup. Detects the active driver and delegates.
2. **Backup Writer** — driver-specific. `PostgresWriter` streams `pg_dump --format=custom`. `SqliteWriter` uses SQLite's online backup (`.backup` / `VACUUM INTO`). Both stream into the chosen encryption mode without an intermediate plaintext copy on the local filesystem.
3. **Encryption Mode** — Core: `none` or `passphrase` (config). Extensions may supply additional modes via a registration contract (Phase 4); each mode owns its artifact layout and operator-facing restore notes.
4. **Storage** — a Laravel filesystem disk plus a path prefix. No custom adapter layer; Laravel disks already cover local, S3, and the rest.
5. **Manifest** — a small sidecar JSON with hash, size, driver, encryption mode, and timestamps. Enough to verify and identify; nothing secret.
6. **Manual restore** — Operator follows `docs/runbooks/database-backup.md` to decrypt (if applicable) and import into a **non-current** Postgres database or SQLite file, then run smoke checks. No `blb:db:restore` in core.

## Design Decisions

### Encryption is a tier choice, not a mandate

Deployments pick one mode:

- **`none`** — artifact is written compressed but plaintext. Intended for small single-tenant deployments with no sensitive content, where the storage disk's access controls are the security boundary. The doc states plainly: "the file is the data; treat it accordingly." Selecting `none` is explicit (`backup.encryption.mode=none`) and the command prints a warning at run time so it is never accidental.
- **`passphrase`** (removed in Phase 7) — artifact was encrypted using **libsodium** (Argon2id key derivation and XChaCha20-Poly1305 secretstream). The passphrase was read from `BACKUP_PASSPHRASE`. Removed under destructive evolution: the class is deleted, existing passphrase-mode artifacts are no longer decryptable in-app, and operators must re-take backups under `app-key` post-upgrade.
- **`app-key`** (default, shipped in Phase 7) — envelope encryption keyed from `APP_KEY`. No operator-managed passphrase.

**Examples of needs core does not implement:** multi-recipient or escrow-friendly encryption; cloud KMS with IAM-bound encrypt/decrypt. Envelope encryption keyed from `APP_KEY` is **the Phase 7 default** and eliminates the `BACKUP_PASSPHRASE` env var entirely. Those are **extension-only** (Phase 4 documents how to plug them in). Rationale: KMS and recipient workflows differ by cloud, org policy, and SDK surface; shipping them in core would drag Belimbing into long-term security and compliance ownership for modes most installs never use.

### Extension-supplied encryption (planned contract)

Extensions under `extensions/{owner}/{module}/` may register additional mode names (factories) so `backup.encryption.mode` can select them. The contract (Phase 4 deliverable) should specify at minimum: a **stable `encryption_mode` string** written to the manifest; **no extra plaintext artifacts** on local disk beyond what the mode strictly needs (same bar as core); **configuration ownership** (optional `backup.encryption.*` keys are extension-defined — core may list inert placeholders for documentation only); **vendor-prefixed mode names** (e.g. `ext-acme-kms`) to avoid colliding with future core keywords; and **runbook hooks** (extension authors document restore and rotation for their modality). Core continues to validate and implement only `none` and `app-key`.

### Backups support both PostgreSQL and SQLite

The driver of the active connection determines the writer:

- **PostgreSQL** — `pg_dump --format=custom`, streamed.
- **SQLite** — SQLite online backup, producing a consistent snapshot without locking writers, then streamed.

The backup command does not assume a single driver. The storage filename does not bake driver-specific extensions; the manifest records the driver explicitly.

### Managed databases use provider snapshots

Deployments on managed Postgres (RDS, Cloud SQL, Neon, Supabase, DigitalOcean, etc.) or managed SQLite-replacements (Turso, LiteFS Cloud) should set `backup.enabled=false` and rely on the provider's snapshot policy. BLB's backup command is the path for self-hosters. The runbook documents this escape hatch so operators do not run two overlapping backup systems by accident.

### No plaintext on the storage disk

The configured backup disk never receives plaintext, regardless of mode. Encryption runs before any byte is uploaded, and a failed run leaves either no artifact or a partial artifact that cleanup removes from the disk.

A short-lived plaintext copy *does* exist locally during the dump→encrypt step: drivers that lack a streaming dump API (notably SQLite's `VACUUM INTO`, which writes to a file path) require a local temp file. Those temps are created with `tempnam(sys_get_temp_dir(), …)`, `chmod`-ed to `0600`, and unlinked in a `finally` block regardless of outcome. The threat model accepts this — local temp on the backup host, owned by the backup process, is a much smaller surface than plaintext on shared storage.

### Backups are disaster-recovery, full-database

Backups include all tables. Table-stability remains a migration safeguard, not a backup-scope filter. The backup command never calls `db:wipe`, `migrate:reset`, or any destructive migration command.

### Restore targets a non-current database

Restore goes into a fresh target — a new Postgres database or a new SQLite file path — never the configured application database. The check is driver-aware: Postgres compares connection name and database name; SQLite compares the resolved file path. Promotion happens deliberately by reconfiguring the connection, not via a flag.

### Retention is simple

Two knobs, applied by a `--prune` flag on the backup command and a scheduler entry:

- `keep_days` — delete artifacts older than this.
- `keep_count` — always keep at least this many of the most recent, regardless of age.

No hourly/daily/weekly/monthly tiers. Deployments that need finer policy can run multiple schedule entries with different prefixes.

### Envelope encryption keyed from APP_KEY (Phase 7 target)

The `passphrase` mode requires operators to manage a separate `BACKUP_PASSPHRASE` secret — a UX burden that leads to forgotten keys and locked-out backups. The Phase 7 `app-key` mode removes this entirely using **envelope encryption**:

1. At backup time: generate a random 32-byte **data encryption key (DEK)** — ephemeral, unique per artifact.
2. Encrypt the artifact with the DEK using XChaCha20-Poly1305 secretstream (same as today). The 24-byte secretstream header is written as the first bytes of the artifact, as in the current passphrase pipeline.
3. Derive a **key encryption key (KEK)** from `APP_KEY` via HKDF-SHA-256 with a fixed `backup-artifact-kek` info label. `APP_KEY` is decoded from its `base64:` prefix form to 32 raw bytes before HKDF; an `APP_KEY` that does not decode to 32 bytes fails preflight.
4. Wrap the DEK: `sodium_crypto_secretbox(DEK, nonce, KEK)` → produces a 48-byte wrapped key (32-byte DEK + 16-byte Poly1305 MAC).
5. Store the wrapped DEK + nonce in the manifest (not secret — without `APP_KEY`, it is useless).

**APP_KEY rotation** becomes safe: a `blb:db:backup:rekey` command reads each manifest, decrypts the wrapped DEK with the old key, re-encrypts it with the new key, and writes the manifest back. The artifact bytes are never touched. This is a fast metadata-only sweep, not a re-encryption of multi-GB files.

**KEK fingerprint — detecting key drift.** The manifest stores a short, non-secret tag derived from the KEK: `hash_hkdf('sha256', KEK, 8, 'blb-backup-kek-fp-v1')` → base64 (11 chars). This contains no key material but is deterministic from `APP_KEY`. On every `blb:db:backup` run (and `--dry-run`), the command computes the fingerprint from the current `APP_KEY` and compares it to existing **`app-key`-mode** manifests on the configured disk. Manifests with `encryption_mode` other than `app-key` (e.g. `none`, extension modes) are skipped. If any `app-key` manifest disagrees, the command prints a warning listing the affected backup IDs and refuses to continue until `blb:key:rotate` (or `blb:db:backup:rekey`) has been run. `blb:db:backup:rekey` updates the fingerprint on each manifest it re-wraps.

**`blb:key:rotate` — safe, sequenced rotation.** Operators must use `blb:key:rotate` rather than `php artisan key:generate` directly. The command: (1) captures the current `APP_KEY` as the old key, (2) generates and writes a new `APP_KEY` via `key:generate --force`, (3) immediately calls `blb:db:backup:rekey --old-key={captured} --commit`. If step 3 fails, the command prints the old key so the operator can re-run rekey manually. To prevent accidental lock-outs, `php artisan key:generate` is overridden in BLB: when `APP_KEY` is already set, it refuses with a pointer to `blb:key:rotate`; when `APP_KEY` is empty (fresh install / CI bootstrap), it delegates to Laravel's stock implementation in-process so first-run setup still works. The fingerprint mismatch check on subsequent backup runs remains as a second line of defense for any rotation that bypasses the override.

**Rekey idempotency and partial-failure recovery.** `blb:db:backup:rekey` is per-manifest idempotent. For each `app-key` manifest it: (1) compares the manifest's `kek_fingerprint` to the current KEK's fingerprint and **skips** if they match (already rewrapped); (2) otherwise tries to unwrap the DEK with the current KEK first, falling back to the `--old-key` KEK if supplied; (3) rewraps under the current KEK with a fresh nonce and updates the fingerprint. If `--old-key` is omitted and a manifest's fingerprint matches neither the current KEK nor (no fallback supplied), the command lists the affected manifests and exits non-zero, instructing the operator to re-run with `--old-key=`. This makes a crashed rotation safe to re-run: already-rewrapped manifests are detected and skipped.

**What this removes from the operator surface:** `BACKUP_PASSPHRASE` env var; `backup.encryption.passphrase_env` config key; the `passphrase` mode registration; the preflight UI warning about missing passphrase. Zero new env vars introduced.

**Manifest additions for `app-key` mode:**
- `wrapped_dek` — base64-encoded `sodium_crypto_secretbox` output (DEK encrypted under KEK); 48 raw bytes
- `dek_nonce` — base64-encoded 24-byte nonce used when wrapping
- `kek_fingerprint` — base64-encoded 8-byte HKDF tag derived from the KEK; not secret; used to detect APP_KEY drift
- `encryption_mode` remains `app-key` as the stable identifier

If a future change to the wrap algorithm or KDF parameters is needed, introduce a new mode name (`app-key-v2`) rather than mutating the `app-key` contract; the registry resolves modes by string.

### Manifest carries facts, not secrets

Manifest fields:

- `backup_id`
- `driver` (`pgsql` | `sqlite`)
- `encryption_mode` — stable string: `none` or `app-key` for core; any extension-registered identifier otherwise (see Phase 4)
- `started_at`, `finished_at`
- `size_bytes`
- `sha256` of the artifact
- `app_environment` label
- `trigger` (command source / scheduled / actor id)
- `status` and a safe error message on failure
- `wrapped_dek` (48 raw bytes, base64) + `dek_nonce` (24 raw bytes, base64) + `kek_fingerprint` (8 raw bytes, base64) — Phase 7 `app-key` mode only. Wrapped DEK is not useful without `APP_KEY`; fingerprint is non-secret but tied to the current key.

No raw passphrases, no plaintext key material, no presigned URLs, no row content.

### `APP_KEY` is backed up separately

When Laravel encrypts settings (provider credentials, etc.), the database alone is not enough to recover them — `APP_KEY` is required. The runbook treats `APP_KEY` as a separate secret-backup artifact with stricter access. The DB backup storage must not contain `APP_KEY`.

### Operational backup settings are stored in `base_settings`

The five operational knobs — `enabled`, `disk`, `path_prefix`, `keep_days`, `keep_count` — are declared as an editable settings group in `app/Base/Database/Config/settings.php` (scope: global). They resolve through the standard `SettingsService` cascade; `config/backup.php` values remain the fallback layer, so existing deployments need no migration.

Settings that stay in `config/backup.php` / `.env` and are **not** operator-editable via the UI:
- `backup.encryption.mode` — changing encryption mid-stream silently orphans existing artifacts; must be a deliberate deploy-time decision. Phase 7 default changes this from `passphrase` to `app-key`.
- `backup.encryption.passphrase_env` — references an env var name; meaningless to store in DB. Removed from the default path in Phase 7.
- Optional keys such as recipient lists or KMS key identifiers — meaningful only for extension-registered modes; extensions document their own env and config shape.

Changing `backup.disk` after artifacts exist does not move old files; the previous disk must be consulted manually. The settings form should carry a help note to that effect.

## Public Contract

### Commands

- `php artisan blb:db:backup` — creates a backup using the configured encryption mode and disk.
- `php artisan blb:db:backup --dry-run` — verifies driver tooling, encryption configuration, and disk write access without producing an artifact.
- `php artisan blb:db:backup --local` — writes to a configured local disk regardless of the default disk; for development and emergencies.
- `php artisan blb:db:backup --prune` — runs the backup, then deletes artifacts that exceed `keep_days` while preserving the most recent `keep_count`.
- `php artisan blb:key:rotate` — safe `APP_KEY` rotation: captures current key, generates a new one, then immediately re-wraps all backup manifests via `blb:db:backup:rekey --commit`. Use instead of `key:generate` when backups exist.
- `php artisan blb:db:backup:rekey` — re-wraps manifest DEKs under a new KEK derived from the current `APP_KEY`. Accepts `--old-key=base64` when the key has already changed. Dry-run by default; `--commit` to write.

Restore is **not** an Artisan command in core; see `docs/runbooks/database-backup.md`.

### Configuration

- `backup.enabled` — top-level on/off; set `false` on managed-DB deployments.
- `backup.disk` — Laravel filesystem disk name.
- `backup.path_prefix` — path under the disk (e.g. `backups/`).
- `backup.encryption.mode` — `none` or `app-key` in core; additional values when an extension registers a mode (Phase 4).
- `backup.encryption.recipients` / `backup.encryption.kms_key` — optional; reserved for **extension** modes that choose to read them. Core ignores them.
- `backup.retention.keep_days`
- `backup.retention.keep_count`

Storage credentials reuse the existing Laravel disk configuration. Private restore keys are never stored in the database.

### Storage layout

`{path_prefix}/{environment}/{YYYYMMDD-HHMMSS}-{backup_id}.bak[.suffix]`
`{path_prefix}/{environment}/{YYYYMMDD-HHMMSS}-{backup_id}.manifest.json`

Core `app-key` mode uses the `.enc` suffix on the artifact; `none` uses plain `.bak`. Extension modes choose their own suffix as part of their contract. Driver lives in the manifest, not the filename.

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

### Phase 2 — Restore _(deprecated — removed)_

> **Deprecated.** The `blb:db:restore` command and all supporting code (`RestoreCommand`, `StagedDump`, `stageDecryptedDump()`, `restore()` / `isCurrentDatabase()` on both writers) were removed. Restore is a manual CLI operation documented in `docs/runbooks/database-backup.md`. The admin page help slot now describes the manual steps. Deliberate friction is a feature — operators should follow the runbook checklist rather than clicking a button that writes to a database.

- [x] Add `blb:db:restore` command with driver detection {Amp/claude-sonnet-4-5}
- [x] Postgres path: restore into a named non-current database via `pg_restore --dbname={target} --clean --if-exists` {Amp/claude-sonnet-4-5}
- [x] SQLite path: restore into a target file path that is not the active DB file {Amp/claude-sonnet-4-5}
- [x] Refuse current database in both drivers; covered by tests {Amp/claude-sonnet-4-5}
- [x] Operator output points to the smoke checks (`migrate:status`, framework primitive presence, critical table counts) to be run post-restore. Automated post-restore smoke runners are deferred — they belong with the connection promotion workflow, not the restore command. {Amp/claude-sonnet-4-5}
- [x] **Removed** all restore command code, `StagedDump`, restore interface methods, restore tests, `backup.restore` config section, and `backup.restore.allow_current_database` config key {Copilot/claude-sonnet-4-6}

### Phase 3 — Retention and scheduling

Goal: don't accumulate forever; don't fail silently.

- [x] Implement `--prune` with `keep_days` + `keep_count` semantics; covered by `RetentionPolicyTest` and a `BackupCommandTest` round-trip with a backdated manifest {Amp/claude-sonnet-4-5}
- [ ] Add a scheduler entry for backup (with `--prune`) — left for the deployment to wire into its own `routes/console.php` or cron, since BLB does not yet ship a global scheduler config; a recommendation is in the runbook.
- [x] Failed-backup alerting flows through the existing audit/log channels; no admin UI in this phase. {Amp/claude-sonnet-4-5}

### Phase 4 — Extension encryption modalities

Goal: teams that need more than **passphrase** (multi-recipient, KMS, org-specific wrappers) carry that logic in **extensions**, not in Belimbing core. Core keeps the backup pipeline, manifest, retention, and the two built-in modes only.

- [x] **Extension hook** — `EncryptionModeRegistry` singleton; extensions call `register(mode, factory)` from `ServiceProvider::boot()`. Registry is the only resolution path — closed `match` is gone. {Copilot/claude-sonnet-4-6}
- [x] **Document the registration contract** — vendor-prefixed names, stable manifest identifier, no-extra-plaintext bar, fail-closed `ensureReady()`, configuration ownership, restore/rotation doc ownership. Documented in `app/Base/Database/AGENTS.md`. {Copilot/claude-sonnet-4-6}
- [x] **Naming guidance** — `ext-{vendor}-{descriptor}` required; `none`, `passphrase`, and unprefixed strings reserved for core. {Copilot/claude-sonnet-4-6}
- [x] **Configuration** — extension-owned `backup.encryption.*` sub-keys; core does not interpret or validate them. Documented in `app/Base/Database/AGENTS.md`. {Copilot/claude-sonnet-4-6}
- [x] **Tier-selection guidance** — passphrase vs. extension modes documented in `app/Base/Database/AGENTS.md`; extension authors own operational complexity and dependency risk. {Copilot/claude-sonnet-4-6}
- [x] **Explicit non-goals for core** — no bundled `age-recipients` or `kms` in `app/Base/Database`; those are examples of what extensions might register. Enforced by the registry design. {Copilot/claude-sonnet-4-6}
- [x] **Test** — `BackupCommandTest`: extension-registered mode (`ext-test-noop`) resolves via `--dry-run` without error. {Copilot/claude-sonnet-4-6}

### Phase 5 — Runbook

Goal: capture operational truth in one document, not as framework features.

- [x] Write `docs/runbooks/database-backup.md` covering: tier selection, passphrase storage, restore drill steps, `APP_KEY` separate backup, key rotation per tier, incident response if storage or key material is suspected compromised {Amp/claude-sonnet-4-5}
- [x] Document the managed-DB escape hatch (`backup.enabled=false`, rely on provider snapshots) {Amp/claude-sonnet-4-5}
- [x] Reference the runbook from the docs index (`docs/AGENTS.md`) {Amp/claude-sonnet-4-5}

### Phase 6 — Operator-editable backup settings

Goal: let operators change operational knobs from the admin UI without touching `.env` or config files.

**Settings to expose** (all global scope, all backed by `config/backup.*` fallback):

| Setting key | Type | Label | Notes |
|-------------|------|-------|-------|
| `backup.enabled` | boolean | Enabled | Off on managed-DB deployments |
| `backup.disk` | text | Disk | Laravel filesystem disk name; help note warns old artifacts stay on the previous disk |
| `backup.path_prefix` | text | Path Prefix | Directory within the disk |
| `backup.retention.keep_days` | integer | Keep Days | 0 = no age-based pruning |
| `backup.retention.keep_count` | integer | Keep Count | Minimum recent backups to always retain |

**Implementation tasks:**

- [x] Add `app/Base/Database/Config/settings.php` declaring the `backup_storage` editable group with the five fields above {Copilot/claude-sonnet-4-6}
- [x] Update `Backups\Index` to read these five keys via `SettingsService::get()` (with `config()` value as default) via a private `resolveConfig()` helper, instead of reading `config()` directly {Copilot/claude-sonnet-4-6}
- [x] Add help note on `backup.disk` field: "Changing the disk does not migrate existing artifacts. Old backups remain on the previous disk and must be managed manually." {Copilot/claude-sonnet-4-6}
- [x] The backup config display on `admin/system/database-backups` links to the settings group via a conditional "Edit backup settings" link (visible when `admin.settings.manage` capability is held) {Copilot/claude-sonnet-4-6}
- [x] Tests: `BackupsIndexTest` seeds a `base_settings` row for `backup.retention.keep_days` and asserts the UI reflects the DB value over the config fallback {Copilot/claude-sonnet-4-6}

### Phase 7 — APP_KEY envelope encryption (app-key mode)

Goal: eliminate `BACKUP_PASSPHRASE` as an operator-managed secret. Zero new env vars; zero UX friction. **Destructive evolution:** `PassphraseEncryption` is deleted entirely; existing `passphrase`-mode artifacts in storage are no longer decryptable in-app and operators must re-take backups under `app-key` after upgrade. We accept this — backups are recovery snapshots, not historical archives, and the operator path of least friction is "take a fresh one."

**Encryption contract:**
- DEK: `random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)` — 32 bytes, generated fresh per backup
- Artifact encryption: XChaCha20-Poly1305 secretstream over the DEK (same chunked stream as today). The 24-byte secretstream header is the first bytes of the artifact file.
- APP_KEY decoding: strip the `base64:` prefix and base64-decode to 32 raw bytes. An `APP_KEY` that does not decode to 32 bytes fails preflight (`ensureReady()` throws `BackupException`).
- KEK derivation: HKDF-SHA-256 over the 32 raw `APP_KEY` bytes, salt = 32 zero bytes, info = `"blb-backup-kek-v1"`, length = 32 bytes
- DEK wrapping: `sodium_crypto_secretbox(DEK, nonce, KEK)` — nonce is 24 random bytes; output is 32-byte DEK + 16-byte Poly1305 MAC = 48 bytes
- KEK fingerprint: `hash_hkdf('sha256', KEK, 8, 'blb-backup-kek-fp-v1')` — 8 bytes
- Manifest additions: `wrapped_dek` (base64, 48 raw bytes), `dek_nonce` (base64, 24 raw bytes), `kek_fingerprint` (base64, 8 raw bytes)

**Implementation tasks:**
- [x] Add `AppKeyEncryption` class implementing `EncryptionMode`; registered as `app-key` in `ServiceProvider::boot()` {Copilot/claude-sonnet-4-6}
- [x] `AppKeyEncryption::ensureReady()` — decode `APP_KEY` (strip `base64:` prefix, base64-decode); throw `BackupException` if the result is not exactly 32 bytes {Copilot/claude-sonnet-4-6}
- [x] `AppKeyEncryption::encryptFile()` — generate DEK → encrypt artifact stream → derive KEK from decoded `APP_KEY` → wrap DEK → compute `kek_fingerprint` → return wrapped DEK, nonce, and fingerprint for manifest storage {Copilot/claude-sonnet-4-6}
- [x] `AppKeyEncryption::decryptFile()` — accept wrapped DEK + nonce from manifest → derive KEK → unwrap DEK → decrypt artifact stream {Copilot/claude-sonnet-4-6}
- [x] `Manifest` DTO gains optional `wrappedDek`, `dekNonce`, and `kekFingerprint` fields; serialised/deserialised transparently {Copilot/claude-sonnet-4-6}
- [x] `blb:db:backup` preflight (and `--dry-run`): compute current `kek_fingerprint` from live `APP_KEY`; scan manifests on the configured disk where `encryption_mode === 'app-key'` (skip `none` and extension modes); warn and abort if any `app-key` manifest has a mismatched fingerprint — instructs operator to run `blb:key:rotate` {Copilot/claude-sonnet-4-6}
- [x] Change `config/backup.php` default `backup.encryption.mode` from `passphrase` to `app-key` {Copilot/claude-sonnet-4-6}
- [x] **Destructively remove** `PassphraseEncryption` class, its `passphrase` registry entry in `ServiceProvider::boot()`, and any tests covering passphrase-mode encryption/decryption {Copilot/claude-sonnet-4-6}
- [x] Remove `backup.encryption.passphrase_env` config key; remove passphrase preflight UI warning {Copilot/claude-sonnet-4-6}
- [x] Add `blb:db:backup:rekey` command — iterates `app-key` manifests on the configured disk and, per manifest: (a) skip if `kek_fingerprint` already matches the current KEK; (b) try unwrapping with the current KEK, fall back to `--old-key` KEK if supplied; (c) rewrap with a fresh nonce and update `kek_fingerprint`. If a manifest is unwrappable by neither the current KEK nor (no `--old-key` supplied), list the affected manifests and exit non-zero, instructing the operator to re-run with `--old-key=`. Dry-run by default; `--commit` to write. Safe to re-run after a partial failure. {Copilot/claude-sonnet-4-6}
- [x] Add `blb:key:rotate` command — (1) captures current `APP_KEY`, (2) generates a new 32-byte key and writes it to `.env`, (3) updates `config('app.key')` in-process, (4) calls `blb:db:backup:rekey --old-key={captured} --commit`; prints old key on step 4 failure so operator can recover manually {Copilot/claude-sonnet-4-6}
- [x] Update `docs/runbooks/database-backup.md` — **lead with**: losing `APP_KEY` means losing every `app-key`-mode backup. Document `APP_KEY` separate-backup discipline up front. Remove passphrase storage instructions; add APP_KEY rotation drill using `blb:key:rotate`; note that direct `key:generate` is detectable via fingerprint mismatch. {Copilot/claude-sonnet-4-6}
- [x] Tests: all Phase 7 test cases green (31 tests, 79 assertions) {Copilot/claude-sonnet-4-6}
