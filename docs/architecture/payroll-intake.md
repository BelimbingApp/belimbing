# Payroll Contribution Intake

**Document Type:** Architecture Specification
**Purpose:** Define the contract through which People producer modules (Leave, Claim, Attendance) hand off operational facts to Payroll.
**Last Updated:** 2026-08-06

## Why this exists

Payroll consumes operational facts that originate in other People modules: unpaid-leave days, approved claim reimbursements, overtime hours, leave encashment payouts, attendance-driven allowances and deductions. Without a single owned contract, every producer module had to know Payroll's internal tables — `payroll_runs`, `payroll_run_participants`, `payroll_inputs` — pick a target run, create participants, and reinvent duplicate protection. That direction was wrong: producers became junior Payroll authors and no module owned Payroll's invariants. See `docs/plans/people/10_payroll-intake-dependency-inversion.md` for the full history.

The integration contract inverts the direction. Producers publish facts in their own vocabulary and do not depend on Payroll. When Payroll is installed, its listeners translate those facts into a Payroll-owned intake payload. Payroll owns run selection, participant creation, idempotency, locked-period correction, and durable pending state.

## The contract

The contract has a producer boundary and a Payroll-internal intake engine:

1. **Producer-domain events.** Attendance, Claim, and Leave publish their own events below their respective `Events/` namespaces. The event payload describes the operational fact without importing a Payroll type. Dispatch remains safe when Payroll is absent.
2. **Payroll-owned listeners.** Listeners below `App\Domains\People\Payroll\Listeners` subscribe to those published events, resolve Payroll-owned mappings, build a `PayrollContributionPayload`, and call the intake service. This is the only producer-to-Payroll translation layer.
3. **`PayrollContributionPayload`** (readonly DTO). Describes one atomic contribution: company, employee, currency, occurred_on, pay_item_code, input_type, amount/quantity/rate, accounting snapshot, source ref, metadata, idempotency key.
4. **`PayrollContributionIntake`** (service).
   - `ingest(PayrollContributionPayload): PayrollContributionOutcome` — idempotent. Materialises a `PayrollInput` if a writable run covers the period, otherwise persists a pending row.
   - `reverse(sourceType, sourceId, payItemCode, periodAnchor, reason): PayrollContributionOutcome` — delete the materialised input if still in a draft run, or insert a compensating reversal in the next open run; mark the pending row reversed either way.
5. **`PayrollContributionStatus`** (service). `for(...)` and `allFor(...)` are Payroll-owned read APIs returning a `PayrollContributionOutcome` keyed on the same composite tuple. Payroll surfaces use this instead of joining `people_payroll_inputs` directly.

The composite source key is `(source_type, source_id, pay_item_code, period_anchor)`, enforced at the DB level on `people_payroll_pending_contributions` via a unique index.

## What producers must and must not do

Producers (Leave, Claim, Attendance, and any future module that contributes to payroll) **must**:

- Publish the documented producer-domain event at the point where the operational fact becomes Payroll-relevant (claim queued or reversed, leave applied or encashed, overtime approved, allowance materialised).
- Keep event payloads in producer vocabulary. The source row remains in the producer module; Payroll listeners may read it through the declared optional dependency when translation needs additional context.
- Include the producer's stable row identity and natural date (incurred_on, starts_on, occurred_at) so the listener can derive the intake source tuple and period anchor.
- Treat successful dispatch as “the fact was published,” not proof that Payroll materialised or accepted a contribution.

Producers **must not**:

- Import any class under `App\Domains\People\Payroll` from production producer code.
- Query `people_payroll_inputs` directly. Payroll-owned status surfaces use `PayrollContributionStatus` instead.
- Choose target runs, create participant rows, or enforce idempotency themselves. Those are Payroll's responsibilities.

Boundary guards in Attendance, Claim, Leave, and Payroll scan production source trees for forbidden imports and fail the build if any return. Tests are outside this production dependency rule because integration tests may deliberately assemble both sides. Do not add production files to an allowlist; publish a producer event and translate it in Payroll instead.

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
- `app/Domains/People/Payroll/Contracts/Intake/` — DTO, state constants, and outcome class.
- `app/Domains/People/Payroll/Services/PayrollContributionIntake.php` and `PayrollContributionStatus.php` — service implementations.
