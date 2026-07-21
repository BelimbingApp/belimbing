# Base Development Table Mirror

Status: In progress — provider onboarding and portable SQLite-to-PostgreSQL mirroring are replacing the PostgreSQL-only assumption
Last Updated: 2026-07-21
Sources: `docs/plans/base-data-share.md`; `docs/runbooks/development-table-mirror.md`; `docs/architecture/database.md`; `docs/architecture/module-system.md`; `app/Base/Database`; `app/Base/Settings`; `app/Base/Media/PhotoCleanup`; `app/Modules/Core/AI`; `resources/core/views/AGENTS.md`
Agents: Codex/Sol-Ultra; codex/sol-extra

## Problem Essence

Belimbing development moves among local SQLite workstations, cloud development hosts, and provider-managed databases, but complete selected-table state stays behind on one endpoint. The first mirror implementation proved exact PostgreSQL-to-PostgreSQL table images, yet it requires a PostgreSQL local database and command-line clients, exposes no provider setup or initialization workflow, and cannot serve the actual SQLite-local allocation path.

## Desired Outcome

An authorized operator connects a development mirror provider, completes a guided and reviewed initialization, then pushes or pulls the complete contents of explicitly selected registered tables. Supabase is the first provider and generic PostgreSQL follows the same provider contract; local SQLite remains a first-class source and destination engine.

Schema stays owned by code and migrations. Provider initialization applies the destination checkout's migrations to create engine-correct schema and protected infrastructure before data moves. Portable mirroring replaces complete selected-table contents across SQLite and PostgreSQL without claiming native DDL fidelity. When both endpoints are PostgreSQL, an optional native table-image mode may additionally replace exact PostgreSQL table definitions and owned objects.

## Top-Level Components

### Provider registry and endpoint contract

Base owns a thin tagged provider registry. A provider describes its key, operator label, credential fields, connection test, endpoint capabilities, initialization preflight, and initialization service. Provider adapters hide hosted-service details from catalog, review, and transfer orchestration.

`Supabase` is the first visible provider. `Generic PostgreSQL` reuses the PostgreSQL endpoint mechanics without Supabase-specific copy or assumptions. Future providers register through the same contract rather than adding branches to the mirror manager.

Provider credentials are global instance settings, write-only after save, encrypted at rest, excluded from persistent plaintext caches, and redacted from exceptions, logs, browser state, process arguments, and audit metadata.

### Database-engine adapters and transfer modes

Provider identity and database engine are separate axes. SQLite and PostgreSQL adapters expose registered-table discovery, snapshot reads, transactional replacement writes, foreign-key preflight, value normalization, and postcondition verification.

Portable mode is the blessed cross-engine path. It transfers typed rows into migration-provisioned destination tables and supports SQLite ↔ PostgreSQL without local `pg_dump` or `psql`. Portable mode never claims to transfer triggers, policies, custom types, native defaults, collations, expression indexes, or other engine-owned DDL.

Native PostgreSQL mode remains an optional same-engine adapter using `pg_dump` and `psql` for exact table images. It is available only when both endpoints and compatible tools satisfy its stricter contract.

### Guided provider onboarding

Data Share Settings presents a provider catalog rather than an unexplained URL. Connecting Supabase is a staged workflow:

1. paste a one-time Supabase Management API access token;
2. let Belimbing discover the organizations and projects that token can access;
3. create a dedicated development project with a generated database password, or select an existing project and enter the password Supabase cannot reveal;
4. let Belimbing derive, test, and encrypt the PostgreSQL connection without asking the operator to assemble a URL;
5. inspect whether the target is empty, initialized, already owned by another instance, or incompatible;
6. initialize the endpoint as a distinct development instance, then transfer explicitly reviewed initial data;
7. verify registry, row counts, constraints, and protected-table exclusions before reporting ready.

The access token is transient setup authority and is cleared after the project connection is configured. Creating a project is an explicit, confirmed action because it can affect Supabase billing. Generic PostgreSQL keeps the manual URL path because no common management API exists across licensee-managed hosts.

Initialization never copies local secrets, sessions, queues, caches, the local instance ID, or an existing mirror credential. It creates a new remote instance ID and sets the remote role to `development`.

### Explicit catalog and directional review

The catalog remains the union of registered live tables. Module and search controls filter visibility only; every review and execution carries a non-empty, deduplicated list of exact table names.

Portable actions are truthful about schema ownership:

| Selected-table state | Portable action |
|---|---|
| Present on both endpoints with compatible migration-owned schema | Replace destination rows |
| Present at source but absent at destination | Block and initialize/apply migrations |
| Absent at source but present at destination | Block and reconcile code/migrations |
| Schema or ownership incompatible | Block with the exact prerequisite |

Native PostgreSQL mode retains `Create`, `Replace`, and `Delete` table-image actions behind its stricter same-engine capability.

### Coordination, atomicity, and failure truth

The configured provider endpoint is the coordination authority for both directions. One bounded provider lock is acquired before the final review and source snapshot and held through destination commit, so push and pull cannot cross or apply a pre-lock dump. Engine adapters add their own target transaction and local snapshot semantics below that operation lock.

A stale review fails before mutation with an explicit “review again” result. Only a connection loss after mutation begins may be reported as indeterminate. Server-reported failures roll back the selected destination changes and leave unselected tables untouched.

## Design Decisions

### Migrations own schema; the portable mirror owns data

Options were translating SQLite DDL into PostgreSQL, limiting local development to PostgreSQL, or using migrations to render engine-correct schema while the mirror transfers typed data. DDL translation would create a second incomplete schema compiler and still could not reproduce PostgreSQL policies, functions, collations, or custom types. Requiring PostgreSQL locally contradicts the established lightweight development allocation. Migration-owned schema wins because code remains the single source of truth and each engine receives its native schema through existing BLB conventions.

### Provider and engine are separate seams

Options were one adapter per endpoint pair, one provider abstraction that also owns every database behavior, or a thin provider registry composed with engine adapters. Pair adapters multiply as Supabase, generic PostgreSQL, SQLite, and future transports arrive. One provider god-interface leaks engine details into hosted-service setup. Separate seams win: Supabase owns connection and onboarding; PostgreSQL and SQLite own database behavior; the orchestrator composes capabilities without provider branches.

### Portable default, native fidelity when earned

Options were one lowest-common-denominator transfer contract or distinct explicit modes. A single contract either lies about cross-engine fidelity or discards PostgreSQL-native capability. Distinct modes win because the UI can state exactly what will move: portable complete-table rows over migration-owned schema, or an exact native PostgreSQL table image when both endpoints qualify.

### Guided initialization, not a credential dead end

A saved URL that rejects a fresh Supabase project leaves the operator without a next action. Provider setup therefore owns connection, preflight, initialization review, schema bootstrap, initial data selection, execution, and verification as one progressive workflow. Destructive steps remain separate confirmations and always materialize the exact table list.

### Supabase account discovery, not connection-string assembly

Supabase connection coordinates are provider implementation detail. The normal path accepts a transient Management API token, discovers accessible projects, and builds the database connection itself. A newly created dedicated project needs only organization and data-region choices; Belimbing generates the database password and never renders it. An existing project additionally requires its current database password because Supabase deliberately does not return that secret. Manual PostgreSQL URLs remain available for the generic adapter and as an advanced Supabase recovery path.

## Public Contract

- Mirror is development-only on both endpoints; staging and production are refused before snapshot or mutation.
- Provider credentials are global, encrypted, write-only, never persisted in plaintext caches, and never exposed in browser state or diagnostics.
- Supabase and generic PostgreSQL are provider adapters; SQLite and PostgreSQL are engine adapters. Provider code does not implement table-transfer policy.
- Local SQLite needs no PostgreSQL command-line client for portable mode. It needs only the configured PHP database drivers required to connect to each endpoint.
- Portable mode transfers complete selected-table contents into migration-provisioned compatible schema. It does not transfer native DDL or database-specific objects.
- Native PostgreSQL mode is available only for two PostgreSQL endpoints with compatible client tooling and advertises its stronger exact-table-image semantics explicitly.
- Code and migrations move through Git before data. Missing or incompatible destination schema blocks portable execution and routes the operator back to initialization/migration preflight.
- Every initialization and mirror operation materializes a reviewed non-empty exact table list. Filters and modules never become implicit mutation scope.
- Protected infrastructure, credentials, sessions, queues, caches, migration history, and filesystem-owned objects are never data-mirror selections.
- A provider-wide operation lock covers fresh review, source snapshot, and destination commit in both directions. A target transaction makes selected writes atomic where the engine supports it.
- Stale review, blocked preflight, pre-mutation failure, rollback, indeterminate connection loss, and success are distinct operator-visible outcomes.
- Existing immutable offer-based Data Share remains unchanged and remains the production-safe promotion path.

## Phases

### Phase 0 — Contract and recovered baseline

Goal: The plan and implementation tell one cross-engine, provider-backed story without discarding proven PostgreSQL work.

- [x] Recover the uncommitted PostgreSQL mirror prototype, UI, CLI, tests, CI job, and runbook after repository synchronization. {codex/sol-extra}
- [x] Replace the PostgreSQL-local product assumption in this plan with provider, engine, and transfer-mode boundaries. {codex/sol-extra}
- [x] Rewrite the operator runbook around provider onboarding, SQLite-local portable mode, migration-owned schema, optional native PostgreSQL mode, credential rotation, and recovery. {codex/sol-extra}
- [x] Keep `docs/plans/base-data-share.md` explicit that provider mirroring is separate from immutable production offers. {codex/sol-extra}

### Phase 1 — Security, coordination, and truthful failure states

Goal: Credentials remain encrypted everywhere at rest and no overlapping direction can apply stale state.

- [x] Prevent decrypted encrypted settings from entering persistent caches and prove it using the database cache store. {codex/sol-extra}
- [x] Replace the target-transaction-only lock with one provider-wide bounded lock covering fresh review, snapshot/export, and commit for push and pull. {codex/sol-extra}
- [x] Distinguish stale review and known pre-mutation failures from genuinely indeterminate commit outcomes in web and CLI feedback. {codex/sol-extra}
- [x] Remove the real `example.test` connection attempt from the feature suite and keep URL-redaction proof at the parser boundary. {codex/sol-extra}

### Phase 2 — Provider registry and Supabase onboarding

Affected pages: `/admin/system/data-share/settings#data_share_mirror`

Goal: A fresh Supabase development project can move from credentials to an initialized, verified mirror without undocumented shell work.

- [x] Add a tagged mirror-provider contract and registry using the established thin-spine provider pattern. {codex/sol-extra}
- [x] Implement Supabase and generic PostgreSQL provider definitions over a shared PostgreSQL endpoint connection service. {codex/sol-extra}
- [x] Store provider key and provider-owned encrypted credentials as global settings with preserve, replace, test, and remove behavior. {codex/sol-extra}
- [x] Add safe endpoint preflight states for unreachable, empty, initialized, self-target, non-development, incompatible, and ready. {codex/sol-extra}
- [x] Add a reviewed initialization service that applies migration-owned schema/infrastructure, creates a distinct remote development identity, and never copies protected runtime state. {codex/sol-extra}
- [x] Route initial eligible-table data through the same explicit Mirror review and postcondition verification as later transfers. {codex/sol-extra}
- [x] Replace Supabase URL entry with transient account discovery, dedicated-project creation, existing-project selection, generated connection configuration, and progressive initialization. {codex/sol-extra}
- [x] Keep manual URL entry as an advanced recovery path and as the normal generic PostgreSQL setup. {codex/sol-extra}

### Phase 3 — Portable SQLite and PostgreSQL data mirror

Goal: Complete selected-table contents move atomically between local SQLite and provider PostgreSQL over compatible migration-owned schema.

- [x] Add a tagged transfer-engine contract/registry plus driver-aware catalog, compatibility, dependency, snapshot, replacement, and verification services. {codex/sol-extra}
- [x] Implement SQLite and PostgreSQL portable transfer using typed values rather than native dump syntax. {codex/sol-extra}
- [x] Preserve exact selection, ownership, protected-table, foreign-key, and unselected-table boundaries in both directions. {codex/sol-extra}
- [x] Replace rows in dependency-safe order, reset target identity/sequence state where applicable, and roll back the complete selected set on failure. {codex/sol-extra}
- [x] Block source-only, destination-only, cyclic, or incompatible schemas with migration-owned next steps rather than translating or dropping DDL. {codex/sol-extra}
- [ ] Verify empty tables, Unicode, JSON, binary, dates, decimals, nullable values, composite keys, foreign keys, and large bounded streams across SQLite and PostgreSQL. {codex/sol-extra}

### Phase 4 — Native PostgreSQL mode

Goal: Preserve the proven exact-table-image path as an optional stronger capability without making it the portable default.

- [x] Move PostgreSQL executable discovery and table-image execution behind the tagged native transfer engine. {codex/sol-extra}
- [x] Acquire provider coordination before native export so waiting operations cannot apply pre-lock dumps. {codex/sol-extra}
- [x] Keep no-`CASCADE`, private temporary material, exact object fidelity, registry reconciliation, and target transaction guarantees. {codex/sol-extra}
- [x] Advertise native mode only when both endpoints and tools pass the strict compatibility preflight. {codex/sol-extra}

### Phase 5 — Provider-first operator surfaces

Affected pages: `/admin/system/data-share/settings#data_share_mirror`; `/admin/system/data-share#mirror`

Goal: Operators understand provider state, initialization progress, transfer mode, exact selection, and failure boundaries without reading a runbook first.

- [x] Replace the unexplained URL-only settings group with provider selection plus progressive test, save, initialize, and remove actions. {codex/sol-extra}
- [x] Distinguish connection, initialization-required, incompatible, and ready states without credential rehydration. {codex/sol-extra}
- [x] Label endpoints from the configured provider and state `Portable data` or `Native PostgreSQL` beside the mirror workflow. {codex/sol-extra}
- [x] Keep explicit table selection, count-bearing direction actions, blockers, separate confirmation, loading, keyboard-native controls, and responsive layouts. {codex/sol-extra}
- [x] Provide truthful empty, schema-migration-required, stale-review, rollback, indeterminate, and success feedback. {codex/sol-extra}
- [x] Prove that the guided Supabase path asks only for a Management API token plus the existing-project password when required, never renders generated credentials, and explains provisioning/billing state. {codex/sol-extra}

### Phase 6 — Proof and handoff

Goal: Automated and live evidence covers the actual SQLite-local Supabase workflow and the retained native PostgreSQL path.

- [ ] Run Pint, focused settings/backend/Livewire/CLI tests, existing offer regressions, and the full suite with failures classified. {codex/sol-extra}
- [x] Add the SQLite ↔ PostgreSQL portable integration file to the existing two-PostgreSQL native CI job. {codex/sol-extra}
- [ ] Browser-test provider setup, initialization, empty/blocked/ready mirror states, both directions, light/dark themes, keyboard focus, and narrow/desktop layouts. {codex/sol-extra}
- [ ] Perform a credential-redacted live SQLite-local → Supabase initialization, push, pull, unselected-control, rollback, and concurrency proof. {codex/sol-extra}
- [ ] Repeat the provider workflow from WSL2 and the cloud development host when those allocations are available. {codex/sol-extra}

Evidence records may contain dates, provider labels, endpoint instance names, selected table names, action counts, and outcomes. They must never contain credentials, connection coordinates, database values, retained transfer files, or content hashes derived from private data.

## Verification Log

### 2026-07-21 — provider and portable implementation

- Pint and `git diff --check` pass.
- Settings cache, mirror backend, and mirror Livewire suites pass together; coverage includes encrypted persistent-cache exclusion, provider registry/options, URL policy, initialization identity, write-only credentials, empty-provider onboarding, stale review, indeterminate native failure, exact selection, and authorization.
- Both PostgreSQL integration files compile and are wired into the `postgres-mirror` CI job. They skip locally until `BLB_POSTGRES_MIRROR_TESTS=true` and the two isolated PostgreSQL databases are available.
- Signed-in local browser verification passed for provider settings, the unconfigured Mirror state, hash-driven tab selection, 390 px layout without horizontal overflow, light/dark themes, and browser-console errors.
- The full `tests/Feature/Database` gate and a narrower non-mirror Data Share regression gate each exceeded the local 10-minute process cap without emitting a failure. Their orphaned test workers were stopped. Do not treat these two timed-out commands as passes; CI/full-suite proof remains open.

### 2026-07-21 — guided Supabase account onboarding

- Replaced the normal Supabase URL field with a progressive account-token workflow: discover organizations/projects, create a dedicated project or select an existing development project, derive a provider connection, test it, initialize schema when ready, then hand off to explicit initial table review.
- New-project database passwords are generated server-side and never enter Livewire state. Supabase access tokens leave Livewire state immediately after discovery, remain only as encrypted session data during the active setup, and are deleted on completion or reset. Existing-project passwords are cleared as soon as connection setup succeeds.
- Provider-authored port-5432 session-pooler coordinates are preferred when the Management API exposes them; the stable direct endpoint is the fallback. Transaction-pooler coordinates are refused. Manual URL entry remains collapsed under **Advanced connection** for recovery, and stays the primary generic PostgreSQL path.
- Focused settings, mirror backend, and encrypted settings suites pass: 64 tests. The Supabase cases cover safe authentication errors, transient-token handling, project discovery, session-pooler discovery, existing-project configuration and replacement, server-generated passwords, encrypted connection storage, preservation across post-creation preflight failure, and provisioning state.
- Signed-in browser verification passed at the default desktop viewport and 390 × 844: progressive content, provider switching, collapsed advanced recovery, no horizontal overflow, and no browser console warnings/errors. The Impeccable detector reported no findings for the new Blade surface.
- A combined run that added `DataShareGenericPackageTest` to those passing suites reached the established 10-minute local cap without emitting a failure; its three orphaned processes were stopped. This is not counted as a pass, and the immutable package path remains in the CI/full-suite proof backlog.
