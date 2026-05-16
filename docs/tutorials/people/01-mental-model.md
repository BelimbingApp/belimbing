# 1. Mental Model

## A payslip is a ledger

Every line on a payslip is one of four kinds:

- **Earnings** — basic salary, overtime, allowances, commission, bonus, incentive. Increase gross.
- **Reimbursements** — approved claims, mileage. Increase gross but usually outside the taxable wage base.
- **Deductions** — employee statutory contributions, income-tax withholding, unpaid leave, loan recovery, court orders. Reduce net.
- **Employer contributions** — what the company pays on top of gross (retirement, social security, training levies). Informational on the payslip; do not affect net.

In BLB this ledger is `PayrollResultLine` — immutable, references the source rule and the statutory rule-set version that produced it. If anyone asks later "why was this on the March payslip", the line points back.

## The source-of-truth rule

A fact belongs to **the one module that can answer it authoritatively**. Payroll trusts those modules; it never recomputes their facts.

- Did the employee work Tuesday's night shift? Only Attendance knows.
- How many days of annual leave remain? Only Leave knows.
- Was the fuel receipt approved? Only Claim knows.
- What is the grade, cost center, supervisor, fixed salary? Only the employee work profile knows.

If you find yourself reading attendance tables from inside Payroll, that's a smell. Move the logic to Attendance and emit a `PayrollInput`.

## The pipeline

Source modules write neutral input rows. The classifier resolves country-specific behavior. The calculator applies statutory rules. The result is appended to the ledger.

```
[source modules]  →  PayrollInput  →  PayItemClassifier  →  statutory calculator  →  PayrollResultLine
 Attendance,         neutral row:     country flags:        country pack:             immutable ledger,
 Leave, Claim,       pay_item_code,   contributable?        rates, bands, formulas    GL posting
 Settings,           amount,          tax path?
 Payroll recurring   source_id        exemption?
```

The neutral envelope is `PayrollInput`. Source modules write a row referencing a `pay_item_code` and let Payroll decide what that code means in this country, this year, this version of the rules. Source modules never call the calculator.

## Three questions to orient yourself in any People module

1. What fact does this module own? That fact is the reason the module exists.
2. What workflow governs changes to that fact — approval, effective-dating, attachments?
3. What does it emit to Payroll? Find the `PayrollInput` writes; the `pay_item_code` tells you the rest.
