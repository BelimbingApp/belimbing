# Data Share Mirror History and Freshness

**Status:** Proposed
**Last Updated:** 2026-07-22
**Sources:** `app/Base/Database/Services/DataShare/Mirror/DataShareMirrorManager.php`, `app/Base/Database/DTO/DataShare/Mirror/DataShareMirrorExecutionResult.php`, `app/Base/Audit/AGENTS.md`, `app/Base/Audit/Services/AuditSemanticActionRecorder.php`, `app/Base/Schedule/Services/ScheduleRunRecorder.php`, `docs/plans/base-schedule-observability.md`
**Agents:** Amp/OpenAI Codex

## Problem Essence

Mirror results currently exist only in transient Livewire state, so the Local and remote row counts disappear after refresh and there is no durable answer to who pushed or pulled which tables, when, or with what outcome. Recording every affected row as an audit mutation would be both misleading and unbounded because native and portable mirroring use bulk database operations that bypass Eloquent, while scheduled imports and Investment processes can change many source rows without producing complete model mutation logs.

## Desired Outcome

Every attempted mirror mutation has one durable, actor-attributed operation record and one compact summary per explicitly selected table. The Mirror catalog retains the latest successful Local and remote observations across refreshes, operators can inspect prior push, force-push, and pull runs, and PostgreSQL installations can later identify Local tables changed since their last successful push without logging every changed row or coupling every scheduled command to Data Share.

## Top-Level Components

### Mirror run ledger

Base Database owns an append-style run ledger. One run records the operation identity, direction, force policy, transfer mode, endpoint identities, actor and trace context, lifecycle status, timing, and safe failure details. One child summary per selected table records the reviewed action, schema fingerprints, observed row counts, observation timestamps, and—when freshness tracking is available—the Local generation captured and acknowledged by the operation.

The ledger is the authoritative history. Audit actions project meaningful mirror events into the global audit timeline but do not replace the ledger because semantic audit writes are deferred and deliberately best-effort.

### Current observation projection

A compact projection keyed by Local instance, remote instance, and table stores only the latest successful observations. The main Mirror table reads this projection on every render, independently of the five-minute remote catalog cache, so endpoint changes never display counts from a different mirror and history retention never removes the current state.

The projection labels counts as **last observed**, not verified equality. Portable transfer can prove snapshot count and content equality; native transfer observes endpoint counts after its external transaction and concurrent Local writes may already have changed the source.

### Audit bridge

Each terminal mirror run emits exactly one retained semantic action through the Foundation-owned `SemanticActionRecorder` contract. Push, force push, and pull are all semantic operations; none generate synthetic audit mutations. The action contains the mirror operation ID, direction, endpoint identity, result, force flag, and aggregate table counts, and links readers to the detailed ledger instead of duplicating the table manifest in JSON.

Mirror ledger and projection writes are excluded from ordinary mutation auditing so a 43-table operation does not create 44 irrelevant framework mutation entries about its own bookkeeping.

### Local table freshness tracker

PostgreSQL mirror-eligible Local tables may gain statement-level tracking for `INSERT`, `UPDATE`, `DELETE`, and `TRUNCATE`. A generic trigger updates one compact generation row for the affected table in the same database transaction as the source change. This covers Eloquent, query-builder upserts, raw SQL, `COPY`, scheduler commands such as `sbg:ibp:import-market-spot`, and Investment processes without creating one history row per business record.

Freshness is an operator aid, not automatic synchronization. It may power **Changed since last push** and **Select changed tables**, but it never starts a push and never weakens explicit review. SQLite reports freshness as unknown until a comparably truthful mechanism exists.

### Mirror history UI

The Mirror catalog gains persistent **Local rows**, **Remote rows**, **Observed**, and **Freshness** columns. A bounded History section lists operations with actor, direction, endpoint, table count, result, and duration; opening a run shows its per-table observations and safe failure details. Audit Log Actions links to the same operation subject for organization-wide chronology.

## Design Decisions

### Use a run ledger plus semantic action, not audit mutations

Three approaches were considered:

1. **Audit actions for push and mutations for pull.** Rejected. Native pull uses external `psql` and portable pull uses bulk query operations, so Eloquent mutation capture would be incomplete. Manufacturing per-row mutations would misrepresent the mechanism and could produce millions of retained rows.
2. **One semantic audit action containing the entire operation.** Better cardinality, but insufficient as the source of truth: audit action persistence is deferred and best-effort, large table manifests are awkward to query, and current catalog observations must survive audit retention.
3. **A Base Database run ledger with one semantic action projection.** Recommended. It preserves actor and operational truth in a queryable bounded shape, keeps Audit decoupled through its Foundation contract, and gives the catalog a durable source for current observations.

Cardinality is one run plus one row per explicit table selection, not one row per mirrored record. No configurable retention is needed initially; indexed timestamps and measured growth should precede pruning. If pruning becomes necessary, current observations and retained semantic actions survive detailed-history deletion.

### Track source change generations at the database boundary

Three freshness approaches were considered:

1. **Infer changes from audit mutations.** Rejected because raw SQL, query-builder bulk writes, external clients, and many imports bypass Eloquent.
2. **Require scheduled tasks to declare affected tables.** Useful as a future attribution hint, but rejected as authority because manual and queued writes remain possible and a successful task does not prove that data changed.
3. **Use PostgreSQL statement-level triggers.** Recommended after a proof gate. They observe every database writer, roll back with the source transaction, and collapse arbitrary row volume into compact per-table state. The cost is an extra control-row write, WAL, possible hot rows, and new lock-order risks that must be measured on real imports before broad attachment.

Phase 1 deliberately remains correct without freshness tracking: operators select tables explicitly and receive persistent history and observations. Phase 2 ships only if trigger restore and workload evidence are acceptable.

### Preserve honest transaction boundaries

The Local ledger and an external `psql` transaction cannot commit atomically. A run is created only after a locked review succeeds and before mutation begins. Terminal meanings are strict:

- **Succeeded:** the engine confirmed completion and Local terminal recording succeeded.
- **Failed:** no destination mutation is known to have committed, including export or other pre-mutation failures.
- **Indeterminate:** the destination may have committed, including a disconnected or non-zero `psql`, process crash, post-commit observation failure, or Local finalization failure.
- **Running:** active only; stale runs are reconciled to indeterminate, never guessed to have failed.

No Local database transaction remains open during a potentially hour-long dump or restore. A future remote receipt protocol may resolve recurring indeterminate commits, but distributed-transaction simulation is not part of this design.

### Do not fabricate history for the completed 43-table push

The existing `sbg_` push predates the ledger. Its 43 schemas and row counts were verified after completion, but the system did not persist an operation identity at mutation time. After Phase 1, an authorized **Capture baseline observation** action may record current fingerprints and counts as a clearly labelled retrospective baseline with the actor and time of observation. It must not be presented as the original push or infer an original user from nearby logs.

## Public Contract

### Operation identity and attribution

- Every push, force push, and pull that reaches mutation preparation receives a stable operation ULID.
- Browser runs retain the authenticated user, company, role, request surface, and trace. CLI, scheduler, queue, and agent actors retain their actual process actor type rather than being presented as users.
- Stable Local and remote Data Share instance IDs identify endpoints. The connection fingerprint and full URL are never persisted as endpoint identity or exposed in history.
- Schedule-run correlation is nullable and recorded only when explicitly available. Temporal proximity is never presented as causation.

### Operation lifecycle

- Normal push, force push, and pull share one internal locked lifecycle: fresh review, optional force-policy transformation, run creation, engine execution, terminal recording, observation projection, then semantic action emission.
- Force policy remains push-only and bypasses only missing/incompatible schema blockers. There is no Force Pull.
- Reviews that remain blocked and operations that fail to acquire the lock do not create mutation-attempt history.
- Stale running operations become indeterminate after the configured operation timeout plus a bounded grace period.

### Per-table observations

- History records every explicitly selected table, including action and terminal table status.
- Local and remote counts are nullable observations with timestamps. Missing tables report zero only when absence was positively observed; unknown is not coerced to zero.
- Schema fingerprints are endpoint observations tied to the run. Portable verified content hashes are retained only when actually computed and verified.
- Failed and indeterminate runs remain visible but never replace the latest successful catalog projection.

### Freshness generations

- Only ordinary mirror-eligible registered PostgreSQL tables receive tracking triggers. Audit, schedule, registry, mirror ledger, observation, and generation infrastructure are always protected and excluded.
- Trigger updates participate in the source transaction, so rolled-back writes do not mark a table changed. `TRUNCATE` is covered explicitly.
- A push acknowledges only the Local generation captured for its snapshot. It never clears a boolean dirty flag. Concurrent commits must remain newer than the acknowledged generation and therefore remain changed.
- Pull suppresses or acknowledges its own Local replacement within a trusted transaction-local context so recovery does not immediately appear as an unpushed Local edit.
- SQLite and any unproven driver report **Unknown**, never **Clean**.

### Scheduler and import behavior

- Existing scheduler run history remains the authority for whether a task ran, succeeded, failed, or was skipped.
- Freshness triggers automatically observe database effects of scheduler commands and Investment processes; commands do not import or call Data Share services.
- Exact schedule-run-to-table attribution is deferred until the scheduler run ID and trace can be propagated into database sessions without inference. Optional affected-table declarations may later provide hints, never authority.
- No scheduled task automatically pushes a mirror in this plan.

## Phases

### Phase 1 — Durable mirror history and persistent observations

Affected pages: `/admin/system/data-share#mirror`, `/admin/audit/actions`

Goal: A completed or interrupted mirror operation remains understandable after refresh, and the main catalog shows the latest successful endpoint observations.

- [ ] Add incubating Base Database storage for mirror runs, per-table summaries, and current endpoint-scoped observations, with protected-table registration and indexes for endpoint, status, actor, trace, and time.
- [ ] Keep mirror bookkeeping out of global mutation audit while using the Foundation semantic action contract for one retained terminal action per operation.
- [ ] Consolidate normal push, force push, and pull under one locked execution lifecycle with fresh in-lock review and strict succeeded, failed, indeterminate, and stale-running semantics.
- [ ] Persist planned table summaries before engine mutation and finalize observations without holding a Local transaction across external transfer work.
- [ ] Record nullable, timestamped row counts and schema fingerprints without overstating native count equality as transfer verification.
- [ ] Merge current observations into every catalog render independently of the remote catalog session cache and isolate observations by stable endpoint IDs.
- [ ] Add persistent catalog columns and a bounded operation History UI with actor, direction, force status, endpoint, result, timing, aggregates, and per-table details.
- [ ] Add an explicit baseline-observation workflow that labels the existing 43-table state as retrospective rather than inventing a historical push.
- [ ] Cover browser and CLI attribution, normal and force push, pull, pre-mutation failure, indeterminate `psql`, portable rollback, stale-run reconciliation, endpoint replacement, Local finalization failure, audit projection failure, and cache refresh behavior.

Validation: Focused Backend/UI tests; isolated PostgreSQL 17/18 native integration; portable integration; browser verification of refresh, endpoint switch, baseline, and Audit Action link; credential redaction assertions; Pint and UI convention scan.

### Phase 2 — PostgreSQL freshness proof gate

Affected pages: `/admin/system/data-share#mirror`

Goal: Evidence proves compact generation tracking is complete and operationally safe before it is attached to production tables.

- [ ] Prototype generic generation infrastructure and statement-level tracking for insert, update, delete, truncate, upsert, and copy on disposable PostgreSQL 17 and 18 databases.
- [ ] Prove rollback behavior, concurrent source writes during a push, captured-generation acknowledgement, and absence of false-clean states.
- [ ] Verify selected-table `pg_dump` and restore behavior because table dumps include trigger definitions but not necessarily their shared function or control tables.
- [ ] Ensure tracking infrastructure is independently migrated on both PostgreSQL endpoints and native table recreation reconciles the expected trigger without copying protected control data.
- [ ] Prove trusted transaction-local pull suppression without using global trigger disabling or `session_replication_role`.
- [ ] Benchmark representative `sbg:ibp:import-market-spot`, other IBP imports, Investment processes, interactive writes, and multi-table transactions for runtime, WAL, hot-row contention, and deadlocks.
- [ ] Record a go/no-go decision in this plan. Do not attach tracking broadly if restore correctness or workload cost remains uncertain.

Validation: Disposable PostgreSQL integration matrix, concurrent transaction tests, trigger restore inspection, representative schedule/import benchmarks, and query/WAL measurements captured as phase evidence.

### Phase 3 — Freshness catalog and changed-table workflow

Affected pages: `/admin/system/data-share#mirror`, `/admin/system/schedule`

Goal: Operators can efficiently select Local tables changed since their last successful push without mistaking hints for audit history or enabling automatic synchronization.

- [ ] Attach proven tracking only to ordinary eligible PostgreSQL tables and reconcile trigger presence as registry membership changes.
- [ ] Capture the Local generation used by each push and acknowledge exactly that generation only after success.
- [ ] Show **Clean**, **Changed since last push**, and **Unknown** in the catalog with the last change and push observation times.
- [ ] Add filtering and **Select changed tables** while preserving exact explicit review and confirmation.
- [ ] Keep SQLite freshness unknown and retain the full-selection workflow rather than adding expensive row-trigger emulation.
- [ ] Cross-link scheduler or process history only where an exact run or trace ID is available; do not infer which job changed a table from timestamps.
- [ ] Verify scheduled imports and Investment processes mark only their actual written registered tables and that no cron task initiates a mirror push.

Validation: End-to-end scheduled and manual mutation scenarios, changed-table selection proof, concurrent post-snapshot write proof, catalog-cache behavior, and browser accessibility/responsiveness review.

### Deferred refinements

- [ ] Add remote commit receipts only if indeterminate native runs occur often enough to justify a cross-endpoint protocol.
- [ ] Propagate exact scheduler-run and trace context into database sessions only after a general Base-owned correlation contract is designed.
- [ ] Add configurable detailed-history pruning only after measured growth demonstrates a need; never prune current observations or retained semantic actions with it.
