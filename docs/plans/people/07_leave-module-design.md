# people/07_leave-module-design

**Status:** Identified
**Last Updated:** 2026-05-12
**Sources:**
- `docs/plans/people/01_people-modules.md` — Leave is one of the planned People submodules; entitlement, balances, requests, approvals, carry-forward, and team calendar visibility called out at suite level
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Country-neutral core + country-pack architecture and effective-dated statutory data; unpaid leave already modelled as a neutral `PayrollInput` type that the country pack classifies; statutory profile resolver, run lifecycle, and audit patterns this plan should mirror
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 e-Leave feature inventory used as parity benchmark (entitlement policies, replacement leave with expiry, burn leave, cancel/withdraw, full/half-day, advance leave, max days per application, attachments, multi-tier approval, state holidays, employee/company leave calendars, year planner, e-mail notifications)
- `docs/plans/people/05_sbg-ipayroll-settings-gap-bridge.md` — People Settings foundation (work calendar/exceptions, reference data, employment groups, employee account access, profile change requests, notification delivery log) that Leave consumes rather than re-implements
- `docs/plans/people/06_ipayroll-employee-module-gap-bridge.md` — Employee workbench naming, payroll data readiness, work-profile dependencies that Leave entitlement and approval routing depend on
- `docs/plans/people/04_pdf-generation-strategy.md` — `App\Base\Pdf\Jobs\RenderPdfJob` is the queue-friendly entry point for any printable Leave document (year planner, balance statement, leave history report)
- `app/Modules/People/Settings/Models/PeopleCalendarException.php` — existing work-calendar exception model Leave should consume
- `app/Modules/People/Payroll/Models/PayrollInput.php` and `PayrollPayItem.php` — neutral pay-input contract through which approved unpaid leave and leave-encashment payouts feed payroll
- `app/Modules/Core/Employee/` — canonical employee identity, supervisor/reporting line, employment dates that drive entitlement accrual and approval routing
- Malaysia Employment Act 1955 (as amended Act A1651, in force 2023-01-01) — statutory minima for annual leave, sick leave, hospitalization leave, maternity leave (98 days), paternity leave (7 days), and gazetted public holidays
- `docs/architecture/file-structure.md` — module placement; `docs/plans/AGENTS.md` — plan conventions
**Agents:** amp/claude-haiku-4.5

## Problem Essence

BLB's People suite currently has no Leave capability. Without it, the Payroll module cannot honestly compute unpaid-leave deductions, leave-encashment payouts, or replacement-leave value; the Self-Service module has nothing to apply for; and SBG cannot retire HR2000 e-Leave. Leave also overlaps statutory regulation: Malaysia's Employment Act fixes minimum entitlements (annual, sick, hospitalization, maternity, paternity), defines public holidays, and constrains how leave can be denied, paid out, or carried forward. Building Leave as a Malaysia-specific feature would repeat the architectural mistake Payroll has already corrected; building it as pure free-form policy would leak compliance risk back to the licensee.

## Desired Outcome

A Leave module under `app/Modules/People/Leave/` that gives a small-to-mid-size Malaysian employer a credible end-to-end leave operation — entitlement configuration, accrual and balances, employee/manager request and approval workflow, calendar visibility, attachments, cancellations, replacement and carry-forward rules, and clean handoff to Payroll for unpaid leave and any encashment — while preserving the same architectural shape Payroll established: a country-neutral Leave Core in `belimbingapp/belimbing`, Malaysia statutory leave behaviour in `BelimbingApp/blb-payroll-my` (or a sibling `blb-people-my` if the boundary proves separate), and SBG-specific leave policies and templates in `kiatng/blb-sbg`. Done means HR can run a normal monthly leave cycle for SBG, the figures match HR2000 within the parity scope, and no Malaysia-specific column or class lives in Leave Core.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Leave type catalog | Neutral identity for each leave (e.g. annual, sick, hospitalization, maternity, paternity, marriage, compassionate, unpaid, replacement, special) with paid/unpaid, gender/eligibility hints, default unit (days/half-days/hours), default approval depth, and whether it interacts with Payroll. Country pack owns statutory semantics; SBG/private packs add company-specific types. | Leave Core; statutory hints from country pack |
| Entitlement policy | Effective-dated rules that, given an employee work profile and a year, produce a target entitlement: years-of-service bands, accrual frequency (annual lump, monthly accrual, anniversary), prorate for joiners/leavers, eligibility window, and country-pack overrides for statutory minima. | Leave Core schema; Malaysia pack provides Employment Act minima as seed policies |
| Balance ledger | Append-only entries that produce current balance per employee per leave type per year: opening, accrual, taken, cancelled, adjusted, carried-forward, expired, encashed. Each entry references its source (policy run, request, adjustment, carry-forward job). | Leave Core |
| Request lifecycle | Draft → submitted → approved/rejected/cancelled → applied → optionally withdrawn-after-approval. Half/full day, multi-day spans, attachments, max-days-per-application validation, advance-leave gating, overlap detection, calendar exception awareness. | Leave Core |
| Approval routing | Multi-tier approval driven by employee supervisor chain, employment group, leave type, and policy. Reuses or wraps the existing Workflow module rather than inventing a parallel engine. | Leave Core consuming Workflow |
| Work calendar integration | Resolve working days, public holidays (gazetted federal + state-specific), and calendar exceptions for entitlement proration, days-deducted calculation, and overlap checks. Consumes `PeopleCalendarException` and a country-pack public-holiday source. | People Settings + country pack |
| Replacement & carry-forward | Replacement leave earned for working a holiday with expiry; year-end carry-forward with cap and expiry; optional burn-leave conversion; encashment hooks for unused balances. | Leave Core; SBG cap/expiry numbers in private pack |
| Payroll handoff | Unpaid leave days and any encashment lines surface as neutral `PayrollInput` rows for the next draft run; the Malaysia country pack classifies them for EPF/SOCSO/EIS/PCB treatment. | Leave Core writes inputs; Payroll/country pack classifies |
| Self-service surfaces | Employee: apply, view balance, view team calendar, withdraw, attach documents. Manager: approve/reject, see subordinate balances, see overlap risk. | People Self-Service consuming Leave Core read/write APIs |
| Notifications | Submitted, approved, rejected, cancelled, balance-low, expiry-approaching, year-planner published. Routes through `PeopleNotificationDeliveryLog` rather than a Leave-private channel. | Leave Core emits; People Settings persists log |
| Reports & documents | Balance statement, leave history, year planner (per employee, team, company), team calendar PDF, statutory leave-utilisation summary, leave-on-behalf audit. PDFs go through `RenderPdfJob`. | Leave Core data builders + Blade templates under `resources/core/views/pdf/leave/` |

## Design Decisions

**Mirror the Payroll country-pack contract instead of inventing a new one.** The Payroll Country Pack v0 surface (`PayrollCountryPack`, `ProvidesPayrollProfileSchemas`, `ClassifiesPayrollPayItems`, statutory rule resolver, effective-dated rule rows) is the proven shape. Leave Core should expose an analogous `LeaveCountryPack` contract with: country/pack identity and version, statutory leave-type definitions, statutory entitlement policies (minimum days by service band, eligibility, proration), public-holiday calendars (federal + state), Employment-Act validation rules (e.g. maternity 98 days, paternity 7 days, hospitalization aggregate cap), and explanation output. Malaysia statutory behaviour plugs in through this contract; Leave Core never imports a Malaysia class. Whether the Malaysia leave pack ships in `BelimbingApp/blb-payroll-my` (alongside the payroll calculators because they share statutory data and employee profiles) or in a sibling `blb-people-my` repo is a Phase 0 decision; the contract is the same either way.

**Statutory minima are a floor, employer policy is the configured value.** The Malaysia pack ships seed entitlement policies that match the Employment Act minima for each service band. Employers (HR admins) can configure higher entitlements but never below the statutory floor for the leave types the Act covers. The Core records whether a configured policy currently meets the active country-pack floor and surfaces violations as blocking validation, not as silent overrides — the same posture Payroll takes for statutory rule rows.

**Effective-dated entitlement and policy data, not "current" values.** Service-band tables, statutory minima, public-holiday lists, and replacement/carry-forward rules change over time (Act amendments, new gazetted holidays). Every entitlement calculation snapshots the resolved policy version so a 2026 balance statement remains explainable in 2028. Same invariant Payroll already enforces.

**Balance is a ledger, not a counter.** Storing only `days_remaining` makes corrections destructive and audit-hostile. Append-only ledger entries (opening, accrual, taken, cancelled, adjusted, carried-forward, expired, encashed) produce the balance by aggregation. Cancelling an approved leave creates a reversing entry; it does not mutate the original. This matches the immutable-result-line discipline Payroll uses for closed runs.

**Reuse the Workflow module for approval routing.** A separate Leave-specific approval engine would diverge from Claims, Overtime, and Profile Change Requests. Leave defines the routing intent (per-type approval depth, by employment group, by amount-of-days threshold) and delegates execution to Workflow. If the existing Workflow module cannot express multi-tier-by-type routing yet, that gap is a Workflow follow-up, not a reason to fork.

**Public holidays are country-pack data with state overlays, not a generic dictionary.** Malaysia has federal gazetted holidays plus state-specific holidays, and PERKESO/EPF do not own this. The Malaysia pack ships effective-dated public-holiday tables keyed by (year, federal/state). Pay-entity work-state determines the applicable overlay; employee-level overrides exist only when legally justified (e.g. employee assigned to a project in another state). Employer-specific company holidays use the existing `PeopleCalendarException` mechanism, not the statutory table.

**Unpaid leave and encashment go through the existing PayrollInput surface.** Payroll already declares unpaid leave as one of its neutral pay-input types and the Malaysia pack already classifies pay items for EPF/SOCSO/EIS/PCB. Leave produces `PayrollInput` rows with the right neutral pay-item code (`unpaid_leave`, `leave_encashment`, `replacement_leave_payout`) and a back-reference to the leave request; Payroll is unchanged. No new Payroll-Leave coupling table is needed.

**No leave-on-behalf without an audit trail.** HR2000 supports applying leave on behalf of employees. BLB allows this only through an explicit on-behalf flow that records the actor, the employee, the reason, and the employee notification. The same audit shape `EmployeeProfileChangeRequest` already uses.

**Half-day and hourly are first-class units, not workarounds.** Some SBG leave types (e.g. medical appointment) are commonly half-day; future country packs may need hourly leave. Leave Core stores requested duration as a typed unit (full-day, half-day-AM, half-day-PM, hours) and converts to days against the resolved work calendar for balance deduction. This avoids the "0.5 day hack" pattern.

**Leave-Module ownership of medical certificate / hospitalization evidence is shallow.** Attachments are stored against the request (using whatever attachment infrastructure the Workflow/Documents layer provides). Leave Core does not OCR, validate, or interpret medical documents in v1 — it records that an attachment exists, its type tag, and who uploaded it.

**Do not build a leave-policy DSL in v1.** Same reasoning Payroll used. Typed PHP policies (annual-by-service-band, accrual-monthly-prorated, fixed-annual, eligibility-after-confirmation, parental-eligibility) plus effective-dated parameter rows cover the SBG/Malaysia surface. Configurable expression rules can come later if multiple country packs demand them.

## Public Contract

**Leave Core promises country packs a normalized leave context per request or per entitlement run:**
- pay-entity, country/state, currency, leave year, evaluation date;
- employee identity and effective-dated work profile snapshot (hire date, employment group, work calendar, pay basis, gender/eligibility-relevant fields the country pack declares);
- prior leave ledger entries needed for accrual, carry-forward, expiry, and cumulative cap calculations;
- pending and approved request history within the relevant period;
- a write API that records ledger entries, attaches policy version IDs, and refuses to mutate frozen periods.

**A Leave Country Pack must provide:**
- **Identity and compatibility:** country code, pack identifier, pack version, supported Leave Core contract version, statutory data version list.
- **Statutory leave types:** the country-required leave types with their canonical neutral codes, paid/unpaid disposition, default unit, eligibility predicates, and statutory floor parameters.
- **Statutory entitlement policies:** for each statutory type, the minimum entitlement schedule (e.g. service-band rows), proration rules for partial-year employment, and aggregate caps (e.g. Malaysia hospitalization aggregate of 60 days inclusive of sick leave).
- **Public holiday calendars:** effective-dated federal and sub-jurisdiction (Malaysia state) holiday lists, with substitution rules (e.g. when a holiday falls on a rest day).
- **Validation rules:** blocking checks that reject configurations or requests that violate the Act (e.g. maternity less than 98 days, paternity less than 7 days, denying gazetted holiday without legal basis).
- **Explanation output:** human- and machine-readable explanations for entitlement and balance computations: which service band applied, which policy version, which holiday calendar, which proration rule.
- **Reports/documents metadata:** descriptors and templates for any country-specific leave report (e.g. employee leave statement that satisfies an audit request).

**The contract explicitly forbids:**
- Leave Core depending on Malaysia-specific classes, columns, or labels (no `is_employment_act_subject`, no `state_holiday_code` on core tables).
- Country packs writing into closed leave periods or mutating prior-year ledger entries directly; corrections happen as new ledger facts.
- SBG private code in `kiatng/blb-sbg` forking Malaysia statutory leave types or holiday calendars; SBG layers configuration and narrow extension hooks on top of the Malaysia pack.
- Implying compliance certification. The contract supports Employment-Act minima and validation; formal advisory or labour-court reliance is the licensee's responsibility.

## Risks and Guardrails

- **Risk: statutory minima silently violated by configured policies.** Guardrail: Leave Core compares configured policies against country-pack floors at save time and at every entitlement run; mismatches block, not warn.
- **Risk: balance drift from imported HR2000 data.** Guardrail: import path lays opening-balance ledger entries with `source: 'migration'` and a per-employee reconciliation report; no destructive overwrite of computed balances.
- **Risk: replacement-leave/expiry surprises year-end.** Guardrail: expiry runs are scheduled jobs with dry-run output, employee notifications before expiry, and explicit ledger entries — never silent zero-out.
- **Risk: country leakage into Leave Core.** Guardrail: same enforcement Payroll uses — no Malaysia table names, no Employment-Act column flags, no `MY_*` constants in Core.
- **Risk: approval routing forks from Workflow.** Guardrail: any routing primitive Leave needs that Workflow lacks is filed as a Workflow enhancement; Leave does not ship a private approver table.
- **Risk: leave-to-payroll double-counting.** Guardrail: each unpaid-leave or encashment `PayrollInput` row carries the leave request ID; Payroll inputs reject duplicates with the same source reference within the same period.
- **Risk: public-holiday tables go stale.** Guardrail: Malaysia pack ships holiday data with explicit year coverage; Leave UI surfaces "no published holidays for year X" rather than silently treating all days as workdays.
- **Risk: parity scope creep.** Guardrail: HR2000 features beyond the parity table (e.g. complex shift-aware leave, bidding, leave-trade marketplaces) stay out of v1 unless SBG validates day-one need.

## Phases

### Phase 0 — Boundary and contract lock

- [ ] Decide whether Malaysia leave statutory behaviour ships in `BelimbingApp/blb-payroll-my` (alongside payroll, sharing statutory data and employee profile snapshots) or in a sibling `BelimbingApp/blb-people-my` repo. Document the rationale.
- [ ] Define the `LeaveCountryPack` v0 contract in prose: statutory types, entitlement policies, public-holiday calendars, validation rules, explanation output, and reports metadata.
- [ ] Codify the no-leak rule: Leave Core depends on no Malaysia class, no `MY_*` constant, no Employment-Act column.
- [ ] Confirm Workflow module covers multi-tier-by-type approval routing, or file a Workflow gap before Phase 3 starts.

### Phase 1 — Leave Core skeleton

- [ ] Create the neutral leave type catalog (paid/unpaid, default unit, default approval depth, payroll-interacting flag).
- [ ] Create effective-dated entitlement policy storage with service-band rows, proration rules, and accrual frequency.
- [ ] Create the append-only balance ledger with entry types: opening, accrual, taken, cancelled, adjusted, carried-forward, expired, encashed.
- [ ] Create the request lifecycle (draft → submitted → approved/rejected/cancelled → applied → withdrawn) with half-day, multi-day, attachments, max-days-per-application, advance-leave, and overlap-detection rules.
- [ ] Capture audit history for each request transition and each ledger write.

### Phase 2 — Calendar and country-pack integration

- [ ] Wire Leave Core to consume `PeopleCalendarException` for company-specific non-working days.
- [ ] Add country-pack public-holiday resolution by pay-entity state and evaluation date.
- [ ] Implement holiday-substitution and rest-day handling per pack rules.
- [ ] Surface calendar-aware days-deducted in request preview before submission.

### Phase 3 — Approval routing and notifications

- [ ] Wire Leave request submission to the Workflow approval engine using per-type, per-employment-group, per-threshold routing.
- [ ] Emit standard notification events (submitted, approved, rejected, cancelled, expiry-approaching, low-balance) through `PeopleNotificationDeliveryLog`.
- [ ] Implement on-behalf application with explicit actor audit.

### Phase 4 — Malaysia leave country pack (first pack)

- [ ] Ship statutory leave types: annual, sick, hospitalization, maternity (98 days), paternity (7 days), gazetted public holidays.
- [ ] Ship statutory entitlement policies as Employment-Act minima with service-band tables.
- [ ] Ship federal and state public-holiday calendars for the current year, with import path for future years.
- [ ] Ship blocking validation rules for Act-floor violations, with explanation output.
- [ ] Decide initial pack home (`blb-payroll-my` or `blb-people-my`) per Phase 0 outcome and register it through the country-pack registry.

### Phase 5 — Replacement, carry-forward, encashment, and payroll handoff

- [ ] Implement replacement-leave earning when working a holiday and replacement-leave expiry job.
- [ ] Implement year-end carry-forward with cap, expiry window, and dry-run output.
- [ ] Implement leave-encashment generation as ledger entries plus matching `PayrollInput` rows.
- [ ] Generate `PayrollInput` rows for unpaid leave with leave-request back-reference; verify Malaysia pack classifies them correctly for EPF/SOCSO/EIS/PCB.
- [ ] Reconcile balance ledger against Payroll inputs as part of payroll lock/audit report.

### Phase 6 — Self-service and reports

- [ ] Employee self-service: apply leave, view balance, view team calendar, withdraw, upload attachments.
- [ ] Manager self-service: approve/reject queue, subordinate balances, overlap-risk view.
- [ ] Reports as `RenderPdfJob` outputs under `resources/core/views/pdf/leave/`: balance statement, leave history, year planner, team calendar, leave-utilisation summary, on-behalf audit.
- [ ] CSV exports for balance, history, and utilisation through the existing operational-CSV pattern.

### Phase 7 — Migration and SBG validation

- [ ] Define HR2000 e-Leave import contract: leave types mapping, opening balances per employee/type/year, historical request log (optional, scoped), pending requests in flight.
- [ ] Run dry-run import against SBG export with reconciliation report.
- [ ] Validate Malaysia pack against SBG's actual leave operation for one full year cycle (entitlement, accrual, year-end carry-forward, expiry, encashment, payroll handoff).
- [ ] Confirm which SBG-specific rules belong in `kiatng/blb-sbg` versus upstream in the Malaysia pack.

## Open Research Before Implementation

- Confirm whether Malaysia pack ships in `BelimbingApp/blb-payroll-my` or `blb-people-my`. Sharing statutory profile snapshots and effective-dated tooling argues for the same repo; cleaner module boundaries argue for a sibling repo.
- Confirm the current Workflow module can express multi-tier approval routing keyed by leave type, employment group, and days threshold; if not, scope a Workflow enhancement before Phase 3.
- Confirm SBG's actual leave types beyond the Employment Act statutory set (marriage, compassionate, examination, prolonged-illness, special-purpose) and their parameters before seeding the SBG private pack.
- Confirm SBG's year-end carry-forward and expiry rules (cap, window) and whether unused balance is ever encashed.
- Confirm whether Leave reporting needs a state-by-state public-holiday view in v1 or whether a single pay-entity state is sufficient for SBG.
- Confirm how Leave attachments interact with the Documents/Workflow attachment infrastructure to avoid a Leave-private upload surface.
- Confirm whether HR2000's "burn leave" maps to forced annual-leave consumption (e.g. shutdown days) or to expired carry-forward, since the BLB modelling differs.
