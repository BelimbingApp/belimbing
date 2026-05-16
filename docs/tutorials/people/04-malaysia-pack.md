# 4. Malaysia Pack

A concrete instantiation of chapter 3. Read this for context on the country BLB ships first, and as a template for thinking about any other country pack.

The rates and bands below are illustrative — they show shape, not current values. Live numbers live in `PayrollStatutoryRuleSet` / `PayrollStatutoryRuleRow` and are updated per statutory year. Always read the rule set for what's in effect.

Each Malaysian acronym is explained on first appearance below; the [glossary](glossary.md) has the full list.

## Retirement: EPF

**EPF — Employees Provident Fund**, run by **KWSP** (Kumpulan Wang Simpanan Pekerja, the Malay name of the same body). It is Malaysia's mandatory retirement-savings scheme: every period, a slice of the employee's wage is paid into a long-term retirement account, with the employer adding more on top.

Mandatory for Malaysian citizens and permanent residents; foreign workers may opt in. The employee contributes around 11% and the employer around 12 to 13% of EPF wages, with reduced rates above age 60 and above a wage threshold. Contributions are **table-rounded** rather than pure percentages — the rounding table lives in the rule set. EPF wages include most cash pay (basic, allowances, overtime, commission, bonus) and exclude reimbursements, non-cash benefits, gratuity, and service charge. Employers remit by the 15th of the following month via KWSP's online employer portal (called **i-Akaun**) using **Form A** (the EPF monthly contribution schedule).

## Social security: SOCSO

**SOCSO — Social Security Organisation**, run by **PERKESO** (Pertubuhan Keselamatan Sosial, the Malay name of the same body). It insures employees against workplace injury and against long-term invalidity that prevents them from working.

Two schemes. **Scheme 1** (Employment Injury + Invalidity) covers employees under 60 who have contributed since young. **Scheme 2** (Employment Injury only) covers employees aged 60 and over or those who entered the scheme late. Typical rates under Scheme 1 are employer around 1.75% and employee around 0.5% of contributable wages, with a wage ceiling. Remitted monthly via PERKESO's online portal (called **ASSIST**) using **Form 8A** (the SOCSO/EIS monthly contribution schedule).

## Unemployment: EIS

**EIS — Employment Insurance System**, also run by PERKESO. It pays benefits to employees who lose their jobs involuntarily, similar to unemployment insurance elsewhere.

For employees aged 18 to 60. Employer and employee each contribute 0.2% of wages, subject to the same ceiling as SOCSO. Filed together with SOCSO on Form 8A.

## Income tax withholding: PCB / MTD

**PCB** (Potongan Cukai Bulanan) and **MTD** (Monthly Tax Deduction) are two names for the same thing: Malaysia's pay-as-you-earn (PAYE) system. The employer withholds income tax from each month's salary and remits it to **LHDN** (Lembaga Hasil Dalam Negeri, the Inland Revenue Board of Malaysia) so the employee's monthly withholding approximates their annual tax liability.

Remitted by the 15th of the following month using **Form CP39** (the monthly PCB remittance schedule). At year end, the employer issues **Form EA** to each employee (an annual income statement covering wages and PCB withheld) and files **Form E** to LHDN by March 31 (a workforce-wide summary of all EA forms).

Three calculation paths, matching the framework's general shape:

- **Normal remuneration.** Regular salary, fixed allowances, recurring shift differentials. Annualize the figure, apply reliefs, compute annual tax, divide across the remaining months.
- **Additional remuneration.** Bonus, irregular incentive, backpay, leave encashment. Compute annual tax with the lump and without; withhold the delta in the month of payment so the lump does not distort monthly take-home pay.
- **Commission (recurring).** Like normal but on its own CP39 line.

Reliefs that the employer can apply at source — EPF contributions capped at RM 4,000 per year for MTD purposes, life-insurance and **takaful** (Islamic-compliant insurance) premiums, child and spouse reliefs — are declared by the employee on **Form TP1** (the employee declaration of reliefs claimed for MTD).

**Zakat** is the Islamic obligatory alms paid by Muslims. Where an employee elects to have zakat deducted at source, it credits against PCB ringgit-for-ringgit, capped at the PCB amount that month. The election lives on the employee's statutory profile.

## Training levy: HRD Corp

**HRD Corp — Human Resources Development Corporation** (the body was previously called **HRDF**, Human Resources Development Fund). It runs a national training fund: employers pay a levy each month, and employees can receive HRD-Corp-subsidized training.

Employer-only — never deducted from the employee. Typically 1% of wages (or 0.5% for optional registrants) for employers in covered industries with at least 10 employees. The contributable base is narrower than EPF: basic plus fixed allowances, excluding overtime, bonus, and non-cash benefits.

## Other items

- **CP38.** A standing instruction from LHDN directing the employer to withhold *additional* tax beyond regular MTD, usually because the employee has unpaid tax from a previous year. Processed as a separate deduction line on the payslip.
- **Voluntary savings deductions.** Common Malaysian schemes that employees may elect to have deducted at source include **Tabung Haji** (a savings fund for the pilgrimage to Mecca) and **Amanah Saham** unit-trust subscriptions. Handled as elected payroll inputs.

## Tax-exempt allowance caps

LHDN exempts certain allowances from PCB up to caps, but only when the pay item is classified as an allowance with the right sub-type. Illustrative items, with values that change over time:

- Travel allowance for official duties up to RM 2,400 per year.
- Parking allowance, reasonable amount.
- Meal allowance for overtime or shift work, reasonable amount.
- Childcare allowance up to RM 3,000 per year.
- Medical and dental benefits, generally exempt within limits.

Mis-classifying a meal allowance as an incentive sends it through the additional-remuneration MTD path and loses the exemption. This is why the pay-item classification matters more than the user-facing label.

## Joiner / leaver / cessation notifications

LHDN requires the employer to notify it when certain events happen, using three forms:

- **CP22** when an employer hires a new employee subject to tax.
- **CP21** when an employee is about to leave Malaysia for more than three months.
- **CP22A** when an employee ceases employment (resignation, retirement, death).

## What lives where in the pack

- Rates, bands, ceilings, rounding tables → `PayrollStatutoryRuleSet` rows scoped to `country_iso = MY`.
- Pay-item classifications (EPF-able, SOCSO-able, EIS-able, PCB path, HRDF-able, exemption code, GL account) → `PayrollPayItemClassification` rows scoped to MY.
- Employer registration numbers, scheme registrations → `PayrollEmployerStatutoryProfile`.
- Employee tax ID, scheme participation, zakat election, TP1 reliefs → `PayrollEmployeeStatutoryProfile`.
- Form generators (EA, E, CP39, Form A, Form 8A) → country-specific templates reading from the ledger and profiles.
