# Base Data Export and Data Share

Status: In progress — the Base-owned relational package, source-published Data Share offer transport, target-side planning/apply, CLI, settings, and web surfaces are built and locally verified; deployed fetch, LIVENPC, PostgreSQL, recovery, and production-pilot evidence remains
Last Updated: 2026-07-11
Sources: `docs/brief.md`; `docs/architecture/module-system.md`; `docs/runbooks/data-share.md`; `DESIGN.md`; `app/Base/Database`; RFC 6750
Agents: Claude Fable 5; Codex/Sol

## Problem Essence

Belimbing must move selected authored relational data between instances without copying the whole database, exposing database credentials, selecting thousands of rows manually, or requiring modules to implement transport code. The first real case is moving the Investment scope from this development machine to LIVENPC production. The same Base mechanism must work for every registered module and exact table subset.

## Desired Outcome

An authorized source operator selects a registered module scope or table subset, reviews a deterministic preview, and publishes an immutable, expiring transfer offer. The offer bundle advertises one or more source HTTPS routes and carries a short-lived bearer capability. An authorized target operator independently reviews that offer, chooses a reachable LAN or Cloudflare route, pulls and verifies the stream into Incoming, then builds a deterministic plan before any domain row changes.

Base owns discovery, serialization, transport, verification, planning, recovery, apply, settings, and audit. Modules do not implement, register, customize, or hook into Data Export or Data Share.

## Architecture

### Base-owned relational export

`base_database_tables` is the inventory. Base groups live registered tables by `module_path`, reads their primary keys, unique indexes, and foreign keys, orders selected tables by dependency, and serializes rows as canonical bounded NDJSON. Selecting a module selects every shareable table by default; the operator may choose an exact subset.

The relational snapshot is destination-neutral. It records source identity and role, scope, tables, schema contracts, physical keys, values, counts, hashes, package identity, offer identity, creation, and expiry. It does not record a target, target credential, target route, or target receipt.

### Data Export and Data Share

Data Export is the reusable snapshot foundation. Data Share is its Belimbing-to-Belimbing publication, delivery, verification, planning, recovery, and mutation specialization. A future external adapter may transform the same serializer output for a concrete consumer; it must not add module hooks or weaken the share contract.

### Source-published transfer offers

The source owns the data and therefore owns what is offered, the exact immutable package bytes, availability window, advertised routes, and revocation. Publishing creates one package and one transfer offer with a 256-bit secret. The secret appears in the JSON bundle and is persisted encrypted at rest on the offer record; its separate SHA-256 hash is used for authentication. This lets an authorized source operator recopy an available offer without reissuing it or storing the secret in plaintext.

The bundle uses `belimbing-data-share/offer/v1` and contains the public offer ID, source identity and role, scope, package ID, package SHA-256, exact bytes and counts, expiry, one or more advertised HTTPS endpoints, and the bearer secret. It contains no database credential and names no target.

The source exposes only `GET /data-share/offers/{offerId}`. The request authenticates with `Authorization: Bearer`, is rate-limited, and streams the exact protected Outgoing bytes. It cannot plan, apply, enumerate tables, or mutate source data. The same immutable bytes may be downloaded until the offer's fetch limit, expiry, or revocation. `max_downloads` (null means unlimited, source-side default via the UI is 1) is claimed atomically before streaming so concurrent requests cannot exceed the authorization limit; the offer moves to `exhausted` as soon as its final slot is claimed. An interrupted transfer therefore consumes a slot. Package/offer/hash replay protection still makes receipt and apply idempotent, and an operator can publish a new offer when another transfer attempt is required.

### Target pull and local receipt

The target operator pastes and reviews the offer bundle. Before networking, target policy checks source role, upward direction, advertised route membership, expiry, declared bytes, and local hard limits. The target then pulls the stream into bounded private Receiving storage, verifies response headers, exact byte count, package hash, package ID, offer ID, manifest, schema, counts, and every payload hash, and only then records a target-local Incoming receipt.

Receipt binds the package to the current target locally. It never plans or applies. A partial or invalid fetch is deleted and can be retried while the source offer remains available. Package ID/hash/offer identity prevent rebinding or duplicate mutation.

### Planning and apply

The target classifies each incoming row as `insert`, `unchanged`, or `conflict`. Missing keys with resolvable parents are inserts; byte-equivalent rows are unchanged; any differing key row, unique collision, missing parent, partial reference, or schema ambiguity is a conflict. There are no generic updates, deletes, source-wins modes, or module merge hooks.

Apply requires the exact reviewed package and plan SHA-256, re-verifies bytes, recomputes actions against current state under a lock, requires a verified production recovery point, inserts in foreign-key order in a transaction, and advances PostgreSQL sequences after explicit numeric keys.

### LAN, WAN, and future subscriptions

LAN and Cloudflare are peers: both are source routes selected by the target. For LAN the target reaches the source privately. For WAN a narrow Cloudflare Tunnel route reaches only the source offer endpoint; ordinary source admin pages remain under human login/Access policy. Persistent pairing credentials, Cloudflare service tokens, SSH transport, JSON-RPC/Base64 package wrapping, detached signing, and recipient encryption are not part of this user-mediated connected path.

The offer shape deliberately permits future catalogs or subscriptions: a target could discover offers or subscribe to a source-owned feed later. No discovery, polling, persistent token, schedule, cursor, or subscription state is built until a concrete use case defines it.

## Public Contract

- Canonical package format is `belimbing-data-share/package/v1` as bounded canonical NDJSON; transfer offers use `belimbing-data-share/offer/v1`.
- Every selected table must be live, registered, and have a primary key. Unsupported relations remain visible with a reason.
- Packages preserve physical primary and foreign keys and exact normalized values.
- Binary and invalid UTF-8 values use an explicit Base64 envelope. JSON, booleans, numbers, dates, and datetimes normalize deterministically.
- Package, table, record, line, and scalar limits are enforced before unbounded allocation or insertion.
- Instance identity, offer routes, offer lifetime, fetch timeout, storage paths, retention, and limits live in global `base_settings` with code defaults and an authorized settings UI; `.env` is not the operator contract.
- Default direction is upward only: development to staging or production, and staging to production. Lateral and downward transfer fail closed on the target.
- Offer endpoints are HTTPS, contain no credentials/query/fragment, and match the exact public offer ID.
- Offer secrets never enter URLs, package bytes, logs, ledger metadata, or persisted plaintext; the offer record holds only application-encrypted ciphertext so an authorized source operator can recopy an available bundle.
- A package ID cannot be rebound to different bytes or another offer. An applied package cannot be applied again.
- Planning and receipt do not mutate domain tables. Apply remains a separate capability and explicit act.
- The ledger stores identities, offer/package/plan hashes, counts, bytes, actors, lifecycle outcomes, and bounded errors without payload values or secrets.

## Operator Surfaces

- `Share`: select scope/tables, preview, publish, then copy the source-owned offer bundle.
- `Incoming`: paste/review an offer, select one advertised LAN or Cloudflare route, fetch and verify, then plan/apply a local receipt.
- `Published`: list source offers, their scope/package/hash/bytes/expiry/download telemetry, and revoke an available offer.
- `History`: inspect export, offer, fetch, receipt, plan, apply, prune, and failure events.
- `Diagnostics`: retain the separate development-only byte-exact row capture/import workflow.
- CLI exposes scope listing, preview/publish, offer fetch/revoke, package inspection, planning, retention, and apply through the same services.

## Delivery Phases

### Phase 0 — Operator surface and ownership

- [x] Remove the duplicate table-local timezone toggle; retain the global top-bar control. {Claude Fable 5; Codex/Sol}
- [x] Present bulk table/module movement as Data Export/Data Share rather than row copy. {Codex/Sol}
- [x] Establish Data Export as the foundation and Data Share as its Belimbing specialization. {Codex/Sol}
- [x] Prohibit and remove module-specific integration, providers, hooks, portable identities, and module migrations. {Codex/Sol}

### Phase 1 — Generic relational export

- [x] Discover scopes/tables from `TableRegistry` and live schema metadata. {Codex/Sol}
- [x] Order foreign-key dependencies and fail closed on unsupported cycles. {Codex/Sol}
- [x] Serialize canonical bounded NDJSON with deterministic previews, hashes, identities, and value fidelity. {Codex/Sol}
- [x] Cover generic Base test tables without importing an application module. {Codex/Sol}
- [ ] Add an external-export adapter only when a concrete non-Belimbing consumer defines its required format.

### Phase 2 — Source offers and pull transport

- [x] Add Base Settings-backed stable identity and upward direction policy. {Codex/Sol}
- [x] Replace target receive grants and push transport with source-owned immutable transfer offers, secret hashes, expiry, revocation, and download telemetry. {Codex/Sol}
- [x] Make preview and packages target-neutral while binding each published package to one source offer. {Codex/Sol}
- [x] Add the authenticated read-only streaming offer endpoint with exact response metadata and repeatable download until expiry/revocation. {Codex/Sol}
- [x] Add target streaming fetch, bounded temporary staging, exact header/byte/hash verification, retry cleanup, and target-local receipt binding. {Codex/Sol}
- [x] Remove receive-grant schema, push controller/sender, stale commands, capabilities, UI, settings, docs, and tests. {Codex/Sol}
- [x] Declare both Data Share migrations incubating and add a guarded pre-pilot conversion that refuses to discard meaningful receipt/plan/apply history. {Codex/Sol}
- [ ] Configure LIVENPC/source identities, source routes, limits, storage, and Tunnel ingress in deployment-owned infrastructure.

### Phase 3 — Conservative destination planning

- [x] Verify destination schema and classify deterministic insert/unchanged/conflict actions. {Codex/Sol}
- [x] Refuse unique collisions, changed primary keys, missing parents, partial references, unsupported cycles, and stale state. {Codex/Sol}
- [x] Persist exact action plans and deterministic plan hashes without module code. {Codex/Sol}
- [ ] Prove the LIVENPC Investment plan is conflict-free before apply.

### Phase 4 — Recovery and apply

- [x] Require exact reviewed hashes, lock/recompute, and transactional inserts. {Codex/Sol}
- [x] Require and verify a fresh production backup; refuse production apply if recovery cannot be proven. {Codex/Sol}
- [x] Advance PostgreSQL sequences after explicit keys and preserve idempotent re-plan behavior. {Codex/Sol}
- [ ] Complete a LIVENPC restore drill and record recovery time/evidence.

### Phase 5 — UX, settings, CLI, and operations

- [x] Add Base-owned Data Share pages, settings in `base_settings`, package inventory, history, and contextual help. {Codex/Sol}
- [x] Rename the feature contract to Data Share and replace the Export/Receive push sequence with Share/Published/Incoming publish-and-pull UI and revised help. {Codex/Sol}
- [x] Replace grant CLI with `blb:db:share` preview/publish, fetch, and offer-revoke commands. {Codex/Sol}
- [x] Update the runbook for source-side LAN/Cloudflare ingress, retry ambiguity, revocation, and CapabilityCatalog-recognized operator actions. {Codex/Sol}
- [x] Verify Share, Incoming, Published, History, Diagnostics, Settings, help, deep links, responsive layout at 562 px, and console health in the in-app browser; no horizontal overflow or browser warnings/errors were present. {Codex/Sol}
- [ ] Complete a keyboard-only publish, convey, deployed fetch, plan, and apply walkthrough against a non-development target before the pilot.

### Phase 6 — Diagnostics

- [x] Keep diagnostic row capture/import separate, bounded, redacted, and development-only. {Claude Fable 5; Codex/Sol}
- [x] Preserve exact parent closure, schema/hash verification, guarded import, and post-restore sanitization. {Claude Fable 5; Codex/Sol}

### Phase 7 — Investment to LIVENPC pilot

- [x] Confirm Investment is discovered automatically and has no Data Share-specific code. {Codex/Sol}
- [x] Profile 24 shareable tables/42,043 rows and review the 22-table authored-data pilot (42,036 rows; agent task/run tables excluded explicitly). {Codex/Sol}
- [ ] Publish the exact source offer and test target pull over preferred LAN plus Cloudflare fallback using the same immutable package SHA-256.
- [ ] Fetch to LIVENPC, review a zero-conflict plan, verify a fresh recovery point, and apply in an approved window.
- [ ] Run read-only integrity/application checks, fetch the same offer or publish an equivalent snapshot, and prove the repeat plan is all unchanged.
- [ ] Restore the pre-import backup into a disposable instance and verify the documented recovery procedure.

### Phase 8 — Hardening and release

- [x] Cover relational bounds, tampering, schema drift, conflicts, stale plans, retention, apply lock, backup refusal, and rollback. {Codex/Sol}
- [x] Replace receive-grant race tests with offer immutability, bearer refusal, revoke/expiry, direction/limit checks, streamed target fetch, wrong-response-metadata cleanup, target-bound receipt idempotency, and apply replay tests. {Codex/Sol}
- [ ] Add deployed truncated-socket cleanup/retry evidence against FrankenPHP; automated HTTP tests prove bounded fetch and cleanup but do not reproduce a mid-socket disconnect.
- [ ] Run transport and apply suites against PostgreSQL and deployed FrankenPHP endpoints.
- [ ] Measure the production-shaped Investment pull path and preserve source/target hash evidence.
- [ ] Remove or split this plan only after the pilot, no-op repeat, restore drill, PostgreSQL run, browser verification, and adversarial suite are complete.

## Completion Evidence

The production pilot must record source/target identities and roles, exact scope/tables, selected route, offer ID/expiry, package ID/count/bytes/source-and-target SHA-256, plan hash and action counts, backup identity/hash, apply actor/time, post-apply checks, repeat all-unchanged result, and restore-drill result. Never record the bearer secret or domain payload values.
