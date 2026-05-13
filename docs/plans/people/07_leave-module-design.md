# people/07_leave-module-design

**Status:** In Progress
**Last Updated:** 2026-05-12
**Sources:**
- `docs/plans/people/01_people-modules.md` — Leave is one of the planned People submodules; entitlement, balances, requests, approvals, carry-forward, and team calendar visibility called out at suite level
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Country-neutral core + country-pack architecture and effective-dated statutory data; unpaid leave already modelled as a neutral `PayrollInput` type that the country pack classifies; statutory profile resolver, run lifecycle, and audit patterns this plan should mirror
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 e-Leave feature inventory used as parity benchmark (entitlement policies, replacement leave with expiry, burn leave, cancel/withdraw, full/half-day, advance leave, max days per application, attachments, multi-tier approval, state holidays, employee/company leave calendars, year planner, e-mail notifications)
- `docs/plans/people/05_sbg-ipayroll-settings-gap-bridge.md` — People Settings foundation (work calendar/exceptions, reference data, employment groups, employee account access, profile change requests, notification delivery log) that Leave consumes rather than re-implements
- `docs/plans/people/06_ipayroll-employee-module-gap-bridge.md` — Employee workbench naming, payroll data readiness, work-profile dependencies that Leave entitlement and approval routing depend on
- `docs/plans/people/04_pdf-generation-strategy.md` — `App\Base\Pdf\Jobs\RenderPdfJob` is the queue-friendly entry point for any printable Leave document (year planner, balance statement, leave history report)
- `docs/plans/people/sbg_leave_ref/` — SBG's live HR2000 e-Leave configuration export: leave types, leave groups (FM/FW/MM/SINGLE), leave policies per type, leave entitlement bands, and balance/application snapshots. Primary parity source for Phase 7 and for shaping the policy/entitlement schema.
- `app/Modules/People/Settings/Models/PeopleCalendarException.php` — existing work-calendar exception model Leave should consume
- `app/Modules/People/Payroll/Models/PayrollInput.php` and `PayrollPayItem.php` — neutral pay-input contract through which approved unpaid leave and leave-encashment payouts feed payroll
- `app/Modules/Core/Employee/` — canonical employee identity, supervisor/reporting line, employment dates that drive entitlement accrual and approval routing
- Malaysia Employment Act 1955 (as amended Act A1651, in force 2023-01-01) — statutory minima for annual leave, sick leave, hospitalization leave, maternity leave (98 days), paternity leave (7 days), and gazetted public holidays
- `docs/architecture/file-structure.md` — module placement; `docs/plans/AGENTS.md` — plan conventions
**Agents:** amp/claude-haiku-4.5, copilot/gpt-5.4

## Problem Essence

BLB's People suite currently has no Leave capability. Without it, the Payroll module cannot honestly compute unpaid-leave deductions, leave-encashment payouts, or replacement-leave value; the Self-Service module has nothing to apply for; and SBG cannot retire HR2000 e-Leave. Leave also overlaps statutory regulation: Malaysia's Employment Act fixes minimum entitlements (annual, sick, hospitalization, maternity, paternity), defines public holidays, and constrains how leave can be denied, paid out, or carried forward. Building Leave as a Malaysia-specific feature would repeat the architectural mistake Payroll has already corrected; building it as pure free-form policy would leak compliance risk back to the licensee.

## Desired Outcome

A Leave module under `app/Modules/People/Leave/` that gives a small-to-mid-size Malaysian employer a credible end-to-end leave operation — entitlement configuration, accrual and balances, employee/manager request and approval workflow, calendar visibility, attachments, cancellations, replacement and carry-forward rules, and clean handoff to Payroll for unpaid leave and any encashment — while preserving the same architectural shape Payroll established: a country-neutral Leave Core in `belimbingapp/belimbing`, Malaysia statutory leave behaviour in `BelimbingApp/blb-payroll-my` (or a sibling `blb-people-my` if the boundary proves separate), and SBG-specific leave policies and templates in `kiatng/blb-sbg`. Done means HR can run a normal monthly leave cycle for SBG, the figures match HR2000 within the parity scope, and no Malaysia-specific column or class lives in Leave Core.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Leave type catalog | Neutral identity for each leave (e.g. annual, sick, hospitalization, maternity, paternity, marriage, compassionate, unpaid, replacement, special) with paid/unpaid, gender/eligibility hints, default unit (days/half-days/hours), default approval depth, and whether it interacts with Payroll. Country pack owns statutory semantics; SBG/private packs add company-specific types. | Leave Core; statutory hints from country pack |
| Entitlement policy | Effective-dated rules that, given an employee work profile and a year, produce a target entitlement: years-of-service bands, accrual frequency (annual lump, monthly accrual, anniversary, earned-until-month-N), prorate for joiners/leavers, eligibility window, rounding rule, bring-forward (cap, expiry month, anchor), and country-pack overrides for statutory minima. | Leave Core schema; Malaysia pack provides Employment Act minima as seed policies |
| Leave assignment (profile) | Named bundle of `(leave_type, entitlement_policy, request_policy)` rows assigned to an employee cohort. Determines which leaves an employee can apply for, under which entitlement table, under which request policy. Cohorts are demographic (gender, marital status, citizenship) and/or employment (group, grade, location). Mirrors HR2000's "Leave Group" concept (e.g. SBG's FM/FW/MM/SINGLE) without copying its name. | Leave Core |
| Balance ledger | Append-only entries that produce current balance per employee per leave type per year: opening, accrual, taken, cancelled, adjusted, carried-forward, expired, encashed. Each entry references its source (policy run, request, adjustment, carry-forward job). | Leave Core |
| Request lifecycle | Draft → submitted → approved/rejected/cancelled → applied → optionally withdrawn-after-approval. Half/full day, multi-day spans, attachments, max-days-per-application validation, advance-leave gating, overlap detection, calendar exception awareness. | Leave Core |
| Approval routing | Multi-tier approval driven by employee supervisor chain, employment group, leave type, and policy. Reuses or wraps the existing Workflow module rather than inventing a parallel engine. | Leave Core consuming Workflow |
| Work calendar integration | Resolve working days, public holidays (gazetted federal + state-specific), and calendar exceptions for entitlement proration, days-deducted calculation, and overlap checks. Consumes `PeopleCalendarException` and a country-pack public-holiday source. | People Settings + country pack |
| Replacement & carry-forward | Replacement leave earned for working a holiday with expiry; year-end carry-forward with cap and expiry; optional burn-leave conversion; encashment hooks for unused balances. | Leave Core; SBG cap/expiry numbers in private pack |
| Payroll handoff | Unpaid leave days and any encashment lines surface as neutral payroll contributions via Payroll's `PayrollContributionIntake` contract; the Malaysia country pack classifies the resulting pay items for EPF/SOCSO/EIS/PCB treatment. **Direction inverted (Phase 5 of plan 10):** `LeavePayrollHandoffService` (`source_type='leave_request'`) and `LeaveEncashmentService` (`source_type='leave_encashment'`) call intake. Leave no longer imports `PayrollInput`/`PayrollRun`/`PayrollRunParticipant`. Pending state (no open run) is durable in Payroll's `people_payroll_pending_contributions`. | Leave emits via Payroll intake; Payroll/country pack classifies |
| Audience-scoped surfaces | Same Leave module exposes admin (`people.leave.manage`), approver (`people.leave.approve`), and employee (`people.leave.view`, scoped to `auth()->user()->employee_id`) screens in one Livewire workbench. Self-service is an authorization scope, not a separate module. | Leave module |
| Notifications | Submitted, approved, rejected, cancelled, balance-low, expiry-approaching, year-planner published. Routes through `PeopleNotificationDeliveryLog` rather than a Leave-private channel. | Leave Core emits; People Settings persists log |
| Reports & documents | Balance statement, leave history, year planner (per employee, team, company), team calendar PDF, statutory leave-utilisation summary, leave-on-behalf audit. PDFs go through `RenderPdfJob`. | Leave Core data builders + Blade templates under `resources/core/views/pdf/leave/` |

## Design Decisions

**Mirror the Payroll country-pack contract instead of inventing a new one.** The Payroll Country Pack v0 surface (`PayrollCountryPack`, `ProvidesPayrollProfileSchemas`, `ClassifiesPayrollPayItems`, statutory rule resolver, effective-dated rule rows) is the proven shape. Leave Core should expose an analogous `LeaveCountryPack` contract with: country/pack identity and version, statutory leave-type definitions, statutory entitlement policies (minimum days by service band, eligibility, proration), public-holiday calendars (federal + state), Employment-Act validation rules (e.g. maternity 98 days, paternity 7 days, hospitalization aggregate cap), and explanation output. Malaysia statutory behaviour plugs in through this contract; Leave Core never imports a Malaysia class. Whether the Malaysia leave pack ships in `BelimbingApp/blb-payroll-my` (alongside the payroll calculators because they share statutory data and employee profiles) or in a sibling `blb-people-my` repo is a Phase 0 decision; the contract is the same either way.

**Statutory minima are a floor, employer policy is the configured value.** The Malaysia pack ships seed entitlement policies that match the Employment Act minima for each service band. Employers (HR admins) can configure higher entitlements but never below the statutory floor for the leave types the Act covers. The Core records whether a configured policy currently meets the active country-pack floor and surfaces violations as blocking validation, not as silent overrides — the same posture Payroll takes for statutory rule rows.

**Effective-dated entitlement and policy data, not "current" values.** Service-band tables, statutory minima, public-holiday lists, and replacement/carry-forward rules change over time (Act amendments, new gazetted holidays). Every entitlement calculation snapshots the resolved policy version so a 2026 balance statement remains explainable in 2028. Same invariant Payroll already enforces.

**Balance is a ledger, not a counter.** Storing only `days_remaining` makes corrections destructive and audit-hostile. Append-only ledger entries (opening, accrual, taken, cancelled, adjusted, carried-forward, expired, encashed) produce the balance by aggregation. Cancelling an approved leave creates a reversing entry; it does not mutate the original. This matches the immutable-result-line discipline Payroll uses for closed runs.

**Reuse the Workflow module for approval routing.** A separate Leave-specific approval engine would diverge from Claims, Overtime, and Profile Change Requests. Leave defines the routing intent (per-type approval depth, by employment group, by amount-of-days threshold) and delegates execution to Workflow. If the existing Workflow module cannot express multi-tier-by-type routing yet, that gap is a Workflow follow-up, not a reason to fork.

**Public holidays are country-pack data with state overlays, not a generic dictionary.** Malaysia has federal gazetted holidays plus state-specific holidays, and PERKESO/EPF do not own this. The Malaysia pack ships effective-dated public-holiday tables keyed by (year, federal/state). Pay-entity work-state determines the applicable overlay; employee-level overrides exist only when legally justified (e.g. employee assigned to a project in another state). Employer-specific company holidays use the existing `PeopleCalendarException` mechanism, not the statutory table.

**Unpaid leave and encashment go through Payroll's intake contract.** Payroll already declares unpaid leave as one of its neutral pay-input types and the Malaysia pack already classifies pay items for EPF/SOCSO/EIS/PCB. Leave submits `PayrollContributionPayload`s through `PayrollContributionIntake::ingest` with the right neutral pay-item code (`unpaid_leave`, `leave_encashment`, `replacement_leave_payout`) and a back-reference to the leave request; Payroll materialises a `PayrollInput` row or holds it pending until a covering run opens. See `docs/architecture/payroll-intake.md`.

**No leave-on-behalf without an audit trail.** HR2000 supports applying leave on behalf of employees. BLB allows this only through an explicit on-behalf flow that records the actor, the employee, the reason, and the employee notification. The same audit shape `EmployeeProfileChangeRequest` already uses.

**Half-day and hourly are first-class units, not workarounds.** Some SBG leave types are half-day (e.g. medical appointment) and SBG already runs an hourly leave type (`T/S` time-slip, 2-hour blocks) in production today. Hourly is therefore a v1 requirement, not future-proofing. Leave Core stores requested duration as a typed unit (full-day, half-day-AM, half-day-PM, hours) with a configurable hour quantum per policy, and converts to days against the resolved work calendar for balance deduction. This avoids the "0.5 day hack" pattern.

**Demographic eligibility is data, not code.** SBG's HR2000 groups (FM = female married, FW = foreign worker, MM = male married, SINGLE) show that leave eligibility is driven by gender, marital status, and citizenship/foreign-worker status, not only by employment group. The work-profile snapshot the country pack consumes therefore declares these as named, optional, sensitive fields; the country pack defines which leave types predicate on which fields (e.g. maternity → female; paternity → male married; AL-FW band → foreign-worker). Leave Core never branches on these values directly.

**Unpaid leave and unauthorized absence are distinct neutral codes.** HR2000 separates `UPL` (employee-requested unpaid leave) from `ABS` (absent without leave, disciplinary). Both classify to the unpaid-leave PayrollInput type but carry different audit posture and approval semantics (ABS is typically recorded by HR, not applied for). Leave Core exposes both as neutral codes (`unpaid_leave`, `unauthorized_absence`) and the country pack maps them to the same payroll classification.

**No HR2000 sentinel values in BLB schema.** HR2000 uses `99.00` for "no upper bound" on service-band tables and `99` for "no max per application". BLB uses `NULL = unlimited` semantics on the relevant columns; importers translate `99`/`99.00` to `NULL` on ingest. The plan does not propagate sentinels into Core tables.

**Leave-Module ownership of medical certificate / hospitalization evidence is shallow.** Attachments are stored against the request (using whatever attachment infrastructure the Workflow/Documents layer provides). Leave Core does not OCR, validate, or interpret medical documents in v1 — it records that an attachment exists, its type tag, and who uploaded it.

**Do not build a leave-policy DSL in v1.** Same reasoning Payroll used. Typed PHP policies (annual-by-service-band, accrual-monthly-prorated, fixed-annual, eligibility-after-confirmation, parental-eligibility) plus effective-dated parameter rows cover the SBG/Malaysia surface. Configurable expression rules can come later if multiple country packs demand them.

## Public Contract

**Leave Core promises country packs a normalized leave context per request or per entitlement run:**
- pay-entity, country/state, currency, leave year, evaluation date;
- employee identity and effective-dated work profile snapshot: hire date, confirmation date, employment group, work calendar, pay basis, plus the demographic fields the country pack declares as eligibility-relevant (currently: gender, marital status, citizenship/foreign-worker status);
- the employee's active leave assignment (which `(leave_type, entitlement_policy, request_policy)` triples apply);
- prior leave ledger entries needed for accrual, carry-forward, expiry, and cumulative cap calculations;
- pending and approved request history within the relevant period, projected as both `consumed_balance` (approved/applied) and `encumbered_balance` (consumed + pending) views;
- a write API that records ledger entries, attaches policy version IDs, and refuses to mutate frozen periods.

**Leave Core's request-policy schema includes (each effective-dated, all optional with documented defaults):**
- `earned_calculation_method`: `annual_lump_no_prorate` | `monthly_accrual` | `earned_until_month_N` | `anniversary`;
- `entitlement_rounding`: `none` | `nearest_1_day` | `nearest_half_day`;
- `bring_forward`: `{cap_days, expiry_month, anchor: year_start | anniversary}`;
- `allow_negative_balance`: bool (approver-overridable balance shortfall);
- `include_pending_as_taken`: bool (drives encumbered-balance projection);
- `allow_multiple_applications_per_day`: bool (needed for hourly/time-slip);
- `no_cross_month_split`: bool (forces month-boundary split, relevant for monthly payroll);
- `compulsory_attachment`: bool (mandatory evidence, e.g. MC/HL/MTL);
- `daytype_exclusions`: `{holiday, off_day, rest_day}` independent booleans for days-deducted calculation;
- `day_of_week_unit_overrides`: map of `day_of_week → full_day | half_day` (e.g. Saturday = half for AL);
- `advance_notice`: `{standard_days, short_notice: {allowed, tag, annual_cap, disallow_today}}`;
- `back_date`: `{allowed, max_days, tag, daytype_exclusions}`;
- `max_days_per_application`: nullable int (`NULL` = unlimited);
- `replacement_expiry`: `{rule: earn_date_plus_days | year_end | leave_end_plus_days, value}` for replacement-leave types only.

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

## Naming Judgement

The HR2000/iPayroll exports are evidence, not vocabulary. BLB should use names that state the business object or workflow clearly, while keeping HR2000 labels only as `source_label`, aliases, or migration notes.

| HR2000 / iPayroll label | BLB name | Judgement |
|-------------------------|----------|-----------|
| Leave Group | Leave Assignment | The entity binds an employee cohort to a curated set of `(type, entitlement, policy)` triples. "Group" conflates with org units and employment groups; "Assignment" names the employee-facing job. |
| Leave Type | Leave Type | Honest. Keep, but use neutral codes (`annual`, `sick`, `hospitalization`, `maternity`, `paternity`, `marriage`, `compassionate`, `exam`, `unpaid_leave`, `unauthorized_absence`, `replacement_leave`, `time_slip`) rather than HR2000 acronyms. |
| Leave Entitlement | Entitlement Policy | Names the rule, not the resulting balance. |
| Leave Policy | Request Policy | Disambiguates the apply/approval/day-counting rule set from the entitlement schedule. |
| Detect As Time | Hourly Leave Unit | The boolean's product meaning is "this type is measured in hours, not days." |
| Detect As NPL | Payroll Unpaid Handoff | The flag's effect is "produce an unpaid `PayrollInput` row." Name the effect, not the storage. |
| Detect As Prorate Allowance | Reduces Prorate Allowance | The flag tells payroll to reduce a configured prorate allowance for days on this leave; it is a payroll classification, not a leave-side concept. |
| Replacement Leave? | Earns Replacement Balance | The flag means "approving this earns a replacement-leave entry," distinct from being itself replacement leave. |
| [R-Leave] Admin Create Only? | Admin-Only Application | Names the access rule. |
| Paid Day (Daily Rated Only) | Counts As Paid Day (Daily Rated) | Disambiguate from the paid/unpaid disposition of the leave type itself. |
| Include Leave Daily Worked Day | Counts Toward Working Day Cap | Names the calendar effect rather than the implementation. |
| B/F Bal Always Zero | No Carry-Forward | Honest, action-oriented. |
| Use Alternative Work-Flow | Custom Approval Workflow | The flag selects a non-default approval route for the type. |
| B/Forward Bal Burn After | Carry-Forward Expiry Month | States the actual rule. |
| Replacement Expiry Days / Replacement Expiry Date Check | Replacement Expiry Rule | Single typed parameter (`earn_date_plus_days` / `year_end` / `leave_end_plus_days`). |
| Earned Calculation Method | Accrual Method | Standard payroll/HR vocabulary. |
| Allow to apply leave after | Eligibility Start | "Immediately" / "After Confirmation" become typed values. |
| Include Pending As Taken | Encumber Pending Balance | States the effect on balance projection. |
| Split Application For Following Year | Allow Year-Boundary Split | Honest. |
| No Cross Month Application | No Month-Boundary Split | Honest, complements the year-boundary policy. |
| Day Of Week Detect As Full | Day-of-Week Unit Override | Names the per-day-of-week unit map. |
| Detect As Emergency? / Tag = EMERGENCY LEAVE | Short-Notice Tag | The HR2000 emergency-tag mechanism is one application of generic short-notice tagging. |
| Detect As Emergency? / Tag = LATE SUBMISSION | Back-Date Tag | Same generic tagging for back-dated submissions. |
| Year Planner | Year Planner | Honest, keep. |
| Employee Leave Balance | Leave Balance Statement | The screen is a statement, not a master record. |

## Risks and Guardrails

- **Risk: statutory minima silently violated by configured policies.** Guardrail: Leave Core compares configured policies against country-pack floors at save time and at every entitlement run; mismatches block, not warn.
- **Risk: balance drift from imported HR2000 data.** Guardrail: import path lays opening-balance ledger entries with `source: 'migration'` and a per-employee reconciliation report; no destructive overwrite of computed balances.
- **Risk: replacement-leave/expiry surprises year-end.** Guardrail: expiry runs are scheduled jobs with dry-run output, employee notifications before expiry, and explicit ledger entries — never silent zero-out.
- **Risk: country leakage into Leave Core.** Guardrail: same enforcement Payroll uses — no Malaysia table names, no Employment-Act column flags, no `MY_*` constants in Core.
- **Risk: approval routing forks from Workflow.** Guardrail: any routing primitive Leave needs that Workflow lacks is filed as a Workflow enhancement; Leave does not ship a private approver table.
- **Risk: leave-to-payroll double-counting.** Resolved by `docs/plans/people/10_payroll-intake-dependency-inversion.md` Phases 1–5: both Leave producers (`LeavePayrollHandoffService`, `LeaveEncashmentService`) now write through `PayrollContributionIntake`, which enforces a DB-level composite unique index `(source_type, source_id, pay_item_code, period_anchor)` on `people_payroll_pending_contributions`.
- **Risk: public-holiday tables go stale.** Guardrail: Malaysia pack ships holiday data with explicit year coverage; Leave UI surfaces "no published holidays for year X" rather than silently treating all days as workdays.
- **Risk: parity scope creep.** Guardrail: HR2000 features beyond the parity table (e.g. complex shift-aware leave, bidding, leave-trade marketplaces) stay out of v1 unless SBG validates day-one need.
- **Risk: HR2000 sentinel values (99-year service band, 99-day max per application) leak into BLB schema.** Guardrail: importers translate `99`/`99.00` to `NULL` (unlimited); Core columns use nullable upper bounds; no magic numbers.
- **Risk: demographic eligibility leakage.** Guardrail: gender, marital status, and citizenship fields are declared by the country pack and stored on the work-profile snapshot; Leave Core never branches on them and never logs them outside the snapshot they belong to.

## Phases

### Phase 0 — Boundary and contract lock

- [ ] Decide whether Malaysia leave statutory behaviour ships in `BelimbingApp/blb-payroll-my` (alongside payroll, sharing statutory data and employee profile snapshots) or in a sibling `BelimbingApp/blb-people-my` repo. Document the rationale.
- [x] Define the `LeaveCountryPack` v0 contract in prose: statutory types, entitlement policies, public-holiday calendars, validation rules, explanation output, and reports metadata. {copilot/gpt-5.4}
- [x] Codify the no-leak rule: Leave Core depends on no Malaysia class, no `MY_*` constant, no Employment-Act column. {copilot/gpt-5.4}
- [ ] Confirm Workflow module covers multi-tier-by-type approval routing, or file a Workflow gap before Phase 3 starts. The current `NullLeaveApprovalRouter` keeps the gap explicit and non-silent.

### Phase 1 — Leave Core skeleton

- [x] Create the neutral leave type catalog (paid/unpaid, default unit including `hours` with quantum, default approval depth, payroll-interacting flag, compulsory-attachment flag). Seed distinct codes for `unpaid_leave` and `unauthorized_absence`. {copilot/gpt-5.4}
- [x] Create effective-dated entitlement policy storage with service-band rows (nullable upper bound), proration rules, accrual frequency (`annual_lump_no_prorate` | `monthly_accrual` | `earned_until_month_N` | `anniversary`), rounding, and bring-forward parameters (`cap_days`, `expiry_month`, `anchor`). {copilot/gpt-5.4}
- [x] Create effective-dated request-policy storage covering the schema enumerated in the Public Contract (negative-balance, pending-as-taken, multi-application-per-day, no-cross-month, daytype exclusions, day-of-week unit overrides, advance-notice with short-notice/emergency sub-policy, back-date with sub-policy, max-days-per-application nullable). {copilot/gpt-5.4}
- [x] Create the `LeaveAssignment` entity binding employee cohort → `(leave_type, entitlement_policy, request_policy)` triples; cohort predicate accepts demographic fields declared by the country pack. {copilot/gpt-5.4}
- [x] Create the append-only balance ledger with entry types: opening, accrual, taken, cancelled, adjusted, carried-forward, expired, encashed. Expose both `consumed_balance` and `encumbered_balance` projections. {copilot/gpt-5.4}
- [x] Create the request lifecycle (draft → submitted → approved/rejected/cancelled → applied → withdrawn) with half-day, hourly, multi-day, attachments, max-days-per-application, advance-leave, back-date, and overlap-detection rules. {copilot/gpt-5.4}
- [ ] Capture audit history for each request transition and each ledger write. Request transitions are audited; ledger writes remain self-auditing facts rather than a separate audit stream.

### Phase 2 — Calendar and country-pack integration

- [x] Wire Leave Core to consume `PeopleCalendarException` for company-specific non-working days. {copilot/gpt-5.4}
- [x] Add country-pack public-holiday resolution by pay-entity state and evaluation date. {copilot/gpt-5.4}
- [ ] Implement holiday-substitution and rest-day handling per pack rules. Sunday substitution now ships for the first KL/Selangor slice; broader rest-day/weekend variants remain open.
- [x] Surface calendar-aware days-deducted in request preview before submission. {copilot/gpt-5.4}

### Phase 3 — Approval routing and notifications

- [ ] Wire Leave request submission to the Workflow approval engine using per-type, per-employment-group, per-threshold routing. Submission currently records approval intent through `NullLeaveApprovalRouter`; execution is still not delegated to Workflow.
- [ ] Emit standard notification events (submitted, approved, rejected, cancelled, expiry-approaching, low-balance) through `PeopleNotificationDeliveryLog`. Core request lifecycle events now include submit/approve/apply/reject/cancel/withdraw; low-balance and expiry-approaching still need emitters.
- [x] Implement on-behalf application with explicit actor audit. {copilot/gpt-5.4}

### Phase 4 — Malaysia leave country pack (first pack)

- [x] Ship statutory leave types only: annual, sick, hospitalization, maternity (98 days), paternity (7 days), gazetted public holidays. Non-statutory employer leave (marriage, compassionate, exam, time-slip, replacement variants) ships in the SBG private pack in Phase 7, not here. {copilot/gpt-5.4}
- [x] Ship statutory entitlement policies as Employment-Act minima with service-band tables. {copilot/gpt-5.4}
- [ ] Ship federal and state public-holiday calendars for the current year, with import path for future years. Federal 2026 coverage plus the first KL/Selangor state overlays now exist; full state coverage and future-year import paths remain open.
- [x] Ship blocking validation rules for Act-floor violations, with explanation output. {copilot/gpt-5.4}
- [x] Declare which work-profile demographic fields the pack consumes (gender for maternity, marital status where required, citizenship/foreign-worker for AL-FW band). {copilot/gpt-5.4}
- [ ] Decide initial pack home (`blb-payroll-my` or `blb-people-my`) per Phase 0 outcome and register it through the country-pack registry.

### Phase 5 — Replacement, carry-forward, encashment, and payroll handoff

- [ ] Implement replacement-leave earning when working a holiday and replacement-leave expiry job. Support per-type expiry rule (`earn_date + N_days` is the SBG default at 365 days from leave-end-date; alternates: `year_end`, `leave_end_plus_days`). Expiry sweep exists; earning logic is still open.
- [x] Implement year-end carry-forward driven by `bring_forward.{cap_days, expiry_month, anchor}` policy fields (e.g. SBG AL-LOCAL: cap 7, expiry March), with dry-run output and explicit ledger expiry entries. {copilot/gpt-5.4}
- [x] Implement leave-encashment generation as ledger entries plus a Payroll intake submission (`LeaveEncashmentService::SOURCE_TYPE = 'leave_encashment'`). When no open run covers `today`, intake records a pending contribution; the materialiser picks it up when a run opens. {copilot/gpt-5.4,amp/claude-opus-4-7}
- [ ] Submit payroll contributions for unpaid leave (both `unpaid_leave` and `unauthorized_absence` codes) via `LeavePayrollHandoffService` (`SOURCE_TYPE = 'leave_request'`) with leave-request back-reference; verify Malaysia pack classifies them correctly for EPF/SOCSO/EIS/PCB. Unpaid-leave handoff exists; distinct unauthorized-absence classification still needs explicit payroll verification.
- [x] Honour `no_cross_month_split` policy when requests straddle a month boundary so payroll-period attribution is unambiguous. {copilot/gpt-5.4}
- [ ] Reconcile balance ledger against Payroll inputs as part of payroll lock/audit report.

### Phase 6 — Employee/manager surfaces and reports

**Note:** BLB has no separate Self-Service module. Each People module exposes admin, approver, and employee screens in the same Livewire workbench, gated by capability (`people.leave.manage` / `.approve` / `.view`) and scoped to `auth()->user()->employee_id` for employee views. "Self-service" here means audience-scoped tabs in `people.leave.index`, not a parallel UI module.


- [ ] Employee tabs in `people.leave.index` (scoped to `auth()->user()->employee_id`): apply leave, my balance, my history, withdraw, upload attachments.
- [ ] Manager tabs in the same view (gated by `people.leave.approve`): approve/reject queue, subordinate balances, overlap-risk view.
- [ ] Reports as `RenderPdfJob` outputs under `resources/core/views/pdf/leave/`: balance statement, leave history, year planner, team calendar, leave-utilisation summary, on-behalf audit.
- [ ] CSV exports for balance, history, and utilisation through the existing operational-CSV pattern.
- [ ] Leave Application List export (HR2000-parity column set: Reference No, Applicant, Leave Type, From Date, To Date, Days, Applied On, Status, Approver, Approved On, Attachment, Remarks) in CSV and PDF.
- [ ] Leave Balance Statement column set matches HR2000 parity (Last B/F, Earned, Leave Burn, Adjustment, Taken, Avail Bal, Future Taken, Next Year C/F, Year Ent.) — projected from the ledger, not stored. A basic statement builder exists but does not yet reach parity shape.

### Phase 7 — Migration and SBG validation

- [ ] Define HR2000 e-Leave import contract: leave types mapping, opening balances per employee/type/year, historical request log (optional, scoped), pending requests in flight. Translate HR2000 sentinels (`99`/`99.00`) to `NULL` on ingest.
- [x] Seed the SBG private pack (`kiatng/blb-sbg`) with the non-statutory SBG types observed in the reference export: marriage (MRL), compassionate (CL), exam (EXAM), time-slip (T/S, hourly), replacement variants (RL and RPL), and SBG's higher-than-floor AL service bands (AL-LOCAL 12/14/16/21) plus the FW band (AL-FW 8/12/16). {copilot/gpt-5.4}
- [x] Seed the SBG `LeaveAssignment` cohorts equivalent to HR2000 groups (FM / FW / MM / SINGLE) using demographic predicates (gender, marital status, citizenship). {copilot/gpt-5.4}
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
- Confirm SBG's reading of HR2000's `Leave Burn` balance column: is it (a) carry-forward expired by `B/Forward Bal Burn After`, (b) admin-initiated burn-down (e.g. forced shutdown days), or (c) both surfaced in one column? BLB models these as distinct ledger entry types (`expired`, `adjusted` with reason tag) and projects them together only at the Balance Statement layer.
- Confirm SBG's expected behaviour for `Detect As Prorate Allowance` on a leave type: which payroll allowances are reduced, by what factor, and whether reduction logic lives in the Malaysia payroll pack or in a Leave→Payroll adapter.
- Confirm semantic difference between SBG's two replacement-leave types `RL` and `RPL` (the latter flags "daily-use alternative workflow" in the HR2000 export) — single neutral `replacement_leave` with a policy switch, or two distinct neutral codes?
- Confirm whether SBG needs `unauthorized_absence` (HR2000 `ABS`) as a distinct neutral code or can collapse it onto `unpaid_leave` with an audit tag.
- Confirm whether SBG's hourly `T/S` time-slip leave must ship at go-live with full hourly support, or can launch as half-day approximation in v1.
- Confirm SBG's preferred `earned_calculation_method` per leave type (the HR2000 export shows "Full 12 Month And Prorate Not Required" for most, "Leave Earned Until December" for AL) and whether the latter is a true monthly accrual or a year-start lump with mid-year proration cap.
