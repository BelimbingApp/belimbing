# Base Data Export and Data Bridge

Status: In progress — the Base-owned relational package, target-issued receive-grant streaming workflow, planning, apply, CLI, and web surfaces are built and locally verified; LIVENPC deployment, PostgreSQL, recovery, and production-pilot work remains
Last Updated: 2026-07-10
Sources: `docs/brief.md`; `docs/architecture/module-system.md`; `docs/runbooks/data-bridge.md`; `DESIGN.md`; `app/Base/Database`; JSON-RPC 2.0 specification; RFC 6750
Agents: Claude Fable 5; Codex/Sol

## Problem Essence

Belimbing has valuable authored data on one instance that must move to another without copying the whole database, exposing general database access, or selecting thousands of rows by hand. The first real case is moving the complete Investment module from this development machine to the LIVENPC production instance, but requiring Investment-specific bridge code would make every future table transfer expensive and would put infrastructure concerns in the wrong ownership boundary.

## Desired Outcome

An authorized operator selects a registered module scope or an exact subset of its tables, previews an immutable export, delivers it over a trusted LAN or Cloudflare path, and reviews a deterministic destination plan before any domain row changes. Base discovers and validates the entire relational contract. Modules do not implement, register, customize, or hook into Data Export or Data Bridge.

The first LIVENPC promotion preserves the source primary and foreign keys, inserts only rows that do not collide with destination state, treats byte-equivalent rows as unchanged, blocks every ambiguous difference as a conflict, creates and verifies a production recovery point, and records the operation without storing payload values in the ledger.

## Top-Level Components

### Base-owned relational export

`base_database_tables` is the scope inventory. Base groups available registered tables by `module_path`, reads their live columns, primary keys, unique indexes, and foreign keys, orders selected tables by dependencies, and serializes their rows deterministically. A module participates only through its ordinary migrations and existing table registration; there is no Data Bridge provider, adapter, interface, metadata file, model trait, portable identity, validator, serializer, or post-import hook.

### Data Export foundation

The reusable foundation is a bounded relational snapshot: selected registered tables, schema contract, ordered row payloads, physical primary keys, values, counts, and hashes. External export can later present that snapshot through destination-neutral file adapters without changing modules or the relational serializer.

### Data Bridge specialization

Data Bridge adds a target Belimbing instance, environment-direction policy, a target-issued one-time receive grant, package expiry, destination schema verification, replay protection, conflict planning, recovery, guarded apply, and an audit ledger. These guarantees are unnecessary for a one-way external export but mandatory before another Belimbing instance mutates its database.

### One-time receive-grant transport boundary

The target user logs in with receive-grant authorization and creates a short-lived grant for an expected source instance and Base scope. Base generates a 256-bit secret, displays it once, stores only its hash, and produces a compact copy/paste bundle containing target identity, deployment-advertised HTTPS endpoints, public grant ID, scope, limit, and expiry. The source user must separately hold export authorization, paste that bundle, choose the reachable route, preview the selected tables, and send when ready.

The canonical NDJSON package streams as the raw HTTPS request body into bounded private staging; it is never Base64-wrapped inside JSON-RPC or buffered as one application string. JSON-RPC remains a possible future control plane for concrete prepare/status/cancel or resumable-upload needs, not the initial byte transport. FrankenPHP, Laravel's request stream, and the filesystem stream carry the payload with constant-size chunks. Receipt authenticates the one-time key, verifies the complete package, then atomically consumes the grant and stores Incoming; an interrupted or rejected stream leaves the unexpired grant available because no package was accepted. Receipt never plans or applies data.

### Destination planning and apply

The destination re-reads its live schema and classifies every incoming row as `insert`, `unchanged`, or `conflict`. Apply accepts only a ready plan whose exact package and plan hashes the operator confirms. It replays the plan against current destination state under a lock, stops if anything changed, creates a verified backup in production, inserts in foreign-key order inside a transaction, and advances PostgreSQL sequences after explicit numeric key insertion.

### Diagnostic row capture

Diagnostic capture remains a separate Base feature for reproducing unusual development data. It starts from selected rows, captures a byte-exact foreign-key parent closure with redaction, and is importable only into development. It is not the production bulk-transfer contract and has no module hooks.

## Design Decisions

### Base discovery versus module-specific integration

The realistic options were module-owned transfer providers, generic Base discovery, or direct database copying. Providers can express semantic merges but force every module to learn infrastructure, duplicate contracts, and maintain migration-era portable identities. Whole-database copying is fast but couples environments and carries users, secrets, queues, sessions, and unrelated state.

Use generic Base discovery. Table registration and relational schema already describe what Base needs for a conservative transfer. This is a deep module: Base absorbs schema inspection, ordering, normalization, compatibility, limits, transport, planning, and recovery behind one operator workflow. Module-specific integration is prohibited, including optional hooks or extension points.

### Physical identities and conservative collision handling

The options were rewriting source IDs through module-declared stable identities, generically upserting by unique keys, or preserving physical keys and rejecting ambiguity. Rewriting IDs adds module code and a permanent identity migration. Generic upsert can silently overwrite independently created production rows.

Preserve physical primary and foreign keys. A missing primary key with resolvable parents and no unique collision is an insert. The same primary key with the exact same normalized row is unchanged. A differing primary key row, unique collision, partially null reference, or missing parent is a conflict. There are no generic updates, deletes, source-wins modes, field merges, or destructive capabilities. Conflicts are resolved outside the bridge, then re-exported.

### Data Export and Data Bridge relationship

Data Bridge is a specialization of Data Export, not the reverse. Both reuse scope discovery, schema description, value normalization, dependency ordering, deterministic row serialization, bounds, and hashes. Data Bridge adds destination-only trust and mutation concerns. Building external export later is therefore low-cost as long as the relational serializer stays independent of receipt authorization and import; an external adapter must not weaken the bridge package contract or introduce module integration.

### User-mediated one-time capability rather than persistent machine trust

The realistic options were persistent instance pairing, manual browser download/upload, or a target-issued one-time receive capability. Persistent SSH keys or Cloudflare service tokens enable unattended automation that the current use case does not need and create rotation/revocation work. Browser handoff is simple but adds a 43 MB download/upload loop and weakens the desired source-to-target send experience.

Use a target-issued one-time receive capability. Target login and authz authorize the grant; source login and authz authorize export and send. The random bearer secret is long enough for copy/paste rather than human memorization, stored only as a hash, transmitted only in the Authorization header over HTTPS, short-lived, source/scope/target/size bound, rate-limited, revocable, and consumed exactly once after successful package verification. Rejected or interrupted bytes consume nothing; competing successful requests serialize on the grant and only one package can win.

Do not add mandatory detached signing, recipient encryption, or quarantine to this connected path. HTTPS plus the one-time capability, target/package binding, hashes, expiry, and replay ledger fit the approved user-mediated threat model without a permanent shared secret. A detached/offline adapter may add dedicated cryptography later without changing export or planning contracts. External Microsoft AX import is a separate integration boundary with its own validation; its lower trust does not justify making two Belimbing instances harder to operate.

### LAN and Cloudflare are transport peers

The same target grant can advertise private LAN HTTPS and WAN Cloudflare Tunnel endpoints. The source chooses one exact advertised route before preview/send; route choice does not enter the package manifest or preview hash. Cloudflare remains outbound-only ingress and TLS delivery; the narrow receive path uses the Base one-time grant instead of a persistent Cloudflare service token, while the ordinary admin UI remains protected by human login/Access policy. The origin never exposes general database, plan, or apply access.

## Public Contract

### Selection

- Base lists scopes by `base_database_tables.module_path` and only includes live registered relations.
- Selecting a module selects every bridgeable registered table in that scope by default. The operator may choose an exact subset.
- Every selected table must have a primary key. A relation without one remains visible but disabled with the reason stated.
- Selecting a child without its parent is allowed only when the destination already contains the referenced parent. Otherwise planning reports a conflict.
- Views and tables without safe row identity are not silently exported.

### Relational package

- The canonical connected format is `belimbing-data-bridge/v2`, encoded as bounded canonical NDJSON rather than PHP serialization or an archive.
- The manifest records source and target instance identity and role, receive-grant ID, source database driver, scope and exact tables, table and row counts, per-table bytes and hashes, creation and expiry, package ID, conflict policy, and preview hash.
- Each table payload records its columns, logical types, nullability, primary key, unique indexes, foreign keys, schema hash, physical primary key values, all column values, and row fingerprint.
- Binary or invalid-UTF-8 strings use an explicit Base64 envelope. JSON, booleans, integers, decimals, dates, and datetimes normalize deterministically.
- Package, table, record, line, and scalar limits are checked before unbounded allocation or insertion.

### Trust and receipt

- An instance has a stable global Base Settings ID, display name, and role: development, staging, or production. Code derives a deterministic first-access fallback, but operators save the explicit identity before exchanging a receive key.
- Default direction is strictly upward: development to staging or production, and staging to production. Lateral and downward transfer fail closed.
- A target-authorized user issues a grant for one expected source instance/role and one Base scope. Its 256-bit secret is shown once and only a one-way hash is persisted.
- The grant bundle carries no database credential: only one or more deployment-advertised receive URLs, target identity/role, public grant ID, secret, scope, maximum bytes, and expiry.
- The source sends the key in `Authorization: Bearer`, never in a URL, manifest, log, ledger, or persisted source setting.
- Receipt validates grant hash/status/expiry, target, direction, expected source, scope, grant ID, content length, schema, counts, and every content hash before Incoming acceptance.
- Competing requests cannot consume the same grant for different bytes. A rejected or interrupted stream leaves no receipt and does not consume the grant; a successfully consumed grant cannot accept another package.
- A package ID cannot be rebound to different bytes or a different grant. An applied package cannot be applied again.

### Plan and apply

- Planning never mutates selected domain tables, dispatches jobs, calls external systems, or invokes module code.
- Plan output contains exact per-table row actions and aggregate `insert`, `unchanged`, and `conflict` counts. Any conflict makes the plan inapplicable.
- Replanning the same package against the same destination state produces the same plan hash.
- Apply requires explicit confirmation of both the package SHA-256 and plan SHA-256. Production web apply also requires recent password confirmation.
- Apply re-verifies package bytes and recomputes every action against current state. Any difference invalidates the reviewed plan.
- Production apply requires a newly created backup whose artifact SHA-256 matches its manifest.
- The ledger records export, grant issue/revocation/consumption, receipt, planning, apply outcome, identities, hashes, counts, actor, and errors without copying domain values or the receive secret.

### Operator surfaces

- The admin page is `admin/system/database-bridge` with Export, Incoming, History, Receive, and Diagnostics tabs.
- Receive lets an authorized target user issue/revoke short-lived source-and-scope-bound grants and copy the newly issued bundle once.
- Export accepts a receive bundle, validates that its scope matches selection, binds the package to that exact target/grant, and shows exact table counts, row counts, bytes, and preview hash before send.
- When the target advertises both LAN and Cloudflare endpoints, Export presents a standard route selector and permits only those exact URLs; changing route invalidates the rendered preview even though recomputation produces the same route-independent preview hash.
- Incoming separates receipt from planning and apply. Upload or receipt alone can never mutate domain data.
- CLI commands expose scope listing, preview/export/send, grant issue/revocation, inspection, planning, retention, and apply through the same services; the ordinary blessed workflow keeps an authorized user in the loop on both instances.

## Phases

### Phase 0 — Correct the operator surface and architectural boundary

Affected pages: `admin/system/database-tables/{table}`; `admin/system/database-bridge`

Goal: the UI does not duplicate the global timezone control or imply that row copy and module-owned profiles are the production transfer design.

- [x] Remove the duplicate table-local timezone toggle while retaining the global top-bar timezone control. {Claude Fable 5; Codex/Sol}
- [x] Rename and reshape the bridge card so bulk transfer is visibly Data Bridge/Data Export work rather than a broad “copy rows” action. {Codex/Sol}
- [x] Record Data Export as the reusable foundation and Data Bridge as its Belimbing-targeted specialization. {Codex/Sol}
- [x] Make “no module-specific integration” an explicit permanent invariant and remove the abandoned provider, profile, portable-ID, trait, migration, and hook work from Investment. {Codex/Sol}

Validation: no bridge symbol or portable identity remains under `extensions/kiat/investment`; no timezone toggle appears above a database table.

### Phase 1 — Base-owned relational export

Goal: any live registered table with a primary key can enter a deterministic relational export without module code.

- [x] Derive module scopes and table definitions from `TableRegistry` plus live schema indexes and foreign keys. {Codex/Sol}
- [x] Order selected tables by intra-selection foreign-key dependencies and fail closed on unsupported cycles. {Codex/Sol}
- [x] Serialize exact columns and physical primary keys as deterministic canonical NDJSON with binary, JSON, decimal, date, and datetime fidelity. {Codex/Sol}
- [x] Add schema, scalar, record, line, table, and package bounds under honest `transfer_limits` configuration. {Codex/Sol}
- [x] Produce repeatable previews with exact tables, records, per-table bytes and hashes, total size estimate, target, and preview hash. {Codex/Sol}
- [x] Cover discovery, numeric key ordering, relationship order, value fidelity, package determinism, and nullable unique behavior using Base test tables only. {Codex/Sol}
- [ ] Add destination-neutral external export adapters and a download surface when a concrete non-Belimbing consumer defines its required format; reuse this relational serializer and add no module hooks.

Validation: `DataBridgeGenericPackageTest` passes without importing or referring to any application module.

### Phase 2 — One-time receive grants and streaming transport

Goal: an authorized LIVENPC user can issue one short-lived receive capability, manually convey it to an authorized source user, and accept one streamed package over LAN or Cloudflare without persistent machine credentials or plan/apply access.

- [x] Add Base Settings-backed instance identity and upward-only environment direction policy, with deterministic code defaults for first access. {Codex/Sol}
- [x] Replace persistent pairing storage with Base-owned receive grants carrying public ID, hashed 256-bit secret, issuer actor, expected source ID/role, allowed scope, target, maximum bytes, expiry, status, consumed package hash, and lifecycle timestamps. {Codex/Sol}
- [x] Generate a compact copy-once receive bundle and revoke/expire grants without ever persisting or logging the plaintext secret. {Codex/Sol}
- [x] Bind preview and package manifests to the exact receive grant and reject mismatched target, source, role, scope, expiry, or size before Incoming acceptance. {Codex/Sol}
- [x] Stream raw canonical NDJSON from Laravel's HTTP client through FrankenPHP/Laravel request streams into bounded private staging; do not Base64-wrap the package or buffer it in JSON-RPC. {Codex/Sol}
- [x] Consume one grant atomically only after full package verification and reject replay or competing bytes while leaving rejected/interrupted streams unconsumed. {Codex/Sol}
- [x] Remove pairing, SSH forced-command, and Cloudflare service-token/JWT code, commands, configuration, capabilities, schema, routes, UI, and tests. {Codex/Sol}
- [x] Store package hash, counts, bytes, grant ID, expiry, scope, issuer, and consumption outcome in the ledger without domain values or secret material. {Codex/Sol}
- [x] Store operator configuration in global `base_settings`, expose it through an authorized Data Bridge Settings page, retain static defaults in code, and remove every Data Bridge setting from `.env.example`. {Codex/Sol}
- [x] Document the one-time-grant LAN/Cloudflare Tunnel route and authenticated-admin-versus-capability endpoint boundary in the settings UI, runbook, and this plan. {Codex/Sol}
- [x] Let targets advertise up to five HTTPS receive base URLs and let web/CLI source operators choose an exact advertised LAN or Cloudflare endpoint without changing package identity. {Codex/Sol}
- [ ] Configure the LIVENPC instance ID/role, advertised routes, request bounds, and private storage through Data Bridge Settings; configure only TLS/Tunnel ingress and human access policy in deployment-owned infrastructure.
- [ ] Deliver separate grant-bound packages over LAN and Cloudflare and prove each source Outgoing SHA-256 equals its target Incoming SHA-256; different grant IDs intentionally make the two packages different.

Validation: only a target-issued valid one-time key accepts a bounded stream; receipt creates no plan and changes no selected domain row; wrong/revoked/expired/replayed key, target, source, scope, direction, size, or bytes fail before Incoming acceptance.

### Phase 3 — Deterministic destination planning

Goal: the destination can explain the complete effect of a package without mutating selected tables or consulting module logic.

- [x] Re-read the destination schema and enforce compatible columns, nullability, primary keys, unique indexes, and foreign keys, with a narrow SQLite/PostgreSQL logical-type compatibility layer. {Codex/Sol}
- [x] Classify missing non-colliding rows as inserts, byte-equivalent rows as unchanged, and every primary-key difference, unique collision, or unresolved reference as a conflict. {Codex/Sol}
- [x] Persist ordered plan actions using truthful physical-primary-key names and derive deterministic destination and plan hashes without database-generated plan IDs. {Codex/Sol}
- [x] Make a conflict anywhere block the whole plan; remove update, delete, skip, destructive, source-wins, destination-wins, and merge options. {Codex/Sol}
- [x] Prove repeat planning is deterministic and that changed destination state invalidates the reviewed plan. {Codex/Sol}
- [ ] Run the compatibility suite against a disposable PostgreSQL destination in addition to SQLite CI.

Validation: planning performs no selected-table write; identical package and destination state produce identical plan hashes; every ambiguous case is inapplicable.

### Phase 4 — Guarded apply, recovery, and audit

Goal: a ready plan applies atomically where supported, remains attributable, and cannot bypass production recovery.

- [x] Require exact reviewed package and plan hashes, explicit confirmation, a global apply lock, and a second fresh-plan pass before mutation. {Codex/Sol}
- [x] Insert selected rows in foreign-key order inside a database transaction and resynchronize PostgreSQL serial sequences after explicit numeric-key insertion. {Codex/Sol}
- [x] Reject replay and make a later package over identical state plan entirely unchanged. {Codex/Sol}
- [x] Require and SHA-256-verify a new backup artifact before production apply. {Codex/Sol}
- [x] Record export, grant lifecycle, receipt, plan, apply, and apply-failure events without payload or secret values. {Codex/Sol}
- [x] Separate export/send, receive-grant issue/revoke, plan review, apply, and diagnostic capabilities; retain no unused destructive or persistent-trust capability. {Codex/Sol}
- [ ] Perform a production-shaped restore drill from the pre-apply backup before declaring the pilot complete.

Validation: injected failure leaves destination rows unchanged; a stale or conflicting plan cannot enter mutation; production cannot apply when backup creation or verification fails.

### Phase 5 — Web and CLI operator workflow

Affected pages: `admin/system/database-bridge`; `admin/system/database-tables/{table}`

Goal: operators choose whole modules or exact tables, understand transport and blast radius, and cannot confuse receipt with apply.

- [x] Add scope listing, preview/export/send, grant issue/revoke, inspect, plan, retention, and apply CLI commands over the shared services. {Codex/Sol}
- [x] Make Export the primary tab with receive-bundle paste, Base-discovered module selection, exact table checkboxes, an “entire module” action, disabled no-primary-key explanations, exact grant/target binding, per-table preview, and streamed send. {Codex/Sol}
- [x] Show Incoming receipts and deterministic plan counts separately from guarded apply confirmation. {Codex/Sol}
- [x] Add Receive grant issue/copy-once/revoke controls and show the payload-free grant/receipt/plan/apply ledger. {Codex/Sol}
- [x] Add a dedicated authorized Data Bridge Settings page for identity, HTTPS routes, storage, retention, bulk-transfer limits, and diagnostic limits, all persisted through `SettingsService`. {Codex/Sol}
- [x] Retain diagnostic capture as a clearly separate development-only workflow. {Claude Fable 5; Codex/Sol}
- [x] Verify tab deep links, malformed-key validation, loading copy, all five workflows, and the advertised-route selector in the in-app browser; the current 22-table Investment pilot preview reports 42,036 rows, and at 562 px all tab labels remain visible with no horizontal overflow or console errors. {Codex/Sol}
- [ ] Complete a keyboard-only issue, copy, send, plan, and apply walkthrough against a non-development target before the pilot.

Validation: the web and CLI paths produce the same hashes and policy decisions; unauthorized users cannot enumerate packages or trust state; receipt alone never enables apply.

### Phase 6 — Diagnostic capture and development-only import

Goal: operators can reproduce problematic rows in a disposable development database without weakening production transfer rules.

- [x] Capture selected rows and their actual foreign-key parent closure byte-exact with bounded depth, row count, size, and explicit secret redaction. {Claude Fable 5; Codex/Sol}
- [x] Store a canonical diagnostic manifest with source driver, encoding/collation provenance, table schemas, row bytes, hashes, and development-only policy. {Claude Fable 5; Codex/Sol}
- [x] Add preview/hash review, protected package inventory, deletion, capability boundaries, and Database Tables entry point. {Claude Fable 5; Codex/Sol}
- [x] Add guarded development import with target checks, compatibility plan, stale hash refusal, transaction, and optional post-restore sanitization contributors for schedules, AI schedules, queues, credential settings, and sessions. {Codex/Sol}

Validation: the diagnostic capture/import suites preserve unusual bytes, redact known secrets, refuse staging/production, and leave no partial import.

### Phase 7 — Investment to LIVENPC pilot

Affected pages: `admin/system/database-bridge`

Goal: the development Investment scope moves once to LIVENPC production without any Investment bridge code and the repeat plan is a no-op.

- [x] Confirm Investment appears automatically as `extensions/kiat/investment` from its ordinary registered tables and contains no bridge provider, hook, portable identity, or model change. {Codex/Sol}
- [x] Profile the current source-side Investment scope: 24 bridgeable tables and 42,043 rows with no primary-key or schema-cycle refusal. The reviewed 22-table pilot selection has 42,036 rows and a 43,513,846-byte estimate. A preview hash is grant/target-bound, so no synthetic benchmark hash is retained as LIVENPC evidence. {Codex/Sol}
- [x] Review the source inventory: the scope contains no credential, cookie, saved browser-session, or filesystem-path column; `kiat_investment_maybank_realized_positions.trading_account` is captured account identity, while `kiat_investment_agent_tasks` (4 current rows) and `kiat_investment_agent_runs` (3 current rows) are operational execution state and are explicitly deselected for the first pilot. Table selection, not module code, is the boundary. {Codex/Sol}
- [ ] Generate a LIVENPC receive grant for the development instance and test the same endpoint over preferred LAN routing and Cloudflare Tunnel fallback.
- [ ] Run and record the exact grant-bound LIVENPC preview and package SHA-256 after the target issues that grant.
- [ ] Export once, deliver, verify Incoming, review a zero-conflict plan, verify the fresh production backup, and apply during an operator-approved window.
- [ ] Run read-only relational integrity checks and application smoke checks without jobs, notifications, scraping, or external side effects.
- [ ] Export again and prove the second LIVENPC plan is entirely unchanged.
- [ ] Restore the pre-import backup into a disposable instance and verify recovery instructions.

Validation: LIVENPC has the expected Investment rows and relationships, no unrelated tables moved, no conflict was overridden, the package is attributable, and the second plan changes nothing.

### Phase 8 — Hardening and general release

Goal: the connected path remains bounded, maintainable, and safe under operational failure and hostile input.

- [x] Cover tampered bytes, wrong target, wrong/revoked/expired/replayed keys, consumed-grant hash binding, malformed bundles, package bounds, schema drift, missing parents, unique/primary-key conflicts, and stale destination state. {Codex/Sol}
- [x] Add explicit foreign-key-cycle and scalar/canonical-line/record/table bound coverage, and prove only one of two requests authenticated before consumption can consume the grant. {Codex/Sol}
- [x] Run a process-level concurrent competing-send race against file-backed SQLite and prove exactly one atomic grant consumption and one ledger event. {Codex/Sol}
- [ ] Run the same process-level concurrent competing-send race against PostgreSQL.
- [x] Add Incoming/Receiving/Outgoing retention cleanup that preserves ledger rows and never deletes an unapplied package without an explicit policy. {Codex/Sol}
- [x] Prove a truncated HTTP stream leaves its grant usable, a complete retry succeeds, and replay is refused after success. {Codex/Sol}
- [x] Exercise apply-lock contention, production backup refusal before mutation, mid-transaction failure rollback, and a clean retry of the unchanged ready plan. {Codex/Sol}
- [ ] Exercise process-level grant races and ambiguous network-response recovery against deployed endpoints.
- [x] Measure the current 22-table/42,036-row Investment pilot preview on SQLite: 43,513,846 estimated bytes in 10.830 seconds with 44,040,192 peak PHP bytes; keep the production-shaped PostgreSQL run open. {Codex/Sol}
- [x] Publish `docs/runbooks/data-bridge.md` covering receive-grant creation/conveyance/revocation, LAN/Cloudflare routing, Investment table review, preview/send, conflict resolution, backup/restore, retry ambiguity, audit lookup, and retention. {Codex/Sol}
- [ ] Remove or split this plan only after the production pilot, no-op repeat, restore drill, PostgreSQL compatibility run, browser verification, and adversarial suite are complete.

Validation: all phase evidence is reproducible from repository tests or deployment runbooks, and a newly registered module becomes selectable without changing Base or module code.
