# people/06_ipayroll-employee-module-gap-bridge

**Status:** Identified
**Last Updated:** 2026-05-12
**Sources:**
- `docs/plans/people/ipayroll_employee/` — iPayroll employee screenshots and exported setup workbooks
- `docs/plans/people/sbg_ipayroll_ref/` — CSV mirror of SBG iPayroll reference exports
- `docs/plans/people/01_people-modules.md` — People suite module map
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 i-Payroll operational parity benchmark
- `docs/plans/people/05_sbg-ipayroll-settings-gap-bridge.md` — People Settings bridge and naming judgement
- `app/Modules/Core/Employee/` — current canonical Employee model, admin screens, relationships, and employee types
- `app/Modules/People/Employees/` — current People-facing employee directory
- `app/Modules/People/Settings/` — current People Settings bridge for reference data, work profiles, account access, change requests, imports, logs, calendars, and restricted-person controls
**Agents:** GitHub Copilot/gpt-5.5

## Problem Essence

The current Employee module is still a basic employment-record foundation: core admin can create and edit a small set of fields, and the People-facing employee screen is a read-only directory. The iPayroll employee reference shows a much broader employee work surface: a dense employee grid, multi-section employee record, reference-driven organization/work classifications, payroll data-readiness fields, employee account provisioning, saved views, employee-submitted change intake, employment documents, organization chart support, and restricted-person safeguards.

## Desired Outcome

Bridge the gap by turning Employee into the HR workbench that sits on top of Core Employee records and People Settings. Core Employee remains the stable identity/employment root; People Settings supplies reusable reference data and migration/import scaffolding; the People/Employees module becomes the day-to-day employee surface for HR, payroll, managers, and employee self-service workflows.

## Current Baseline

| Area | Current BLB state | Gap against iPayroll employee reference |
|------|-------------------|------------------------------------------|
| Core employee record | `employees` stores company, department, supervisor, employee number, names, designation, type, contact, status, employment dates, and metadata. | Too shallow for employee workbench parity: no integrated work profile, payroll data-readiness, demographic, bank/payment, statutory-profile, document, or lifecycle sections. |
| Admin employee UI | Admin screens create/show employees, edit core fields, manage status/type/user link, subordinates, and addresses. | Admin UI is system administration, not an HR workbench with saved views, payroll filters, bulk actions, migration status, or document actions. |
| People employee UI | `people.employees.index` lists/searches employees in the licensee company tree. | It has no employee detail route, work-profile columns, filters by BLB classifications, saved views, bulk operations, or drill-down sections. |
| People Settings bridge | Reference entries, aliases, import jobs, employee work profiles, account access, profile-change requests, calendar exceptions, notification logs, and restricted-person records exist as a foundation. | These records are not yet wired into the Employee workbench, employee creation/update flows, payroll data-readiness checks, organization chart views, or employee self-service request workflows. |
| Payroll statutory profile | Payroll owns effective-dated employee statutory profile payloads by country pack. | Employee detail screens need a safe summary/edit entry point without leaking Malaysia-specific fields into Core Employee. |
| iPayroll exports | The reference folder includes banks, departments, sections, categories, jobs, occupations, qualifications, race/religion-like demographic lists, government address, and trainers. | Import contracts exist at the settings layer, but Employee needs migration mapping/reporting from imported codes to employee work profiles and employee detail sections. |

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Core Employee root | Stable employment identity, company scope, reporting line, user relationship, basic lifecycle status, and addresses. | `app/Modules/Core/Employee` |
| Employee work profile | Cost center, organization unit, employment group, job title, workforce class, job grade, work calendar, pay basis, hire/resign dates, and source metadata. | `app/Modules/People/Settings` data, surfaced by `app/Modules/People/Employees` |
| Employee workbench | Searchable/filterable employee grid, saved employee views, bulk readiness actions, employee detail drill-down, and migration status. | `app/Modules/People/Employees` |
| Employee detail sections | HR/personnel, work profile, payroll data-readiness, statutory summary, addresses, documents, account access, change requests, subordinate/team context, and audit/history. | `app/Modules/People/Employees` with module-specific sections |
| Employee account access | Provision, activate/revoke, invite, and audit employee access to employee self-service workflows. | `app/Modules/People/Settings` service, surfaced by Employees and Settings |
| Profile change requests | Controlled employee-submitted changes with review, approval/rejection, and explicit application to canonical fields. | People employee-facing workflows plus Employees review surface |
| Employee data migration | Import SBG/iPayroll employee and setup data, map source codes to BLB references, dry-run errors, aliases, and readiness reports. | People Settings import services plus SBG private seed/mapping repo |
| Restricted-person safeguards | Restricted-person register, reason visibility policy, retention policy, and rehiring safeguards. | People Settings/Compliance, linked from Employees only for authorized users |

## Design Decisions

**Keep Core Employee deep and small.** The core `employees` table should not absorb every iPayroll staff field. It should hold only identity and employment-root facts that every module needs. Rich HR/payroll fields should live in typed profile tables owned by People Settings, Payroll, Leave, Attendance, Training, or future HR modules.

**Make People/Employees the workbench, not Core/Admin.** Admin employee screens are for system operators. HR users need a People-facing workbench scoped to the licensee company tree, with operational columns, filters, saved views, and actions that reflect payroll and HR data readiness.

**Wire the Settings bridge before adding new employee fields.** `EmployeeWorkProfile`, `PeopleReferenceEntry`, `EmployeePortalAccess`, `EmployeeProfileChangeRequest`, imports, notification logs, and restricted-person records already express much of the iPayroll reference layer. The next gap is integration into employee UX and workflows, not another parallel reference-data or profile schema. Product-facing labels should say Employee Account Access even if the current model class remains `EmployeePortalAccess`.

**Do not copy iPayroll tab names or unsafe bulk actions.** Treat Employee Master, ESS Access, Save To Query, e-Letter, BlackList, and Database Import as evidence of user jobs. BLB labels should be Employee Workbench, Employee Account Access, Saved Employee View, Employment Document, Restricted-Person Register, and Scoped Employee Import.

**Treat imported SBG values as migration evidence.** Source labels/codes stay in import metadata and aliases. Product behavior should use cleaned BLB names, active/inactive state, canonical references, and explicit alias/merge mappings so historical iPayroll references remain explainable.

**Separate demographic/statutory privacy from ordinary profile data.** Race/religion/nationality-like lists and restricted-person records need permissioned visibility and audit. They should not become default columns in broad employee grids unless a legal/reporting need is clear.

**Let country packs own statutory details.** Employee screens can summarize Malaysia statutory profile readiness, but EPF/SOCSO/LHDN/zakat field semantics belong to the Malaysia payroll pack, not the Core Employee model or generic People/Employees schema.

## Naming Judgement

The iPayroll screenshots and exports are evidence, not vocabulary. BLB should use names that state the business object or workflow clearly, while retaining iPayroll labels only as `source_label`, aliases, or migration notes.

| iPayroll or fuzzy label | BLB name | Judgement |
|-------------------------|----------|-----------|
| Employee Master | Employee Workbench | The screen is a working surface for HR/payroll operations over employees, not a master table. |
| Staff profile / profile tabs | Employee Detail Sections | The UI composes sections over canonical records; it should not imply one giant profile blob. |
| Personnel | HR Details | Use only for non-payroll HR facts. Keep payroll, statutory, bank, and account access in their own sections. |
| Department / Section / Division / Unit | Organization Unit | These are level labels inside one organization hierarchy, not separate product concepts. |
| Category | Employment Group | SBG values describe employee grouping or banding; "category" is too vague. |
| Occupation | Job Title | The values are job titles held by employees, not a labor-market occupation taxonomy. |
| Job | Workforce Class | SBG values such as Direct Labor, Indirect Labor, Management, and Office Labor classify the workforce; they are not job titles. |
| Pay Rate / Pay Type | Pay Basis | The business question is how the employee is paid, such as monthly, daily, hourly, or piece-rated. |
| Payroll readiness | Payroll Data Readiness | The check is about whether required employee data exists; it does not mean payroll itself is ready to run. |
| ESS Access / Portal Access | Employee Account Access | The admin job is provisioning the employee's account and access state. Avoid implying a separate ESS island. |
| Broadcast Login Info | Send Access Invitation | This is an audited invitation/notification, not a broad broadcast. |
| Employee Request / e-Request | Profile Change Request | Name the controlled employee-submitted data-change workflow. |
| Save To Query | Saved Employee View | Users save a filtered/sorted employee view, not raw SQL or an opaque query. |
| e-Letter | Employment Document | Use concrete document names in UI, such as Offer Letter or Confirmation Letter. |
| Database Import | Scoped Employee Import | Imports must be scoped, validated, auditable jobs, not generic database writes. |
| BlackList | Restricted-Person Register | The object is a sensitive, access-controlled risk/rehire register; the iPayroll label is loaded and imprecise. |
| Org chart | Organization Chart | Use the full business term in docs; reserve `org` only for terse UI where space is constrained. |

## Public Contract

Employee workbench parity should provide these stable contracts:

- **Employee root:** one canonical employee identity per company-scoped employment relationship, with immutable employee number uniqueness per company and explicit user/account access linkage.
- **Work profile:** one current employee work profile references typed People Settings entries for organization, costing, job title, workforce class, grade, calendar, and pay-basis fields; historical changes must remain auditable before payroll/attendance/leave depend on them.
- **Saved views:** HR users can save employee filters/sorts as named views rather than raw queries, with company/user visibility and export/report reuse.
- **Employee account access:** provisioning exposes employee, mapped user, login identifier, email, display name, status, activation/revocation timestamps, last invitation, and notification audit.
- **Profile requests:** employee-submitted changes are reviewed before they update canonical employee/work/payroll fields; requested payload, reviewer, decision, and timestamps remain auditable.
- **Migration evidence:** every imported employee/work-profile value can trace back to source system, source label, source code, row result, and alias/merge decision.
- **Payroll data readiness:** employee detail surfaces show whether required payroll inputs exist: work profile, pay basis, bank/payment details, statutory profile readiness, account/document delivery status, and missing blocking data.

## Gap Inventory

| Priority | Gap | Why it matters |
|----------|-----|----------------|
| Must bridge before SBG employee migration | Employee import/mapping from iPayroll source fields into Core Employee, EmployeeWorkProfile, addresses, account access, and payroll statutory profile placeholders. | Without a migration path, the reference dictionaries are isolated and HR must re-key employee data manually. |
| Must bridge before payroll cutover | Payroll data-readiness view per employee: work profile, pay basis, bank/payment data, statutory profile readiness, active status, hire/resign dates, and missing blockers. | Payroll can calculate only if employee source data is complete, classified, and explainable. |
| Must bridge before HR daily use | People/Employees workbench with dense BLB filters, saved views, operational columns, drill-down, and bulk actions. | A plain directory cannot replace an HR workbench for employee and payroll operations. |
| Must bridge before employee self-service rollout | Account access actions and profile-change request review integrated into employee detail/workbench. | Employee self-service parity needs account lifecycle and controlled data-change handling, not only employee routes. |
| Should bridge before organization/reporting rollout | Organization-unit, supervisor, cost-center, job title, grade, workforce class, and calendar fields visible and filterable from employee screens. | Organization charts, headcount reports, leave calendars, attendance, and payroll costing all depend on the same clean taxonomy. |
| Later bridge | Employment documents, letters, broad HR history sections, medical/training sections, restricted-person hiring checks, and organization chart graphics. | These are important HR parity features but should follow the workbench and payroll data-readiness spine. |

## Phases

### Phase 1 — Employee field map and migration boundary

- [ ] Catalog the iPayroll Staff screenshots into employee detail sections: identity, employment, organization, payroll/payment, statutory, demographics, family/dependents if present, documents, account access, and audit/history.
- [ ] Define the field placement map: Core Employee, EmployeeWorkProfile, Payroll statutory profile, Address, Employee Account Access, Profile Change Request, future module, or SBG-private metadata.
- [ ] Mark fields that must never be broad-grid defaults because they are sensitive, statutory, or personally identifying beyond normal HR access.
- [ ] Define the SBG employee import contract and dry-run report shape, including source employee number matching, duplicate detection, missing dictionary references, inactive source codes, and alias/merge requirements.

### Phase 2 — People employee workbench

- [ ] Expand `people.employees.index` from a directory into an Employee Workbench with work-profile joins for cost center, organization unit, employment group, job title, workforce class, job grade, pay basis, calendar, hire/resign dates, account-access state, and payroll data-readiness state.
- [ ] Add filters for status, company, organization unit, cost center, employment group, job title, workforce class, job grade, pay basis, account-access state, and payroll data-readiness blockers.
- [ ] Wire `PeopleSavedEmployeeView` into the workbench so users can save and recall filtered/sorted employee views.
- [ ] Provide safe exports from the active workbench view, using BLB names while preserving source labels/codes where imported data is shown.

### Phase 3 — Employee detail sections

- [ ] Add a People-facing employee detail route that composes sections over existing records instead of duplicating the admin employee show page.
- [ ] Surface editable Core Employee basics only where HR has permission; keep system-admin-only concerns in the admin module.
- [ ] Add Work Profile section backed by `EmployeeWorkProfile` and typed `PeopleReferenceEntry` selectors with active/inactive and source/alias visibility.
- [ ] Add Payroll Data Readiness section that summarizes work profile completeness, bank/payment details, statutory profile existence/validity, active employment status, and payroll-blocking gaps without embedding Malaysia-specific fields in Employees.
- [ ] Add Employee Account Access and Profile Change Request sections so HR can provision/revoke/invite access and review pending employee-submitted profile changes from the employee context.

### Phase 4 — Employee import and reconciliation

- [ ] Build a scoped employee import dry-run that maps source rows into Core Employee plus EmployeeWorkProfile and reports row-level errors before writing anything.
- [ ] Require imported organization, cost center, category/employment group, job/occupation/workforce class, grade, bank, and calendar references to resolve through People Settings aliases or explicit mapping.
- [ ] Preserve source system/label/code metadata on imported work-profile and related records so SBG can reconcile old iPayroll reports against BLB.
- [ ] Generate a post-import employee migration report covering created/updated employees, unresolved references, inactive codes, missing payroll blockers, missing account-ready identifiers, and sensitive-field follow-ups.

### Phase 5 — Employee account access and controlled profile changes

- [ ] Move Employee Account Access from a passive Settings list into workbench/detail actions: provision, activate, revoke, send invitation, and view notification history.
- [ ] Decide the SBG login-identifier rule before activation: employee number, email, or separate username. Record the decision in the plan and import mapping.
- [ ] Implement profile-change request review actions that validate requested changes, apply approved updates to canonical fields, and leave an audit trail.
- [ ] Route employee-facing profile requests into this workflow rather than allowing direct uncontrolled edits to payroll-relevant fields.

### Phase 6 — Payroll, documents, and org reporting readiness

- [ ] Feed employee work-profile and bank/payment readiness into Payroll run participant selection and bank-export validation.
- [ ] Expose payslip/document delivery status once Payroll document artifacts and employee self-service documents are available.
- [ ] Add employment-document actions for letters using the shared PDF/document infrastructure, but keep document templates outside the first employee migration blocker.
- [ ] Use the cleaned organization taxonomy and supervisor links as the source for organization chart and headcount reports.
- [ ] Link restricted-person checks into hiring/rehire workflows only after legal/access/retention policy is confirmed.

## Open Questions

- Does SBG have an employee export matching the Staff screenshots, or must the first migration infer employee fields from screenshots plus database/export access later?
- Which iPayroll Staff tabs are mandatory before payroll cutover versus HR cleanup after cutover?
- Should BLB maintain a single current `EmployeeWorkProfile` first, or introduce effective-dated work-profile history before payroll, leave, and attendance start consuming it?
- Which bank/payment fields are required for SBG's first bank export format?
- Should employee account login identifiers mirror employee numbers for migration familiarity, or should BLB require email/user-login identifiers from day one?
- Which demographic fields are legally/reporting-required for SBG, and who is allowed to view or export them?
