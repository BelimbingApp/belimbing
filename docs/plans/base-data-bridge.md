# base-data-bridge

Status: Proposed
Last Updated: 2026-07-10
Sources: `docs/brief.md`; `docs/architecture/module-system.md`; `docs/architecture/database.md`; `docs/architecture/settings.md`; `app/Base/Database/AGENTS.md`; `extensions/kiat/investment/AGENTS.md`; `docs/plans/database-backup-security.md`; `docs/runbooks/database-backup.md`
Agents: Codex/GPT-5

## Problem Essence

Belimbing can promote code between development, staging, and production with Git, but it has no governed way to promote selected durable data. Operators therefore copy seed-like settings and expensive-to-rebuild module data manually, which is error-prone, unaudited, difficult to repeat, and especially unsafe when environments contain credentials or user data.

The immediate case is the Kiat Investment research database developed on a laptop on the same LAN. Rebuilding that research on production would discard useful work, while copying the whole development database would also copy unrelated environment state and secrets.

## Desired Outcome

An operator can preview and promote an explicitly declared dataset from one Belimbing instance to another without granting either instance general database access. The destination proves that its code and schema can understand the package, shows inserts, updates, skips, conflicts, and deletions before applying anything, creates a recovery point, and records an immutable audit trail of what was accepted and by whom.

Modules own the meaning and portability rules of their data; Base owns packaging, transport-neutral verification, policy enforcement, execution, and audit. The first complete proof is a laptop-to-production promotion of Kiat Investment research data that preserves relationships and history while excluding credentials, machine-local sessions, operational run history, and other environment-owned state.

## Top-Level Components

### Dataset declarations

Each participating module contributes named, versioned dataset definitions through a Base Database contract and discovery registry. A definition owns its records, dependency order, stable identity, serialization, validation, sensitivity classification, import strategy, and compatibility upgrades. Base never infers portability from a table prefix and never accepts an arbitrary table list from a request.

Datasets are smaller than a database and may be grouped into a module profile. For example, Investment can expose `research`, `portfolio-history`, and `market-cache` separately, then offer a recommended `research-workbench` profile. This keeps high-value authored research distinct from reproducible scraped/cache data and from private brokerage data.

### Transfer package

Export produces an immutable package containing a canonical manifest, dataset payloads, record counts, content hashes, source instance/environment identity, module and dataset versions, schema/migration fingerprint, creation time, expiry, sensitivity summary, and package identifier. Payload records use dataset-defined stable keys and references rather than source database primary keys.

The package is authenticated and confidential independently of the transport. It is signed by the source instance and encrypted to one or more destination instance recipients. Encryption keys are dedicated bridge keys, not `APP_KEY`, database credentials, or user passwords. A destination trusts explicitly paired source identities and rejects modified, expired, replayed, wrongly addressed, or untrusted packages.

### Planner and importer

The destination verifies and decrypts a package into protected temporary storage, runs compatibility and policy checks, and builds a deterministic import plan without mutating domain tables. The plan reports per dataset and record: insert, update, unchanged, skip, conflict, or deletion. Applying requires the exact reviewed package hash and plan hash, runs under a database transaction where the driver and dataset permit it, and is idempotent on retry.

Dataset definitions choose one blessed strategy from a small Base vocabulary: append-only, upsert by stable key, or replace-owned-snapshot. Destructive synchronization is not a generic option. A dataset that genuinely owns a complete snapshot must declare deletion semantics, and production requires a separate explicit destructive confirmation.

### Policy, recovery, and audit

An instance declares its immutable environment role (`development`, `staging`, or `production`) outside the database. Default policy permits promotion only in that order. Reverse or lateral transfer is denied unless deployment configuration explicitly permits the named source, destination, and dataset; production-to-lower-environment export additionally requires a dataset-owned redaction/anonymisation policy.

Before a production apply, Base creates and verifies a database backup using the existing backup service. Import attempts, verification failures, previews, approvals, applies, and results are recorded in a Base-owned bridge ledger with package hashes, identities, actor, dataset versions, counts, timestamps, and errors. Payload values and secrets are not copied into the audit ledger.

### Operator surfaces and transport

The core workflow is available through non-interactive Artisan commands suitable for SSH and deployment automation. A Base administration page later provides package inventory, trust/pairing status, preview, and apply controls behind dedicated capabilities and recent authentication.

Transport is deliberately replaceable and untrusted. Phase 1 uses local files and an SSH-assisted pull/push workflow on the LAN or between hosts; the package remains encrypted and authenticated if copied through shared storage or another channel. A permanently reachable Belimbing-to-Belimbing HTTP endpoint is deferred until a real operational need justifies its attack surface.

## Design Decisions

### Move declared datasets, not databases or seeders

Three options are viable: copy the whole database, rerun seeders, or exchange module-declared datasets. Whole-database copying is fast but couples environments, overwrites destination-owned state, and carries users and secrets. Seeders work for canonical static defaults but are a poor representation of evolving authored research and cannot describe conflicts between two live instances. Declared datasets require an initial contract but preserve module ownership and allow truthful preview, validation, and merge behavior.

Recommend module-declared datasets. Existing production seeders remain the code-owned mechanism for universal defaults; the bridge is for selected instance data. A setting definition may opt a non-secret setting into a dataset, but `base_settings` is never exported wholesale.

### Use packages first, not live database links or a general REST sync API

Direct database links have broad credentials, weak domain validation, and tight network coupling. A live sync API offers convenience but introduces exposed endpoints, distributed conflict behavior, token rotation, retry semantics, and a larger production attack surface. Immutable packages give a reviewable boundary, work across one or several machines, tolerate disconnected instances, and separate transport from trust.

Recommend signed, recipient-encrypted packages with SSH/file transport first. Add an HTTPS transport only as another delivery adapter after the package and import contracts are proven; it must not bypass package verification or destination-side approval.

### Preserve meaning through stable keys, not source IDs

Raw primary-key preservation is easy until the destination already has rows, sequences differ, or records reference shared Core entities. Dataset-defined natural or portable keys make imports idempotent and relationships resolvable. Where a domain lacks a durable key, it should add a UUID/ULID or a truthful composite key before declaring that record portable.

Recommend portable keys plus explicit reference resolvers. The bridge maintains a package-local identity map during planning and apply; it does not make source IDs globally meaningful.

### One-way promotion by default, not automatic bidirectional sync

Automatic multi-master synchronization would require persistent change tracking, conflict-free semantics, deletion tombstones, and domain-specific reconciliation for every participating dataset. The current need is controlled promotion of work, not collaborative offline editing.

Recommend operator-initiated, one-way promotion. Re-exporting an updated dataset and applying it idempotently is supported. Bidirectional continuous sync is outside this design unless a later concrete module proves it necessary.

### Dedicated instance trust, not shared application secrets

Reusing `APP_KEY` would make key rotation and cross-instance trust unsafe and would require sharing a secret that protects unrelated application data. Bearer tokens authenticate a connection but do not keep a copied package verifiable later.

Recommend a dedicated public-key identity per instance: signing keys authenticate sources and encryption recipients protect destinations. Private keys live outside the database with restrictive filesystem permissions or a future KMS adapter. Pairing exchanges fingerprints through an operator-verified out-of-band step; trust is scoped by instance and may be further restricted by environment direction and dataset.

## Public Contract

### Dataset definition promises

A portable dataset definition must provide:

- A globally stable name namespaced to its owning module, a positive integer format version, and a sensitivity class.
- The installed module/version and schema prerequisites that must hold at the destination.
- A deterministic export order and deterministic serialization with stable record keys.
- Reference declarations to other records or prerequisite datasets; no opaque database IDs in the portable contract.
- Validation rules, authorization label, allowed environment directions, default conflict policy, and whether the data is append-only, upsertable, or a complete owned snapshot.
- Optional format upgraders between explicitly supported dataset versions. Unknown versions fail closed; Base never guesses a migration.
- An explicit secret policy. Secret-bearing fields are excluded by default. An opt-in secret transfer must explain the destination use, use bridge encryption, and never serialize framework ciphertext that is bound to the source `APP_KEY`.

Dataset exporters must be side-effect free. Import planning must not mutate domain data, dispatch jobs, send notifications, call external systems, or update source freshness markers. Apply suppresses ordinary per-record external side effects; a dataset may provide one post-commit reconciliation hook that is safe to retry.

### Package and trust promises

- A package is addressed to explicit destination recipient fingerprints. Plaintext packages are forbidden outside automated tests.
- The signed manifest covers every payload hash and all compatibility, identity, policy, and expiry metadata.
- Verification happens before parsing domain payloads; payload extraction is size-limited, path-safe, and kept outside public storage.
- Package identifiers are globally unique. A successfully applied package cannot be applied again; a failed apply may resume only when the dataset contract declares the operation idempotent and the reviewed hashes still match.
- Pairing does not itself authorize import. Destination capabilities, environment policy, dataset policy, compatibility checks, preview, and apply confirmation all remain mandatory.
- Trust records may be stored in the database, but private keys and environment role are deployment configuration. Revoking a source prevents new imports without invalidating historical audit evidence.

### Conflict and apply promises

- `fail` is the default conflict policy when source and destination both differ from the last known imported state or when no safe ancestry is available.
- `source-wins` is allowed only when a dataset explicitly declares the affected fields source-owned. `destination-wins` records skips. Interactive field-by-field merging is deferred until a real dataset needs it.
- Imports do not silently delete destination rows. Snapshot deletions are listed individually or by bounded selector in the preview and require destructive approval in production.
- The destination records the last accepted source package and record fingerprints per dataset so later plans can distinguish unchanged rows from genuine divergence.
- Production apply refuses to start without a fresh verified backup, sufficient temporary disk space, a matching current schema fingerprint, and an exclusive bridge apply lock.

### Initial Investment contract

The pilot dataset is `extensions.kiat.investment/research-workbench`. Its first implementation includes the authored research graph rooted at case companies: company identity and mapping, financial quarters and captured market/research evidence, research notes, valuations, trade-call snapshots and observations, validation snapshots, journal entries, value estimates, and watch triggers. Exact membership and dependency order are asserted by contract tests against the module schema before the first real export.

The pilot excludes:

- `base_settings`, including GlobalWits and MOST credentials.
- Browser/session artifacts under `storage/`, Maybank session files, and all machine-local files.
- Users, roles, company tenancy, schedules, AI runs, operation dispatches, command/run ledgers, and audit infrastructure.
- Maybank holdings/snapshots, portfolio capital entries, dividends, and ignored holdings until the owner deliberately enables a separate `portfolio-history` dataset.
- Reproducible or volatile caches such as radar candidates and stock daily prices by default. They may become separate optional datasets if measured rebuild cost warrants transfer.
- Source-update/freshness observations whose meaning is local to a scraper run, unless the module later proves they are part of the research evidence contract.

Company records use a durable investment-owned portable identity, preferably an added UUID/ULID plus unique Bursa code where present. Child records receive durable portable identities where their existing domain composite key is not sufficient. References are resolved through those identities; production database IDs are never overwritten to match development.

The first production promotion is merge-only: inserts and safe updates, no deletions. Any conflict stops that dataset before mutation and is resolved at the source or by an explicit future conflict policy. After apply, the module runs read-only integrity checks and refreshes derived views/caches without scraping, notifying, scheduling agents, or placing trades.

## Security Model

The threat model includes a stolen package, a malicious or compromised development machine, an attacker replaying an old valid package, accidental import to the wrong environment, archive traversal/decompression bombs, schema confusion, privilege escalation through crafted records, secret leakage, partial apply, and a legitimate operator selecting the wrong scope.

The design assumes that a fully compromised production host or production database administrator can alter production data; the bridge cannot defend against its own trusted runtime. It does reduce blast radius from a compromised lower environment: source trust is explicit and scoped, development cannot initiate a production apply, the destination independently validates every record, and production requires a local privileged actor plus recovery point.

Security invariants are:

- No inbound database credentials and no arbitrary SQL, table names, model classes, PHP objects, or executable hooks arrive in a package.
- Package formats use bounded data encodings, not PHP serialization. Every archive entry, total uncompressed size, dataset count, record count, and scalar length is limited before allocation or insertion.
- Validation uses destination code and authorization policy; a valid source signature proves origin, not safety.
- Import capabilities are separate from backup, migration, settings, and ordinary module-edit capabilities. Export and apply are separate capabilities; production apply requires recent authentication in the web surface.
- Logs and UI show metadata and safe record keys, not secret values or complete sensitive payloads.
- Temporary plaintext is created only when unavoidable, with restrictive permissions, outside served paths, and removed in `finally` paths. Streaming verification/import is preferred.
- Pairing and key rotation are observable. Fingerprints, not raw keys, appear in confirmation prompts and audit records.
- Production never automatically polls and applies data from development. Scheduling may fetch an encrypted package into quarantine, but preview and apply remain destination actions.

## Phases

### Phase 0 — Prove the data boundary

Goal: the Investment pilot has an agreed inventory that distinguishes durable authored research from secrets, environment state, brokerage history, and reproducible caches.

- [ ] Inventory every current `kiat_investment_*` table, `base_settings` key, and relevant storage artifact; assign each to `research-workbench`, a future optional dataset, reproducible/local state, or forbidden secret/identity state.
- [ ] Draw and test the portable dependency graph, including references to Core/Base records; remove or bridge any hidden dependency before export exists.
- [ ] Add durable portable identities or stable composite keys where the current schema has only an auto-increment ID or an ambiguous domain key.
- [ ] Record representative production collision scenarios and the expected insert/update/conflict result for each authored entity.
- [ ] Confirm the source laptop and production checkout have compatible Investment code/migrations before attempting a package; code promotion remains a prerequisite to data promotion.

Validation: schema inventory is complete; contract tests fail when a newly added durable Investment table is left unclassified; no credential or session artifact is classified as portable.

### Phase 1 — Establish Base contracts and deterministic packages

Goal: modules can declare datasets and produce a reproducible, bounded package without transport or destination mutation.

- [ ] Add the Base Database dataset definition contract, registry/discovery seam, dataset/profile metadata values, and domain-specific bridge exceptions.
- [ ] Define the canonical manifest and payload encoding, deterministic ordering, content hashing, limits, expiry, module/schema compatibility metadata, and package identity.
- [ ] Implement dedicated instance signing/encryption key management, fingerprint display, rotation/revocation behavior, and protected key storage outside the database.
- [ ] Implement export authorization and environment-direction policy with fail-closed defaults; make instance role deployment-owned and immutable at runtime.
- [ ] Provide a dry-run export report listing datasets, sensitivity, included/excluded record counts, dependencies, recipient fingerprints, and output size estimate.
- [ ] Add adversarial package tests for tampering, wrong recipient, untrusted signer, expiry, replay metadata, traversal, duplicate paths, excessive expansion, excessive records, invalid encodings, and unsupported versions.

Validation: two test instances exchange and verify an encrypted package; identical source state yields identical payload hashes; private keys, secrets, raw SQL, and PHP-serialized values never enter the artifact.

### Phase 2 — Build destination planning and safe apply

Goal: a destination can explain the exact outcome before mutation and apply the reviewed plan atomically and idempotently.

- [ ] Implement quarantine, signature/decryption verification, compatibility checks, schema fingerprint checks, and protected cleanup.
- [ ] Implement the portable identity map, dependency ordering, insert/update/unchanged/skip/conflict/delete planner, and persisted plan hash.
- [ ] Implement transactional apply, exclusive locking, replay protection, retry rules, side-effect suppression, post-commit reconciliation, and failure cleanup.
- [ ] Integrate a mandatory fresh verified backup before production apply; abort cleanly when backup, capacity, compatibility, or locking preconditions fail.
- [ ] Add the Base bridge ledger and audit integration without retaining domain payloads or secret values.
- [ ] Provide separate export, inspect/verify, plan, apply, trust, and key-management Artisan commands with machine-readable output and explicit non-interactive confirmation flags.
- [ ] Prove cross-driver behavior for SQLite and PostgreSQL, including transaction boundaries, constraint ordering, timestamp precision, JSON normalization, and sequence handling.

Validation: fault injection at every dataset boundary leaves either the old state or the complete new state where atomicity is promised; retry does not duplicate records; changing destination data after preview invalidates the plan; production apply cannot bypass backup or capability checks.

### Phase 3 — Pilot Kiat Investment laptop to production

Goal: production receives the laptop's authored Investment research graph once, without rebuilding it and without importing credentials or environment-owned state.

- [ ] Implement the `research-workbench` definition and explicit exclusion inventory in the Investment module.
- [ ] Add serializers, validators, stable reference resolvers, merge-only conflict rules, and side-effect-free post-import integrity checks for the full agreed research graph.
- [ ] Add module contract tests covering relationship preservation, null/JSON/date/decimal fidelity, existing-row updates, genuine divergence, missing parents, and forbidden datasets/fields.
- [ ] Pair laptop and production using verified fingerprints, with production trusting only the laptop's signing identity for the named Investment dataset and forward direction.
- [ ] Create and retain a production recovery backup, export to the production recipient, transfer over SSH/LAN, verify, preview, and manually reconcile every reported conflict before apply.
- [ ] Apply once under the production operator, verify row counts and representative company histories through the Investment UI, and run domain integrity checks.
- [ ] Record package/plan hashes, commands, backup reference, counts, exclusions, conflicts, result, and rollback procedure in the bridge ledger and an operator runbook example; do not commit real package contents or sensitive record data.

Affected pages: `/investment`, `/investment/company-research`, representative `/investment/company-research/{slug}`, `/investment/journal`

Validation: all selected laptop research is present and navigable on production; source and destination portable fingerprints match; credentials, sessions, brokerage/private portfolio data, operational histories, and caches remain production-owned or absent; a second import plans as entirely unchanged.

### Phase 4 — Add the administration workflow

Goal: an authorized operator can manage trust and perform the same safe workflow without shell fluency, while production keeps deliberate approval friction.

- [ ] Add Administration → System → Database → Data Bridge using Core UI conventions, with Incoming, History, Datasets, and Trusted Instances views.
- [ ] Show source/destination environment, trust fingerprint, expiry, sensitivity, compatibility, backup status, per-dataset counts, conflicts, exclusions, destructive actions, and package/plan hashes in plain operator language.
- [ ] Require dedicated capabilities and recent authentication for trust changes and production apply; make export, preview, apply, and destructive apply independently authorizable.
- [ ] Stream uploads into quarantine with server-side limits; never place package or plaintext payloads in a public/media disk.
- [ ] Make conflict and failure states actionable without exposing sensitive values; link successful applies to their audit record and recovery backup.
- [ ] Add browser tests for keyboard access, focus/error handling, large previews, expired plans, concurrent changes, and accidental double submission.

Affected pages: `/admin/system/database/data-bridge`

Validation: the web flow produces the same plan/apply hashes and policy decisions as CLI; an unauthorized user cannot enumerate packages, trust records, or dataset metadata; production apply cannot occur from upload alone.

### Phase 5 — Generalize only from proven adopters

Goal: other modules share selected data by implementing the same narrow contract, without Base learning their schemas or weakening the pilot's guarantees.

- [ ] Add opt-in portability metadata to Settings definitions for non-secret code-like/reference settings; keep undeclared and encrypted settings excluded.
- [ ] Pilot one Base/Core reference dataset such as deliberately selected non-secret system settings, with scope mapping for global/company-owned values and explicit destination ownership.
- [ ] Pilot a second business module whose conflict behavior differs from Investment and refine the strategy vocabulary only where both use cases justify it.
- [ ] Add an SSH transport helper or quarantined remote fetch adapter that moves packages but cannot verify, plan, or apply them on the source's authority.
- [ ] Measure package sizes and import time; add chunking/streaming checkpoints without relaxing whole-package manifest verification.
- [ ] Decide from operational evidence whether an authenticated HTTPS transport is warranted. If built, use mutual instance authentication, strict rate/size limits, quarantine-only receipt, and the unchanged destination approval path.
- [ ] Publish an extension-author guide covering dataset selection, stable identity, secrets, conflicts, version upgrades, validation, and contract-test expectations.

Validation: at least three independently owned datasets use the registry; no module-specific table knowledge exists in Base; removing a module removes its dataset definitions without corrupting bridge history.

## Explicit Non-Goals

- Replacing Git, migrations, seeders, database backups, ETL/reporting tools, or module APIs.
- Whole-database cloning between environments.
- Automatic production refresh from development, continuous multi-master synchronization, or silent conflict resolution.
- Arbitrary table/column selection, SQL transfer, Eloquent model serialization, or package-supplied executable code.
- Copying users, authorization assignments, API credentials, encrypted settings, sessions, or machine-local runtime state by default.
- An in-app database restore button. Recovery continues to follow the existing deliberate restore runbook.
- Guaranteeing anonymisation generically. A module that exports production data downward owns and proves its redaction semantics; otherwise the direction remains forbidden.

## Proof of Done

The bridge is ready for general use only when the Investment pilot has completed on real laptop and production instances, its second import is a no-op, a restore drill proves the pre-import backup, adversarial package tests pass, SQLite and PostgreSQL contract suites agree, all import actions are attributable in the audit ledger, and another module can add a dataset without changing Base internals.
