# Payroll Contribution Intake

**Document Type:** Architecture Specification
**Purpose:** Define the contract through which People producer modules (Leave, Claim, Attendance) hand off operational facts to Payroll.
**Last Updated:** 2026-05-13

## Why this exists

Payroll consumes operational facts that originate in other People modules: unpaid-leave days, approved claim reimbursements, overtime hours, leave encashment payouts, attendance-driven allowances and deductions. Without a single owned contract, every producer module had to know Payroll's internal tables — `payroll_runs`, `payroll_run_participants`, `payroll_inputs` — pick a target run, create participants, and reinvent duplicate protection. That direction was wrong: producers became junior Payroll authors and no module owned Payroll's invariants. See `docs/plans/people/10_payroll-intake-dependency-inversion.md` for the full history.

The intake contract inverts the direction. Producers depend on a small, Payroll-owned API. Payroll owns run selection, participant creation, idempotency, locked-period correction, and durable pending state.

## The contract

Three public surfaces, all under `App\Modules\People\Payroll\Contracts\Intake` and `App\Modules\People\Payroll\Services`:

1. **`PayrollContributionPayload`** (readonly DTO). Describes one atomic contribution: company, employee, currency, occurred_on, pay_item_code, input_type, amount/quantity/rate, accounting snapshot, source ref, metadata, idempotency key.
2. **`PayrollContributionIntake`** (service).
   - `ingest(PayrollContributionPayload): PayrollContributionOutcome` — idempotent. Materialises a `PayrollInput` if a writable run covers the period, otherwise persists a pending row.
   - `reverse(sourceType, sourceId, payItemCode, periodAnchor, reason): PayrollContributionOutcome` — delete the materialised input if still in a draft run, or insert a compensating reversal in the next open run; mark the pending row reversed either way.
3. **`PayrollContributionStatus`** (service). `for(...)` and `allFor(...)` read APIs returning a `PayrollContributionOutcome` keyed on the same composite tuple. Producers query this instead of joining `people_payroll_inputs` directly.

The composite source key is `(source_type, source_id, pay_item_code, period_anchor)`, enforced at the DB level on `people_payroll_pending_contributions` via a unique index.

## What producers must and must not do

Producers (Leave, Claim, Attendance, and any future module that contributes to payroll) **must**:

- Call `PayrollContributionIntake::ingest()` inside their own transaction at the point where the operational fact becomes payroll-relevant (claim approved, leave applied, OT request approved, encashment recorded).
- Define a stable `source_type` constant on their service (e.g. `AttendanceOvertimeService::SOURCE_TYPE = 'attendance_overtime_request'`). Source types are how Payroll and downstream reporting recognise the origin of a contribution.
- Pass the producer's own row id as `sourceId`. The source row must remain in the producer module; Payroll never reaches back across the boundary.
- Use the producer's natural date (incurred_on, starts_on, occurred_at) as `periodAnchor`. Payroll resolves the target run from this date.

Producers **must not**:

- Import `PayrollInput`, `PayrollRun`, `PayrollRunParticipant`, or any other Payroll model not exposed through the intake namespace.
- Query `people_payroll_inputs` directly. Use `PayrollContributionStatus` instead.
- Choose target runs, create participant rows, or enforce idempotency themselves. Those are Payroll's responsibilities.

A PHPUnit guard (`tests/Feature/Modules/People/Payroll/PayrollIntakeBoundaryTest.php`) scans Leave/Claim/Attendance for forbidden imports and fails the build if any return. Do not add files to its allowlist; route the dependency through intake instead.

## State vocabulary

The `PayrollContributionOutcome::state` returned by `ingest`/`reverse`/status queries follows Payroll's `PayrollRun` status semantics:

| State | Meaning |
|-------|---------|
| `absent` | No pending row and no PayrollInput exists for this source tuple. |
| `pending` | Pending row written; no open run covered the period. Will materialise when one opens. |
| `queued_in_run` | Materialised into a draft run; payable but not yet calculated. |
| `calculated` | Run has progressed to calculated/reviewed/approved; contribution is locked into the calculation. |
| `closed` | Run is closed; contribution is paid (in payroll terms). |
| `voided` | Run was voided; contribution did not pay out. |
| `reversed` | Producer called `reverse()`; contribution withdrawn or compensated. |
| `rejected_locked` | Targeted a closed/voided run and was rejected. |

Producers branch on `isMaterialized()`, `isPending()`, `isRejected()` rather than parsing the raw state string.

## Idempotency

Re-firing `ingest` with the same composite tuple returns the existing row's outcome unchanged. Producers can call `ingest` from any retry path safely. The pending-row unique index plus the catch-on-unique-violation upsert mean concurrent calls converge on a single row.

## Locked period correction (current behaviour)

When a contribution targets a run that is `reviewed` or `approved` (mutable but past the writer window), intake writes a pending row with state `pending` and `reason='run X is reviewed/approved'`. When the target run is `closed` or `voided`, intake returns `rejected_locked`. The locked-window policy is currently global; if Finance requires per-input-type variation, it lands as configuration on the intake rather than producer branches.

## Pending materializer

`PayrollContributionIntake::materializePendingForRun(PayrollRun)` scans pending contributions whose `period_anchor` falls in the run's period and writes the corresponding `PayrollInput` rows. It runs automatically when a `PayrollRun` is created (via model event) and is also exposed as the `payroll:materialize-pending` Artisan command for safety-net scheduling.

## See also

- `docs/plans/people/10_payroll-intake-dependency-inversion.md` — design rationale, phase log, and risks/guardrails.
- `docs/architecture/database.md` — the People tier scheme (`_01_*` foundation, `_02_*` producers, `_03_*` consumers) that encodes this direction in the migration registry.
- `app/Modules/People/Payroll/Contracts/Intake/` — DTO, state constants, and outcome class.
- `app/Modules/People/Payroll/Services/PayrollContributionIntake.php` and `PayrollContributionStatus.php` — service implementations.
