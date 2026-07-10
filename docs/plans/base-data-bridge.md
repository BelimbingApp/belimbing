# base-data-bridge

Status: In progress — diagnostic capture and local development import are implemented; declared datasets and connected network receipt remain open
Last Updated: 2026-07-10
Sources: `docs/brief.md`; `docs/architecture/module-system.md`; `docs/architecture/database.md`; `docs/architecture/settings.md`; `app/Base/Database/AGENTS.md`; `extensions/kiat/investment/AGENTS.md`; `docs/plans/database-backup-security.md`; `docs/runbooks/database-backup.md`; [Cloudflare Tunnel](https://developers.cloudflare.com/tunnel/); [Cloudflare Access service tokens](https://developers.cloudflare.com/cloudflare-one/access-controls/service-credentials/service-tokens/); [Cloudflare Access JWT validation](https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/authorization-cookie/validating-json/)
Agents: Codex/Sol; Claude Fable 5

## Problem Essence

Belimbing can promote code between development, staging, and production with Git, but it has no governed way to promote selected durable data. Operators therefore copy seed-like settings and expensive-to-rebuild module data manually, which is error-prone, unaudited, difficult to repeat, and especially unsafe when environments contain credentials or user data.

The immediate case is the complete portable Kiat Investment workspace developed on this machine, which must move to the LIVENPC production instance on the same LAN without selecting hundreds of individual rows. Rebuilding that workspace on production would discard useful work, while copying the whole development database would also copy unrelated environment state and secrets.

## Desired Outcome

An operator can preview and promote an explicitly declared dataset from one Belimbing instance to another without granting either instance general database access. The destination proves that its code and schema can understand the package, shows inserts, updates, skips, conflicts, and deletions before applying anything, creates a recovery point, and records an immutable audit trail of what was accepted and by whom.

Modules own the meaning and portability rules of their data; Base owns packaging, transport-neutral verification, policy enforcement, execution, and audit. The first complete proof is a development-to-LIVENPC promotion of every Investment table classified as portable, preserving its relationships and history while excluding credentials, machine-local sessions, operational run history, and other environment-owned state.

## Top-Level Components

### Dataset declarations

Each participating module contributes named, versioned dataset definitions through a Base Database contract and discovery registry. A definition owns its records, dependency order, stable identity, serialization, validation, sensitivity classification, import strategy, and compatibility upgrades. Base never infers portability from a table prefix and never accepts an arbitrary table list from a request.

Datasets are smaller than a database and may be grouped into a module profile. Investment exposes separately governed datasets such as `research`, `portfolio-history`, and `market-cache`, then offers one recommended `complete-workspace` profile containing every dataset that the module classifies as portable. The operator selects the module/profile once; preview expands it into its exact datasets, tables, counts, dependencies, and exclusions. Profiles enumerate membership explicitly rather than wildcarding a table prefix, so a newly added table fails classification tests instead of silently entering a production package.

### Transfer package

Export produces an immutable package containing a canonical manifest, dataset payloads, record counts, content hashes, source instance/environment identity, module and dataset versions, schema/migration fingerprint, creation time, expiry, sensitivity summary, and package identifier. Payload records use dataset-defined stable keys and references rather than source database primary keys.

The canonical package is integrity-addressed: its manifest covers every payload hash and the destination records the hash it actually received. In the default connected mode, source authentication and confidentiality come from the explicitly paired SSH or HTTPS/Cloudflare Access channel. The destination binds the receipt to that authenticated connection identity, then rejects modified, expired, replayed, wrongly addressed, or unpaired packages before planning.

Application-level signing and recipient encryption are an optional detached envelope, not a prerequisite for the paired Belimbing path. They are required only when package bytes may outlive or leave the authenticated channel through an untrusted relay such as shared object storage or manual offline handoff. Detached keys are dedicated bridge keys, never `APP_KEY`, database credentials, or user passwords.

### Planner and importer

The destination verifies a package in protected Incoming storage, decrypting a detached envelope when present, then runs compatibility and policy checks and builds a deterministic import plan without mutating domain tables. The plan reports per dataset and record: insert, update, unchanged, skip, conflict, or deletion. Applying requires the exact reviewed package hash and plan hash, runs under a database transaction where the driver and dataset permit it, and is idempotent on retry.

Dataset definitions choose one blessed strategy from a small Base vocabulary: append-only, upsert by stable key, or replace-owned-snapshot. Destructive synchronization is not a generic option. A dataset that genuinely owns a complete snapshot must declare deletion semantics, and production requires a separate explicit destructive confirmation.

### Policy, recovery, and audit

An instance declares its immutable environment role (`development`, `staging`, or `production`) outside the database. Default policy permits promotion only in that order. Reverse or lateral transfer is denied unless deployment configuration explicitly permits the named source, destination, and dataset; production-to-lower-environment export additionally requires a dataset-owned redaction/anonymisation policy. A dataset whose sensitivity class declares no secret or personal fields may satisfy that requirement with an explicit no-op redaction policy.

The two directions carry different primary risks and are policed asymmetrically. Upward, the risk is integrity — bad data corrupting production — so the apply side carries backup, preview, and confirmation ceremony. Downward, the risk is confidentiality — production values landing on a less-protected machine — so the export side carries sensitivity gates and redaction, while apply-side ceremony may be lighter because the destination is disposable.

Before a production apply, Base creates and verifies a database backup using the existing backup service. Import attempts, verification failures, previews, approvals, applies, and results are recorded in a Base-owned bridge ledger with package hashes, identities, actor, dataset versions, counts, timestamps, and errors. Payload values and secrets are not copied into the audit ledger.

### Operator surfaces and transport

The core workflow is available through non-interactive Artisan commands suitable for SSH and deployment automation. The first Base administration surface now inventories local diagnostic packages; later phases expand it with pairing status, Incoming packages, preview, and apply controls behind dedicated capabilities and recent authentication. Diagnostic row capture is the deliberate exception to CLI-first ordering: its value is interactive selection, so its surface lands UI-first on the existing Database Tables browser, and its packages are refused by production importers regardless of surface.

Transport is deliberately replaceable but its authenticated connection identity is part of the connected trust boundary. Local protected files and a restricted SSH-assisted upload remain the bootstrap and break-glass paths on the LAN. The LIVENPC pilot also establishes an Incoming-only HTTPS receipt adapter behind Cloudflare Tunnel and Cloudflare Access: the source authenticates with a dedicated service token, the origin validates the Access JWT and application audience, and the adapter accepts only bounded packages addressed to that instance. Receipt records bytes and connection identity; it never plans, applies, or grants broader dataset authority. Those remain destination-side actions after validation.

## Design Decisions

### Move declared datasets, not databases or seeders

Three options are viable: copy the whole database, rerun seeders, or exchange module-declared datasets. Whole-database copying is fast but couples environments, overwrites destination-owned state, and carries users and secrets. Seeders work for canonical static defaults but are a poor representation of evolving authored research and cannot describe conflicts between two live instances. Declared datasets require an initial contract but preserve module ownership and allow truthful preview, validation, and merge behavior.

Recommend module-declared datasets with module/profile selection as the primary operator affordance. Existing production seeders remain the code-owned mechanism for universal defaults; the bridge is for selected instance data. Tables remain visible in preview for blast-radius review, but arbitrary table checkboxes do not define a portable contract. A setting definition may opt a non-secret setting into a dataset, but `base_settings` is never exported wholesale.

### Use packages first, not live database links or a general REST sync API

Direct database links have broad credentials, weak domain validation, and tight network coupling. A live sync API offers convenience but introduces exposed endpoints, distributed conflict behavior, token rotation, retry semantics, and a larger production attack surface. Immutable packages give a reviewable boundary, work across one or several machines, tolerate disconnected instances, and separate transport from trust.

Recommend canonical integrity-addressed packages over explicitly paired connected transports. Cloudflare Tunnel plus Access is the blessed WAN deployment for the first real adopter because LIVENPC already uses `cloudflared`; restricted SSH is the direct-LAN and recovery path. Both authenticate and protect the connection, while the destination still independently validates package hashes, compatibility, domain records, replay state, and local approval. Add signed, recipient-encrypted envelopes only for a later detached relay where the authenticated channel no longer protects the stored artifact.

### Preserve meaning through stable keys, not source IDs

Raw primary-key preservation is easy until the destination already has rows, sequences differ, or records reference shared Core entities. Dataset-defined natural or portable keys make imports idempotent and relationships resolvable. Where a domain lacks a durable key, it should add a UUID/ULID or a truthful composite key before declaring that record portable.

Recommend portable keys plus explicit reference resolvers. The bridge maintains a package-local identity map during planning and apply; it does not make source IDs globally meaningful.

### One-way promotion by default, not automatic bidirectional sync

Automatic multi-master synchronization would require persistent change tracking, conflict-free semantics, deletion tombstones, and domain-specific reconciliation for every participating dataset. The current need is controlled promotion of work, not collaborative offline editing.

Recommend operator-initiated, one-way promotion. Re-exporting an updated dataset and applying it idempotently is supported. Bidirectional continuous sync is outside this design unless a later concrete module proves it necessary.

### Production to development: two paths, only one through the bridge

Replicating a production bug on development is a real operational need, but whole-state replication is not bridge work. Production bugs often live in exactly the state dataset declarations exclude — settings values, operational history, half-migrated rows — and redaction can destroy the bug when the malformed value is the bug. For whole-state replication, restore an existing `blb:db:backup` artifact into a disposable development instance, then run the contributor-based post-restore sanitizer command (`blb:db:sanitize-dev --commit`) that neutralizes environment-owned dangers: pause framework schedules, disable AI schedules, remove pending queue work, wipe complete external-integration setting groups that contain credentials, and clear persistent sessions. The command asserts the deployment-owned development role and previews by default; operators must stop workers before restore because sanitization cannot undo work a running production queue already claimed. Restoring without the production `APP_KEY` leaves Laravel-encrypted columns unreadable — a feature for debugging, unless the bug involves encrypted-column handling, in which case copying the key is a conscious, temporary acceptance that the development machine holds production secrets.

Dataset-level pulls downward do go through the bridge: the explicit deployment-config permit plus the dataset's redaction policy (no-op where the sensitivity class allows) covers cases such as pulling authored research from production back to a rebuilt development instance.

### Row-level copy: one selection UI, three routes underneath

Operators need to copy individual rows across instances — a handful of companies or employees for administration, or a record whose weird value (right-to-left marks, zero-width characters, non-NFC sequences) triggers a production-only bug. Row selection in the Database Tables browser is UI sugar; what happens on send depends on coverage and direction:

1. **Scoped dataset export.** When a declared dataset covers the table, the selection becomes a record selector on that dataset: the selected records plus their declared dependency closure export with the same serialization, validation, secret exclusion, and conflict planning as a full export. This is the only row-level route into production.
2. **Diagnostic row capture.** When no dataset covers the table, the rows can still be captured **byte-exact** — raw column values, no canonicalization, since dataset-style normalization would smooth away exactly the values that reproduce encoding bugs. The dependency closure is discovered by walking actual foreign keys via schema inspection, previewed before packaging so the operator sees the blast radius. Columns that are Laravel-encrypted casts or match secret classifications are redacted at capture time. Diagnostic packages are marked as such in the manifest and are importable **only into development instances**; production refuses them categorically. The manifest records the source driver, encoding, and collation, because byte fidelity cannot guarantee reproduction across drivers — collation and Unicode-comparison bugs may need the same driver, and recording provenance tells the operator when to suspect the driver rather than the data.
3. **Refusal with a pointer.** Rows from an uncovered table destined for production are refused with a message naming the module that would need to declare a dataset. This keeps "Base never accepts an arbitrary table list" meaningful where it matters.

### Connected instance trust by default, detached cryptography only when needed

The existing Microsoft AX imports establish the right baseline for connected integrations: the destination controls the connection, maps an exact schema, validates and converts records, applies transactionally, and records the batch. A paired Belimbing source deserves at least that trust level. Requiring a second application signing and encryption system on top of SSH or Cloudflare Access would add key lifecycle and failure modes without improving the first LIVENPC path proportionately.

Recommend explicit instance pairing scoped to an authenticated transport identity, environment direction, and dataset/profile. For HTTPS that identity is the validated Cloudflare Access application/audience and service credential; for SSH it is the restricted account/key and forced receipt command. The package hash, identifier, expiry, target instance, receipt identity, and replay ledger preserve integrity and idempotency after receipt. If a later detached mode is justified, it adds dedicated signing and recipient-encryption keys outside the database without changing dataset, planning, or apply contracts.

## Public Contract

### Dataset definition promises

A portable dataset definition must provide:

- A globally stable name namespaced to its owning module, a positive integer format version, and a sensitivity class.
- The installed module/version and schema prerequisites that must hold at the destination.
- A deterministic export order and deterministic serialization with stable record keys.
- Reference declarations to other records or prerequisite datasets; no opaque database IDs in the portable contract.
- Validation rules, authorization label, allowed environment directions, default conflict policy, and whether the data is append-only, upsertable, or a complete owned snapshot.
- Optional format upgraders between explicitly supported dataset versions. Unknown versions fail closed; Base never guesses a migration.
- Optional record selectors. A dataset may support exporting a subset filtered by stable keys plus the declared dependency closure; the manifest records the selector, and a scoped export carries every guarantee of a full export.
- References to record types the bridge excludes (for example the user account behind an employee) must be expressed as resolvable hints such as an email match, never as the excluded record itself; the destination links or leaves the reference unlinked.
- An explicit secret policy. Secret-bearing fields are excluded by default. An opt-in secret transfer must explain the destination use, require a confidential-at-rest envelope in addition to transport protection, and never serialize framework ciphertext that is bound to the source `APP_KEY`.

Dataset exporters must be side-effect free. Import planning must not mutate domain data, dispatch jobs, send notifications, call external systems, or update source freshness markers. Apply suppresses ordinary per-record external side effects; a dataset may provide one post-commit reconciliation hook that is safe to retry.

### Package and pairing promises

- A package names its source and target instance identities. Its canonical manifest covers every payload hash and all compatibility, identity, policy, and expiry metadata.
- Connected mode accepts bytes only through a configured local handoff, restricted SSH receipt, or authenticated HTTPS receipt whose connection identity is explicitly paired to the claimed source and allowed scope.
- Detached mode wraps the same canonical package in a source-signed, destination-encrypted envelope. Plaintext detached handoff through shared storage or an untrusted relay is forbidden.
- Verification happens before parsing domain payloads; payload extraction is size-limited, path-safe, and kept outside public storage.
- Package identifiers are globally unique. A successfully applied package cannot be applied again; a failed apply may resume only when the dataset contract declares the operation idempotent and the reviewed hashes still match.
- Pairing does not itself authorize import. Destination capabilities, environment policy, dataset policy, compatibility checks, preview, and apply confirmation all remain mandatory.
- Pairing records may be stored in the database, but connection credentials, optional detached private keys, and environment role are deployment configuration. Revoking a source prevents new receipts without invalidating historical audit evidence.

### Transport promises

- A transport moves immutable package bytes into protected destination Incoming storage and returns a receipt containing the destination-observed package hash and authenticated connection identity. It cannot create a plan, apply data, or authorize its sender beyond the paired receipt scope.
- Local-file and restricted SSH delivery remain supported without changing canonical package bytes or destination policy. SSH credentials are scoped to a write-only Incoming path or forced receipt command, never a general production shell.
- The HTTPS adapter exposes one bounded receipt operation behind Cloudflare Tunnel and a Cloudflare Access service-auth policy. The source uses a dedicated expiring service token; LIVENPC validates the Access JWT signature, issuer, and application audience before accepting bytes.
- Cloudflare or SSH credentials authenticate the connected source, but do not authorize apply. The package must still name the paired source and LIVENPC target, be unexpired, unreplayed, compatible, hash-valid, and approved locally before apply.
- Receipt is idempotent by package identifier and content hash. A retry of identical bytes returns the existing Incoming receipt; the same identifier with different bytes is rejected and audited.
- Detached delivery unwraps and verifies its signed, recipient-encrypted envelope before placing the canonical package into the same Incoming workflow; nothing downstream forks by transport.

### Conflict and apply promises

- `fail` is the default conflict policy when source and destination both differ from the last known imported state or when no safe ancestry is available.
- `source-wins` is allowed only when a dataset explicitly declares the affected fields source-owned. `destination-wins` records skips. Interactive field-by-field merging is deferred until a real dataset needs it.
- Imports do not silently delete destination rows. Snapshot deletions are listed individually or by bounded selector in the preview and require destructive approval in production.
- The destination records the last accepted source package and record fingerprints per dataset so later plans can distinguish unchanged rows from genuine divergence.
- Production apply refuses to start without a fresh verified backup, sufficient temporary disk space, a matching current schema fingerprint, and an exclusive bridge apply lock.

### Initial Investment contract

The pilot profile is `extensions.kiat.investment/complete-workspace`. It composes every Investment dataset classified as portable after the Phase 0 inventory, expected to include at least `research`, `portfolio-history`, and `market-cache`. Its first implementation therefore covers the authored research graph rooted at case companies, Maybank holdings and snapshots, portfolio capital entries, dividends and ignored holdings, captured market/research evidence, research notes, valuations, trade-call snapshots and observations, validation snapshots, journal entries, value estimates, and watch triggers. Exact membership and dependency order are asserted by contract tests against every current `kiat_investment_*` table before the first real export.

"Complete" means every module-owned table is explicitly classified and every portable dataset is selected by default. It does not mean copying unrelated Base/Core state or machine-local artifacts. A table may be excluded only with a recorded reason such as secret material, ephemeral execution state, or data that is provably safe and cheaper to rebuild; the profile preview shows those exclusions rather than hiding them.

The pilot excludes:

- `base_settings`, including GlobalWits and MOST credentials.
- Browser/session artifacts under `storage/`, Maybank session files, and all machine-local files.
- Users, roles, company tenancy, schedules, AI runs, operation dispatches, command/run ledgers, and audit infrastructure.
- Investment agent runs/tasks and source-update/freshness observations whose meaning is local to an execution, unless the inventory proves they are durable user-visible evidence rather than operational history.
- Any reproducible cache excluded by the inventory because measured rebuild cost is lower than transfer and validation cost. Portable market data remains in `market-cache` and is included by `complete-workspace`.

Company records use a durable investment-owned portable identity, preferably an added UUID/ULID plus unique Bursa code where present. Child records receive durable portable identities where their existing domain composite key is not sufficient. References are resolved through those identities; production database IDs are never overwritten to match development.

The first production promotion is merge-only: inserts and safe updates, no deletions. Any conflict stops that dataset before mutation and is resolved at the source or by an explicit future conflict policy. After apply, the module runs read-only integrity checks and refreshes derived views/caches without scraping, notifying, scheduling agents, or placing trades.

## Security Model

The threat model includes a stolen package or detached envelope, a malicious or compromised development machine, an attacker replaying an old valid package, misuse of a paired receipt credential, accidental import to the wrong environment, archive traversal/decompression bombs, schema confusion, privilege escalation through crafted records, secret leakage, partial apply, and a legitimate operator selecting the wrong scope.

The design assumes that a fully compromised production host or production database administrator can alter production data; the bridge cannot defend against its own trusted runtime. It does reduce blast radius from a compromised lower environment: source trust is explicit and scoped, development cannot initiate a production apply, the destination independently validates every record, and production requires a local privileged actor plus recovery point.

Security invariants are:

- No inbound database credentials and no arbitrary SQL, table names, model classes, PHP objects, or executable hooks arrive in a package.
- Package formats use bounded data encodings, not PHP serialization. Every archive entry, total uncompressed size, dataset count, record count, and scalar length is limited before allocation or insertion.
- Validation uses destination code and authorization policy; an authenticated receipt identity or valid detached signature proves origin, not safety.
- Import capabilities are separate from backup, migration, settings, and ordinary module-edit capabilities. Export and apply are separate capabilities; production apply requires recent authentication in the web surface.
- Logs and UI show metadata and safe record keys, not secret values or complete sensitive payloads.
- Canonical packages, Incoming bytes, and temporary plaintext use restrictive permissions outside served paths and follow bounded retention and `finally` cleanup rules. Streaming verification/import is preferred.
- Pairing, connection-credential rotation, and optional detached-key rotation are observable. Confirmation and audit surfaces show safe instance and credential fingerprints, never raw credentials or keys.
- Production never automatically polls and applies data from development. Scheduling may fetch a package into Incoming storage, but preview and apply remain destination actions.
- Diagnostic row capture packages are marked in their manifest and refused by production importers categorically, regardless of trust, capability, or operator intent.

## Phases

### Phase 0 — Prove the data boundary

Goal: the Investment pilot has an agreed inventory that classifies every module-owned table as a portable dataset member or an explicit exclusion, distinguishing durable workspace data from secrets, environment state, execution history, and cheaper-to-rebuild caches.

- [ ] Inventory every current `kiat_investment_*` table, `base_settings` key, and relevant storage artifact; assign each table to a named dataset included by `complete-workspace` or record its explicit exclusion reason.
- [ ] Draw and test the portable dependency graph, including references to Core/Base records; remove or bridge any hidden dependency before export exists.
- [ ] Add durable portable identities or stable composite keys where the current schema has only an auto-increment ID or an ambiguous domain key.
- [ ] Record representative production collision scenarios and the expected insert/update/conflict result for each authored entity.
- [ ] Confirm this development checkout and LIVENPC have compatible Investment code/migrations before attempting a package; code promotion remains a prerequisite to data promotion.

Validation: schema inventory is complete; contract tests fail when a newly added durable Investment table is left unclassified; no credential or session artifact is classified as portable.

### Phase 1 — Establish Base contracts and deterministic packages

Goal: modules can declare datasets and produce a reproducible, bounded package without transport or destination mutation.

- [ ] Add the Base Database dataset definition contract, registry/discovery seam, dataset/profile metadata values, and domain-specific bridge exceptions.
- [ ] Define the canonical manifest and payload encoding, deterministic ordering, content hashing, limits, expiry, module/schema compatibility metadata, and package identity.
- [ ] Implement explicit source/target instance identity, connected pairing records scoped by transport identity, direction, and dataset/profile, plus rotation/revocation behavior; keep connection credentials outside the database.
- [ ] Implement export authorization and environment-direction policy with fail-closed defaults; make instance role deployment-owned and immutable at runtime.
- [ ] Provide a dry-run export report listing datasets, sensitivity, included/excluded record counts, dependencies, target instance, connected pairing identity, and output size estimate.
- [ ] Add adversarial package tests for tampering, wrong target, unpaired receipt identity, expiry, replay metadata, traversal, duplicate paths, excessive expansion, excessive records, invalid encodings, and unsupported versions.

Validation: two test instances exchange and verify a canonical package through a paired connected receipt; identical source state yields identical payload hashes; credentials, secrets, raw SQL, and PHP-serialized values never enter the artifact.

### Phase D — Diagnostic row capture (parallel track, UI-first)

Goal: an operator can select rows in the Database Tables browser, preview the foreign-key dependency closure, and produce a byte-exact diagnostic package that only a development instance will import. This track does not depend on dataset contracts (Phases 0–1) and deliberately lands UI before CLI so the surface can be corrected early from operator feedback.

Slice 1 — capture surface (no crypto, no import):

- [x] Add row selection to the Database Tables browser and a clearly labelled Data Bridge package-preview action gated by a dedicated capability; keep diagnostic capture distinct from the module/profile export workflow. {Claude Fable 5; Codex/Sol}
- [x] Compute and preview the bounded foreign-key dependency closure via schema inspection: exact composite-key traversal, per-table row counts, redacted columns, total size estimate, and a hash that must still match at package creation. {Claude Fable 5; Codex/Sol}
- [x] Redact Laravel ciphertext (including ciphertext stored inside JSON columns), secret-like column names, and explicit Base runtime-payload classifications at capture time; show what was redacted in the preview. {Claude Fable 5; Codex/Sol}
- [x] Write the bounded package (canonical manifest + byte-exact string payloads, source driver/encoding/collation provenance, diagnostic marker) into a configured private disk, and fail closed on public disks or failed writes. {Claude Fable 5; Codex/Sol}
- [x] Add a minimal Data Bridge page listing created packages with hashes, contents summary, localized creation time, and guarded delete. {Claude Fable 5; Codex/Sol}
- [ ] Add a module-contributed secret-column classification seam before treating diagnostic capture as generally complete across pluggable module and extension schemas; the current implementation covers explicit Base opaque payloads plus conservative name/ciphertext detection.

Evidence: `app/Base/Database/Services/Bridge/`; `app/Base/Database/Livewire/DatabaseTables/Show.php`; `app/Base/Database/Livewire/Bridge/Index.php`; `tests/Feature/Database/DataBridgeDiagnosticCaptureTest.php`; browser verification of selection, preview, and inventory on 2026-07-10.

Validation: `php artisan test --compact tests/Feature/Database/DatabaseTablesShowTest.php tests/Feature/Database/DataBridgeDiagnosticCaptureTest.php`; `vendor/bin/pint --dirty`; browser checks with a real local `users` row and `kiat_investment_maybank_holdings` row, including dependency closure, Data Bridge labelling, global timezone inheritance, and password/token redaction.

Slice 2 — local handoff and dev-side import:

- [x] Admit a selected local diagnostic package into protected Incoming storage with an idempotent receipt and destination-observed hash; keep connected network receipt adapters in Phase 2. {Codex/Sol}
- [x] Add `blb:db:bridge:import-diagnostic`: validate the receipt, manifest, marker, limits, payload hash, destination schema, parent ordering, and target role; preserve redacted destination fields; transactionally upsert by primary key; categorically refuse receipt and import on non-development instances. {Codex/Sol}
- [x] Add contributor-based `blb:db:sanitize-dev` dry-run/commit behavior for the backup-restore path: remove credential-bearing integration setting groups, pause framework schedules, disable AI schedules, remove pending jobs/batches, clear sessions, and assert the deployment-owned development role. {Codex/Sol}

Evidence: `app/Base/Database/Services/Bridge/DiagnosticPackageInbox.php`; `app/Base/Database/Services/Bridge/DiagnosticPackageImporter.php`; `app/Base/Database/Console/Commands/ImportDiagnosticBridgePackageCommand.php`; `app/Base/Database/Services/DevelopmentSanitizer.php`; module-owned `DevelopmentSanitizationContributor` implementations; `tests/Feature/Database/DataBridgeDiagnosticImportTest.php`; `tests/Feature/Database/DevelopmentDatabaseSanitizerTest.php`.

Slice 3 — routing convergence:

- [ ] When a declared dataset covers the selected table (Phase 1+), route the same selection UI to a scoped dataset export; show which route applies and why before packaging.
- [ ] Refuse uncovered-table selections aimed at production with a pointer to the owning module.

Affected pages: `/admin/system/database-tables/{tableName}`, `/admin/system/database-bridge`

Validation: `php artisan test --compact tests/Feature/Database/DatabaseTablesShowTest.php tests/Feature/Database/DataBridgeDiagnosticCaptureTest.php tests/Feature/Database/DataBridgeDiagnosticImportTest.php tests/Feature/Database/DevelopmentDatabaseSanitizerTest.php`; a captured package byte-round-trips RTL/zero-width/non-NFC values; encrypted-cast columns never appear in a package; a production instance refuses diagnostic receipt and import in every code path; closure preview counts match what the package contains; sanitizer preview is non-mutating and commit neutralizes restored executable state.

### Phase 2 — Build destination planning and safe apply

Goal: a destination can explain the exact outcome before mutation and apply the reviewed plan atomically and idempotently.

- [ ] Implement protected Incoming storage, canonical manifest/hash verification, optional detached-envelope verification/decryption seam, compatibility checks, schema fingerprint checks, retention, and cleanup.
- [ ] Implement idempotent local/SSH and Cloudflare Tunnel HTTPS receipt adapters that can only place bounded bytes into Incoming storage and return the destination-observed hash plus authenticated connection identity; require Access service authentication plus origin JWT audience validation for HTTPS.
- [ ] Implement the portable identity map, dependency ordering, insert/update/unchanged/skip/conflict/delete planner, and persisted plan hash.
- [ ] Implement transactional apply, exclusive locking, replay protection, retry rules, side-effect suppression, post-commit reconciliation, and failure cleanup.
- [ ] Integrate a mandatory fresh verified backup before production apply; abort cleanly when backup, capacity, compatibility, or locking preconditions fail.
- [ ] Add the Base bridge ledger and audit integration without retaining domain payloads or secret values.
- [ ] Provide separate export, receive, inspect/verify, plan, apply, and pairing Artisan commands with machine-readable output and explicit non-interactive confirmation flags.
- [ ] Prove cross-driver behavior for SQLite and PostgreSQL, including transaction boundaries, constraint ordering, timestamp precision, JSON normalization, and sequence handling.

Validation: fault injection at every dataset boundary leaves either the old state or the complete new state where atomicity is promised; retry does not duplicate records; changing destination data after preview invalidates the plan; production apply cannot bypass backup or capability checks.

### Phase 3 — Pilot the complete Investment workspace to LIVENPC

Goal: LIVENPC receives every portable Investment dataset from this development machine once, without row-by-row selection, rebuilding useful state, or importing credentials and environment-owned state.

- [ ] Implement the `complete-workspace` profile, its constituent dataset definitions, and the explicit table/artifact exclusion inventory in the Investment module.
- [ ] Make Investment/module-profile selection the primary export affordance; preview the exact datasets, tables, rows, dependencies, sensitivity, and exclusions before package creation.
- [ ] Add serializers, validators, stable reference resolvers, merge-only conflict rules, and side-effect-free post-import integrity checks for the full agreed portable Investment workspace.
- [ ] Add module contract tests covering relationship preservation, null/JSON/date/decimal fidelity, existing-row updates, genuine divergence, missing parents, and forbidden datasets/fields.
- [ ] Pair this development instance and LIVENPC using verified instance identifiers and transport identities, with LIVENPC accepting only this source's restricted SSH key or Cloudflare Access identity for the named Investment profile and forward direction.
- [ ] Prove that direct LAN/SSH and Cloudflare Tunnel/Access delivery produce the same destination-observed package hash and Incoming state; neither path may plan or apply from source authority.
- [ ] Create and retain a LIVENPC recovery backup, export for its target instance, deliver the package, verify, preview, and manually reconcile every reported conflict before apply.
- [ ] Apply once under the production operator, verify row counts and representative company histories through the Investment UI, and run domain integrity checks.
- [ ] Record package/plan hashes, commands, backup reference, counts, exclusions, conflicts, result, and rollback procedure in the bridge ledger and an operator runbook example; do not commit real package contents or sensitive record data.

Affected pages: `/investment`, `/investment/company-research`, representative `/investment/company-research/{slug}`, `/investment/journal`

Validation: all data in the `complete-workspace` profile, including the classified portfolio and Maybank history, is present and navigable on LIVENPC; source and destination portable fingerprints match; credentials, sessions, excluded execution history, and machine-local artifacts remain production-owned or absent; a second import plans as entirely unchanged.

### Phase 4 — Add the administration workflow

Goal: an authorized operator can manage trust and perform the same safe workflow without shell fluency, while production keeps deliberate approval friction.

- [ ] Expand Administration → System → Database → Data Bridge using Core UI conventions, with Profiles, Incoming, History, and Trusted Instances views; Profiles begins with module/profile selection rather than raw table or row selection.
- [ ] Show source/destination environment, paired connection identity, expiry, sensitivity, compatibility, backup status, per-dataset counts, conflicts, exclusions, destructive actions, and package/plan hashes in plain operator language.
- [ ] Require dedicated capabilities and recent authentication for trust changes and production apply; make export, preview, apply, and destructive apply independently authorizable.
- [ ] Stream uploads into Incoming storage with server-side limits; never place package or plaintext payloads in a public/media disk.
- [ ] Make conflict and failure states actionable without exposing sensitive values; link successful applies to their audit record and recovery backup.
- [ ] Add browser tests for keyboard access, focus/error handling, large previews, expired plans, concurrent changes, and accidental double submission.

Affected pages: `/admin/system/database-bridge`

Validation: the web flow produces the same plan/apply hashes and policy decisions as CLI; an unauthorized user cannot enumerate packages, trust records, or dataset metadata; production apply cannot occur from upload alone.

### Phase 5 — Generalize only from proven adopters

Goal: other modules share selected data by implementing the same narrow contract, without Base learning their schemas or weakening the pilot's guarantees.

- [ ] Add opt-in portability metadata to Settings definitions for non-secret code-like/reference settings; keep undeclared and encrypted settings excluded.
- [ ] Pilot one Base/Core reference dataset such as deliberately selected non-secret system settings, with scope mapping for global/company-owned values and explicit destination ownership.
- [ ] Pilot a second business module whose conflict behavior differs from Investment and refine the strategy vocabulary only where both use cases justify it.
- [ ] Refine the shipped SSH and HTTPS adapters only from LIVENPC operating evidence; keep their receipt-only authority identical.
- [ ] Measure package sizes and import time; add chunking/streaming checkpoints without relaxing whole-package manifest verification.
- [ ] Decide from measured package size and disconnected-operation evidence whether detached delivery is warranted; if built, wrap canonical packages in a source-signed, recipient-encrypted envelope and keep R2/object storage a delivery adapter with short-lived object-scoped credentials and unchanged destination approval.
- [ ] Publish an extension-author guide covering dataset selection, stable identity, secrets, conflicts, version upgrades, validation, and contract-test expectations.

Validation: at least three independently owned datasets use the registry; no module-specific table knowledge exists in Base; removing a module removes its dataset definitions without corrupting bridge history.

## Explicit Non-Goals

- Replacing Git, migrations, seeders, database backups, ETL/reporting tools, or module APIs.
- Whole-database cloning between environments.
- Automatic production refresh from development, continuous multi-master synchronization, or silent conflict resolution.
- Arbitrary table/column selection **into production imports**, SQL transfer, Eloquent model serialization, or package-supplied executable code. Diagnostic row capture is the deliberate carve-out: schema-discovered, downward-only, development-import-only.
- Copying users, authorization assignments, API credentials, encrypted settings, sessions, or machine-local runtime state by default.
- An in-app database restore button. Recovery continues to follow the existing deliberate restore runbook.
- Guaranteeing anonymisation generically. A module that exports production data downward owns and proves its redaction semantics; otherwise the direction remains forbidden.

## Proof of Done

The bridge is ready for general use only when the Investment `complete-workspace` pilot has completed between this development instance and LIVENPC, its second import is a no-op, direct-LAN and Cloudflare delivery preserve the same package hash, a restore drill proves the pre-import backup, adversarial package tests pass, SQLite and PostgreSQL contract suites agree, all import actions are attributable in the audit ledger, and another module can add a dataset without changing Base internals.
