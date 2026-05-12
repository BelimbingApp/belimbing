# people/06_ipayroll-employee-module-gap-bridge

**Status:** Identified
**Last Updated:** 2026-05-11
**Sources:**
- `docs/plans/people/ipayroll_employee/` — iPayroll employee screenshots and exported setup workbooks
- `docs/plans/people/sbg_ipayroll_ref/` — CSV mirror of SBG iPayroll reference exports
- `docs/plans/people/01_people-modules.md` — People suite module map
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 i-Payroll operational parity benchmark
- `docs/plans/people/05_sbg-ipayroll-settings-gap-bridge.md` — People Settings bridge and naming judgement
- `app/Modules/Core/Employee/` — current canonical Employee model, admin screens, relationships, and employee types
- `app/Modules/People/Employees/` — current People-facing employee directory
- `app/Modules/People/Settings/` — current People Settings bridge for reference data, work profiles, portal access, requests, imports, logs, calendars, and restricted people
**Agents:** GitHub Copilot/gpt-5.5

## Problem Essence

The current Employee module is still a basic employment-record foundation: core admin can create and edit a small set of fields, and the People-facing employee screen is a read-only directory. The iPayroll employee reference shows a much broader employee operations surface: a dense Employee Master grid, multi-tab staff profile data, reference-driven organization/work classifications, payroll/payment readiness fields, employee portal access provisioning, saved views, profile-change intake, employment documents, org chart support, and sensitive restricted-person handling.

## Desired Outcome

Bridge the gap by turning Employee into the operational HR workbench that sits on top of Core Employee records and People Settings. Core Employee remains the stable identity/employment root; People Settings supplies reusable dictionaries and migration/import scaffolding; the People/Employees module becomes the day-to-day employee operations surface for HR, payroll, managers, and employee-facing workflows.

## Current Baseline

| Area | Current BLB state | Gap against iPayroll employee reference |
|------|-------------------|------------------------------------------|
| Core employee record | `employees` stores company, department, supervisor, employee number, names, designation, type, contact, status, employment dates, and metadata. | Too shallow for Employee Master parity: no integrated work-profile, payroll-readiness, demographic, bank/payment, statutory-profile, document, or lifecycle panels. |
| Admin employee UI | Admin screens create/show employees, edit core fields, manage status/type/user link, subordinates, and addresses. | Admin UI is system administration, not an HR operations workbench with saved views, payroll filters, bulk actions, migration status, or document actions. |
| People employee UI | `people.employees.index` lists/searches employees in the licensee company tree. | It has no profile/detail route, work-profile columns, filters by iPayroll-style classifications, saved views, bulk operations, or drill-down panels. |
| People Settings bridge | Reference entries, aliases, import jobs, employee work profiles, portal access, profile-change requests, calendar exceptions, notification logs, and restricted-person records exist as a foundation. | These records are not yet wired into the Employee workbench, employee creation/update flows, payroll readiness checks, org chart views, or employee-facing request workflows. |
| Payroll statutory profile | Payroll owns effective-dated employee statutory profile payloads by country pack. | Employee profile screens need a safe summary/edit entry point without leaking Malaysia-specific fields into Core Employee. |
| iPayroll exports | The reference folder includes banks, departments, sections, categories, jobs, occupations, qualifications, race/religion-like demographic lists, government address, and trainers. | Import contracts exist at the settings layer, but Employee needs migration mapping/reporting from imported codes to employee work profiles and profile panels. |

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Core Employee root | Stable employment identity, company scope, reporting line, user relationship, basic lifecycle status, and addresses. | `app/Modules/Core/Employee` |
| Employee work profile | Cost center, organization unit, employment group, job title, workforce class, job grade, work calendar, pay-rate type, hire/resign dates, and source metadata. | `app/Modules/People/Settings` data, surfaced by `app/Modules/People/Employees` |
| Employee operations workbench | Searchable/filterable employee grid, saved employee views, bulk readiness actions, profile drill-down, and migration status. | `app/Modules/People/Employees` |
| Employee profile panels | HR/personnel, work profile, payroll readiness, statutory summary, addresses, documents, portal access, requests, subordinate/team context, and audit/history. | `app/Modules/People/Employees` with module-specific panels |
| Employee portal access | Provision, activate/revoke, invite, and audit employee access to employee-facing People workflows. | `app/Modules/People/Settings` service, surfaced by Employees and Settings |
| Profile change requests | Controlled employee-submitted changes with review, approval/rejection, and explicit application to canonical fields. | People employee-facing workflows plus Employees review surface |
| Migration bridge | Import SBG/iPayroll employee and setup data, map source codes to BLB references, dry-run errors, aliases, and readiness reports. | People Settings import services plus SBG private seed/mapping repo |
| Sensitive people controls | Restricted-person register, reason visibility policy, retention policy, and rehiring safeguards. | People Settings/Compliance, linked from Employees only for authorized users |

## Design Decisions

**Keep Core Employee deep and small.** The core `employees` table should not absorb every iPayroll staff field. It should hold only identity and employment-root facts that every module needs. Rich HR/payroll fields should live in typed profile tables owned by People Settings, Payroll, Leave, Attendance, Training, or future HR modules.

**Make People/Employees the workbench, not Core/Admin.** Admin employee screens are for system operators. HR users need a People-facing workbench scoped to the licensee company tree, with operational columns, filters, saved views, and actions that reflect payroll and HR readiness.

**Wire the Settings bridge before adding new employee fields.** `EmployeeWorkProfile`, `PeopleReferenceEntry`, `EmployeePortalAccess`, `EmployeeProfileChangeRequest`, imports, notification logs, and restricted-person records already express much of the iPayroll reference layer. The next gap is integration into employee UX and workflows, not another parallel dictionary or profile schema.

**Do not copy iPayroll tab names or unsafe bulk actions.** Treat Employee Master, ESS Access, Save To Query, e-Letter, BlackList, and Database Import as evidence of user jobs. BLB labels should be Employee Operations Workbench, Employee Portal Access, Saved Employee View, Employment Document, Restricted-Person Register, and Scoped Import.

**Treat imported SBG values as migration evidence.** Source labels/codes stay in import metadata and aliases. Product behavior should use cleaned BLB names, active/inactive state, canonical references, and explicit alias/merge mappings so historical iPayroll references remain explainable.

**Separate demographic/statutory privacy from ordinary profile data.** Race/religion/nationality-like lists and restricted-person records need permissioned visibility and audit. They should not become default columns in broad employee grids unless a legal/reporting need is clear.

**Let country packs own statutory details.** Employee screens can summarize Malaysia statutory profile readiness, but EPF/SOCSO/LHDN/zakat field semantics belong to the Malaysia payroll pack, not the Core Employee model or generic People/Employees schema.

## Public Contract

Employee workbench parity should provide these stable contracts:

- **Employee root:** one canonical employee identity per company-scoped employment relationship, with immutable employee number uniqueness per company and explicit user/portal access linkage.
- **Work profile:** one current employee work profile references typed People Settings entries for organization, costing, job, class, grade, calendar, and pay-rate fields; historical changes must remain auditable before payroll/attendance/leave depend on them.
- **Saved views:** HR users can save employee filters/sorts as named views rather than raw queries, with company/user visibility and export/report reuse.
- **Portal access:** provisioning exposes employee, mapped user, login identifier, email, display name, status, activation/revocation timestamps, last invitation, and notification audit.
- **Profile requests:** employee-submitted changes are reviewed before they update canonical employee/work/payroll fields; requested payload, reviewer, decision, and timestamps remain auditable.
- **Migration evidence:** every imported employee/work-profile value can trace back to source system, source label, source code, row result, and alias/merge decision.
- **Payroll readiness:** employee detail surfaces show whether required payroll inputs exist: work profile, pay-rate type, bank/payment details, statutory profile readiness, portal/document delivery status, and missing blocking data.

## Gap Inventory

| Priority | Gap | Why it matters |
|----------|-----|----------------|
| Must bridge before SBG employee migration | Employee import/mapping from iPayroll source fields into Core Employee, EmployeeWorkProfile, addresses, portal access, and payroll statutory profile placeholders. | Without a migration path, the reference dictionaries are isolated and HR must re-key employee data manually. |
| Must bridge before payroll cutover | Payroll readiness view per employee: work profile, pay-rate type, bank/payment data, statutory profile readiness, active status, hire/resign dates, and missing blockers. | Payroll can calculate only if employee source data is complete, classified, and explainable. |
| Must bridge before HR daily use | People/Employees workbench with iPayroll-like dense filters, saved views, operational columns, drill-down, and bulk actions. | A plain directory cannot replace Employee Master for HR and payroll operations. |
| Must bridge before employee-facing rollout | Portal access actions and profile-change request review integrated into employee detail/workbench. | ESS parity needs account lifecycle and controlled data-change handling, not only employee routes. |
| Should bridge before org/reporting rollout | Organization-unit, supervisor, cost-center, job title, grade, workforce class, and calendar fields visible and filterable from employee screens. | Org charts, headcount reports, leave calendars, attendance, and payroll costing all depend on the same clean taxonomy. |
| Later bridge | Employment documents, letters, broad HR history tabs, medical/training panels, restricted-person hiring checks, and org chart graphics. | These are important HR parity features but should follow the workbench and payroll-readiness spine. |

## Phases

### Phase 1 — Employee field map and migration boundary

- [ ] Catalog the iPayroll Staff screenshots into employee profile sections: identity, employment, organization, payroll/payment, statutory, demographics, family/dependents if present, documents, access, and audit/history.
- [ ] Define the field placement map: Core Employee, EmployeeWorkProfile, Payroll statutory profile, Address, Portal Access, Profile Change Request, future module, or SBG-private metadata.
- [ ] Mark fields that must never be broad-grid defaults because they are sensitive, statutory, or personally identifying beyond normal HR access.
- [ ] Define the SBG employee import contract and dry-run report shape, including source employee number matching, duplicate detection, missing dictionary references, inactive source codes, and alias/merge requirements.

### Phase 2 — People employee operations workbench

- [ ] Expand `people.employees.index` from a directory into an Employee Operations Workbench with work-profile joins for cost center, organization unit, employment group, job title, workforce class, job grade, pay-rate type, calendar, hire/resign dates, portal-access state, and payroll-readiness state.
- [ ] Add filters for status, company, organization unit, cost center, employment group, job title, workforce class, job grade, pay-rate type, portal-access state, and payroll-readiness blockers.
- [ ] Wire `PeopleSavedEmployeeView` into the workbench so users can save and recall filtered/sorted employee views.
- [ ] Provide safe exports from the active workbench view, using BLB names while preserving source labels/codes where imported data is shown.

### Phase 3 — Employee detail profile panels

- [ ] Add a People-facing employee detail route that composes panels over existing records instead of duplicating the admin employee show page.
- [ ] Surface editable Core Employee basics only where HR has permission; keep system-admin-only concerns in the admin module.
- [ ] Add Work Profile panel backed by `EmployeeWorkProfile` and typed `PeopleReferenceEntry` selectors with active/inactive and source/alias visibility.
- [ ] Add Payroll Readiness panel that summarizes work profile completeness, bank/payment details, statutory profile existence/validity, active employment status, and payroll-blocking gaps without embedding Malaysia-specific fields in Employees.
- [ ] Add Portal Access and Profile Requests panels so HR can provision/revoke/invite access and review pending employee-submitted profile changes from the employee context.

### Phase 4 — Employee import and reconciliation

- [ ] Build an employee import dry-run that maps source rows into Core Employee plus EmployeeWorkProfile and reports row-level errors before writing anything.
- [ ] Require imported organization, cost center, category/employment group, job/occupation/workforce class, grade, bank, and calendar references to resolve through People Settings aliases or explicit mapping.
- [ ] Preserve source system/label/code metadata on imported work-profile and related records so SBG can reconcile old iPayroll reports against BLB.
- [ ] Generate a post-import employee migration report covering created/updated employees, unresolved references, inactive codes, missing payroll blockers, missing portal-ready identifiers, and sensitive-field follow-ups.

### Phase 5 — Portal access and controlled profile changes

- [ ] Move Employee Portal Access from a passive Settings list into workbench/detail actions: provision, activate, revoke, send invitation, and view notification history.
- [ ] Decide the SBG login-identifier rule before activation: employee number, email, or separate username. Record the decision in the plan and import mapping.
- [ ] Implement profile-change request review actions that validate requested changes, apply approved updates to canonical fields, and leave an audit trail.
- [ ] Route employee-facing profile requests into this workflow rather than allowing direct uncontrolled edits to payroll-relevant fields.

### Phase 6 — Payroll, documents, and org reporting readiness

- [ ] Feed employee work-profile and bank/payment readiness into Payroll run participant selection and bank-export validation.
- [ ] Expose payslip/document delivery status once Payroll document artifacts and employee portal documents are available.
- [ ] Add employment-document actions for letters using the shared PDF/document infrastructure, but keep document templates outside the first employee migration blocker.
- [ ] Use the cleaned organization taxonomy and supervisor links as the source for org chart and headcount reports.
- [ ] Link restricted-person checks into hiring/rehire workflows only after legal/access/retention policy is confirmed.

## Open Questions

- Does SBG have an employee master export matching the Staff screenshots, or must the first migration infer employee fields from screenshots plus database/export access later?
- Which iPayroll Staff tabs are mandatory before payroll cutover versus HR cleanup after cutover?
- Should BLB maintain a single current `EmployeeWorkProfile` first, or introduce effective-dated work-profile history before payroll, leave, and attendance start consuming it?
- Which bank/payment fields are required for SBG's first bank export format?
- Should portal login identifiers mirror employee numbers for migration familiarity, or should BLB require email/user-login identifiers from day one?
- Which demographic fields are legally/reporting-required for SBG, and who is allowed to view or export them?
