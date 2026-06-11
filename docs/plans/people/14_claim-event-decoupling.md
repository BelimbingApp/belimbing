# people/14_claim-event-decoupling

**Status:** Phase 1 complete (2026-05-16). Pay-item-code migration deferred. {claud/opus-4.7}
**Last Updated:** 2026-05-16
**Owners:** claud/opus-4.7
**Sources:**
- `docs/plans/people/12_attendance-event-decoupling.md` — canonical pattern.
- `docs/plans/people/13_leave-event-decoupling.md` — sibling Leave plan.
- `docs/architecture/module-system.md`.
- `app/Modules/People/Claim/Services/ClaimPayrollHandoffService.php` — queue + reverse paths that call intake.
- `app/Modules/People/Claim/Models/ClaimType.php`, `ClaimLine.php` — own `payroll_pay_item_code`, `payroll_eligible`.

**Agents:** claud/opus-4.7

---

## Problem

`ClaimPayrollHandoffService` imports `PayrollContributionIntake` / `PayrollContributionPayload` and has both a queue path and a reverse path. ClaimType/Line carry payroll-vocabulary columns. Same shape as plan 12, applied to Claim.

## Desired Outcome

1. Claim dispatches public events at the two handoff points (lines queued + lines reversed).
2. Payroll listens on both; intake unchanged.
3. No Claim class imports anything under `App\Modules\People\Payroll\`.
4. Architectural test gates the boundary.

## Out of Scope (deferred)

- Moving `ClaimType.payroll_pay_item_code` to a Payroll-side mapping table. Same deferral as plan 13.
- Renaming `ClaimType.payroll_eligible` to a downstream-neutral name. Same deferral.
- Removing `ClaimLine.payroll_pay_item_code` — it is the snapshot of what was dispatched. Operational state; stays per plan 12 D7-style reasoning.
- Replacing the per-line outcome summary in `queueApprovedRequest` (today the producer learns how many lines materialised/pending/rejected) — switching to events removes that synchronous outcome. Producers receive only a "dispatched" signal; downstream status comes from a separate Payroll-side status query.

## Components

| Component | Owner |
|-----------|-------|
| `Events\ClaimReimbursementQueued` (fires per eligible line) | Claim |
| `Events\ClaimReimbursementReversed` (fires per line on reversal) | Claim |
| `Listeners\RecordClaimReimbursement` | Payroll |
| `Listeners\ReverseClaimReimbursement` | Payroll |
| `ClaimDoesNotImportPayrollTest` | Tests |

## Phases

### Phase 1 — Events + listeners

- [x] `Events\ClaimReimbursementQueued` (per the payload in Components). {claud/opus-4.7}
- [x] `Events\ClaimReimbursementReversed` (per the payload in Components). {claud/opus-4.7}
- [x] `Listeners\RecordClaimReimbursement` calls intake `ingest`. {claud/opus-4.7}
- [x] `Listeners\ReverseClaimReimbursement` calls intake `reverse`. {claud/opus-4.7}
- [x] Register both listeners in Payroll `ServiceProvider`. {claud/opus-4.7}
- [x] Refactor `ClaimPayrollHandoffService::queueApprovedRequest` to dispatch one event per eligible line. Summary shape changed from `(eligible, queued, pending, skipped, rejected)` to `(eligible, dispatched, skipped)` — producers no longer learn the listener's materialisation outcome. {claud/opus-4.7}
- [x] Refactor `ClaimPayrollHandoffService::reverseRequest` to dispatch one event per line with a `payroll_pay_item_code`. {claud/opus-4.7}
- [x] Remove all Payroll imports from Claim. Dropped intake injection from the handoff service. {claud/opus-4.7}
- [x] Architectural test (`ClaimDoesNotImportPayrollTest`). {claud/opus-4.7}
- [x] Full People test suite green — 52 Claim tests passing. {claud/opus-4.7}

**Exit criterion:**
- [x] Claim imports nothing under `App\Modules\People\Payroll\`.
- [x] Queueing an approved claim produces the right `PayrollInput` rows via the listener.
- [x] Reverse path still produces compensating entries via the listener.

**Note on payload exception:** unlike Attendance/Leave, this plan's events carry `payItemCode` in the payload. The Claim line already snapshots that code at submission (it is operational state, not payroll vocabulary leakage). The event simply forwards what the line already records. When the future "move pay-item to Payroll mapping" phase lands, the line snapshot disappears and the event payload sheds the field at the same time.
