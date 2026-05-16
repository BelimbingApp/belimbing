# 5. Module Map

Each People module owns one kind of fact. Find the fact, find the module.

## What each module owns

**Settings** owns shared people-domain reference data and the effective employee work profile. Key models: `EmployeeWorkProfile` (effective-dated employment terms — salary, pay basis, calendar, supervisor, cost center, workforce class), `PeopleCalendarException` (non-working days, company holidays, special workdays), `PeopleReferenceEntry` (lookups). It emits fixed recurring earnings via the work profile and provides the calendar facts Attendance and Leave consume.

**Attendance** owns clock events, shifts, overtime, lateness, and any allowance whose eligibility depends on those facts. Key models: `AttendanceClockEvent`, `AttendanceShiftTemplate`, `AttendanceDay`, `AttendanceOvertimeRequest`, `AttendanceAllowanceRule`, `AttendancePolicyGroup`, `AttendanceAdjustmentRequest`. Emits OT earnings, lateness deductions, and conditional allowances to Payroll.

**Leave** owns entitlement, balance, and approved absence. Key models: `LeaveType`, `LeaveEntitlementPolicy`, `LeaveRequest`, `LeaveRequestDay`, `LeaveBalanceLedgerEntry`. Emits unpaid-leave deductions and leave-encashment earnings.

**Claim** owns expense reimbursements with attachments and approval. Key models: `ClaimType`, `ClaimPolicy`, `ClaimRequest`, `ClaimLine`, `ClaimEntitlementUsageEntry`. Emits reimbursement lines, usually outside the taxable wage base.

**Payroll** owns the pay-item taxonomy, country packs, calculation, ledger, and statutory remittance. Key models: `PayrollPayItem`, `PayrollPayItemClassification`, `PayrollInput`, `PayrollResultLine`, `PayrollStatutoryRuleSet`, `PayrollStatutoryRuleRow`, `PayrollRun`, `PayrollPeriod`, `PayrollEmployerStatutoryProfile`, `PayrollEmployeeStatutoryProfile`. Produces the payslip and statutory output files.

**Workflow** (in Core, not People) owns approval chains, routing, and audit trails. Attendance OT requests, leave requests, and claim requests all route through Workflow. Workflow has no payroll output of its own.

## How a source module emits to Payroll

Always the same shape: write a `PayrollInput` row referencing a `pay_item_code`. The source module does not touch pay-item or classification tables and does not branch on country.

```php
PayrollInput::create([
    'payroll_run_id'   => $run->id,
    'employee_id'      => $employee->id,
    'source_type'      => 'attendance.allowance_rule',
    'source_id'        => $rule->id,
    'pay_item_code'    => $rule->payroll_pay_item_code,
    'input_type'       => PayrollInput::TYPE_EARNING,
    'quantity'         => $hours,
    'rate'             => $rate,
    'amount'           => $amount,
    'occurred_on'      => $date,
]);
```

`source_type` and `source_id` make the line traceable back to the originating record. `pay_item_code` is the only contract with Payroll — the country pack decides what that code means at run time.

## How Payroll classifies an input

`PayItemClassifier` resolves the `pay_item_code` against `PayrollPayItemClassification` rows scoped by country and effective date. The classifier answers the questions the calculator needs: is this contributable to each statutory scheme, which tax path applies, is there an exemption category and cap, which GL account does it post to. `PayrollRunCalculator` is the consumer; it does not branch on country.

## Where to look for what

- *What pay items exist?* `PayrollPayItem` model and its migration.
- *How is a pay item classified in country X?* `PayrollPayItemClassification` rows for that country, resolved by `PayItemClassifier`.
- *How does a run compute?* `PayrollRunCalculator`.
- *What are this year's rates for country X?* `PayrollStatutoryRuleSet` and `PayrollStatutoryRuleRow` rows scoped to that country.
- *What does an attendance allowance rule look like?* `AttendanceAllowanceRule` and the `AllowanceRules` Livewire screen.
- *How is OT approved and computed?* `AttendanceOvertimeRequest` and the attendance services.
- *How is leave balance tracked?* `LeaveBalanceLedgerEntry`.
- *How is claim approval wired?* `ClaimRequest` plus Core Workflow.
