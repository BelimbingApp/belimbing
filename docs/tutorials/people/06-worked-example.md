# 6. Worked Example: Night-Meal Allowance

One conditional allowance, traced end-to-end. This is the canonical shape — substitute any other source module and the pattern is the same.

## Scenario

An employee clocks in at 18:00 and out at 04:00 the next day, a 10-hour shift. Company policy: a meal allowance is payable when a shift starts after 17:00 and runs at least 8 hours.

Under the Malaysia pack (chapter 4), that allowance is contributable to **EPF** (Employees Provident Fund — Malaysia's mandatory retirement-savings scheme), **SOCSO** (Social Security Organisation — workplace-injury and invalidity cover), and **EIS** (Employment Insurance System — unemployment cover), and is taxed via the normal **MTD** path (Monthly Tax Deduction — Malaysia's income-tax withholding, also called PCB / Potongan Cukai Bulanan), but tax-exempt up to a cap because it's a meal-for-shift-work allowance. **HRDF** (Human Resources Development Fund — the employer training levy, now run by HRD Corp) does not apply to it.

## Step 1 — Attendance records the facts

`AttendanceClockEvent` rows are written by the clocking device, mobile app, or manual entry. The shift resolver attributes the cross-midnight punches to the shift that started the prior calendar day, producing one `AttendanceDay` row with the worked minutes set.

The Attendance module owns this end-to-end. It knows the shift template, the punch window, the policy group, and the cross-midnight rule. Nothing else does.

## Step 2 — Allowance rule evaluates

`AttendanceAllowanceRule` "Night meal allowance" carries the configuration:

- `payroll_pay_item_code` = `MEAL_ALLOW_SHIFT`
- `allowance_type` = `daily`
- `resolution_method` = `sum`
- `condition_rows` = `[{ shift_start_after: "17:00", min_duration_minutes: 480 }]`
- effective period covers the payroll period

The allowance evaluator runs against each `AttendanceDay` in the period, matches the condition, and writes a `PayrollInput` row:

```php
PayrollInput::create([
    'payroll_run_id' => $run->id,
    'employee_id'    => $emp->id,
    'source_type'    => 'attendance.allowance_rule',
    'source_id'      => $rule->id,
    'pay_item_code'  => 'MEAL_ALLOW_SHIFT',
    'input_type'     => PayrollInput::TYPE_EARNING,
    'amount'         => 15.00,
    'occurred_on'    => $attendanceDay->work_date,
]);
```

Attendance has now done its job. It never touches anything statutory.

## Step 3 — Payroll classifies the pay item

When the run calculates, `PayItemClassifier` resolves `MEAL_ALLOW_SHIFT` against `PayrollPayItemClassification` rows for Malaysia, effective on the run date. The rows answer: contributable to EPF yes, SOCSO yes, EIS yes, HRDF no; tax path normal; exemption category `MEAL_SHIFT_WORK`; GL account configured.

In a different country pack, the same code could classify differently. That's the whole point of separating classification from emission.

## Step 4 — Calculator applies the flags

`PayrollRunCalculator` reads the input plus its classification and folds it in. The RM15 enters the EPF, SOCSO, and EIS wage bases. It enters PCB taxable income and is then subtracted under the `MEAL_SHIFT_WORK` exemption within the configured cap. It is excluded from HRDF wages. It contributes RM15 to gross pay; the calculator subtracts deductions to produce net.

The calculator did not branch on country. It read country-specific data and applied uniform arithmetic.

## Step 5 — Ledger writes immutable lines

One `PayrollResultLine` is written for the pay item with the amount, the `source_type` and `source_id` pointing back to the allowance rule, and the statutory rule-set version stamped on the row. The line is immutable. If anyone asks later why the RM15 appeared on March's payslip, the line points to the exact rule and the exact rule-set version that produced it.

## Recipe for a new conditional allowance

1. Create or pick a `PayrollPayItem` (for example, `ONCALL_ALLOW`).
2. Add `PayrollPayItemClassification` rows for each country that will use it.
3. Create an `AttendanceAllowanceRule` pointing at the code with the right `condition_rows`.
4. Nothing in Payroll's code changes.

## Anti-patterns to spot

- Attendance computing EPF, SOCSO, or tax directly. Wrong — emit a `PayrollInput` and let Payroll classify.
- Payroll reading `AttendanceDay` to recompute allowances. Wrong — consume the `PayrollInput`.
- A perfect-attendance bonus modelled as an `AttendanceAllowanceRule`. Wrong — bonuses use the additional-remuneration tax path; create a distinct pay item and route via a different mechanism.
- Hardcoded rates anywhere outside `PayrollStatutoryRuleSet`. Wrong — statutory values change yearly.
- Code branching on country (`if (country === 'MY')`). Wrong — the difference belongs in classification rows or rule-set rows.
