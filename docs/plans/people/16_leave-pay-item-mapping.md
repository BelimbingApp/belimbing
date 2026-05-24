# people/16_leave-pay-item-mapping

**Status:** Complete (2026-05-16). {claud/opus-4.7}
**Last Updated:** 2026-05-16
**Sources:**
- `docs/plans/people/12_attendance-event-decoupling.md` Phase 2 — canonical pattern (allowance-rule mapping table).
- `docs/plans/people/13_leave-event-decoupling.md` — established the event seam.
- `app/Modules/People/Payroll/Models/PayrollAttendanceRulePayItem.php` — sibling mapping model used as template.

## Problem

After Plan 13, `LeaveType.payroll_pay_item_code` is a payroll concept still living on a Leave-side row. Same shape as the `AttendanceAllowanceRule.payroll_pay_item_code` Plan 12 Phase 2 already moved.

## Approach

Mirror Plan 12 Phase 2 exactly. Move the column to a Payroll-owned mapping table with effective-dating; listener resolves at handoff time; UI moves to Payroll.

## Implementation

- New model `PayrollLeaveTypePayItem` in `app/Modules/People/Payroll/Models/`.
- Migration `0320_03_01_000009_create_people_payroll_leave_type_pay_items_table.php` creates the mapping + copies existing values from `LeaveType.payroll_pay_item_code` non-destructively.
- Migration `0320_03_01_000010_drop_payroll_pay_item_code_from_leave_types.php` drops the column.
- `RecordLeaveContribution` listener reads pay-item code from the mapping table via `effective_from <= occurred_on` resolution.
- New Payroll Livewire screen `LeaveTypePayItemMapping` + blade view + route + menu entry.
- Leave Livewire `Index` strips the pay-item dropdown from the leave-type form.
- `DevLeaveSeeder` stops seeding `payroll_pay_item_code`.
- `SeedSbgLeavePackCommand` migrated to seed the mapping table when Payroll is installed (guarded by `Schema::hasTable`).
- `LeaveType.PAYROLL_CODE_*` constants stay (still referenced by the encashment listener for the encashment code; that's a separate concern).
