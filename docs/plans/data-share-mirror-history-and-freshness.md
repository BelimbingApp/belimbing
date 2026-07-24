# Data Share Mirror History and Freshness

**Status:** In progress — durable history is operational; shared remote caching, pull freshness reconciliation, and full browser/subprocess proof remain open
**Last Updated:** 2026-07-24
**Sources:** `app/Base/Database/Services/DataShare/Mirror/DataShareMirrorManager.php`, `app/Base/Database/Services/DataShare/Mirror/DataShareMirrorCatalog.php`, `app/Base/Database/Livewire/DataShare/Concerns/ManagesDevelopmentTableMirror.php`, `app/Base/Database/DTO/DataShare/Mirror/DataShareMirrorExecutionResult.php`, `app/Base/Audit/AGENTS.md`, `app/Base/Audit/Services/AuditSemanticActionRecorder.php`, `app/Base/Foundation/Contracts/SemanticActionRecorder.php`, `app/Base/Schedule/Services/ScheduleRunRecorder.php`, `extensions/sb-group/ibp/Models/ImportBatch.php`, `extensions/sb-group/ibp/Services/MarketSpotImportRunner.php`, `extensions/sb-group/ibp/Services/LegacyAxImporter.php`, `docs/plans/base-schedule-observability.md`
**Agents:** Amp/OpenAI Codex; Codex/GPT-5

## Implementation Status (2026-07-23 review)

**Built and tested** (`tests/Feature/Database/DataOperationLedgerTest.php`, `DataOperationsPageTest.php`, `DataShareMirrorBackendTest.php`, `extensions/sb-group/ibp/Tests/Feature/ImportMarketSpotCommandTest.php`):

- The shared ledger — `base_database_data_operation_runs`, `base_database_data_operation_tables`, `base_database_data_share_observations` (incubating, `base_`-prefixed, auto-increment id; all three permanently protected from mirroring).
- Foundation `DataOperationRecorder` contract (`open`/`resume`/`recordTable`/`finalize`) with a `Null` default and the real `LedgerDataOperationRecorder`: current browser/console actor attribution, best-effort **idempotent** audit projection (guarded, no backlink), first-terminal-status-wins, and rejection of late summary writes/resume after terminalization. Queue/scheduler actor kinds, browser role/surface, and exact schedule context still require the deferred execution-context contract.
- Mirror push/force-push/pull are recorded in-lock. Execution now fails before mutation unless a durable run and exact planned table manifest exist; engine result manifests must match that plan exactly. Per-table observations commit in one short Local transaction, then terminal success/audit projection occurs after commit; confirmed destination mutation plus bookkeeping failure is indeterminate.
- The execution result carries its durable ledger `run_id`; the mirror completion summary links to it and `/admin/system/data-operations` is deep-linkable to a specific run (`?run=`).
- AX/IBP import records an `ax_import` run with honest per-action effect counts (delete ≠ reject conflation fixed) and a `quoted_on` `min_max_hint` range. The private extension wires parent-opened `--operation-run-id` to the resuming child; production-style browser-to-child attribution proof is still open.
- Central read-only history UI at `/admin/system/data-operations` (route, capability, menu, filters, per-table detail).
- **Baseline-observation workflow** — `captureBaseline()` first obtains every selected Local/remote count, then atomically records the labelled `mirror_baseline` summaries and observations. Any count/bookkeeping failure records a failed run and leaves the prior current projection unchanged; the UI never presents a baseline as an original push.
- **Transient result table removed** — a completed mirror operation now shows a compact summary + durable-run link; per-table counts persist in the catalog columns and the durable run.
- **Freshness mechanism (Phase 3 conditional GO)** — `base_database_data_freshness_events` (protected) + an append-only `DataFreshnessTracker`; the catalog shows **Unknown** unless PostgreSQL has an enabled tracking trigger for that exact table. Compaction is one race-safe delete statement. Manual PostgreSQL 17.9 benchmarking rejected the naive shared-row design (deadlock) in favour of append-only events (no observed deadlock, ~5.7× faster); reproducible restore/pull and version-matrix evidence remains open below.
- **Phase 4 code** — push acknowledges exactly the generation captured before mutation; `DataFreshnessAttachmentService` + `blb:db:share:freshness-attach` attach triggers using Local-only eligibility, explicitly (never on migrate), and no-op on non-PostgreSQL. Detaching obsolete triggers and restoring/reconciling triggers after native pull are not implemented, so broad operational attachment remains pending.
- **Stale-run reconciler** — `DataOperationReconciler` + `blb:db:data-operations:reconcile` sweep runs that stayed `running` past the timeout and mark them `indeterminate` (via the same atomic finalize claim); never guessed as failed. Tested.
- **Local-first catalog + async enrichment** — `DataShareMirrorCatalog::localCatalog()` builds the picker from the Local registry with **no remote call** and merges durable endpoint observations through a non-connecting endpoint identity (normalized host + effective port + database; no provider-wide fallback). The Livewire component always starts from fresh Local rows with `mirrorRemotePending = true`; a separate `enrichMirrorRemote()` request fills remote presence. The stale session-wide combined snapshot was removed because it could hide Local membership/observation changes. A shared endpoint-scoped stale-while-revalidate remote snapshot remains open.
- **PR validation repair (2026-07-24)** — the native PostgreSQL mirror harness installs the real incubating operation-history migration and asserts the successful durable run, keeping its fixtures aligned with the fail-closed mutation contract. Freshness health now requires the exact public, origin-enabled, statement-level **AFTER** trigger and reads generations from the caller-supplied connection; unavailable trigger metadata preserves endpoint counts while freshness falls back to **Unknown**.

**Fixes from code review (2026-07-23):** Force Push re-reviews inside the lock and verifies the visible review token (no stale destructive plan); observations are keyed by a stable remote-endpoint id (normalized host + effective port + database), never the provider adapter key, and Force Push no longer passes a null remote; mirror execution fails closed without durable history and persists its reviewed manifest before mutation; incomplete/duplicate engine manifests become indeterminate; baseline scans are all-or-nothing; late ledger writes cannot mutate terminal runs; audit projection follows Local commit; AX imports track commit and report post-commit bookkeeping failures as indeterminate, not "failed before commit"; determinate engine failures are recorded `failed`, only uncertain outcomes `indeterminate`; the ledger models are excluded from global mutation audit (regression-tested: one semantic action, zero bookkeeping mutations); the history UI labels endpoint counts as *Local · remote (observed)*, not before→after.

**Remaining / open:**

- **Freshness restore and pull semantics.** Enabled-trigger health prevents a false **Clean**, but native pull can remove a Local trigger and portable pull can create a Local change event. Trigger reattachment/suppression/acknowledgement must be proven before broad attachment.
- **Shared remote snapshot.** Current loading is truthful Local-first plus a separate live enrichment request; it does not yet provide cross-session stale-while-revalidate remote presence.
- **Attribution completeness.** Browser/console attribution is present. Queue/scheduler kinds, browser role/surface, exact schedule context, and a production subprocess attribution test remain open.
- **Live browser timing capture.** The Local-first ordering is built and proven deterministically at the component level (Local rows + `mirrorRemotePending` in the first response, remote enrichment in a separate `enrichMirrorRemote` request via `x-init`). A literal browser screenshot/waterfall — showing Local rows painted while a *slow* remote enriches afterward — is meaningful only against a configured (and ideally latent) Supabase endpoint; it can be captured live via `/run` once such an endpoint is connected. The behaviour itself is implemented and tested.

## Problem Essence

Mirror results currently exist only in transient Livewire state, so the Local and remote row counts disappear after refresh and there is no durable answer to who pushed or pulled which tables, when, or with what outcome. Recording every affected row as an audit mutation would be both misleading and unbounded because native and portable mirroring use bulk database operations that bypass Eloquent, while scheduled imports and Investment processes can change many source rows without producing complete model mutation logs.

The same gap exists beyond mirroring. Mass data updates—the `sbg:ibp` AX API import, other IBP imports, Investment processes—rewrite large row ranges with no shared, queryable record of when, which tables, what changed, and how many rows. Ironically the import side already keeps a partial answer in `sbg_ibp_import_batches` (imported_by, imported_at, status, row/accepted/rejected/quarantined counts, with child rows linking back via `import_batch_id`), but it is IBP-only, file-shaped, conflates deletions with rejections, carries a nullable actor, and is disconnected from both mirror history and the global audit timeline. Mirror and imports are the same *kind* of event—an actor-attributed bulk data operation over one or more tables—yet there is no common axis that answers "what mass data change happened, to which tables, when, by whom."

## Desired Outcome

Every attempted mass data operation—mirror push, force push, pull, and reporting imports such as the AX/IBP market-spot import—has one durable, actor-attributed operation record and one compact summary per affected table answering **when**, **which tables**, **what changed** (per action, with honest effect counts), and, where the key is ordered, **the key range** touched. Each terminal operation projects at most one best-effort, idempotent semantic action into the global audit timeline; the action references the detailed record, and the ledger—not audit—is the count of record.

For mirror specifically, the Local catalog renders immediately without waiting for Supabase, retains the latest successful Local and remote observations across refreshes, and enriches remote state independently when available. Operators can inspect prior push, force-push, pull, and import runs on one central surface, and PostgreSQL installations can later identify Local tables changed since their last successful push without logging every changed row or coupling every scheduled command to Data Share.

## Top-Level Components

### Data operation ledger (mirror + imports)

Base Database owns an append-style operation ledger shared by every mass data operation. A **run** (`base_database_data_operation_runs`) uses an auto-increment integer id—human-friendly (operators reference "run #1042") and consistent with the sibling `base_schedule_runs` and `sbg_ibp_import_batches` ledgers, which makes the IBP convergence trivial. The ledger is per-installation and a protected, never-mirrored table, so sequential ids never leave home as identities and cannot collide across endpoints. A run records the operation identity, an `operation_type` discriminator (`mirror_push`, `mirror_force_push`, `mirror_pull`, `ax_import`, `investment_process`, …), source and endpoint identities, force policy, transfer mode, actor and trace context, a nullable opaque `schedule_run_ref` (see attribution below—not a foreign key), lifecycle status, timing, safe failure details, aggregate table count and total rows affected, and a nullable `audit_projection_attempted_at` marker. The ledger does **not** store a link to the audit action: `SemanticActionRecorder::record()` returns void and persists later, so no action ID is available at write time (see the audit bridge).

One **per-table summary** (`base_database_data_operation_tables`) per affected table records:

- the set of `actions` applied (a table may be both `upsert` and prune-`delete` in one operation),
- honest effect counters—`rows_source`, `rows_attempted`, and the nullable per-effect breakdown `rows_inserted` / `rows_updated` / `rows_deleted` / `rows_unchanged` / `rows_rejected` (insert vs update stays null where a bulk `upsert` cannot distinguish them; `rows_written` carries the combined figure),
- nullable endpoint totals `rows_before` / `rows_after` (mirror observations, not the same as attempted writes),
- ordered `key_columns` (composite-safe) with a `range_kind` (`contiguous` / `min_max_hint` / `not_applicable`) and canonical tuple boundaries `first_key` / `last_key` (null when not applicable—UUID/text/random keys or no primary key),
- schema fingerprints, observation timestamps, and—when freshness tracking is available—the Local generation captured and acknowledged.

Mirror is operation writer #1; the AX/IBP import is operation writer #2. The ledger is the authoritative history. Audit actions project meaningful operation events into the global audit timeline but do not replace the ledger because semantic audit writes are deferred and deliberately best-effort.

### Current observation projection

A compact mirror-only projection (`base_database_data_share_observations`) keyed by Local instance, remote instance, and table stores only the latest successful observations. The main Mirror table reads this projection on every render, independently of the five-minute remote catalog cache, so endpoint changes never display counts from a different mirror and history retention never removes the current state. Imports have no remote endpoint to compare, so they write ledger rows but not this endpoint-scoped projection.

The projection labels counts as **last observed**, not verified equality. Portable transfer can prove snapshot count and content equality; native transfer observes endpoint counts after its external transaction and concurrent Local writes may already have changed the source.

### Local-first catalog and remote enrichment

The Local table registry is the catalog source of truth and renders synchronously without testing or scanning Supabase. Remote-only registry entries do not expand the picker: application code and Local migration ownership must exist before a table can be safely pulled. A registered Local table remains visible even when its Local relation is missing, which preserves recovery use cases.

The page immediately merges two non-blocking sources into those Local rows: persisted successful observations and a shared last-known remote catalog snapshot keyed to the stable endpoint. If the snapshot is absent or stale, a separate Livewire request refreshes remote presence, relation kind, ownership, and connection status, then enriches the existing rows. A remote failure leaves the Local catalog usable and reports the remote columns as unavailable; it never replaces the table with a page-level loading state or empties Local results.

The remote snapshot uses shared stale-while-revalidate semantics rather than the current session-only cache because endpoint catalog state is not user-specific. Explicit **Refresh remote data** bypasses freshness, while automatic refresh serves stale data with its observation time until a new successful snapshot arrives. A queued refresh job is deferred unless measurement shows that a separate Livewire request still harms worker availability.

Catalog enrichment never performs live row counts for every table. Row counts come from completed operations or an explicit baseline/observation action and persist in the current observation projection. Live review remains authoritative and inspects only the exact selected tables, so cached presence and counts are display hints rather than mutation permission.

### Audit bridge

Each terminal operation run attempts to emit **at most one** best-effort retained semantic action into `base_audit_actions` through the Foundation-owned `SemanticActionRecorder` contract. Because `record()` returns void, persists via a deferred buffer, and swallows audit failures, the ledger cannot obtain an action ID, confirm persistence, or block on it—so the projection is explicitly best-effort and the direction of reference is inverted: the audit action names the operation as its **subject** (subject `data_operation`, id = the operation run id), rather than the ledger storing an action ID. Emission is made idempotent by keying on the operation run id so stale-run reconciliation and retries do not create duplicate actions; the ledger records only that emission was attempted (`audit_projection_attempted_at`). A stronger delivery guarantee (a transactional outbox) is possible but deferred until best-effort projection is proven insufficient.

Push, force push, pull, and reporting imports are all semantic operations; none generate synthetic audit mutations. The action contains the operation ID, operation type, direction, endpoint or source identity, result, force flag, and aggregate table counts, and links readers to the detailed ledger instead of duplicating the table manifest in JSON.

Ledger, observation, and generation writes are excluded from ordinary mutation auditing so a 43-table operation does not create 44 irrelevant framework mutation entries about its own bookkeeping.

### Local table freshness tracker

PostgreSQL mirror-eligible Local tables may gain statement-level tracking for `INSERT`, `UPDATE`, `DELETE`, and `TRUNCATE`. A generic trigger appends one generation event per affected statement in the same database transaction as the source change; race-safe compaction retains only the latest event per table. This covers Eloquent, query-builder upserts, raw SQL, `COPY`, scheduler commands such as `sbg:ibp:import-market-spot`, and Investment processes without creating one history row per business record.

Freshness is an operator aid, not automatic synchronization. It may power **Changed since last push** and **Select changed tables**, but it never starts a push and never weakens explicit review. SQLite reports freshness as unknown until a comparably truthful mechanism exists.

### Data operations history UI

The generalized history lives on one central Base Database surface—**`/admin/system/data-operations`**—that lists every operation (mirror and imports, filterable by operation type) with actor, direction, endpoint or source, table count, result, and duration; opening a run shows its per-table effect counts, key ranges, and safe failure details. Audit Log Actions links to the same operation subject for organization-wide chronology.

Scoped views link *into* that surface rather than reimplementing it, so imports are not visually coupled to Mirror:

- The **Mirror** catalog gains persistent **Local rows**, **Remote rows**, **Observed**, and **Freshness** columns and a scoped history filtered to mirror operations.
- The **IBP import workbench** links its runs to the central surface.

Once durable history and catalog observations exist, the current transient post-operation result table is removed. A completed operation updates the catalog projection in place and shows a compact success summary with a link to its durable run; users no longer need to know that a temporary result table exists below the review panel, and refresh no longer destroys the only visible evidence.

## Design Decisions

### Record operations on a shared ledger plus semantic action, not audit mutations

Mass data operations sit on their own axis, distinct from the two Base concerns already present:

| Axis | Answers | Owner |
| --- | --- | --- |
| Audit (`base_audit_*`) | What happened to *this entity/record*? | Base Audit |
| Schedule (`base_schedule_runs`) | Did *this job* run / succeed / fail / skip? | Base Schedule |
| **Data operation ledger** (`base_database_data_operation_*`) | What *mass data change* touched *which tables*, *when*, *what effect*, *how many rows*, by whom? | Base Database |

Three approaches for the ledger axis were considered:

1. **Audit actions for push and mutations for pull.** Rejected. Native pull uses external `psql` and portable pull uses bulk query operations, so Eloquent mutation capture would be incomplete. Manufacturing per-row mutations would misrepresent the mechanism and could produce millions of retained rows. The same objection applies to a mass import.
2. **One semantic audit action containing the entire operation.** Better cardinality, but insufficient as the source of truth: audit action persistence is deferred and best-effort, `record()` returns void so no backlink or confirmation is possible, large table manifests are awkward to query, and current catalog observations must survive audit retention.
3. **A Base Database run ledger with one best-effort semantic action projection, generalized to an operation type.** Recommended. It preserves actor and operational truth in a queryable bounded shape, keeps Audit decoupled through its Foundation contract, and gives the catalog a durable source for current observations. Making the ledger operation-typed from day one lets mirror (writer #1) and the AX/IBP import (writer #2) share one table, one History UI, and one audit bridge instead of the current two disconnected mini-ledgers: the transient mirror DTO and the IBP-only `sbg_ibp_import_batches`.

Any writer records through a Foundation-owned `DataOperationRecorder` contract (a sibling to `SemanticActionRecorder`), so the `sb-group/ibp` extension and Investment processes report provenance without depending on Data Share internals. This is not a coupling of imports to the mirror: recording provenance is not triggering a mirror, and no scheduled task pushes a mirror in this plan.

The audit projection is best-effort and idempotent, not authoritative: it references the operation by its run id as subject, is deduplicated by that id, and the ledger remains the count of record. Cardinality is one run plus one row per affected table, not one row per mirrored record. No configurable retention is needed initially; indexed timestamps and measured growth should precede pruning. If pruning becomes necessary, current observations and retained semantic actions survive detailed-history deletion.

### Own operation identity explicitly across process boundaries

An operation may span a browser request that spawns a child Artisan process (the AX import runs via `LegacyAxCliProcess`, with an in-process fallback). If the child self-opens a run it records a console actor and loses the browser actor; if both the runner and the importer open one, history duplicates. Ownership is therefore explicit:

- The **initiator that holds real attribution opens the run** and obtains the run id: a browser parent captures the authenticated user, company, role, surface, and trace, inserts the operation (status `running`), and passes the returned id to a **resumable child**.
- A **child process resumes** the passed id (`DataOperationRecorder::resume($id)`); it never opens its own run.
- **Direct CLI or scheduler invocation self-opens** with its real console/scheduler actor.
- The **in-process path** naturally keeps the parent actor.

`DataOperationRecorder` exposes `open(...) : int` (returns the new run id), `resume(int $id)`, per-table `record(...)`, and `finalize(status, …)`. A production-style subprocess attribution test asserts a browser-initiated import is attributed to the browser user, not the console.

### Track source change generations at the database boundary

Three freshness approaches were considered:

1. **Infer changes from audit mutations.** Rejected because raw SQL, query-builder bulk writes, external clients, and many imports bypass Eloquent.
2. **Require scheduled tasks to declare affected tables.** Useful as a future attribution hint, but rejected as authority *for the freshness dirty-state question* because manual and queued writes remain possible and a successful task does not prove that data changed. Note the distinction: for the *provenance* question—"this import ran and wrote these rows"—the importer genuinely is the authority on its own writes, and its self-reported effect counts are more truthful than a native mirror's post-hoc observation. Declaration is authoritative provenance and non-authoritative freshness at the same time.
3. **Use PostgreSQL statement-level triggers.** Recommended after a proof gate. They observe every database writer, roll back with the source transaction, and collapse arbitrary row volume into compact per-table state. The cost is an extra control-row write, WAL, possible hot rows, and new lock-order risks that must be measured on real imports before broad attachment. Triggers are the completeness safety net for un-instrumented writers; explicit `DataOperationRecorder` reporting gives rich attributed provenance. They are complementary, not competing.

Phase 1 deliberately remains correct without freshness tracking: operators select tables explicitly and receive persistent history and observations. The freshness proof gate ships only if trigger restore and workload evidence are acceptable.

### Preserve honest transaction boundaries

The Local ledger and an external `psql` transaction cannot commit atomically. A run is created only after a locked review succeeds and before mutation begins. Terminal meanings are strict:

- **Succeeded:** the engine confirmed completion and Local terminal recording succeeded.
- **Failed:** no destination mutation is known to have committed, including export or other pre-mutation failures.
- **Indeterminate:** the destination may have committed, including a disconnected or non-zero `psql`, process crash, post-commit observation failure, or Local finalization failure.
- **Running:** active only; stale runs are reconciled to indeterminate, never guessed to have failed.

No Local database transaction remains open during a potentially hour-long dump or restore. A future remote receipt protocol may resolve recurring indeterminate commits, but distributed-transaction simulation is not part of this design.

### Render Local first and cache remote state at endpoint scope

Three loading approaches were considered:

1. **Keep one blocking combined catalog and rely on the session cache.** Rejected. The first visit remains slow, cache work is repeated across sessions, and a remote failure prevents Local tables from rendering even though Local owns selection.
2. **Render Local, then perform remote enrichment in the same Livewire component with a shared stale snapshot.** Recommended. It gives immediate useful UI, keeps remote state endpoint-scoped, preserves simple request/error handling, and avoids introducing a job protocol solely to load one screen.
3. **Queue every remote refresh and poll for completion.** Operationally isolated but premature. It adds durable job state, worker dependency, polling, and cancellation concerns before a separate post-render request has been measured as harmful.

The catalog never treats cached remote state as review evidence. The exact selected-table review still performs fresh endpoint checks immediately before mutation. This keeps the display fast without creating a stale-write safety gap.

### Do not fabricate history for completed operations

The existing `sbg_` push predates the ledger. Its 43 schemas and row counts were verified after completion, but the system did not persist an operation identity at mutation time. After Phase 1, an authorized **Capture baseline observation** action may record current fingerprints and counts as a clearly labelled retrospective baseline with the actor and time of observation. It must not be presented as the original push or infer an original user from nearby logs.

Historical `sbg_ibp_import_batches` may be backfilled into the ledger as labelled retrospective import runs, but only with what that table truthfully recorded: `imported_by` is nullable and `LegacyAxImporter` writes it as null, so those runs must preserve an **unknown** actor—never inferring console, scheduler, or user attribution—and inherit no per-table key range where none was captured.

## Public Contract

### Operation identity and attribution

- Every mass data operation—push, force push, pull, and reporting import—that reaches mutation preparation receives a stable auto-increment run id and an `operation_type`.
- Operation identity is owned by the initiator that holds real attribution. A browser parent opens the run and passes its id to a resumable child; the child never opens its own run; direct CLI/scheduler invocation self-opens with its real actor type. Browser runs retain the authenticated user, company, role, request surface, and trace; console, scheduler, queue, and agent actors retain their actual process actor type rather than being presented as users.
- The stable Local Data Share instance ID identifies this installation; a versioned, non-secret hash of normalized remote host, effective port, and database identifies the configured remote endpoint. Imports identify their source (for example `sbg.ibp.market-spot`). Credentials, the full URL, and the broader configuration fingerprint are never persisted as endpoint identity or exposed in history.
- Schedule correlation is a nullable opaque `schedule_run_ref`, not a foreign key to `base_schedule_runs`, so Base Database is not coupled to Base Schedule internals or migration order. It is recorded only when a Base-owned execution-context contract exposes a run reference; otherwise it stays null. Exact, queryable schedule-run correlation is deferred. Temporal proximity is never presented as causation.

### Operation lifecycle

- Normal push, force push, and pull share one internal locked lifecycle: fresh review, optional force-policy transformation, run creation, engine execution, terminal recording, observation projection, then a best-effort semantic action attempt.
- Force policy remains push-only and bypasses only missing/incompatible schema blockers. There is no Force Pull.
- Reviews that remain blocked and operations that fail to acquire the lock do not create mutation-attempt history.
- Stale running operations become indeterminate after the configured operation timeout plus a bounded grace period.
- The audit projection is at most one action per operation, keyed idempotently by the operation run id; re-emission during reconciliation or retry must not duplicate it, and its absence never changes the ledger's terminal status.

### Per-table observations and effect counts

- History records every affected table, including the set of `actions` applied and terminal table status.
- Effect is recorded as honest counters, keeping distinct quantities separate: `rows_source` (rows seen), `rows_attempted` (writes attempted), the nullable per-effect breakdown `rows_inserted` / `rows_updated` / `rows_deleted` / `rows_unchanged` / `rows_rejected`, and endpoint totals `rows_before` / `rows_after`. Deletions are never folded into a rejected count. Where a bulk `upsert` cannot separate insert from update, those two counters are null and `rows_written` carries the combined figure. No count is coerced from unknown to zero; zero is recorded only when positively observed.
- Key range is recorded as ordered `key_columns` plus a `range_kind` (`contiguous`, `min_max_hint`, or `not_applicable`) and canonical tuple boundaries. Composite primary keys are supported; UUID/text/random keys and keyless native tables record `not_applicable` with null boundaries. A `min_max_hint` is a hint, not proof of contiguity; the effect counts remain authoritative.
- Schema fingerprints are endpoint observations tied to the run. Portable verified content hashes are retained only when actually computed and verified.
- Failed and indeterminate runs remain visible but never replace the latest successful catalog projection.

### Mass data operations and imports

- Any writer records through the Foundation-owned `DataOperationRecorder` contract; extensions and Investment processes never depend on Data Share internals to report provenance.
- A reporting import opens (or resumes) a typed run, records each written table with its actions, effect counters, and key range where applicable, and finalizes with the same strict succeeded/failed/indeterminate/running terminal semantics.
- The importer is authoritative for its own writes; its self-reported effect counts are trusted provenance and are not re-derived from a post-hoc scan.
- `sbg_ibp_import_batches` converges on the shared ledger: it is either folded in or retained as IBP-specific detail that references `base_database_data_operation_runs.id`, never a competing source of truth. Its current delete-as-rejected conflation is corrected when it reports through the recorder.
- Recording provenance is not triggering a mirror. No scheduled task automatically pushes a mirror in this plan.

### Catalog loading

- Local rows render without a configured or reachable remote endpoint and remain searchable/selectable while remote enrichment is pending.
- The picker is Local-registry-driven; remote-only ownership cannot introduce executable tables into this checkout.
- Persisted observations and shared last-known remote state render immediately with explicit observation times.
- Automatic remote enrichment runs separately and updates only remote fields. Failure preserves Local rows and last-known observations while showing an honest remote error.
- Remote enrichment does not count all tables. Explicit baseline/observation and completed operations own durable counts.
- Review and execution do not trust cached remote state; they freshly inspect the exact selection.

### Freshness generations

- Only ordinary mirror-eligible registered PostgreSQL tables receive tracking triggers. Audit, schedule, registry, operation ledger, observation, and generation infrastructure are always protected and excluded.
- Trigger updates participate in the source transaction, so rolled-back writes do not mark a table changed. `TRUNCATE` is covered explicitly.
- A push acknowledges only the Local generation captured for its snapshot. It never clears a boolean dirty flag. Concurrent commits must remain newer than the acknowledged generation and therefore remain changed.
- Pull suppresses or acknowledges its own Local replacement within a trusted transaction-local context so recovery does not immediately appear as an unpushed Local edit.
- SQLite and any unproven driver report **Unknown**, never **Clean**.

### Scheduler and import behavior

- Existing scheduler run history remains the authority for whether a task ran, succeeded, failed, or was skipped.
- A reporting import records its own provenance run through `DataOperationRecorder`; freshness triggers additionally observe database effects of any scheduler command and Investment process without those commands calling Data Share services.
- Exact schedule-run-to-table attribution is deferred until the scheduler run ID and trace can be propagated into database sessions without inference; the running command cannot retrieve the run ID that today lives only on the scheduler `Event`. Optional affected-table declarations provide hints, never freshness authority.
- No scheduled task automatically pushes a mirror in this plan.

## Phases

### Phase 1 — Durable operation history and persistent observations

Affected pages: `/admin/system/data-share#mirror`, `/admin/system/data-operations`, `/admin/audit/actions`

Goal: Local tables appear immediately on first visit, remote state fills in independently, and a completed or interrupted mirror operation remains understandable after refresh with persistent endpoint observations in the main catalog.

- [x] Split `DataShareMirrorCatalog` into an immediate Local registry catalog and exact remote enrichment so Local rendering has no provider-status or remote-snapshot dependency. {Amp/OpenAI Codex}
- [ ] Replace the session-only combined snapshot with a shared endpoint-scoped stale-while-revalidate remote snapshot carrying an observation time and explicit invalidation when the configured endpoint changes.
- [x] Render fresh Local rows and persisted observations first, start remote enrichment as a separate request, and preserve Local rows on timeout or failure. {Amp/OpenAI Codex}
- [ ] Keep search, filtering, and selection responsive while enrichment runs; disable only actions whose fresh selected-table review is actively running rather than the entire catalog.
- [x] Add incubating Base Database storage—`base_database_data_operation_runs` (typed by operation, opaque `schedule_run_ref`, `audit_projection_attempted_at`), `base_database_data_operation_tables` (actions set, per-effect counters, ordered `key_columns` + `range_kind` + tuple boundaries, fingerprints), and `base_database_data_share_observations` (current endpoint-scoped counts)—with protected-table registration and indexes for operation type, endpoint, status, actor, trace, and time. {Amp/OpenAI Codex}
- [x] Keep operation bookkeeping out of global mutation audit while attempting at most one best-effort semantic action per operation, idempotent by operation run id, referencing the operation as subject rather than storing an action backlink. {Amp/OpenAI Codex}
- [x] Consolidate normal push, force push, and pull under one locked execution lifecycle with fresh in-lock review and strict succeeded, failed, indeterminate, and stale-running semantics. {Amp/OpenAI Codex}
- [x] Require a durable run, persist the exact planned table manifest before engine mutation, reject mismatched engine result manifests, and finalize observations without holding a Local transaction across external transfer work. {Amp/OpenAI Codex}
- [ ] Persist schema fingerprints from the exact review/execution observations; current mirror summaries persist nullable counters and ranges but do not yet populate fingerprint columns.
- [x] Merge current observations into fresh Local catalog renders and isolate observations by a versioned host/port/database endpoint identity with no provider-wide fallback. {Amp/OpenAI Codex}
- [x] Build the central `/admin/system/data-operations` history/detail surface and add persistent **Local rows**, **Remote rows**, **Observed**, and **Freshness** columns with completion deep links into the central surface. {Amp/OpenAI Codex}
- [x] Replace the transient post-operation table with an in-place catalog projection update, compact completion summary, and durable run link. {Amp/OpenAI Codex}
- [x] Add an all-or-nothing baseline-observation workflow that labels the existing 43-table state as retrospective rather than inventing a historical push. {Amp/OpenAI Codex}
- [ ] Cover first uncached visit, shared cached visit, stale refresh, remote timeout/failure, endpoint replacement, browser and CLI attribution, normal and force push, pull, pre-mutation failure, indeterminate `psql`, portable rollback, stale-run reconciliation and idempotent re-projection, Local finalization failure, audit projection failure, and cache refresh behavior.

Validation: Focused Backend/UI tests; isolated PostgreSQL 17/18 native integration; portable integration; browser timing proof that Local rows render before delayed remote enrichment; browser verification of refresh, endpoint switch, baseline, and Audit Action link; idempotent-reprojection assertion; credential redaction assertions; Pint and UI convention scan.

### Phase 2 — Import and mass-update provenance

Affected pages: `/admin/system/data-operations`, `/admin/audit/actions`, `extensions/sb-group/ibp` import workbench

Goal: The AX/IBP import (and later Investment processes) write the same durable provenance as mirror—one run, per-table effect counts and key ranges—reusing the shared ledger and audit bridge, correctly attributed across the subprocess boundary, without triggering any mirror.

- [x] Add a Foundation-owned `DataOperationRecorder` contract (sibling to `SemanticActionRecorder`) with a `Null` binding and `open`/`resume`/`record`/`finalize` methods, so any writer records a run plus per-table summaries without depending on Data Share internals. {Amp/OpenAI Codex}
- [ ] Define subprocess ownership: the browser parent opens the run and passes the run id to `MarketSpotImportRunner`; the child Artisan process resumes that id; direct CLI/scheduler invocation self-opens with its real actor. Add a production-style subprocess attribution test proving a browser-initiated import is attributed to the browser user, not the console.
- [x] Wire `LegacyAxImporter` to record per-table effect counts that model its real behavior—separating upserts from stale-row deletions and from genuine rejections (fixing the current `rejected_count += prunedCount` conflation)—with `first_key`/`last_key` only where the key is ordered. {Amp/OpenAI Codex}
- [ ] Converge `sbg_ibp_import_batches`: fold it into the shared ledger or retain it as IBP-specific detail that references `base_database_data_operation_runs.id`, with no duplicate source of truth.
- [x] Emit at most one best-effort semantic action per import run, idempotent by operation run id, referencing the run; keep per-row import writes out of mutation audit. {Amp/OpenAI Codex}
- [x] Record the opaque `schedule_run_ref` only if the execution-context contract exposes one; otherwise leave it null. Exact schedule-run correlation is not a Phase 2 acceptance criterion. {Amp/OpenAI Codex}
- [x] Surface imports in the central `/admin/system/data-operations` history, filterable by operation type, with per-table effect counts and ranges. {Amp/OpenAI Codex}
- [ ] Link the central data-operation run from the IBP workbench/import result.
- [ ] Optionally backfill historical `sbg_ibp_import_batches` as labelled retrospective import runs, preserving an unknown actor where `imported_by` is null and inheriting only what that table truthfully recorded.
- [ ] Cover import success, partial/rejected rows, deliberate stale-row deletion counted as deletion (not rejection), scheduler vs manual invocation, subprocess attribution, audit projection, and the assertion that no mirror is triggered.

Validation: IBP import command/service tests asserting run plus per-table effect counts and range, subprocess browser attribution, audit projection idempotency, and no mirror side effect; Pint and convention scan.

### Phase 3 — PostgreSQL freshness proof gate

Affected pages: `/admin/system/data-share#mirror`

Goal: Evidence proves compact generation tracking is complete and operationally safe before it is attached to production tables.

- [x] Prototype append-only generation infrastructure and statement-level tracking for insert, update, delete, and truncate; retain the recorded manual upsert/copy evidence pending a reproducible checked-in matrix. {Amp/OpenAI Codex}
- [x] Prove rollback behavior, captured-generation acknowledgement, and missing, disabled, or wrong-timing trigger fallback to **Unknown** rather than false **Clean**. {Amp/OpenAI Codex; Codex/GPT-5}
- [ ] Verify selected-table `pg_dump` and restore behavior because table dumps include trigger definitions but not necessarily their shared function or control tables.
- [ ] Ensure tracking infrastructure is independently migrated on both PostgreSQL endpoints and native table recreation reconciles the expected trigger without copying protected control data.
- [ ] Prove trusted transaction-local pull suppression without using global trigger disabling or `session_replication_role`.
- [ ] Preserve reproducible benchmark evidence for representative `sbg:ibp:import-market-spot`, other IBP imports, Investment processes, interactive writes, and multi-table transactions; the figures below are manual evidence, not a checked-in matrix.
- [x] Record a conditional go/no-go decision in this plan. Do not attach tracking broadly while restore and pull correctness remain uncertain. {Amp/OpenAI Codex}

#### Go/no-go decision (2026-07-23): **CONDITIONAL GO for the append-only mechanism; NO-GO for broad attachment; the naive shared-row design is rejected**

Benchmarked on a real, disposable **PostgreSQL 17.9** instance. Two designs were measured.

**Naive design — one shared per-table row updated via `INSERT … ON CONFLICT DO UPDATE` (rejected):**
- **Deadlock (confirmed):** two concurrent transactions writing two tracked tables in *opposite order* deadlock on the shared control row — `deadlock detected … while inserting index tuple in relation base_database_data_freshness_generations`. `pg_stat_database.deadlocks` incremented. This is the exact lock-order risk the plan flagged, and multi-table imports/Investment processes hit it.
- **Hot row:** 4 concurrent workers × 3,000 single-row inserts to one tracked table = **0.96 s** (all writers serialize on the one control row).

**Append-only design — a statement-level trigger `INSERT`s one event row per statement (adopted):**
- **No deadlock:** the same opposite-order concurrent transactions both commit (Δdeadlocks = 0) — distinct inserted rows never lock each other.
- **~5.7× faster under contention:** the same 4 × 3,000 concurrent inserts = **0.168 s**.
- **Negligible overhead:** a 20,000-row bulk insert generated **2,611 kB** WAL with the trigger vs **2,610 kB** without (**+1 kB**, one event per statement), with no measurable time cost. 2,000 statement-level fires ran in 75 ms.
- **Correct:** rolled-back writes leave no event (rollback safety); `TRUNCATE` is covered; a generation captured before a push stays **Changed** after a concurrent commit (never falsely Clean). Verified by `tests/Integration/Database/DataFreshnessPostgresTest.php` passing on PG 17.

**Decision: conditional GO for the append-only mechanism, not broad attachment.** `DataFreshnessTracker` and `base_database_data_freshness_events` implement statement-level events; `compact()` now removes only rows for which a newer same-table event exists, in one statement. The catalog reports **Unknown** when its exact trigger is absent/disabled. Attachment remains behind `blb:db:share:freshness-attach`, never migrate. The recorded PostgreSQL 16.14/17.9/18.3 matrix and contention/WAL figures were manual evidence from the implementation session; checked-in reproducibility, dump/restore behavior, native-pull trigger reconciliation, and trusted pull acknowledgement remain acceptance gates before broad attachment.

Validation: Disposable PostgreSQL integration matrix, concurrent transaction tests, trigger restore inspection, representative schedule/import benchmarks, and query/WAL measurements captured as phase evidence.

### Phase 4 — Freshness catalog and changed-table workflow

Affected pages: `/admin/system/data-share#mirror`, `/admin/system/schedule`

Goal: Operators can efficiently select Local tables changed since their last successful push without mistaking hints for audit history or enabling automatic synchronization.

- [ ] Attach proven tracking only to ordinary eligible PostgreSQL tables and reconcile trigger presence as registry membership changes.
- [x] Capture the Local generation used by each push and acknowledge exactly that generation only after success; require an enabled exact-table trigger before reporting **Clean**. {Amp/OpenAI Codex}
- [x] Show **Clean**, **Changed since last push**, and **Unknown** badges in the catalog. {Amp/OpenAI Codex}
- [ ] Show truthful last-change and last-push observation times beside freshness.
- [ ] Add filtering and **Select changed tables** while preserving exact explicit review and confirmation.
- [x] Keep SQLite freshness unknown and retain the full-selection workflow rather than adding expensive row-trigger emulation. {Amp/OpenAI Codex}
- [ ] Cross-link scheduler or process history only where an exact run or trace ID is available; do not infer which job changed a table from timestamps.
- [ ] Verify scheduled imports and Investment processes mark only their actual written registered tables and that no cron task initiates a mirror push.

Validation: End-to-end scheduled and manual mutation scenarios, changed-table selection proof, concurrent post-snapshot write proof, catalog-cache behavior, and browser accessibility/responsiveness review.

### Deferred refinements

- [ ] Design a Base-owned execution-context contract that propagates the exact scheduler-run reference and trace into running commands and database sessions, then upgrade `schedule_run_ref` correlation from opaque/best-effort to exact.
- [ ] Add remote commit receipts only if indeterminate native runs occur often enough to justify a cross-endpoint protocol.
- [ ] Add a transactional outbox for the audit projection only if best-effort idempotent emission proves insufficient in practice.
- [ ] Add configurable detailed-history pruning only after measured growth demonstrates a need; never prune current observations or retained semantic actions with it.
