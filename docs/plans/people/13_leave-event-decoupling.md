# people/13_leave-event-decoupling

**Status:** Phase 1 complete (2026-05-16). Pay-item-code migration and column rename deferred. {claud/opus-4.7}
**Last Updated:** 2026-05-16
**Owners:** claud/opus-4.7
**Sources:**
- `docs/plans/people/12_attendance-event-decoupling.md` — canonical pattern. This plan adopts plan 12's design decisions (events first, producer-domain payloads, intake stays as listener's internal write path, architectural test as boundary guard).
- `docs/architecture/pluggable-modules.md` — pluggable-modules architecture.
- `app/Modules/People/Leave/Services/LeavePayrollHandoffService.php` — leave-applied handoff that calls intake.
- `app/Modules/People/Leave/Services/LeaveEncashmentService.php` — encashment handoff that calls intake.
- `app/Modules/People/Leave/Models/LeaveType.php` — owns `interacts_with_payroll`, `payroll_pay_item_code`.

**Agents:** claud/opus-4.7

---

## Problem

Two Leave services import `PayrollContributionIntake` / `PayrollContributionPayload` directly. Leave's `LeaveType` carries `payroll_pay_item_code` (a payroll concept). Plan 12 established the right shape for Attendance; this plan applies the same shape to Leave.

## Desired Outcome

1. Leave dispatches public events on the two handoff points (leave applied + leave encashed).
2. Payroll has listeners for each event; intake stays unchanged.
3. No Leave class imports anything under `App\Modules\People\Payroll\`.
4. Architectural test (`LeaveDoesNotImportPayrollTest`) enforces the boundary.

## Out of Scope (deferred)

- Moving `LeaveType.payroll_pay_item_code` to a Payroll-side mapping table. Equivalent to plan 12 Phase 2 for Attendance. Belongs in a follow-up phase once the events ship and the boundary is verified clean. Phase 1 keeps the column on `LeaveType`; the listener reads it directly.
- Renaming `LeaveType.interacts_with_payroll` to a downstream-neutral name (`produces_payroll_input` or similar). Cosmetic, deferred.

## Components

| Component | Owner |
|-----------|-------|
| `Events\LeaveApplied` | Leave |
| `Events\LeaveEncashed` | Leave |
| `Listeners\RecordLeaveContribution` | Payroll |
| `Listeners\RecordLeaveEncashmentContribution` | Payroll |
| `LeaveDoesNotImportPayrollTest` | Tests |

## Phases

### Phase 1 — Events + listeners (only)

- [x] `Events\LeaveApplied` (companyId, employeeId, leaveRequestId, leaveTypeId, leaveBalanceLedgerEntryId, occurredOn, quantity, unit). {claud/opus-4.7}
- [x] `Events\LeaveEncashed` (companyId, employeeId, leaveTypeId, leaveBalanceLedgerEntryId, leaveYear, occurredOn, days, currency). {claud/opus-4.7}
- [x] `Listeners\RecordLeaveContribution` (looks up leave type, calls intake when applicable). {claud/opus-4.7}
- [x] `Listeners\RecordLeaveEncashmentContribution`. {claud/opus-4.7}
- [x] Register both listeners in Payroll `ServiceProvider`. {claud/opus-4.7}
- [x] Refactor `LeavePayrollHandoffService::onLeaveApplied` to dispatch the event. Return type changed from `?PayrollContributionOutcome` to `bool`. Service dropped the intake injection. {claud/opus-4.7}
- [x] Refactor `LeaveEncashmentService::encash` to dispatch the event. Dropped intake injection. Encashment pay-item code (`LeaveType::PAYROLL_CODE_LEAVE_ENCASHMENT`) moved to the listener. {claud/opus-4.7}
- [x] Architectural test (`LeaveDoesNotImportPayrollTest`). {claud/opus-4.7}
- [x] Full People test suite green — 28 Leave tests passing. {claud/opus-4.7}

**Exit criterion:**
- [x] Leave imports nothing under `App\Modules\People\Payroll\`.
- [x] Leave-applied + encashment tests still produce the expected `PayrollInput` rows via the listener.

Subsequent phases (pay-item mapping, column audits) follow the plan 12 numbering and pattern when scheduled.
