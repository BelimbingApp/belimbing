# people/10_payroll-intake-dependency-inversion

**Status:** Proposed (revised after Copilot + Amp review)
**Last Updated:** 2026-05-13
**Sources:**
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll Core/country-pack boundary and the neutral `PayrollInput` contract that all upstream sources feed.
- `docs/plans/people/07_leave-module-design.md` — Leave module's `LeavePayrollHandoffService` and `LeaveEncashmentService` both write `PayrollInput` rows directly.
- `docs/plans/people/08_claim-module-design.md` — Claim module's `ClaimPayrollHandoffService` writes `PayrollInput::TYPE_REIMBURSEMENT` rows; open guardrails record duplicate-source race and stale-policy approval risks tied to this direction.
- `docs/plans/people/09_attendance-module-design.md` — Attendance overtime already writes to Payroll via `AttendanceOvertimeService`, and the module ships its own `attendance_payroll_handoffs` table whose composite uniqueness key prototypes the pending-store pattern this plan generalizes.
- `app/Modules/People/Claim/Services/ClaimPayrollHandoffService.php`, `app/Modules/People/Leave/Services/LeavePayrollHandoffService.php`, `app/Modules/People/Leave/Services/LeaveEncashmentService.php`, `app/Modules/People/Attendance/Services/AttendanceOvertimeService.php` — the five current producer-side writers (Claim 1, Leave 2, Attendance 1 with more to come) that import `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`.
- `app/Modules/People/Attendance/Models/AttendancePayrollHandoff.php` and migration `0320_01_15_000000_create_attendance_core_tables.php:364` — module-local handoff store with composite source key, to be generalized and moved into Payroll.
- `app/Modules/People/Payroll/Models/PayrollRun.php` — actual run status vocabulary (`draft|calculated|reviewed|approved|closed|voided`) and `assertMutable` semantics (blocks only `closed|voided`).
- `app/Modules/People/Payroll/Models/PayrollInput.php` — neutral integration surface (`source_type`, `source_id`, `pay_item_code`, `input_type`, `amount`/`quantity`, `occurred_on`, `metadata`).
**Agents:** amp/claude-opus-4-7

## Problem Essence

Five producer-side services (Claim×1, Leave×2, Attendance×1, with Attendance period work expected to add more) currently import `PayrollInput`, `PayrollRun`, and `PayrollRunParticipant`, choose target runs, create participant rows, and reinvent duplicate protection. None of them own Payroll's invariants. Concrete symptoms:

- The duplicate `(source_type, source_id)` guard is a get-then-insert in each producer; there is no enforcing constraint because no module owns it.
- `PayrollRunParticipant` rows are created in five places.
- Attendance already invented `attendance_payroll_handoffs` as a module-local pending/landing table with a composite source key — proof that the pattern wants to exist, just in the wrong place.
- Leave's encashment writer is a second writer in the same module, easy to miss in any partial port.
- "Locked period" rules conflict between producers (who use `draft|calculated` as the writable window) and Payroll's actual mutability rule (`assertMutable` blocks only `closed|voided`).

## Desired Outcome

Payroll owns:
1. A typed payload contract (`PayrollContributionPayload`) and a single intake service (`PayrollContributionIntake::ingest`) that producers call directly inside their own transactions.
2. A durable pending store (a Payroll-owned table generalized from `attendance_payroll_handoffs`) so a contribution survives even when no open run currently covers its `occurred_on`. The intake either writes a `PayrollInput` immediately or persists the pending row and later materializes it when a run opens.
3. The composite uniqueness key — `(source_type, source_id, pay_item_code, period_anchor)` — applied at the DB level on both the pending store and `payroll_inputs`. Producers that fan out multiple pay items per source (Attendance period lines) are first-class, not edge cases.
4. The status read API (`PayrollContributionStatus`) that producers call instead of joining `payroll_inputs` themselves.

Producers (Claim, Leave including encashment, Attendance including overtime) stop importing any Payroll model. Domain events are *not* the foundation — they are an optional adapter that can sit on top of the synchronous intake call later if asynchronous handoff is ever needed.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| `PayrollContributionPayload` DTO | Typed value object describing one atomic contribution: company, employee, currency, occurred_on, pay_item_code, input_type, amount/quantity/rate, accounting snapshot, source ref `(type, id)`, period_anchor (target period date, optional), metadata. Lives in Payroll. | Payroll Core |
| `PayrollContributionIntake` service | Single entry point. Resolves target run if one is open; ensures participant; performs an atomic upsert keyed on the composite source tuple. If no run is open, persists a pending contribution row instead. Returns a structured outcome. | Payroll Core |
| `payroll_pending_contributions` table | Payroll-owned durable store for contributions that arrived before an open run exists, or that need re-materialization (e.g. reversal after run close). Composite-unique on `(source_type, source_id, pay_item_code, period_anchor)`. Generalizes the existing `attendance_payroll_handoffs` table. | Payroll Core |
| Pending materializer | Run-open hook that scans `payroll_pending_contributions` for rows whose `period_anchor` falls in the new run and writes corresponding `PayrollInput` rows. | Payroll Core |
| `PayrollContributionStatus` query | Read API returning `{ state, payroll_input_id, payroll_run_id, payroll_run_status, last_updated_at, last_event_reason }`. State vocabulary aligned to actual Payroll model: `absent`, `pending`, `queued_in_run`, `calculated`, `closed`, `voided`, `reversed`, `rejected_locked`. | Payroll Core |
| Atomic upsert path | DB-level: either `INSERT ... ON CONFLICT DO NOTHING` (PostgreSQL) / `INSERT IGNORE` + reload (MySQL), or a `try { insert } catch (UniqueViolation) { reload }` wrapper. `firstOrCreate` is explicitly insufficient because it is select-then-insert and racy. | Payroll Core |
| Producer call site | Each producer service calls `PayrollContributionIntake::ingest($payload)` inside its existing `DB::transaction`. No event indirection, no Payroll model imports. | Each producer module |
| Optional event adapter (deferred) | If asynchronous intake is later required (bulk Attendance finalize, cross-process flow), introduce a Payroll-owned event class that wraps the payload and a listener that calls intake. Not part of v1. | Deferred |

## Design Decisions

**The foundation is a synchronous Payroll-owned contract, not events.** Plan 10 v1 had producer modules owning event classes that Payroll listened to, which contradicted the "Payroll Core does not import producer classes" rule. Drop events. Producers call a Payroll service directly with a Payroll-owned DTO. The dependency direction is unambiguous: producers depend on a Payroll contract, Payroll depends on nothing producer-specific.

**The atomic unit is one pay-item contribution, not one producer request.** The source granularity must match what produces a single `PayrollInput`. Today that varies:
- Claim: one claim line → one input.
- Leave application: one leave request → one input.
- Leave encashment: one encashment event → one input.
- Attendance overtime: one OT request → one input.
- Attendance period (future): one period fans out to multiple inputs across different pay items.

The composite uniqueness key `(source_type, source_id, pay_item_code, period_anchor)` accommodates all of these. Producers whose source is naturally per-input (claim line, leave request, OT request, encashment event) get fan-out for free if they later need it. Aggregate sources (attendance period) submit multiple payloads sharing the same `source_id` but different `pay_item_code`s.

**Payroll owns a durable pending store.** Today, when no open run covers the occurred date, producers either store "pending handoff" metadata in their own request or quietly skip and rely on the producer to re-fire later. That fragments truth and conflates producer state with Payroll state. Generalize `attendance_payroll_handoffs` into `payroll_pending_contributions` owned by Payroll. The intake writes the pending row, the next opened run materializes it, and producers query `PayrollContributionStatus` instead of re-firing.

**Lock vocabulary follows Payroll, not the writer convention.** `PayrollRun::assertMutable()` is the source of truth: only `closed`/`voided` runs are immutable. The intake writes into runs in `draft|calculated` (the producer's current convention) but explicitly defines that window in Payroll, not in producers. Reviewed/approved/closed runs reject intake with `rejected_locked`, and the intake decides whether to (a) materialize into the next open run, (b) hold pending, or (c) bubble back rejection — per a policy owned by Payroll.

**Uniqueness is enforced at the DB level, not by the application.** `firstOrCreate` is select-then-insert and loses under concurrent approval. The intake uses an atomic upsert path appropriate to the driver. The plan does not pick the SQL dialect specifics in advance, but Phase 2 must demonstrate the path is genuinely atomic with a concurrent-insert test.

**Producer call sites stay synchronous and inside the producer transaction.** Today, `ApproveClaimRequestService` calls handoff inside `DB::transaction`. Move it to `PayrollContributionIntake::ingest($payload)`; identical commit semantics, identical rollback semantics. Async is not part of v1.

**Reversal is a Payroll-owned operation, called by producers via the same intake API.** Adding `PayrollContributionIntake::reverse($sourceRef, $reason)` keeps the API symmetric. Producers do not branch on payroll run state — they call reverse, and intake decides delete-pending, reverse-input, or queue-compensating-input in the next open run.

**Scope inventory is exhaustive and named.** Five writers exist today and all must be retired:
- `ClaimPayrollHandoffService` (Claim)
- `LeavePayrollHandoffService` (Leave)
- `LeaveEncashmentService` (Leave) — the writer Copilot caught
- `AttendanceOvertimeService` (Attendance)
- `AttendancePayrollHandoff` model + table (Attendance) — replaced by `payroll_pending_contributions`

The architectural test in the cleanup phase will fail if any of these still import Payroll models. That is the test's purpose.

## Public Contract

**`PayrollContributionPayload` carries:**
- `source_type` (string, namespaced, e.g. `claim_line`, `leave_request`, `leave_encashment`, `attendance_overtime`, `attendance_period_line`);
- `source_id` (int, stable);
- `pay_item_code` (required — composite key field);
- `period_anchor` (date, the occurred_on or contribution date used to pick a run; composite key field);
- `company_id`, `employee_id`, `currency`, `occurred_on`;
- `input_type` (matches `PayrollInput::TYPE_*`);
- `amount`, `quantity`, `rate` (combination per input_type);
- `accounting_snapshot` (debit/credit codes at the point the producer captured them);
- `label` for display;
- `metadata` (arbitrary producer context preserved into `payroll_inputs.metadata`);
- `idempotency_key` (defaults to `source_type:source_id:pay_item_code:period_anchor`).

**`PayrollContributionIntake::ingest` guarantees:**
- exactly one materialized `PayrollInput` row per composite key (enforced by DB unique index, verified by concurrent-insert test);
- target run resolution: first open `draft|calculated` run whose period covers `period_anchor`, in `company_id` scope;
- automatic `PayrollRunParticipant` creation;
- when no open run is available, a `payroll_pending_contributions` row is written with the same composite key — also unique-enforced — and the outcome is `pending`;
- when the resolved run is in `reviewed|approved` (mutable per `assertMutable` but past the writer window), the intake's locked-window policy applies (default: write pending row, do not mutate the run);
- when the resolved run is `closed|voided`, the outcome is `rejected_locked` and Payroll emits a structured error producers can surface;
- the function is idempotent: re-firing the same payload returns the existing materialized or pending row.

**`PayrollContributionIntake::reverse` guarantees:**
- the existing materialized row is deleted if its run is still in `draft`;
- a compensating reversal input is inserted in the next open run otherwise;
- the pending row is deleted if not yet materialized;
- the reversal action is recorded in `payroll_pending_contributions.metadata` and the status query reflects it.

**`PayrollContributionStatus::for($sourceType, $sourceId, $payItemCode = null, $periodAnchor = null)` returns:**
- `state`: `absent | pending | queued_in_run | calculated | closed | voided | reversed | rejected_locked`;
- references: `payroll_input_id`, `payroll_run_id`, `payroll_run_status`, `period_id`;
- audit: `last_updated_at`, `last_event_reason`.
- When `pay_item_code` or `period_anchor` are omitted, returns the aggregate set so a producer can show "all contributions from this leave request" without re-implementing the composite lookup.

**The contract forbids:**
- producer modules importing `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`, or any Payroll service other than `PayrollContributionIntake` and `PayrollContributionStatus`;
- producer migrations referencing payroll tables for handoff (Attendance's `attendance_payroll_handoffs` is retired);
- Payroll Core importing producer-specific classes (event classes, if introduced later, are Payroll-owned wrappers around the payload).

## Risks and Guardrails

- **Risk: composite key is still wrong for some producer.** Guardrail: Phase 0 audit walks every existing writer and confirms each currently produces ≤1 `PayrollInput` per `(source_type, source_id, pay_item_code, period_anchor)` tuple. If any producer produces multiple rows per tuple, the key includes a `line_seq` field.
- **Risk: atomic upsert path lies.** Guardrail: Phase 2 includes a concurrent-insert test (two parallel transactions ingesting the same payload) that must produce exactly one materialized row. `firstOrCreate` fails this test by design.
- **Risk: pending contributions accumulate silently.** Guardrail: Payroll dashboard surfaces pending count per company; the materializer runs on every run-open and on a scheduled scan; producers see `pending` state via the status query and can surface it in their own UIs.
- **Risk: synchronous intake inside producer transaction increases failure surface.** Guardrail: intake exceptions are typed (`LockedRunException`, `MissingParticipantException`, `DuplicateSourceException`) so producers can decide whether to roll back their approval or commit it and record a `pending`/`rejected_locked` state.
- **Risk: reversal semantics differ across producers.** Guardrail: Payroll defines the three reversal outcomes (delete-pending, delete-in-draft, compensating-input) and producers do not branch. Per-producer reversal policy lives as configuration on the intake, not as producer code.
- **Risk: `AttendancePayrollHandoff` table holds in-flight data when the migration ships.** Guardrail: Phase 5 includes a one-shot data migration from `attendance_payroll_handoffs` to `payroll_pending_contributions` before dropping the old table.
- **Risk: refactor stalls halfway.** Guardrail: phases 3–5 (Claim, Leave-including-encashment, Attendance-including-overtime) land in a single release. The architectural test in Phase 6 fails if any producer still imports a Payroll model, locking the inversion in.
- **Risk: events are reintroduced opportunistically and recreate the original confusion.** Guardrail: events stay deferred. Any future event adapter is a Payroll-owned wrapper that calls the same intake; producers never own event classes that Payroll subscribes to.

## Phases

### Phase 0 — Audit and granularity decision

- [ ] Walk every current writer and confirm composite key `(source_type, source_id, pay_item_code, period_anchor)` is sufficient: `ClaimPayrollHandoffService`, `LeavePayrollHandoffService`, `LeaveEncashmentService`, `AttendanceOvertimeService`. Document each producer's atomic granularity.
- [ ] Confirm Attendance's planned period-level handoff (not yet implemented) will use one payload per `(period, employee, pay_item_code)`, not one per period.
- [ ] Audit existing `payroll_inputs` and `attendance_payroll_handoffs` rows for composite-key duplicates; classify as bugs vs benign dev-seeder noise.
- [ ] Decide locked-window policy: when an intake targets a `reviewed|approved` run, does it write pending, write into the run, or reject? Default proposal: write pending and surface `pending` to producer.
- [ ] Decide reversal semantics per producer: which producers actually need reversal in v1 (Claim yes, Leave probably, Attendance overtime yes, encashment open).

### Phase 1 — Payroll-owned contract and intake skeleton

- [ ] Define `PayrollContributionPayload` DTO in `App\Modules\People\Payroll\Contracts\Intake\`.
- [ ] Create `payroll_pending_contributions` table with composite unique index on `(source_type, source_id, pay_item_code, period_anchor)`, FK `payroll_input_id` (nullable, populated on materialization), `status` (`pending|materialized|reversed|rejected_locked`), `transformation_snapshot`, `metadata`.
- [ ] Add the same composite unique index on `payroll_inputs (source_type, source_id, pay_item_code, occurred_on)` after the audit clears. Coordinate with the existing non-unique index.
- [ ] Build `PayrollContributionIntake::ingest()` using an atomic upsert path; throw typed exceptions for locked/missing-run cases.
- [ ] Build `PayrollContributionIntake::reverse()`.
- [ ] Build pending materializer hook on run open (`PayrollRun::open` or equivalent lifecycle method).
- [ ] Build `PayrollContributionStatus` read service.

### Phase 2 — Intake test harness

- [ ] Unit tests: first-time insert, idempotent re-fire (same payload twice), reversal-of-pending, reversal-of-materialized-in-draft, reversal-after-run-close, status query parity.
- [ ] Concurrent-insert test (two parallel transactions, same payload) demonstrates exactly one materialized row and one row only.
- [ ] Locked-run test: ingest targeting `closed` returns `rejected_locked`; ingest targeting `reviewed` follows configured policy.
- [ ] Materializer test: pending row written when no open run, materialized when run opens covering the period.

### Phase 3 — Port Claim

- [ ] Replace `ClaimPayrollHandoffService::queueApprovedRequest` body with a loop that builds payloads from claim lines and calls `PayrollContributionIntake::ingest`.
- [ ] Wire Claim cancel/withdraw/adjust paths to call `PayrollContributionIntake::reverse`.
- [ ] Replace direct `payroll_inputs` queries in Claim Operations views with `PayrollContributionStatus`.
- [ ] Delete Payroll model imports from Claim.
- [ ] Update plan 08's open guardrails (duplicate handoff, locked-period correction) to mark them resolved here.

### Phase 4 — Port Leave (handoff and encashment)

- [ ] Port `LeavePayrollHandoffService` to use intake.
- [ ] Port `LeaveEncashmentService` to use intake. This is the writer Copilot caught and is not optional.
- [ ] Audit `LeaveBalanceLedgerService` for any indirect Payroll writes; route them through intake if found.
- [ ] Delete Payroll model imports from Leave.
- [ ] Update plan 07 to record the new boundary.

### Phase 5 — Port Attendance and retire `attendance_payroll_handoffs`

- [ ] Port `AttendanceOvertimeService` to use intake.
- [ ] Generalize the `attendance_payroll_handoffs` schema as `payroll_pending_contributions` (already created in Phase 1). Data-migrate any non-empty rows from the old table.
- [ ] Drop `attendance_payroll_handoffs` table and `AttendancePayrollHandoff` model.
- [ ] Ensure Attendance period-level handoff (when it lands) is implemented against intake from day one; coordinate with plan 09.
- [ ] Delete Payroll model imports from Attendance.

### Phase 6 — Cleanup and lock-in

- [ ] Delete now-unused producer handoff services.
- [ ] Add an architectural test (PHPUnit + reflection or a CI grep) that fails if any file under `app/Modules/People/{Claim,Leave,Attendance}/` imports `PayrollInput`, `PayrollRun`, or `PayrollRunParticipant`.
- [ ] Document the contract in `docs/architecture/` so future producers (commission, bonus, expense) follow it.
- [ ] Update plan 02 (Payroll Malaysia top-level design) to record that producer ingestion is now Payroll-owned.
- [ ] Optional: introduce a Payroll-owned event class wrapping the payload, as the foundation for any future async/queued intake. Do not ship without a concrete use case.

## Open Research Before Implementation

- Should the locked-window policy (what intake does when targeting `reviewed|approved`) be a single global rule, or configurable per `input_type`? Real Finance practice is to allow reimbursements into approved runs but not deductions; check with SBG before freezing.
- Does the pending materializer need to run on a schedule (overnight scan) in addition to the run-open hook, to catch cases where a contribution arrives between scans and a run was already open? Probably yes; cheap to add.
- Does the reversal API need a "force" mode for HR/Finance correction flows (override locked-run rejection with explicit authorization)? Likely yes for Claim; the gate is authz, not intake logic.
- When Attendance period handoff lands, will it dispatch one payload per (period, employee, pay_item) or one per (period, employee)? The composite key forces the former; confirm this matches plan 09's intent.
- Should `PayrollContributionStatus` cache its lookups? Producer Operations views may query it for hundreds of rows on render.
- Is there value in a future event adapter (Phase 6 optional bullet), or is direct synchronous intake sufficient permanently? Revisit only if a real async use case emerges.
