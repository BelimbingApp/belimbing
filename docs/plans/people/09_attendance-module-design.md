# people/09_attendance-module-design

**Status:** Identified
**Last Updated:** 2026-05-13
**Sources:**
- `docs/plans/people/01_people-modules.md` - Attendance is a planned People module for daily attendance, clock-in/out, overtime calculation, shift patterns, conditional allowances, payroll feed, and future mobile/geofenced attendance.
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` - Payroll expects attendance/overtime inputs as neutral `PayrollInput` rows and keeps rotating shifts, GPS/geofence, and device binding outside payroll v1 unless SBG confirms day-one need.
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` - HR2000 parity benchmark calls out Time Management, complex 24-hour rotating shifts, unlimited shift patterns, conditional allowances, overtime application/approval, attendance exports, mobile clock-in/out, and geofencing.
- `docs/plans/people/05_sbg-ipayroll-settings-gap-bridge.md` - People Settings owns shared work calendars, calendar exceptions, employee work-profile references, notification delivery log, and import scaffolding that Attendance should consume rather than duplicate.
- `docs/plans/people/06_ipayroll-employee-module-gap-bridge.md` - Employee work-profile readiness, organization placement, supervisor, cost center, grade, workforce class, and calendar fields that Attendance scheduling, approval, and payroll costing depend on.
- `docs/plans/people/07_leave-module-design.md` - Leave calendar, workflow, notification, employee/manager surface, and payroll-handoff patterns that Attendance should mirror where domains overlap.
- `docs/plans/people/08_claim-module-design.md` - Claim lifecycle, Workflow routing, line-level payroll handoff, and audit guardrails that Attendance overtime requests should mirror.
- `docs/plans/people/sbg_attendance_ref/` - SBG HR2000 Time Management screenshots: TMS Group setup, punch windows, daily/monthly rounding, daily-rated workday flags, break/lateness options, overtime adjustment/export, conditional allowances, absenteeism batch entry, time clock audit, geofence, and geogroup setup.
- `app/Modules/People/Settings/Models/PeopleCalendarException.php` - existing work-calendar exception model Attendance consumes for non-working days, company holidays, and special workdays.
- `app/Modules/People/Settings/Models/EmployeeWorkProfile.php` - effective employee work profile used to resolve calendar, cost center, supervisor, pay basis, and workforce class.
- `app/Modules/People/Payroll/Models/PayrollInput.php` and `PayrollPayItem.php` - neutral payroll-input surface for overtime earnings, attendance allowances, lateness deductions, unpaid absence deductions, and one-off time adjustments.
- `docs/architecture/database.md` - reserves migration prefix `0320_01_15_*` for People Attendance with dependencies on Company, Employee, User, Settings, Payroll, and Workflow.
- `docs/architecture/file-structure.md` - module placement and singular PascalCase module directory convention.
- `docs/plans/AGENTS.md` - plan conventions.
**Agents:** codex/gpt-5

## Problem Essence

BLB does not yet have an Attendance module. Payroll can accept neutral overtime and adjustment inputs, but there is no governed source of truth for schedules, clock events, attendance exceptions, overtime approval, lateness/early-out rules, or conditional allowances. Without this module, payroll-adjacent attendance data remains manual and SBG cannot reach HR2000 Time Management parity.

## Desired Outcome

An Attendance module under `app/Modules/People/Attendance/` that records and explains daily attendance from schedules, clock events, manual adjustments, and approved exceptions; supports shift and roster patterns including 24-hour and rotating shifts; routes overtime through Workflow; produces neutral Payroll inputs for approved overtime, allowances, lateness/absence deductions, and attendance adjustments; and exposes employee, supervisor, HR, and payroll surfaces through the People workbench. Core Attendance should remain country-neutral. Malaysia statutory or wage-classification behavior belongs in the Malaysia payroll country pack, while SBG-specific shift rules, allowance formulas, import mappings, and device/geofence policy belong in `kiatng/blb-sbg`.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Shift catalog | Defines shift templates: planned start/end, break windows, paid/unpaid break treatment, grace minutes, cross-midnight behavior, workday length, overtime thresholds, and day-type eligibility. | Attendance Core; private packs seed company shifts |
| Roster pattern | Assigns shift sequences to employees or cohorts over a date range: fixed weekly, rotating cycle, ad hoc roster, rest-day/off-day/public-holiday overrides, and effective-dated changes. | Attendance Core consuming People Settings |
| Attendance day | The resolved daily fact for one employee/date: expected shift, clock summary, attendance state, payable hours, late/early/absent minutes, overtime candidate, exceptions, and payroll export state. | Attendance Core |
| Clock event stream | Append-only raw events from web/PWA entry, import files, future device APIs, or admin entry. Stores event time, source, actor/device metadata, location/geofence evidence when enabled, and correction lineage. | Attendance Core; device integrations/private packs |
| Timesheet adjustment | Manual correction workflow for missing punch, wrong punch, work-from-home, business travel, training, approved absence, clock-source dispute, and payroll-period correction. | Attendance Core consuming Workflow |
| Overtime request | Employee/on-behalf/supervisor overtime request and approval lifecycle with pre-approved and post-work modes, attachments/remarks, cancel/withdraw, payroll handoff, and duplicate protection. | Attendance Core consuming Workflow |
| Rule policy | Effective-dated attendance rules for rounding, grace, lateness, early-out, absent-without-leave, payable break treatment, overtime eligibility, rest-day/public-holiday work, and conditional allowances. | Attendance Core schema; country/private packs seed defaults |
| Attendance policy group | Cohort-assigned bundle of shift templates, work-hour rules, lateness rules, overtime rules, overtime export mappings, lateness export mappings, conditional allowance rules, and payroll-facing defaults. Mirrors HR2000 TMS Group without copying its storage shape. | Attendance Core; SBG private pack imports setup |
| Attendance allowance rule | Typed conditional allowance definition with payroll pay-item code, active state, ceiling, min/max resolution, daily/monthly condition rows, typed predicates, and source-script preservation for import/audit. | Attendance Core; private packs seed formulas |
| Payroll handoff | Converts approved/finalized attendance facts into neutral `PayrollInput` rows for overtime, attendance allowance, shift allowance, lateness deduction, unpaid absence, rest-day work, and one-off time adjustments. | Attendance Core writes inputs; Payroll/country pack classifies |
| Calendar integration | Resolves scheduled workdays, rest days, off days, public holidays, and company exceptions from People Settings and country-pack holiday data. | People Settings + country pack |
| Clock-source and geofence policy | Optional policy layer for allowed clock sources, card/device identity, outlet/location labels, photo evidence, IP/location/geofence checks, geofence groups, reminder rules, and evidence retention. Not required for first payroll-adjacent slice unless SBG confirms day-one need. | Attendance Core contract; private/device integrations |
| Absenteeism batch workflow | HR/payroll workflow to review generated absence candidates, group/filter them, batch-create absence facts, and respect an attendance/absenteeism lock date before payroll handoff. | Attendance Core consuming Leave/Payroll state |
| Audience-scoped surfaces | Employee clock/timesheet/overtime tabs, supervisor approval and exceptions queue, HR roster/rules/admin correction screens, and payroll reconciliation screens in one Attendance module. | Attendance module |
| Notifications | Missing punch, late arrival, overtime submitted/approved/rejected/cancelled, roster published/changed, exception requested/approved, geofence/device violation, and payroll-export completion through `PeopleNotificationDeliveryLog`. | Attendance Core emits; People Settings persists log |
| Reports & documents | Attendance summary, late/early/absence exceptions, overtime list, roster, payroll handoff reconciliation, clock-source audit, and monthly attendance statement. PDFs use `RenderPdfJob`; CSV exports follow People/Payroll export patterns. | Attendance Core builders and report views |

## Design Decisions

**Use singular `Attendance` for the module and keep "Time Management" as benchmark language only.** The module path should be `app/Modules/People/Attendance/`, matching the database registry and BLB's singular capability convention. HR2000 labels such as e-TMS are evidence of operational scope, not vocabulary to copy into Core.

**Attendance is the source of operational time facts; Payroll only classifies and pays them.** Payroll should not own schedules, punches, roster changes, geofence evidence, or overtime approval. Attendance owns the time lifecycle and writes neutral pay inputs only after the relevant fact is approved/finalized and attributable to an open payroll period.

**Raw clock events are append-only; daily attendance is a derived/finalized fact.** Punches and imported device rows should never be overwritten. Corrections add new events or adjustment records linked to the original source. The Attendance Day projection can be recomputed while a period is open and frozen when exported/locked for payroll.

**Roster and rule data are effective-dated, not "current settings."** Shift templates, grace periods, rounding, overtime thresholds, and allowance conditions change over time. Every attendance day and payroll handoff snapshots the roster/rule versions used so a 2026 attendance statement remains explainable after policies change.

**Support cross-midnight and rotating shifts in the model from day one.** HR2000 parity explicitly includes 24-hour rotating shifts. Designing only for calendar-day office attendance would force a migration later across attendance days, overtime rows, payroll inputs, and reports. The model should distinguish attendance date, shift start/end instants, payroll attribution period, and per-punch acceptance windows for IN, break out/in, and OUT.

**TMS Group becomes an Attendance Policy Group, not a flat setup table.** SBG's HR2000 TMS Group combines shift windows, work-hour rounding, daily-rated employee options, break-hour treatment, lateness grace, overtime eligibility, overtime adjustment, overtime export mapping, and lateness export mapping. BLB should model this as a cohort-assigned policy bundle with versioned child rows, so the employee-facing effect is clear and historical attendance days can snapshot the bundle version.

**Do not build a general rule DSL in v1.** Typed policy fields cover the known cases: grace, rounding, paid/unpaid breaks, late/early thresholds, absence classification, overtime before/after shift, rest-day/public-holiday work, daily/monthly rounding, overtime adjustment bands, overtime export thresholds, lateness export rounding, shift allowance, and meal/transport allowance triggers. SBG script-like conditional allowance criteria should be translated into typed predicates where possible, with the original script preserved as source text for audit/import. Arbitrary custom script execution can wait until multiple real customers prove typed predicates insufficient.

**Overtime is an approval workflow, not a computed side effect.** The system may compute overtime candidates from attendance days, but payable overtime should come from an approved overtime request or an HR-approved generated batch. This prevents raw punch drift from silently changing payroll.

**Attendance exceptions and overtime reuse Workflow.** Attendance chooses route context such as employee, supervisor, shift, date, overtime hours, cost center, and exception type; Workflow executes approval routing, delegation, escalation, and transition audit. Attendance must not grow a private approval engine.

**Calendar semantics are shared with Leave, not duplicated.** Working days, company holidays, rest days, special workdays, and employee calendar assignment come from People Settings and country-pack public-holiday data. Attendance may add shift-level overrides, but it should not maintain a competing holiday dictionary.

**Absence without approved leave is an attendance fact with payroll and disciplinary consequences.** Leave owns employee-requested leave. Attendance owns unresolved absence, missing punch, late, early-out, and unauthorized absence facts. Payroll receives neutral inputs where policy says an attendance exception affects pay; Disciplinary may later consume repeated unauthorized absence.

**Mobile/geofence support is schema-ready but not mandatory for the first slice.** HR2000 mobile clock-in/out and geofence are real parity targets, but BLB should start with responsive web/PWA clocking, imports, and manual adjustments unless SBG confirms device binding/geofence as a day-one blocker. The clock-event schema should still preserve source, device, IP, coordinates, geofence result, and evidence metadata without enforcing a native-app dependency.

**Conditional allowances belong to Attendance policy, classification belongs to Payroll.** Attendance decides that a shift/night/meal/transport/rest-day allowance was earned based on schedule and attendance facts. Payroll decides statutory treatment, wage base, payslip classification, and country-specific compliance.

**Conditional allowance rules need cap and resolution semantics.** SBG's conditional allowance setup has payroll code, ceiling, active state, and "take min/max" behavior. Attendance Core should therefore store allowance amount facts with the rule version, cap, min/max resolution, daily/monthly condition type, and explanation. Core should not store SBG's allowance codes as hard-coded enums.

**Overtime export mapping is separate from overtime calculation.** SBG maps overtime hour bands to export targets by day type (normal, rest, holiday, off day). BLB should compute approved overtime minutes first, then map them to one or more payroll pay items using effective-dated export rules. This keeps payroll handoff explainable when different OT bands classify differently.

**Overtime knock-off against lateness/NPL is a first-class policy, not a hidden report tweak.** HR2000 supports knocking non-paid leave or lateness from overtime before reports and salary posting. BLB can support this only as an explicit policy with before/after quantities and audit explanation because it materially changes payable inputs.

**Absenteeism has a batch workflow and its own lock date.** SBG's absenteeism screen generates candidates from normal days with no shift number and no leave type, then supports grouping, batch entry, batch record review, and an absenteeism lock date. BLB should model absence candidates separately from finalized absence facts; batch finalization should refuse dates before the attendance lock date and should check Leave before creating unauthorized absence inputs.

**Geofence setup has two layers: fence and group.** SBG separates Geo Fence (location/code/coordinates) from Geo Group (assignment grouping). BLB should preserve that shape in the contract: fences are evidence/control objects, groups are assignable policy bundles for employees/cohorts. Clock events reference the evaluated fence/group result, not only raw latitude/longitude.

**Imports must support both raw events and opening/finalized attendance facts.** SBG cutover may require importing historical clock data, approved overtime, monthly summaries, or already-paid attendance adjustments. Importers should preserve source references and export state rather than fabricating fresh BLB approvals.

**Self-service uses the People shell.** Employees clock, review timesheets, and request overtime inside the Attendance module's People UI, gated by capability and employee mapping. Do not create a separate ESS module just because HR2000 names employee-facing flows ESS/MSS.

## Public Contract

**Attendance Core promises rule evaluators and approval routers a normalized attendance context:**
- company/pay entity, country/state, timezone, attendance date, shift start/end instants, payroll period, and evaluation time;
- employee identity and effective-dated work profile snapshot: calendar, supervisor, organization unit, cost center, job grade, workforce class, pay basis, and employment status;
- resolved roster assignment, shift template, rule policy, day type, public-holiday/company-exception result, and any override source;
- raw clock events and trusted clock summary with source, event type, source confidence, correction chain, and optional device/geofence evidence;
- attendance day projection: expected minutes, worked minutes, payable minutes, late minutes, early-out minutes, absent minutes, break minutes, overtime candidate minutes, and exception tags;
- route-selection context for overtime and adjustments: actor, on-behalf status, supervisor relationship, amount/hours threshold, cost center, exception type, and capability scope;
- write APIs that append clock events, record adjustments, transition overtime/exception workflows, finalize attendance days, and create payroll-input handoff references without mutating locked facts.

**A shift template should declare:**
- neutral code, display name, active state, effective dates, and source alias;
- planned start/end time, timezone resolution, and whether the shift crosses midnight;
- accepted punch windows for IN, break out, break in, and OUT, each with earliest/latest bounds and whether unmatched punches become exceptions;
- paid and unpaid break windows, flexible-break rules, and minimum break enforcement;
- expected work minutes, half-day/full-day treatment, rest-day/off-day/public-holiday eligibility, and day-type overrides;
- clock-in/out grace, late/early-out thresholds, rounding method, missing-punch policy, and auto-close policy;
- overtime eligibility before shift, after shift, rest day, off day, and public holiday;
- allowance triggers such as night shift, meal, transport, attendance incentive, rest-day work, public-holiday work, and minimum worked minutes;
- payroll attribution rule for cross-month/cross-period shifts.

**An attendance policy group should declare:**
- neutral code, display name, active state, effective dates, source alias, and assigned employee/cohort scope;
- shift templates and roster defaults available to the group;
- work-hour calculation rules, including daily rounding table/method/minutes, pay-basis-specific break exclusion, and whether break lateness reduces payable work hours;
- daily-rated employee options for whether paid rest days, paid off days, and paid holidays count as one workday;
- lateness rules, including separate daily rounding, grace for in/out/start-break/end-break, and monthly lateness export rounding;
- overtime rules, including early OT, minimum early OT, late OT, minimum late OT, day-type eligibility, overtime adjustment bands, and overtime knock-off against lateness/NPL;
- overtime export mappings by day type, hour threshold, and payroll pay-item/export target;
- lateness export mappings for monthly payroll posting.

**An attendance allowance rule should declare:**
- neutral code, display name, active state, source alias, payroll pay-item code, and optional accounting/export metadata;
- allowance type: daily or monthly;
- ceiling/cap, amount, and whether the final value takes minimum or maximum across matched rows;
- condition rows with description, amount, day/month scope, and typed predicates over attendance context such as day type, clock-out time, worked minutes, leave/absence tag, shift code, and work profile;
- preserved source-script text where imported HR2000 conditions cannot yet be expressed as typed predicates;
- explanation output showing which rows matched, which rows were capped, and which payroll input was produced.

**A roster assignment should support:**
- employee or cohort target resolved from People Settings references;
- assignment source: fixed weekly schedule, rotating cycle, imported roster, ad hoc date rows, or manager override;
- effective date range, publish state, lock state, and revision history;
- exception rows for swapped shifts, rest-day changes, company holidays, special workdays, and employee-specific overrides;
- source system/code/label preservation for SBG or device imports.

**An attendance day should expose one lifecycle state at a time:**
- `scheduled`: roster resolved, no clock facts yet;
- `in_progress`: at least one clock event exists and the shift is not closed;
- `exception_pending`: missing punch, late/early, absence, or adjustment requires approval/review;
- `ready_for_review`: computed and complete for HR/supervisor review;
- `finalized`: approved for attendance reporting and payroll candidate generation;
- `exported_to_payroll`: payroll inputs were created for an open target period/run;
- `locked`: payroll period is locked; corrections must create reversal/new facts in an open period.

**An absenteeism batch should support:**
- candidate generation from attendance days that are normal scheduled workdays, have no resolved shift/punch facts where required, and have no approved leave type covering the date;
- filters and grouping by period, date range, cost center, department, section, employee, day type, and leave/absence code;
- batch entry that creates absence facts with actor, reason, source candidate, and optional payroll input policy;
- batch record review and reversal before payroll lock;
- an attendance/absenteeism lock date that blocks new or changed absence facts before the lock.

**An overtime request should support:**
- request mode: pre-approved planned overtime, post-work actual overtime, HR-generated batch, or on-behalf request;
- source attendance day, date/time span, requested minutes, approved minutes, payable minutes, reason, attachment state, and employee/approver remarks;
- lifecycle: draft, submitted, approved, rejected, cancelled, withdrawn, queued_for_payroll, paid/settled;
- route snapshot and policy version used at submission;
- duplicate protection by employee, source attendance day, overtime span, and payroll target;
- correction path for locked payroll periods through reversing/new `PayrollInput` rows.

**The payroll handoff contract is:**
- only finalized attendance days and approved overtime/adjustments can create payroll inputs;
- each row carries employee, pay item code, input type, amount or unit quantity, occurred date, payroll period/run target, source type, source id, policy version, roster version, attendance day id, and explanation metadata;
- supported neutral input families include overtime earning, shift allowance, attendance allowance, meal/transport allowance, lateness deduction, early-out deduction, unpaid absence deduction, rest-day/public-holiday work earning, and one-off attendance adjustment;
- overtime rows may be split by day type, hour threshold, export mapping, adjustment band, and knock-off policy; each split row keeps the source quantity and transformation explanation;
- lateness and absence rows may be posted daily or as monthly export totals, depending on the policy group;
- duplicate handoff is rejected for the same source fact and payroll target unless an explicit reversal/override event exists;
- locked payroll-period corrections do not mutate exported rows; they create reversing/new rows in an open period;
- country packs classify statutory treatment and wage bases; Attendance Core does not compute EPF/SOCSO/EIS/PCB or other country-specific payroll effects.

**The contract explicitly forbids:**
- Attendance Core depending on Malaysia-specific statutory classes, Malaysian overtime law labels, device-vendor SDKs, or SBG account/payroll codes;
- Payroll directly editing raw clock events, attendance-day state, overtime approval state, or roster history;
- overwriting imported clock events or deleting attendance audit history after payroll export;
- treating geofence/device checks as the only truth source; they are evidence attached to clock events and policy decisions;
- silently paying computed overtime without an approval or HR-finalized batch.

## Naming Judgement

| HR2000 / legacy label | BLB name | Judgement |
|-----------------------|----------|-----------|
| Time Management / e-TMS | Attendance | "Time Management" is broad vendor packaging. BLB should name the People module by the employee attendance facts it owns. |
| Shift Pattern | Roster Pattern | A pattern assigns shifts across dates. "Shift" stays the daily template; "roster" names employee/date assignment. |
| Shift | Shift Template | The reusable schedule definition, not an employee's worked day. |
| Clock In / Clock Out | Clock Event | Raw punch/event stream. Event type distinguishes in, out, break, transfer, manual entry, and import. |
| Attendance Record | Attendance Day | The per-employee/date resolved fact. It may span midnight but belongs to one attendance date and payroll attribution rule. |
| Overtime Application | Overtime Request | It is a request until approved and paid. |
| MSS Approval | Supervisor Approval | Use audience/role language instead of vendor acronyms. |
| Conditional Allowance | Attendance Allowance Rule | The condition is evaluated by Attendance; Payroll classifies the resulting pay item. |
| TMS Group | Attendance Policy Group | HR2000's group is a bundle of shift, work-hour, lateness, overtime, export, and payroll rules. "Policy Group" names the employee-facing assignment. |
| Time Zone columns in Shift setup | Punch Acceptance Window | The columns around IN, break, and OUT define valid matching windows for punches, not time zones in the geographical sense. |
| Work Hour | Work-Hour Rule | The tab controls rounding, daily-rated workday counting, and break treatment for payable hours. |
| Overtime Export | Overtime Export Mapping | Maps approved overtime bands to payroll/export targets by day type and threshold. |
| Lateness Export (Monthly) | Monthly Lateness Export Rule | Separate monthly payroll posting rule, not the same thing as daily lateness calculation. |
| Overtime Knock Off | Overtime Knock-Off Policy | Names the payroll-impacting transformation where lateness/NPL reduces overtime before export. |
| Missing Punch | Missing Clock Event Exception | Names the evidence gap, not merely the UI warning. |
| Device Binding | Clock Device Policy | Device binding is one policy under the broader clock-source governance surface. |
| GPS / Geofence | Location Evidence / Geofence Check | Coordinates and geofence result are evidence fields attached to a clock event, not the attendance record itself. |
| Geo Fence | Geofence | A physical/location boundary with coordinates and descriptive code. |
| Geo Group | Geofence Group | Assignable group of fences for clock policy. |
| Absenteeism | Absence Candidate / Absence Batch | Candidate generation and batch finalization should be separate from finalized absence facts. |
| Export to Payroll | Payroll Handoff | Payroll input creation with duplicate protection, period checks, and audit reference. |

## Risks and Guardrails

- **Risk: raw punch edits destroy auditability.** Guardrail: clock events are append-only; corrections link to original events and attendance-day projections are recomputed only while periods are open.
- **Risk: computed overtime leaks into payroll without approval.** Guardrail: payroll handoff requires approved overtime request or HR-finalized batch, never just a long worked duration.
- **Risk: cross-midnight shifts corrupt dates and payroll periods.** Guardrail: store shift start/end instants and a separate attendance date/payroll attribution rule; reports must not infer from calendar date alone.
- **Risk: Attendance duplicates Leave calendars.** Guardrail: consume People Settings calendar exceptions and country-pack holiday data; Attendance adds only shift/roster overrides.
- **Risk: device/geofence scope bloats v1.** Guardrail: schema supports source/location evidence, but native apps, device binding, and strict geofence enforcement stay behind explicit SBG day-one confirmation.
- **Risk: payroll double-counts attendance effects.** Guardrail: payroll inputs have unique source references and require reversal/override events for corrections.
- **Risk: SBG policy flags become opaque metadata.** Guardrail: model known effects as typed policy fields: rounding, grace, break treatment, overtime eligibility, allowance triggers, and payroll attribution.
- **Risk: imported HR2000 custom scripts become unsafe executable business logic.** Guardrail: translate known scripts to typed predicates, preserve source script text for audit, and block arbitrary runtime execution in Core v1.
- **Risk: overtime transformations are unexplainable.** Guardrail: store raw approved OT, adjustment bands, knock-off amounts, export mapping, final payroll quantity, and explanation as separate facts.
- **Risk: absenteeism batch entry bypasses Leave.** Guardrail: candidate generation and batch finalization must check approved/pending Leave coverage before creating unauthorized absence facts.
- **Risk: employee privacy leakage from location/device evidence.** Guardrail: store only policy-relevant evidence, scope access through Attendance permissions, and avoid exposing coordinates in normal reports unless the actor can audit clock events.
- **Risk: attendance period locks conflict with payroll locks.** Guardrail: attendance finalization/export checks payroll period state; locked-period corrections become open-period adjustments.
- **Risk: Workflow gaps cause a private approval engine.** Guardrail: file Workflow enhancements for any missing routing primitive; Attendance does not fork approval execution.

## Phases

### Phase 0 - Boundary and parity lock

- [ ] Confirm SBG's day-one Attendance scope: manual/imported attendance only, web/PWA clocking, overtime approval, rotating shifts, conditional allowances, GPS/geofence, device binding, and current HR2000 export/report requirements.
- [x] Review current SBG Time Management setup exports/screenshots in `docs/plans/people/sbg_attendance_ref/`: TMS Group, conditional allowance, overtime export, lateness export, absenteeism batch, clock transaction, geofence, and geogroup surfaces. {codex/gpt-5}
- [ ] Collect missing SBG exports/screenshots not present in `sbg_attendance_ref`: rounding table, export setup, report output, actual populated timecards, populated absenteeism batches, and device/geofence assignment details.
- [ ] Decide which attendance defaults belong in Core seeders, Malaysia payroll pack metadata, and `kiatng/blb-sbg` private configuration.
- [ ] Confirm Workflow can execute overtime and attendance-exception approval profiles selected from employee, supervisor chain, hours/amount threshold, cost center, shift/day type, and delegated approver limit; file a Workflow gap if not.
- [ ] Confirm the first payroll integration target: approved overtime only, or overtime plus lateness/absence/allowances.

### Phase 1 - Attendance Core skeleton

- [x] Create `app/Modules/People/Attendance/` using migration prefix `0320_01_15_*`, module authz, menu config, routes, service provider, and Livewire workbench shell. {codex/gpt-5}
- [x] Create shift template, punch acceptance window, roster pattern, roster assignment, attendance policy group, attendance day, clock event, attendance adjustment, overtime request, allowance rule, absenteeism batch, geofence/geofence group, and payroll handoff reference tables/models. {codex/gpt-5}
- [x] Implement append-only clock event ingestion for manual/web entries and file imports, including source, actor, timezone, device/location evidence fields, and correction lineage. Web, manual, import, and correction APIs are implemented behind `ClockEventIngestionService`; file parser UX remains a later import surface. {codex/gpt-5}
- [ ] Implement attendance-day projection from roster, rule policy, clock events, and calendar exceptions while the period is open. A first projection service exists for clock-in/out, worked minutes, late/early flags, absence, and OT candidates; roster/calendar integration remains open.
- [ ] Implement lifecycle states for attendance days and prevent mutation after payroll lock except through reversal/new adjustment facts.
- [x] Add dev seed data for common office shifts, a cross-midnight shift, punch windows, a weekly roster, a rotating roster, daily/monthly rounding, conditional allowance rules, and sample overtime/exception/absenteeism cases. {codex/gpt-5}

### Phase 2 - Calendar, roster, and shift rules

- [ ] Wire Attendance to `EmployeeWorkProfile` and People Settings calendar references for employee calendar, supervisor, cost center, workforce class, and pay basis resolution.
- [ ] Consume `PeopleCalendarException` for company holidays, special workdays, rest-day overrides, and employee-specific exceptions.
- [ ] Implement fixed weekly roster, rotating roster cycle, ad hoc roster rows, roster publishing, and revision history.
- [ ] Implement typed rule-policy evaluation for grace, daily/monthly rounding, paid/unpaid breaks, pay-basis-specific break exclusion, break lateness, missing punch, late, early-out, absent, cross-midnight, daily-rated workday counting, and payroll attribution.
- [ ] Implement attendance policy group assignment so employees/cohorts resolve shift, work-hour, lateness, overtime, export, and allowance rules from one versioned bundle.
- [ ] Surface schedule and attendance previews before roster publish and before payroll handoff.

### Phase 3 - Exceptions, overtime, and Workflow routing

- [ ] Implement missing-punch and attendance-adjustment requests with employee, supervisor, HR, and on-behalf modes.
- [ ] Implement overtime request lifecycle: draft, submitted, approved, rejected, cancelled, withdrawn, queued for payroll, paid/settled.
- [ ] Generate overtime candidates from attendance days without paying them until approval or HR-finalized batch.
- [ ] Implement overtime adjustment bands and overtime knock-off policy with before/after quantities and explanation output.
- [ ] Implement absenteeism candidate generation, batch entry, batch record review, grouping, and attendance lock-date enforcement.
- [ ] Route overtime and exceptions through Workflow using employee/supervisor, shift/day type, cost center, hours/amount threshold, and capability context.
- [ ] Emit standard notifications through `PeopleNotificationDeliveryLog`: roster published/changed, missing punch, late/early exception, overtime submitted/approved/rejected/cancelled, and payroll-export completion.

### Phase 4 - Payroll handoff and reconciliation

- [ ] Generate neutral `PayrollInput` rows for approved overtime with attendance-day and overtime-request source references.
- [ ] Generate neutral inputs for configured attendance allowances, shift allowances, rest-day/public-holiday work, lateness deductions, early-out deductions, unpaid absence deductions, and attendance adjustments when enabled by policy.
- [ ] Split overtime payroll inputs by day type, hour threshold, adjustment band, and export mapping where policy requires distinct pay items.
- [ ] Support daily and monthly lateness export rules, including monthly rounding separate from daily exception calculation.
- [ ] Enforce duplicate protection per source fact and payroll target; require explicit reversal/override for corrections.
- [ ] Block export into locked payroll periods and create open-period correction inputs for locked-period changes.
- [ ] Add attendance-to-payroll reconciliation report covering source fact, policy version, pay item, quantity/amount, payroll period, export state, and reversal state.
- [ ] Verify Malaysia payroll country pack classifies attendance inputs correctly without Attendance Core importing Malaysia-specific logic.

### Phase 5 - Employee, supervisor, HR, and payroll surfaces

- [ ] Employee tabs in `people.attendance.index`: clock in/out where enabled, my schedule, my attendance, missing-punch/adjustment request, overtime request, and history.
- [ ] Supervisor tabs gated by approval capability: subordinate attendance exceptions, overtime approvals, roster view, overlap/coverage warnings, and subordinate summaries.
- [ ] HR/admin tabs: shift templates, attendance policy groups, roster patterns, roster assignment, rule policies, allowance rules, absenteeism batches, imports, manual corrections, finalization, and audit.
- [ ] Payroll tabs: attendance export queue, payroll handoff status, reconciliation, reversal/correction review, and lock-state warnings.
- [ ] Reports as `RenderPdfJob` outputs under `resources/core/views/pdf/attendance/`: attendance summary, overtime list, late/absence exceptions, absenteeism batch record, roster, payroll reconciliation, and monthly attendance statement.
- [ ] CSV exports for attendance days, clock events audit, overtime, roster, exceptions, absenteeism batches, geofence audit, and payroll handoff.
- [ ] Saved filters/query views for timecard, absenteeism, overtime, clock transaction, and payroll reconciliation grids.

### Phase 6 - Device, geofence, and mobile parity follow-up

- [ ] Decide after SBG validation whether device binding, GPS/geofence, IP restrictions, clock reminders, and native push are day-one, fast-follow, or deferred.
- [ ] If enabled, implement clock-source policy for allowed sources, device/card identity, outlet/location labels, photo evidence, IP address, location evidence retention, geofence/geogroup checks, exception routing, and privacy-scoped reporting.
- [ ] Keep responsive web/PWA clocking as the default first delivery path; native apps remain outside Core unless a concrete deployment need justifies them.
- [ ] Add import/integration contracts for third-party time clocks without binding Attendance Core to a device-vendor SDK.

### Phase 7 - SBG migration and parity validation

- [ ] Import SBG TMS Group, shift windows, rounding, lateness, overtime, overtime export, lateness export, conditional allowance, geofence, and geogroup setup into private configuration with source aliases and typed policy translation.
- [ ] Import historical or opening attendance summaries, approved overtime, and already-paid attendance adjustments needed for payroll cutover.
- [ ] Reconcile BLB attendance/overtime/payroll handoff against HR2000 reports for a selected historical pay period.
- [ ] Document remaining HR2000 gaps, explicitly separating Core gaps, Malaysia pack gaps, and `kiatng/blb-sbg` private-policy gaps.
