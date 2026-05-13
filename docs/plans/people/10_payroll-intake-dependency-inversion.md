# people/10_payroll-intake-dependency-inversion

**Status:** Proposed — awaiting Attendance module skeleton (people/09) before execution
**Last Updated:** 2026-05-13
**Sources:**
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll Core/country-pack boundary and the neutral `PayrollInput` contract that all upstream sources feed.
- `docs/plans/people/07_leave-module-design.md` — Leave module writes `PayrollInput::TYPE_DEDUCTION` rows through `LeavePayrollHandoffService`.
- `docs/plans/people/08_claim-module-design.md` — Claim module writes `PayrollInput::TYPE_REIMBURSEMENT` rows through `ClaimPayrollHandoffService`; open guardrails record duplicate-source race and stale-policy approval risks tied to this direction.
- `docs/plans/people/09_attendance-module-design.md` — Attendance will be the third producer (OT, allowances, deductions), making the current per-producer push pattern the most painful.
- `app/Modules/People/Claim/Services/ClaimPayrollHandoffService.php` and `app/Modules/People/Leave/Services/LeavePayrollHandoffService.php` — current producer-side handoffs that reach into `PayrollRun`, `PayrollRunParticipant`, and `PayrollInput`.
- `app/Modules/People/Payroll/Models/PayrollInput.php` — neutral integration surface (`source_type`, `source_id`, `pay_item_code`, amount, occurred_on, metadata).
**Agents:** amp/claude-opus-4-7

## Problem Essence

Claim and Leave both write directly into Payroll's tables today. They import `PayrollRun`, `PayrollRunParticipant`, and `PayrollInput`; they choose the target run; they create participant rows; they decide what duplicate protection looks like. Payroll, which should own those decisions, is a passive recipient. The smell is concrete:

- The duplicate `(source_type, source_id)` guard lives in each producer as an application-level get-then-insert; the underlying index is non-unique because no single module owns it. Two producers cannot agree on a unique constraint they both rely on.
- Producer services create `PayrollRunParticipant` rows, which is a Payroll-internal concern. Future participant rules (exclusion lists, multi-currency runs, prorated participation) would have to be re-implemented in every producer.
- Locked-period correction (reversal vs new input) is documented in plan 08 as a producer responsibility, but the rule belongs to Payroll.
- Attendance landing soon will triple the surface: OT, allowance, and deduction rows per period per employee, each repeating the same Payroll-reaching glue.

## Desired Outcome

Producers (Claim, Leave, Attendance) emit normalized domain events when an operational fact becomes payroll-relevant. Payroll owns a single intake service that consumes those events, resolves the target run/participant, enforces duplicate-source uniqueness at the schema level, and handles locked-period reversal. Producers stop importing any Payroll model. Read-back of "was this handed off / paid?" goes through a thin Payroll query API.

The integration surface — `payroll_inputs.source_type` + `source_id` + `metadata` — stays exactly as it is today. Only the writer moves.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Producer domain events | `ClaimRequestApproved`, `LeaveRequestApproved`, `AttendancePeriodFinalized` (and reversal counterparts). Carry a normalized payload: employee, company, currency, occurred_on, pay item hint, amount/quantity, accounting snapshot, source ref `(type, id)`, idempotency key. | Each producer module |
| Payroll contribution payload contract | A typed DTO (or PHP readonly class) shared across modules describing what a producer must hand to Payroll. Lives in Payroll Core so producers depend on Payroll's *contract*, not its tables. | Payroll Core |
| Payroll contribution intake service | Resolves target `PayrollRun`, ensures `PayrollRunParticipant`, writes `PayrollInput` with unique-source enforcement, classifies locked-period correction (reversal-then-insert vs reject). Replaces both existing handoff services. | Payroll Core |
| Event listeners | Synchronous listeners registered by Payroll for each producer event; they invoke the intake service inside the producer's existing transaction so semantics match today's inline write. | Payroll Core |
| Payroll contribution status query | Read API: `PayrollContributionStatus::for($sourceType, $sourceId)` → `{ state: queued|reimbursed|reversed|absent, payroll_input_id, payroll_run_id, locked }`. Producers use this for badges, exports, and "is it safe to mutate?" checks. | Payroll Core |
| Schema constraint | Unique index on `payroll_inputs (source_type, source_id)` (partial / scoped to non-null source_id), now genuinely owned. | Payroll Core migration |

## Design Decisions

**Invert the dependency, do not introduce a third bridge module.** The natural home for "translate a domain fact into a payroll input" is Payroll itself. Adding a `PayrollBridge` module would just move the problem; it would still own a payroll write path without owning payroll's invariants.

**Producers depend on a Payroll *contract*, not Payroll *models*.** The payload DTO and the event classes live in `App\Modules\People\Payroll\Contracts\` (or similar). Producers do not import `PayrollInput`, `PayrollRun`, or `PayrollRunParticipant`. This is the actual win: when Payroll changes how runs/participants work, producers do not break.

**Keep handoff synchronous and inline with the producer's transaction.** Today, `ApproveClaimRequestService` calls `ClaimPayrollHandoffService` inside its `DB::transaction`. Listeners must run synchronously so the approval and the payroll input either both commit or both roll back. Do not queue these. Asynchronous payroll write is a different feature.

**Producers emit reversal events explicitly.** Cancelling/withdrawing/reducing an approved claim, or unapproving a leave request, fires a `*Reversed` event with the same source ref and a reason. Payroll's intake decides what to do (delete pending input, insert a reversing input in an open period, or reject if the source period is locked and no open period exists). Producers do not branch on Payroll state.

**Unique constraint is added in Payroll, not in producer migrations.** Phase 1 adds `unique(source_type, source_id)` (filtered to non-null source_id where the DB allows) and resolves existing dev-seeder collisions by scoping them to a non-payroll sentinel source_type. Application-level get-then-insert is replaced by `firstOrCreate` keyed on the unique pair, so the constraint is the truth.

**Producers query Payroll for "what happened next?", they don't peek.** When Claim's operations view needs to show "Reimbursed in run R-2026-05", it asks `PayrollContributionStatus`. Producer SQL that joins to `payroll_inputs` directly should be replaced or hidden behind the status query. This keeps the inversion honest.

**Locked-period correction is a Payroll concern.** Plan 08's open guardrail ("Prevent mutation of claim lines already handed to a locked payroll run; support explicit reversal/new-period correction flows") moves to Payroll. Claim only emits "I reduced/cancelled this approved line"; Payroll decides whether that becomes a reversal input in the next open period or a rejection that bubbles back as a status the producer surfaces.

**Do not generalize prematurely.** Three producers (Claim, Leave, Attendance) are enough to justify the inversion. Do not build a plugin registry, a contribution-source SPI, or per-producer configuration. A handful of typed event classes is fine.

## Public Contract

**Producer events must carry:**
- `source_type` (string, namespaced: `claim_line`, `leave_request`, `attendance_period_line`);
- `source_id` (integer, stable for the producer's lifetime of that fact);
- `company_id`, `employee_id`, `currency`, `occurred_on`;
- `pay_item_code` (producer's best-effort hint; country pack still classifies);
- `input_type` (reimbursement, deduction, earning, statutory hint);
- `amount` and optional `quantity`/`rate` (for unit-based contributions like leave days or OT hours);
- `accounting_snapshot` (debit/credit codes captured at approval time);
- `label` for display;
- `idempotency_key` (defaults to `source_type:source_id`);
- `metadata` (producer-specific context preserved in `payroll_inputs.metadata`).

**Payroll's intake service guarantees:**
- exactly one `payroll_inputs` row per `(source_type, source_id)` (enforced by unique index, not by application check);
- target run selection from open `draft`/`calculated` runs covering `occurred_on`; if none, returns `pending` status and no row is written;
- automatic `PayrollRunParticipant` creation if absent;
- locked-period writes are rejected with a structured outcome the producer can record;
- reversal events translate to either deletion (if the original row is in an open run and not yet calculated) or a compensating input in the next open run.

**Payroll status query returns:**
- `state`: `absent | pending | queued | calculated | paid | reversed | locked_rejected`;
- `payroll_input_id`, `payroll_run_id`, `payroll_period_id`;
- `last_updated_at`, `last_event_reason`.

**The contract forbids:**
- producer modules importing `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`, or any Payroll service;
- producer migrations referencing payroll tables for handoff;
- Payroll Core depending on producer-specific classes (events live in Payroll Core or a neutral contracts namespace; payloads are primitive/typed).

## Risks and Guardrails

- **Risk: synchronous listener inside producer transaction increases failure surface.** Guardrail: the intake service must be deterministic and exception-typed; producer code catches `LockedPeriodException` and surfaces it to the user without rolling back the approval if business policy says approval still stands. Decide this per producer during the port.
- **Risk: unique constraint backfill collides with existing dev-seeder rows.** Guardrail: Phase 1 audits `payroll_inputs` for duplicate `(source_type, source_id)` rows before applying the constraint; rename dev-seeder source_types to disambiguate.
- **Risk: event payload churn ripples across producers.** Guardrail: the payload DTO is versioned (`v1`); additive changes are safe, removals require a new event class. Producers and the intake service both pin to a version.
- **Risk: producer needs Payroll state during its own UI flow and re-introduces a direct query.** Guardrail: `PayrollContributionStatus` must be cheap and cacheable; producers consume it through a thin trait/helper, not by re-querying `payroll_inputs`.
- **Risk: refactor stalls halfway, leaving one producer on the new path and the others on the old.** Guardrail: land the contract and intake first, port Claim and Leave in the same release, and keep Attendance on the new path from day one.
- **Risk: tests double-fire listeners.** Guardrail: feature tests assert event dispatch separately from intake; intake unit tests construct payloads directly without producers.

## Phases

### Phase 0 — Decision and audit

- [ ] Confirm Attendance's first slice has merged so we know its handoff shape before freezing the payload contract.
- [ ] Audit current `payroll_inputs` rows for duplicate `(source_type, source_id)` pairs; classify by source_type and decide which are bugs vs benign dev-seeder noise.
- [ ] Confirm whether reversal/correction is a day-one requirement (it likely is for Claim, given the locked-period guardrail in plan 08) or can land in a follow-up phase.
- [ ] Decide where the contract namespace lives: `App\Modules\People\Payroll\Contracts\Intake\` vs a top-level shared contracts package.

### Phase 1 — Contract and intake service

- [ ] Define `PayrollContributionPayload` DTO with the fields listed in the Public Contract section.
- [ ] Define `PayrollContributionEvent` base + per-producer subclasses (`ClaimRequestApproved`, `ClaimRequestReversed`, `LeaveRequestApproved`, `LeaveRequestReversed`, `AttendancePeriodFinalized`, `AttendancePeriodReversed`).
- [ ] Build `PayrollContributionIntake` service: open-run resolution, participant ensure, idempotent insert, locked-period exception, reversal handling.
- [ ] Add migration: unique index on `payroll_inputs (source_type, source_id)` after the audit clears.
- [ ] Build `PayrollContributionStatus` read service.
- [ ] Unit tests covering: first-time insert, idempotent re-fire, locked-period rejection, reversal in open run, reversal when no open run exists.

### Phase 2 — Port Claim to events

- [ ] Replace `ClaimPayrollHandoffService` body with event dispatch (`ClaimRequestApproved`) inside the approval transaction.
- [ ] Register Payroll listener that calls `PayrollContributionIntake::ingest(payload)`.
- [ ] Wire Claim's cancel/withdraw/adjust paths to emit `ClaimRequestReversed`.
- [ ] Replace direct `payroll_inputs` queries in Claim operations views with `PayrollContributionStatus`.
- [ ] Remove Claim's imports of `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`.
- [ ] Update plan 08's open guardrails (duplicate handoff, locked-period correction, stale-policy approval boundary) to point at the new home.

### Phase 3 — Port Leave to events

- [ ] Same shape as Phase 2 for `LeavePayrollHandoffService` → `LeaveRequestApproved`/`LeaveRequestReversed`.
- [ ] Audit `LeaveBalanceLedgerService` for any cross-into-Payroll writes; route them through the same intake.
- [ ] Update plan 07 to record the new boundary.

### Phase 4 — Attendance on the new path from day one

- [ ] Attendance emits `AttendancePeriodFinalized` per contribution line (OT, allowance, deduction); never imports Payroll models.
- [ ] Reversal flow (period re-opened after finalize) emits `AttendancePeriodReversed`.
- [ ] Plan 09 references this plan as the handoff contract.

### Phase 5 — Cleanup and lock-in

- [ ] Delete the now-unused producer-side handoff services.
- [ ] Add an architectural test (or grep CI rule) that fails if a producer module imports a Payroll model.
- [ ] Document the contract in `docs/architecture/` so future producers (commission, bonus, expense report) follow it.
- [ ] Update plan 02 (Payroll Malaysia top-level design) to record that producer ingestion is now Payroll-owned.

## Open Research Before Implementation

- Does the Attendance design need a unit-based payload (hours, days) distinct from amount-based, or can a single DTO with optional `quantity`/`rate` cover all three producers? Decide before freezing the contract in Phase 1.
- Should reversal events be standalone classes or a single `*Reversed` event with a reason enum? Standalone is more discoverable; enum is leaner. Pick based on how many distinct reversal reasons each producer actually has.
- Does the country pack need to observe these events too (for statutory side-effects on approval), or only when Payroll calculates? Today the answer is "only at calculation"; verify before exposing events outside Payroll.
- Is there a real need for asynchronous (queued) intake — e.g., bulk Attendance finalize for 5000 employees — or is synchronous always acceptable? If async is needed, it changes the failure model and should be a Phase 6.
- Does Workflow's approval transition already provide a natural event hook we should reuse, or should producer services fire their own events? Reusing Workflow would couple this design to Workflow's event shape.
