# people/02_payroll-malaysia-top-level-design

**Status:** Phase 4 in progress — Malaysia EPF, SOCSO, EIS, and HRD levy calculators in place
**Last Updated:** 2026-05-11
**Sources:**
- `docs/plans/people/01_people-modules.md` — People suite framing and Payroll as a planned module
- `docs/plans/people/04_pdf-generation-strategy.md` — PDF rendering infrastructure (complete); supplies `RenderPdfJob`, `PdfRenderer`, `PdfPostProcessor`, and the artifact contract that Phases 5 and 9 consume
- `docs/architecture/pdf-rendering.md` — renderer surface, template convention, concurrency model, escape hatches
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 i-Payroll parity benchmark
- `docs/architecture/file-structure.md` — module placement and extension boundaries
- KWSP/EPF employer mandatory contribution guidance — https://www.kwsp.gov.my/en/employer/responsibilities/mandatory-contribution
- KWSP/EPF non-Malaysian employee contribution guidance — https://www.kwsp.gov.my/en/employer/responsibilities/non-malaysian-citizen-employees
- LHDN/IRBM MTD payment guidance — https://www.hasil.gov.my/en/employers/mtd-payment/
- LHDN/IRBM 2026 computerized MTD specification — https://www.hasil.gov.my/media/arvlrzh5/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf
- PERKESO contribution-rate entry point — https://www.perkeso.gov.my/en/rate-of-contribution.html
- HRD Corp levy calculation guideline — https://supportcentre.hrdcorp.gov.my/portal/en/kb/articles/hrd-levy
- Malaysian Government zakat salary deduction service — https://www.malaysia.gov.my/en/digital-services/zakat-payment-via-salary-deduction-scheme-application
- Oracle PeopleSoft Global Payroll documentation — https://docs.oracle.com/cd/G48964_01/hcm92pbr54/eng/hcm/hgpe/GlobalPayrollDocumentation-e35b9e.html
**Agents:** amp/gpt-5.1-codex

## Problem Essence

Belimbing needs Payroll for Malaysian employers first, but Payroll is one of the highest-risk HR domains because the business result is both financial and regulatory. Malaysia alone involves EPF/KWSP, SOCSO/PERKESO, EIS/SIP, PCB/MTD income tax, zakat salary deductions, HRD Corp levy, bank payment files, agency submission files, annual statements, rounding rules, wage-basis differences, employee residency/nationality differences, and future effective-date changes.

The design should let BLB ship a useful Malaysia payroll module without trapping the whole framework in Malaysia-specific assumptions. The long-term shape should be a country-neutral payroll core with country packs that provide local statutory rules, statutory data, forms, file exports, and validation cases.

## Desired Outcome

A Payroll module that can run normal Malaysian payroll safely for small-to-mid-size businesses, produce explainable payslips and statutory totals, and later accept additional countries without rewriting payroll runs, payslips, approvals, accounting exports, or employee self-service. HR2000 benchmarking sharpens the definition of parity: Payroll must cover not only statutory calculation accuracy, but also the operational workflow around payroll — claims, leave, attendance/overtime, statutory and bank outputs, employee documents, run locking, role security, and auditability.

The recommendation is a hybrid architecture:

- **Country-neutral Payroll Core** owns pay periods, payroll runs, input collection, calculation orchestration, ledger-like result storage, approvals, payslips, accounting exports, reversals, and audit trails.
- **Country Packs** own statutory rules and local outputs for a jurisdiction. They should be designed as extension-compatible modules from day one. The first pack is Malaysia (`MY`). Future packs such as Singapore (`SG`) or Indonesia (`ID`) plug into the same core.
- **First-party incubation, extension destination.** Malaysia may live internally while Payroll Core is young, but it must use the same contract a future `extensions/belimbing/payroll-my` package would use. Once the contract proves stable, country packs become first-party or third-party extensions.
- **Effective-dated statutory data** stores contribution tables, caps, rates, brackets, rounding policies, and file-format versions with validity windows. Rules never look up “current” values implicitly; every payroll run resolves rules as of the pay period/payment date and snapshots the version used.
- **Deterministic code for complex rules, data tables for rates.** Use code interfaces for logic that is genuinely procedural, such as PCB formulas or statutory eligibility. Use data tables for official schedules, rates, caps, wage bands, and text-file layouts. Avoid building a broad no-code payroll DSL in the first version.

## Malaysia Research Summary

Malaysia payroll is a set of related but distinct obligations, not one deduction formula.

| Area | What BLB must model | Design implication |
|------|---------------------|--------------------|
| **EPF / KWSP** | Employer and employee monthly retirement contributions. Rates vary by citizenship/PR status, age, wage band, and special non-Malaysian rules effective from October 2025. KWSP states employers must remit by the 15th of the following month and refer to the Third Schedule, not simple percentage math, for many cases. | EPF needs eligibility classification, wage-base classification, official schedule tables by effective date, rounding policy, voluntary excess contributions, and contribution-month handling. |
| **SOCSO / PERKESO** | Social security contributions with categories, wage bands, age/status differences, and an insured wage ceiling. The operational source is the official contribution table rather than a naive percentage. | SOCSO needs table-driven brackets, category selection, foreign/local applicability, wage ceiling, and clear separation from EIS even if paid through related channels. |
| **EIS / SIP** | Employment Insurance System contributions administered through SOCSO, with employer and employee shares, eligibility rules, age rules, and wage ceiling/table behavior. | EIS should be a separate statutory component with its own eligibility and schedule, not a flag inside SOCSO. |
| **PCB / MTD income tax** | LHDN supports computerized payroll calculation and e-Jadual PCB/e-CP39. The official computerized specification includes resident/non-resident handling, normal vs additional remuneration formulas, reliefs, zakat offsets, rounding, text-file format, and verification procedure. | PCB is a country-pack calculation service with official formula versioning, employee tax profile inputs, prior-employment values, TP forms, zakat interaction, and CP39/e-PCB export support. |
| **Zakat** | Employees may pay zakat through salary deduction schemes. Zakat affects PCB/MTD calculation and may be deducted by employer or merely declared as paid separately by employee. Malaysia also has state-level zakat authorities and forms/services. | Zakat should be modeled as an employee-authorized deduction/offset with state authority metadata, separate from tax but visible to PCB calculation. |
| **HRD Corp levy** | Employer levy under HRD Corp/PSMB rules, based on Malaysian employees and wage definitions. The HRD Corp support guideline states mandatory employers pay 1% of monthly wages and voluntary registrants pay 0.5%. | HRD levy is employer-cost payroll overhead and statutory reporting, not an employee payslip deduction. It needs employer eligibility, wage basis, and training-fund ledger visibility. |
| **Payments and submissions** | Agencies and banks need monthly files, portals, proofs, and reconciliation. Annual employee statements and employer filings also matter. | Payroll results must be exportable through country-pack file generators and must retain immutable proof of what was generated and paid. |

## Recommended Architecture

### 1. Payroll Core as the Stable Deep Module

The core should be boring and country-neutral. It should not know what EPF or PCB means. It should know these concepts:

- Employer payroll profile: pay schedule, pay entity, base currency, default country, bank/payment settings, accounting mappings.
- Worker payroll profile: employee link, employment status, country of employment, tax residency, payroll calendar assignment, statutory profile references.
- Pay periods and payroll calendars: monthly, weekly, semi-monthly, ad hoc/off-cycle.
- Pay inputs: base salary, hourly wages, overtime, commissions, bonuses, allowances, unpaid leave, claims reimbursements, benefits-in-kind, employer benefits, recurring deductions, one-off adjustments.
- Payroll run lifecycle: draft, input locked, calculated, reviewed, approved, posted, paid, closed, voided/reversed.
- Calculation result ledger: each earning, deduction, employee contribution, employer contribution, tax, levy, net pay, and accounting posting stored as an immutable line with source rule and version.
- Payslip generation: explainable summary over ledger lines, localized by country pack.
- Reconciliation and audit: who changed inputs, who approved, which statutory tables/rules were used, which exports were generated, and which payment proofs were attached.

This keeps payroll operations reusable across countries while allowing each country to have different statutory mechanics.

### 2. Country Pack Contract

Each country pack should answer a small set of questions for the core:

- What statutory profiles are required for employers and employees in this country?
- Which pay inputs are taxable, pensionable, insurable, levyable, or excluded under local rules?
- Which statutory calculations must run for this employee in this period?
- Which employee deductions, employer contributions, employer levies, and informational lines should be returned?
- Which result lines require monthly submission, annual reporting, or employee-facing disclosure?
- Which file exports, forms, and validation reports does the jurisdiction require?

For Malaysia, the pack would provide calculators/exporters for EPF, SOCSO, EIS, PCB, zakat handling, HRD levy, and local payslip/statutory reporting labels.

### 3. Effective-Dated Statutory Data

Payroll needs stronger versioning than normal app configuration. A future correction must not silently change a closed payroll run.

Store statutory material as effective-dated records:

- Contribution schedules and wage bands.
- Tax brackets, relief amounts, formulas, and special regimes.
- Wage ceilings and eligibility cutoffs.
- Rounding rules.
- Export file layouts and portal format versions.
- Agency identifiers and payment references.

Each payroll run snapshots the resolved statutory version IDs into the result ledger. If Malaysia changes EPF rates in 2027, old 2026 payslips remain explainable and reproducible.

### 4. Pay Item Classification Instead of Country-Specific Columns

Do not add columns like `is_epf_subject`, `is_socso_subject`, or `is_pcb_subject` directly to generic pay items. That leaks Malaysia into the core and will not scale.

Instead, model pay items with neutral identity and let country packs classify them by jurisdiction and effective date. For example, a “bonus” input can be:

- an earning in the core,
- EPF-subject or EPF-excluded depending on country-pack classification,
- PCB additional remuneration in Malaysia,
- a different tax treatment in another country.

The classification layer should be inspectable so payroll admins can answer “why was this allowance included in EPF but excluded from HRD levy?”

### 5. Immutable Run Results, Adjustable Next Run

Closed payroll runs should not be edited in place. Corrections should flow through reversal/adjustment runs or next-period arrears/overpayment entries. This is important for audit, employee trust, and statutory reconciliation.

The core should support:

- recalculation while a run is draft,
- locking inputs for review,
- finalizing immutable result lines,
- reversing a closed run,
- carrying adjustments into a later run,
- showing differences between calculation attempts before approval.

### 6. Country Packs as Extensions

BLB already has an extension model, and payroll country packs fit that model well because statutory payroll behavior is naturally jurisdiction-specific, changes independently from the country-neutral core, and benefits from local expert maintenance. The long-term target should be:

- `app/Modules/People/Payroll` — the country-neutral Payroll Core.
- `BelimbingApp/blb-payroll-my` — public first-party Malaysia statutory pack repository, installed into BLB as `extensions/belimbing/payroll-my`.
- `BelimbingApp/blb-payroll-sg` — public first-party Singapore statutory pack repository, if BLB chooses to maintain it, installed as `extensions/belimbing/payroll-sg`.
- `extensions/{vendor}/payroll-{country}` — community, vendor, or licensee country packs.
- `extensions/{licensee}/payroll-{country}-custom` — business-specific statutory overrides, union rules, file layouts, or reporting variations where legally safe.

Country-pack extensions should be stricter than ordinary UI/menu extensions. Payroll packs are financial/regulatory code, so the installation contract should require explicit compatibility with the Payroll Core version, statutory-data version metadata, deterministic calculators, validation fixtures, and audit/explanation output for each statutory line.

Repository ownership should follow the same boundary:

- `belimbingapp/belimbing` owns Payroll Core and the public country-pack contract. It should stay country-neutral.
- `BelimbingApp/blb-payroll-my` owns reusable Malaysia statutory behavior: EPF, SOCSO, EIS, PCB, zakat handling, HRD Corp levy, statutory exports, statutory data imports, and Malaysia validation fixtures.
- `kiatng/blb-sbg` owns private SBG licensee customization while BLB is being developed for SBG: company policies, approval variants, payroll-accounting mappings, cost centers, private reports, bank/payment preferences, custom allowances/deductions, and any business-specific rules that should not be generalized.

The SBG private repo should layer on top of `BelimbingApp/blb-payroll-my`, not fork or copy Malaysia statutory calculators. If SBG needs a custom allowance or deduction treatment, the preferred path is configuration or a narrow extension hook exposed by the Malaysia pack. If the need is truly statutory and reusable across Malaysian employers, it belongs upstream in `BelimbingApp/blb-payroll-my`.

### 7. Country Pack Ownership Options

There are three viable placement models for country packs.

| Option | Shape | Strengths | Weaknesses | Recommendation |
|--------|-------|-----------|------------|----------------|
| **Internal packs inside Payroll** | `People/Payroll` contains `MY`, later `SG`, etc. | Simple for first launch, one deployment, fastest iteration. | Core may gradually absorb country-specific assumptions if discipline is weak. | Acceptable only as incubation for Malaysia while the contract is still moving. |
| **Extension packs** | Core Payroll ships neutral APIs; `extensions/vendor/payroll-my` provides Malaysia. | Best long-term ecosystem story, countries can be maintained independently, aligns with BLB extension model. | More upfront API discipline and installation/version management. | Preferred target architecture. Country packs should be extension-shaped even before they physically move to `extensions/`. |
| **Pure database-config rules** | Admins configure formulas/tables without code. | Appealing for frequent rate changes and customer customization. | Payroll logic becomes shallow, hard to test, hard to audit, and dangerous for complex tax formulas. | Do not start here. Use data-driven tables inside tested country-pack code instead. |

Recommended path: **build Malaysia as a reference first-party country pack behind a country-pack interface**, and keep its structure extension-compatible from the first commit. It can be physically internal during incubation if that speeds refactoring, but the design target is a first-party extension pack. The core should never depend on concrete Malaysia classes, EPF/SOCSO/PCB table names, or Malaysia-specific database columns.

The reason not to start as a fully independent extension immediately is practical: the first country will reveal missing core concepts. Keeping Malaysia close while the interface matures avoids premature extension API promises. The reason to keep it extension-shaped anyway is strategic: country proliferation is real, and moving from one internal Malaysia implementation to a clean extension ecosystem later would be expensive if the boundary is not protected now.

## Top-Level Domain Components

| Component | Ownership | Notes |
|-----------|-----------|-------|
| Payroll calendars and periods | Core | Neutral; every country uses calendars. |
| Pay run lifecycle | Core | Draft through closed/reversed, with approvals and locks. |
| Pay input collection | Core with People integrations | Pull salary from Employee, time/overtime from Attendance, unpaid leave from Leave, claims reimbursements from Claims, and allowances/deductions from configured pay inputs. |
| Calculation orchestration | Core | Calls country pack calculators in deterministic order and stores result lines. |
| Statutory profile forms | Country pack | Malaysia needs EPF/SOCSO/tax/zakat/HRD employer and employee fields. |
| Statutory calculators | Country pack | EPF, SOCSO, EIS, PCB, zakat offset/deduction handling, HRD levy. |
| Statutory tables | Country pack data | Effective-dated official schedules, caps, brackets, and file formats. |
| Payslip rendering | Core layout plus country labels | Core renders from result lines; country pack provides labels and statutory explanations. |
| Agency exports | Country pack | EPF, SOCSO/EIS, PCB/CP39/e-PCB, zakat, HRD levy files/reports. |
| Annual statutory documents | Country pack plus Core document delivery | Malaysia needs employee annual tax/statutory documents such as EA/CP8A-style forms and PCB2-style records, exposed through self-service and exportable for payroll administrators. |
| Accounting export | Core with mapping | Employer cost, liability, net wage, and payment clearing accounts. |
| Employee self-service | People/Self-Service over Payroll | Payslip viewing, tax forms, bank details, zakat instructions, statutory IDs. |

## Data Model Principles

Do not decide table names yet, but the model should preserve these invariants:

- Money is stored in minor units or decimal-safe values with explicit currency.
- Every payroll result line has a type: earning, employee deduction, employee contribution, employer contribution, employer levy, tax, reimbursement, informational, or net pay.
- Every result line references its source input or source statutory rule where possible.
- Every statutory calculation line records country, jurisdiction, rule code, effective version, wage base, cap/table row used, rounding rule, employee share, employer share, and display label.
- Employee statutory profiles are effective-dated because citizenship, PR status, tax residency, marital status, dependents, zakat authorization, and voluntary contribution choices change over time.
- Employer statutory profiles are effective-dated because registrations, HRD eligibility, agency account numbers, branch/entity setup, and payment methods change over time.
- Pay item classifications are effective-dated and country-specific.
- Closed run results are immutable; corrections are new facts, not destructive edits.

## Public Contract

The first implementation boundary is the **Payroll Country Pack v0 contract**. It is intentionally small: large enough to keep Malaysia statutory behavior out of Payroll Core, but not so broad that BLB promises a stable third-party extension API before the first country has proven the seams.

Payroll Core promises to provide country packs with a normalized calculation context:

- pay entity, country, currency, pay period, pay date, and contribution/submission month metadata;
- employer payroll profile and employee payroll profile as effective-dated snapshots;
- approved payroll participants for the run;
- normalized pay inputs with neutral pay item codes, amounts, quantities, source module references, and source effective dates;
- prior run/result history needed for arrears, reversals, year-to-date values, or statutory cumulative calculations;
- a result writer that accepts structured result lines without allowing country packs to mutate closed runs directly.

A Payroll Country Pack must provide these capabilities:

- **Identity and compatibility:** country code, pack identifier, pack version, supported Payroll Core contract version, statutory data version list, and migration/import notes.
- **Employer statutory profile schema:** country-specific fields the employer/pay entity must maintain, with effective dates and validation rules.
- **Employee statutory profile schema:** country-specific fields each employee must maintain, with effective dates and validation rules.
- **Pay item classification:** effective-dated classification of neutral pay inputs into statutory wage bases, tax treatments, contribution bases, levy bases, exclusions, and informational categories.
- **Calculators:** deterministic calculators that return employee deductions, employee contributions, employer contributions, employer levies, taxes, reimbursements, informational lines, warnings, and blocking validation errors.
- **Statutory data resolution:** lookup of effective-dated rates, tables, brackets, caps, rounding policies, and file-format versions by pay period/payment date.
- **Explanation output:** human-readable and machine-readable explanations for each statutory line, including wage base, employee/employer category, statutory data version, cap/table/bracket used, and rounding rule.
- **Exports and documents:** metadata and generators for country-specific monthly submissions, bank/statutory files where owned by the pack, annual employee documents, and administrator reports.
- **Validation fixtures:** official or curated examples that prove calculators and exports behave as intended for common and edge cases.

The contract explicitly forbids these shortcuts:

- Payroll Core must not contain EPF, SOCSO, EIS, PCB, zakat, HRD Corp, or other country-specific columns or concrete service dependencies.
- Country packs must not write directly into closed payroll results; they return proposed result lines for Payroll Core to persist.
- SBG-specific rules in `kiatng/blb-sbg` must not fork or copy Malaysia statutory calculators from `BelimbingApp/blb-payroll-my`; they must use configuration or narrow extension hooks unless the rule belongs upstream for all Malaysian employers.
- Formal compliance claims such as LHDN endorsement must not be implied by the contract. The contract supports official formulas, effective-dated statutory data, and validation fixtures; endorsement/verification is a separate operational status.

## Future-Country Accommodation

The hard boundary is country of employment/pay entity, not UI menu placement. A BLB tenant might mostly operate in Malaysia today, then hire in Singapore later. The core should support:

- ISO country code on pay entity and employee payroll profile.
- Country pack selected by pay entity, with employee-specific override only when legally justified.
- Tax residency as separate from work country and citizenship.
- Sub-jurisdictions where needed. Malaysia has state-level zakat authorities; other countries may have province/state taxes, city taxes, social-security regions, or canton-like rules.
- Multi-currency payments and reporting, even if Malaysia v1 only uses MYR.
- Country-specific statutory profile screens mounted through country-pack metadata.
- Country-specific exports and annual forms without changing core run tables.
- Local-language labels without changing calculation semantics.

This mirrors proven global-payroll systems: a country-neutral core payroll application plus country extensions for statutory/customary objects, rules, reports, and pages.

## Risks and Guardrails

- **Risk: treating official tables as percentages.** KWSP and PERKESO-style schedules can produce different answers from raw percentages due to wage bands and rounding. Guardrail: Malaysia calculators must use effective-dated official schedules where official schedules exist.
- **Risk: retroactive statutory changes.** Guardrail: version every statutory data import and snapshot rule versions per run.
- **Risk: country leakage into core.** Guardrail: no EPF/SOCSO/PCB columns in core payroll tables; country pack owns statutory meaning.
- **Risk: opaque calculations.** Guardrail: every statutory line needs an explanation payload suitable for admin review and payslip drill-down.
- **Risk: overbuilding a DSL.** Guardrail: begin with typed PHP calculators and data tables; introduce a rule-expression layer only after repeated country packs prove which parts are genuinely common.
- **Risk: unaudited compliance claims.** Guardrail: Malaysia v1 should say “based on configured official tables/specifications” until validated against official calculators and, for PCB, the LHDN computerized MTD verification path is considered.
- **Risk: mistaking HR2000 parity for Malaysia-only architecture.** Guardrail: benchmark HR2000 at the operational-job level — payroll inputs, approvals, outputs, and controls — while keeping Payroll Core country-neutral and pushing statutory behavior into country packs.
- **Risk: mobile parity bloats payroll v1.** Guardrail: treat GPS/geofence/device-binding attendance as an Attendance/Self-Service capability to validate with SBG, not a blocker for deterministic payroll correctness unless SBG confirms it is day-one critical.

## Phases

### Phase 0 — Boundary and contract lock

- [x] Confirm the ownership boundary: `belimbingapp/belimbing` owns Payroll Core, `BelimbingApp/blb-payroll-my` owns Malaysia statutory behavior, and `kiatng/blb-sbg` owns private SBG customization. {amp/gpt-5.1-codex}
- [x] Define the first country-pack contract in prose: statutory profiles, pay-item classification, calculators, statutory data, exports, validation fixtures, and explanation/audit output. {amp/gpt-5.1-codex}
- [x] Codify the Payroll Country Pack v0 extension contract and singleton registry in Payroll Core so country packs expose manifest, profile-schema, pay-item-classifier, calculator, and export facets without concrete country dependencies. {amp/gpt-5.1-codex}
- [x] State the no-leak rule before implementation: Payroll Core must not depend on EPF/SOCSO/PCB classes, Malaysia table names, or Malaysia-specific columns. {amp/gpt-5.1-codex}

### Phase 1 — Payroll Core skeleton

- [x] Create country-neutral payroll calendars and pay periods. {amp/gpt-5.1-codex}
- [x] Create the payroll run lifecycle: draft, calculated, reviewed, approved, closed, and voided. Reversal remains a later accounting/output concern once closed-run correction flows are designed. {amp/gpt-5.1-codex}
- [x] Model run participants and neutral pay inputs for salary, allowance, deduction, overtime, unpaid leave, claim reimbursement, bonus/additional remuneration, and one-off adjustments. {amp/gpt-5.1-codex}
- [x] Store immutable result ledger lines for earnings, employee deductions, employee contributions, employer contributions, employer levies, taxes, reimbursements, and net pay. {amp/gpt-5.1-codex}
- [x] Generate a basic payslip snapshot from result lines without Malaysia statutory logic. {amp/gpt-5.1-codex}
- [x] Capture audit history for calculation, review, approval, close, and void actions. {amp/gpt-5.1-codex}

### Phase 2 — Pay item classification model

- [x] Define a neutral pay item catalog for basic salary, fixed allowance, variable allowance, overtime, bonus, claim reimbursement, unpaid leave, and deductions. {amp/gpt-5.1-codex}
- [x] Add the country-pack classification hook so Malaysia can classify each pay input for EPF, SOCSO, EIS, PCB normal remuneration, PCB additional remuneration, and HRD levy without changing core schema. {amp/gpt-5.1-codex}
- [x] Make classification effective-dated and inspectable before final payroll calculation. {amp/gpt-5.1-codex}
- [x] Produce classification explanation metadata that states the pack, version, country, effective window, and reason a pay input belongs to a statutory treatment. Detailed statutory wage-base explanations continue in Phase 4 when Malaysia calculators emit result lines. {amp/gpt-5.1-codex}

### Phase 3 — Malaysia statutory profile setup

- [x] Capture employer statutory profile payloads for EPF, SOCSO, LHDN, HRD Corp applicability, and future zakat authority metadata without adding Malaysia-specific core columns. `BelimbingApp/blb-payroll-my` owns the field schema and validation semantics. {amp/gpt-5.1-codex}
- [x] Capture employee statutory profile payloads for citizenship/PR/foreign-worker status, age category, EPF number, SOCSO number, tax number, tax residency, PCB profile inputs, and future zakat authorization without adding Malaysia-specific core columns. `BelimbingApp/blb-payroll-my` owns the field schema and validation semantics. {amp/gpt-5.1-codex}
- [x] Make statutory profile changes effective-dated so profile updates do not mutate closed payroll runs. {amp/gpt-5.1-codex}
- [x] Resolve employer and employee statutory profile snapshots for a payroll period by country and country-pack version; the Malaysia pack will turn those snapshots into statutory categories during Phase 4 calculators. {amp/gpt-5.1-codex}

### Phase 4 — Malaysia EPF, SOCSO, EIS, and HRD levy

- [x] Add effective-dated statutory table storage for contribution schedules, wage bands, wage ceilings, rates, and rounding rules. {amp/gpt-5.1-codex}
- [x] Add an internal extension-shaped Malaysia country-pack skeleton registered through the Payroll Country Pack v0 contract, with manifest metadata, employer/employee profile schemas, pay-item classification adapter, skeleton calculator, and planned statutory export definitions. {amp/gpt-5.1-codex}
- [x] Wire Payroll Core calculation orchestration to registered country packs: build per-participant calculation context, resolve statutory profile snapshots and pay-item classifications, persist proposed country-pack result lines, include employee statutory deductions in net pay, and audit the pack version used. {amp/gpt-5.1-codex}
- [x] Implement Malaysia EPF, SOCSO, EIS, and HRD levy calculators using classified statutory wage bases and effective-dated rule-table rows before PCB. EPF/SOCSO/EIS emit employee and employer contribution lines; HRD emits employer levy lines when the employer statutory profile marks HRD levy applicable. {amp/gpt-5.1-codex}
- [x] Write result ledger lines for employee contribution, employer contribution, and employer levy amounts for EPF/SOCSO/EIS/HRD. {amp/gpt-5.1-codex}
- [ ] Complete calculation explanations across EPF/SOCSO/EIS/HRD: wage base, employee category, statutory version, cap/bracket/table row used, and rounding rule. Current lines record wage base, rule set/row, rates, rounding policy, profile IDs, pack, and share; employee category remains open. {amp/gpt-5.1-codex}
- [ ] Show statutory deductions/contributions on the payslip and employer-cost reports.

### Phase 5 — Payroll outputs baseline

**PDF infrastructure ready for consumption.** `docs/plans/people/04_pdf-generation-strategy.md` is complete; `App\Base\Pdf\Jobs\RenderPdfJob` is the queue-friendly entry point for every visual PDF below, and the `App\Base\Pdf\Events\PdfArtifactRendered` event is the hook for persisting artifact lineage against `PayrollRun` / `PayrollRunParticipant` (template version, data version, sha256, produced_by, produced_at — exactly what this plan's Phase 4 audit requirements need). See also `docs/architecture/pdf-rendering.md` for the renderer surface, the `resources/core/views/pdf/payroll/...` template convention, and the concurrency model.

- [ ] Produce payslip PDF for a closed payroll run via `RenderPdfJob` against `resources/core/views/pdf/payroll/payslip.blade.php` (template authored as a Phase 1 stub by the PDF plan; production data shape and template polish belong to this phase). Persist the resulting `PdfArtifact` (disk, path, sha256, template/data versions) against the payslip record so closed runs have immutable PDF lineage.
- [ ] Produce payroll summary, employee statutory contribution, and employer cost reports as their own Blade templates under `resources/core/views/pdf/payroll/`. Use `PdfRenderer::renderInline` for static reports (no auth dependency); `renderView` only where a report depends on per-user state.
- [ ] Generate the first SBG-needed bank payment export or a clearly marked bank-export placeholder while the exact bank format is confirmed.
- [ ] Export operational reports to practical formats such as PDF, CSV/XLSX, and statutory text files where applicable. PDF goes through `RenderPdfJob`; statutory text files (CP39, EPF, SOCSO, EIS, bank GIRO) are plain-text emitters in the Malaysia country pack and do **not** touch the PDF pipeline.
- [ ] Provide a payroll lock/audit report for review before and after closing a run.
- [ ] Bulk-job throughput validation: run a 500-employee monthly payroll batch through `RenderPdfJob` and record p95 wall time, peak memory, and OOM eviction count. If those numbers cross the Gotenberg trigger thresholds in `docs/architecture/pdf-rendering.md`, escalate to the escape hatch instead of tuning the in-process path further.

### Phase 6 — Claims as payroll input

- [ ] Create the minimal Claims workflow: claim type, amount, attachment, submit, approve/reject, and mark payable through payroll.
- [ ] Pull approved claims into the next draft payroll run as reimbursement inputs.
- [ ] Display claim reimbursements on payslips separately from statutory wages unless the country pack classifies them otherwise.

### Phase 7 — Attendance and overtime as payroll input

- [ ] Create minimal attendance/overtime records without attempting a full TMS implementation.
- [ ] Add overtime request and approval workflow.
- [ ] Pull approved overtime into payroll as earning inputs.
- [ ] Let the country pack classify overtime for statutory wage bases.
- [ ] Keep rotating shifts, GPS/geofence, and device binding as SBG-validated follow-ups unless they become day-one requirements.

### Phase 8 — PCB and zakat

- [ ] Implement LHDN computerized MTD formula versioning after the result ledger, statutory profiles, and statutory explanations have been proven with other contributions.
- [ ] Support normal remuneration, additional remuneration, non-resident handling, prior-employment/TP inputs, and common resident employee cases.
- [ ] Add zakat deduction/offset handling with state authority metadata where needed.
- [ ] Validate PCB and zakat behavior against official examples/calculators and decide whether formal LHDN verification is required before SBG production use.

### Phase 9 — Self-Service documents

- [ ] Let employees view and download payslips from Self-Service. The closed-run payslip `PdfArtifact` from Phase 5 is the source; ESS streams from the artifact's disk + path.
- [ ] Add employee access to annual tax/statutory documents once those outputs exist. Visual EA/CP8A/PCB2 forms render through `RenderPdfJob` against BLB-authored Blade templates at `resources/core/views/pdf/payroll/{ea-form,cp8a,pcb2}.blade.php` — the layouts satisfy the published LHDN specifications, not overlays on LHDN-issued AcroForm PDFs. (If filling LHDN-issued AcroForms ever becomes a hard requirement, the `pdf-lib` escape hatch documented in `docs/architecture/pdf-rendering.md` is the right next step — not Phase 9 work as planned.)
- [ ] Let payroll admins publish documents to employees through the portal.
- [ ] Password-encrypted PDF distribution: dispatch `RenderPdfJob` with the `password` field set. `PdfPostProcessor::protectWithPassword` applies AES-256 automatically via the injected `QpdfRunner`. Whether to enable this is conditional on SBG or parity validation actually requiring it — keep the policy decision separate from the technical capability. qpdf binary must be installed on the rendering host; see `docs/guides/pdf-rendering.md`.

### Phase 10 — Extension hardening and second-country proof

- [ ] Decide when to physically maintain Malaysia in the public `BelimbingApp/blb-payroll-my` repository and install it as `extensions/belimbing/payroll-my`.
- [ ] Validate the country-pack contract with a thin second-country spike before building another full statutory pack.
- [ ] Confirm which SBG-specific payroll rules belong in `kiatng/blb-sbg` versus upstream in `BelimbingApp/blb-payroll-my`.

## Open Research Before Implementation

- Confirm current PERKESO official PDFs/tables and import format for SOCSO/EIS, including exact foreign-worker and age-category handling.
- Confirm KWSP Third Schedule import strategy and October 2025 non-Malaysian contribution rollout details.
- Confirm LHDN 2026 PCB computerized-calculation verification expectations for BLB: whether BLB as open-source software should seek verification, provide a verification harness, or leave verification to licensees/custom implementers.
- Confirm state-by-state zakat salary deduction workflows and whether BLB should ship generic zakat deduction plus state metadata first, rather than full state-specific integrations.
- Confirm HRD Corp employer eligibility rules by industry and employee-count threshold beyond the basic levy calculation.
- Decide whether Payroll belongs as `app/Modules/People/Payroll` from the start or whether it should become a deeper cross-cutting People/Finance boundary once accounting exists.
