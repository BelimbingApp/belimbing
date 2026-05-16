# 2. Pay Components

## Background

### The problem we are solving

A payslip is the destination of many independent business processes — someone clocked in, someone approved overtime, someone filed a claim, someone took unpaid leave, someone hit a sales target, the tax authority required a contribution. Each fact lives in its own module with its own lifecycle, approvals, and source data.

If Payroll tried to know all of those processes, it would become a god module. If each source module tried to know payroll's statutory rules, the statutory logic would be duplicated and would drift between modules. We need a way to let many modules contribute facts to one payslip **without coupling**.

### How BLB solves it

- Source modules write a **neutral envelope row** — `PayrollInput` — referencing a string **pay-item code**.
- Payroll resolves the code at run time against country-specific classification data and applies the statutory arithmetic.
- The source module never touches statutory rules; Payroll never reads the source module's tables.

The result: business facts stay where they belong; statutory routing stays in one place; the country pack is the only thing that changes country to country.

### Pay-item code: where, why, alternatives

- **Defined in:** `PayrollPayItem` (model at `app/Modules/People/Payroll/Models/PayrollPayItem.php`). One row per pay component the system knows about (`BASIC`, `OT_NORMAL`, `MEAL_ALLOW_SHIFT`, `EPF_EE`, etc.).
- **Referenced by:** every source module, as a string on `PayrollInput.pay_item_code`. Never via foreign key.
- **Classified by:** `PayrollPayItemClassification` rows scoped per country.

Why a string code rather than a foreign-key reference:

- The code is stable across migrations and country packs.
- Source modules do not have to import Payroll's classes; the domains stay decoupled.
- A country pack can change a code's classification (e.g. add a tax exemption) without touching the code itself.

**Alternative considered:** each source module defines its own pay-component enum. **Rejected** — there would be no central registry, adding a new component would touch every consumer, and statutory routing would leak into source modules.

**Trade-off accepted:** string codes invite typos. Mitigated by validating codes when a `PayrollInput` is written, and by a single registry where new codes are added.

### Module boundaries — what is and isn't in People

The People domain is the **HR** domain. Sub-modules under `app/Modules/People/`:

- `People/Settings` — shared people-domain data (work profile, calendars).
- `People/Attendance` — clock events, shifts, overtime, lateness, attendance-conditioned allowances.
- `People/Leave` — entitlement, balance, leave requests.
- `People/Claim` — expense reimbursements.
- `People/Payroll` — the payroll engine itself: pay-item taxonomy, country packs, calculator, ledger.

What is **not** in People (and why):

- **Sales / commission schemes** belong in a future `Sales` domain (separate from `People/`). Commission depends on revenue, deals, and sales targets — facts the HR domain does not own. Today there is no Sales module, so commission enters Payroll as a direct manual input. When Sales lands, it will emit to Payroll the same way Attendance does.
- **Finance / GL** belongs in a future `Finance` domain. Payroll's calculator writes pay items with a GL account from classification, but the postings themselves are consumed by Finance.
- **Procurement / vendor advances** likewise.

The principle: a fact lives in the domain that authoritatively owns the *source data*. If People does not own the source data, the rule does not belong in People — even if its end-effect appears on a payslip.

### How to read the rest of this chapter

Each pay component below uses a key-value sketch:

- **Owner:** which BLB module writes the `PayrollInput`. `People/<sub>` for in-People sources; "direct Payroll input" when no upstream module exists yet.
- **Source models:** the records that drive it.
- **Emits:** the kind of input written (earning, deduction, reimbursement).
- **Statutory:** generic shape — the country pack decides the specifics (see chapter 3).
- **Notes:** anything non-obvious.

---

## Earnings

### Basic salary

The fixed periodic wage in the employment contract.

- **Owner:** `People/Settings`
- **Source models:** `EmployeeWorkProfile` (pay basis, amount, effective dates)
- **Emits:** earning, every run
- **Statutory:** contributable to most schemes; tax path normal
- **Notes:** no workflow — read directly by Payroll when building the run.

### Fixed allowances

Recurring amounts tied to employment terms, not behaviour. Examples: housing allowance, **COLA** (cost-of-living adjustment, paid to offset inflation or location costs), fixed transport allowance.

- **Owner:** `People/Settings` or `People/Payroll` (recurring inputs)
- **Source models:** `EmployeeWorkProfile` or a recurring-input table in Payroll
- **Emits:** earning, every run, until end-dated
- **Statutory:** usually contributable; tax path normal; some types may have country-specific tax exemptions

### Attendance-conditioned allowances

Allowances whose **eligibility** depends on attendance facts. Examples: shift differential, meal allowance when shift exceeds X hours, on-call allowance, night premium.

- **Owner:** `People/Attendance`
- **Source models:** `AttendanceAllowanceRule`, `AttendanceDay`, `AttendanceShiftTemplate`
- **Emits:** earning, when the rule's `condition_rows` match for a given day
- **Statutory:** contributable; tax path normal; meal/transport sub-types often country-tax-exempt up to a cap
- **Notes:** the rule references a `payroll_pay_item_code` string. Payroll never reads attendance tables.

### Overtime

Pay for hours beyond the normal schedule, usually at statutory multipliers defined by the country's labour law.

- **Owner:** `People/Attendance`
- **Source models:** `AttendanceOvertimeRequest`, shift hour calculation
- **Emits:** earning, after the OT request is approved
- **Statutory:** contributable; tax path normal
- **Notes:** the approved request resolves to payable hours × multiplier and writes the `PayrollInput`.

### Commission

Sales-linked variable pay — recurring monthly or one-off.

- **Owner today:** direct `People/Payroll` input (no upstream module)
- **Owner in future:** a Sales / CRM domain (`Sales/Commission` or similar), not in People
- **Source models today:** manual entry or import into `PayrollInput`
- **Emits:** earning
- **Statutory:** contributable; tax path depends on country — many countries (Malaysia included) use a separate commission path
- **Why not in People:** commission depends on revenue, deal closure, and sales targets — facts the HR domain does not own.

### Incentives and bonuses

Irregular or period-end variable pay. Examples: perfect-attendance bonus, KPI bonus, festival bonus, production incentive.

- **Owner today:** direct `People/Payroll` input
- **Owner in future:** a Compensation or Incentive module if BLB grows that need
- **Source models today:** manual entry or import
- **Emits:** earning
- **Statutory:** contributable; tax path is the additional-remuneration path (lump-sum withholding)
- **Notes:** do **not** model these as `AttendanceAllowanceRule`. A perfect-attendance bonus looks like an attendance fact but is a bonus from the statutory perspective — different tax path, different lifecycle (HR/management sign-off rather than daily evaluation).

### Reimbursements

Repayment of out-of-pocket business expenses.

- **Owner:** `People/Claim`
- **Source models:** `ClaimRequest`, `ClaimLine`, `ClaimPolicy`
- **Emits:** reimbursement (`PayrollInput::TYPE_REIMBURSEMENT`)
- **Statutory:** generally **not** contributable and **not** taxable, *if* it's a genuine receipted reimbursement. A disguised allowance gets reclassified.
- **Notes:** Claim has its own lifecycle (submit → review → approve → pay) and attachment handling. Payroll only sees the approved amount.

### Backpay / arrears

Retroactive salary adjustment for a prior closed period (e.g. promotion backdated three months).

- **Owner:** `People/Payroll` (generated, not user-entered)
- **Source models:** `EmployeeWorkProfile` (effective-dated salary change crossing a closed period)
- **Emits:** earning
- **Statutory:** contributable; tax path is additional-remuneration (lump)

### Leave encashment

Cash payout for unused leave balance — at termination or under an annual encashment policy.

- **Owner:** `People/Leave`
- **Source models:** `LeaveBalanceLedgerEntry` plus the termination event
- **Emits:** earning
- **Statutory:** contributable; tax path additional-remuneration

### BIK — benefits-in-kind

Non-cash benefits the employee receives: a company car, employer-provided housing, a club membership. Recorded for year-end reporting but not paid in cash. Malaysia tracks a specific kind called **VOLA** (Value of Living Accommodation), the imputed taxable value of employer-provided housing.

- **Owner:** `People/Settings` or `People/Payroll` (recurring inputs)
- **Source models:** work profile or a benefits table
- **Emits:** informational earning (no cash impact on net)
- **Statutory:** usually **not** contributable to social-security wage bases; **is** taxable for income tax — country pack decides

---

## Deductions

### Employee statutory contributions

Retirement, social-security, unemployment contributions withheld from the employee.

- **Owner:** `People/Payroll` (computed by the statutory calculator)
- **Source models:** `PayrollStatutoryRuleSet`, `PayrollEmployeeStatutoryProfile`
- **Emits:** deduction, computed in-engine (not via a `PayrollInput`)
- **Notes:** the employee never writes these directly.

### Income-tax withholding

Generically called **PAYE** (pay-as-you-earn): the employer withholds income tax from each pay period and remits it to the tax authority on the employee's behalf, so by year-end the employee has prepaid roughly what they owe. Country-specific names: Malaysia **PCB** (Potongan Cukai Bulanan) / **MTD** (Monthly Tax Deduction); UK PAYE; Australia PAYG (pay-as-you-go); US federal income-tax withholding.

- **Owner:** `People/Payroll`
- **Source models:** `PayrollStatutoryRuleSet`, employee reliefs on `PayrollEmployeeStatutoryProfile`
- **Emits:** deduction, computed in-engine
- **Tax paths:** normal recurring, additional-remuneration (lumps), and sometimes a separate commission path

### Unpaid leave

- **Owner:** `People/Leave`
- **Source models:** `LeaveRequest`, `LeaveRequestDay`
- **Emits:** deduction `PayrollInput` per unpaid day at the configured rate

### Lateness / early-out

- **Owner:** `People/Attendance`
- **Source models:** attendance policy + `AttendanceDay`
- **Emits:** deduction
- **Notes:** the policy decides whether lateness is forgiven, rounded, or deducted.

### Loans, advances, court orders, voluntary savings

- **Owner:** `People/Payroll` recurring inputs (today) or a future Loan-schedule module
- **Source models:** recurring-input table
- **Emits:** deduction, scheduled per agreement

### Special tax instructions

A standing instruction from the tax authority directing extra withholding beyond the normal schedule. Malaysia's version is **CP38**.

- **Owner:** `People/Payroll`
- **Emits:** deduction
- **Notes:** country-specific.

---

## Employer contributions

These are paid by the company, shown on the payslip for transparency, and do not reduce net pay.

### Employer-side statutory contributions

Retirement-fund employer share, social-security employer share, unemployment employer share, training levies.

- **Owner:** `People/Payroll`
- **Source models:** `PayrollStatutoryRuleSet`, `PayrollEmployerStatutoryProfile`
- **Emits:** employer-contribution line, computed in-engine
- **Notes:** mirror the employee deductions but with employer-side rates. Country-specific employer-only levies (e.g. Malaysia's HRD Corp training levy) sit here too.

---

## Why the catalogue matters in code

- The **Owner** field tells you which module to open when you want to change behaviour.
- The **pay-item code** tells you which `PayrollPayItemClassification` rows you need for the country.
- Mis-classifying a recurring shift premium as a bonus, for example, routes it through the additional-remuneration tax path — withholding spikes when paid, under-withholds when not, year-end true-up surprises the employee.

The trade-off behind every choice in this chapter is the same: **keep the source of truth narrow, keep the envelope neutral, push variation into data**. The chapters that follow show that data layer (chapter 3) and one concrete instance of it (chapter 4).
