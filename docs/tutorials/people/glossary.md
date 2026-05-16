# Glossary

## BLB framework terms

- **Country pack.** The set of data rows that describes one country's statutory behavior — classifications, rule sets, profile schemas, form templates. Code is country-neutral; packs are data.
- **Source-of-truth module.** The one module that owns a fact authoritatively (Attendance for clock events, Leave for balances, etc.). Other modules consume its outputs and never recompute its facts.
- **Pay item.** A typed code for one kind of payslip line (basic, OT, meal allowance, EPF deduction). Country-neutral on the surface; classification rows give it country-specific meaning.
- **Classification.** Per-country flags on a pay item — contributability, tax path, exemption, GL account. Lives in `PayrollPayItemClassification`.
- **Neutral input.** A `PayrollInput` row written by any source module to hand a fact to Payroll without touching country logic.
- **Statutory rule set.** Versioned country rate tables — contribution percentages, ceilings, tax bands, rounding tables. Lives in `PayrollStatutoryRuleSet` and `PayrollStatutoryRuleRow`.
- **Tax path.** The branch of withholding arithmetic a pay item uses — normal recurring, additional remuneration (lump), or commission. Set on the pay item via classification.
- **Result line.** `PayrollResultLine` — immutable ledger entry produced by the calculator, stamped with source and rule-set version.

## Generic payroll concepts

- **Wages (statutory).** A country-defined wage base for each contribution scheme. Usually most cash pay; specific exclusions vary.
- **Contributable wages.** The portion of pay that counts toward a particular scheme's calculation.
- **Normal remuneration.** Regular recurring pay; taxed via the standard withholding formula.
- **Additional remuneration.** Lumps — bonus, irregular incentive, backpay, encashment — taxed via the with-vs-without delta method.
- **PAYE.** Pay-As-You-Earn; the generic name for employer-side income-tax withholding.
- **Benefits-in-kind (BIK).** Non-cash benefits. Often taxable but not always within social-security wage bases.
- **Gratuity.** Lump payment on termination or retirement.

## Employment / labour

- **Overtime multipliers.** Country-defined factors (commonly 1.5×, 2.0×, 3.0×) applied to base hourly rate for overtime, rest-day, and public-holiday work.
- **Rest day / off day.** The weekly non-working day required by labour law.
- **Public holiday.** Gazetted national or regional non-working day.

## Malaysia statutory bodies and schemes

- **LHDN / IRBM.** Lembaga Hasil Dalam Negeri / Inland Revenue Board of Malaysia. Income tax.
- **KWSP / EPF.** Kumpulan Wang Simpanan Pekerja / Employees Provident Fund. Retirement savings.
- **PERKESO / SOCSO.** Pertubuhan Keselamatan Sosial / Social Security Organisation. Workplace injury and invalidity.
- **EIS.** Employment Insurance System, administered by PERKESO. Unemployment cover.
- **HRD Corp.** Human Resources Development Corporation. Training-levy fund (was HRDF).
- **Zakat.** Voluntary Muslim alms; in Malaysia, credits against PCB.

## Malaysia forms

- **CP21 / CP22 / CP22A.** Notifications for employees leaving the country, joining, or ceasing employment.
- **CP38.** LHDN instruction to deduct additional tax beyond MTD (typically for past arrears).
- **CP39.** Monthly PCB remittance schedule.
- **TP1.** Employee declaration of reliefs claimed for MTD calculation.
- **Form A.** EPF monthly contribution schedule.
- **Form 8A.** SOCSO/EIS monthly contribution schedule.
- **EA.** Annual employee income statement issued by employer (by end of February).
- **E.** Annual employer return summarizing all EA forms (by March 31).

## Malaysia pay-component terms

- **PCB / MTD.** Potongan Cukai Bulanan / Monthly Tax Deduction — Malaysia's income-tax withholding.
- **VOLA.** Value of Living Accommodation — imputed taxable value of employer-provided housing.

## Self-service and operational

- **MSS / ESS.** Manager Self-Service / Employee Self-Service.
- **TMS.** Time Management System — generic term for attendance/shift management; in BLB the area is the Attendance module.

## BLB code shorthand

- **`PayrollInput`.** Neutral envelope. Source modules write this; carries `pay_item_code` and amount.
- **`PayrollResultLine`.** Immutable calculated line. Stamped with source and rule-set version.
- **`PayrollPayItem`.** Pay-component definition (code, label, kind).
- **`PayrollPayItemClassification`.** Per-country flags per pay item.
- **`PayrollStatutoryRuleSet` / `PayrollStatutoryRuleRow`.** Versioned country rate tables.
- **`PayrollEmployerStatutoryProfile` / `PayrollEmployeeStatutoryProfile`.** Per-entity statutory registrations and elections.
- **`EmployeeWorkProfile`.** Effective-dated employment terms (salary, calendar, supervisor, cost center).
- **`AttendanceAllowanceRule`.** Attendance-conditioned allowance configuration.
