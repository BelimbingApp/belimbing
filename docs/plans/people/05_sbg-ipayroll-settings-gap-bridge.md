# people/05_sbg-ipayroll-settings-gap-bridge

**Status:** Complete — People Settings foundation delivered
**Last Updated:** 2026-05-11
**Sources:**
- `docs/plans/people/01_people-modules.md` — People suite module map
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll core, country-pack, SBG customization boundaries
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 i-Payroll parity benchmark
- `docs/plans/people/04_pdf-generation-strategy.md` — PDF/document output infrastructure
- `docs/plans/people/sbg_ipayroll_ref/` — SBG iPayroll screenshots and exported setup lists
**Agents:** GitHub Copilot/gpt-5.5

## Problem Essence

The existing People and Payroll plans cover module architecture, statutory calculation, outputs, employee-facing documents, and payroll-adjacent workflows, but the SBG iPayroll reference shows a large practical settings layer that is not yet planned as a first-class deliverable. That layer includes reference-data setup, organization taxonomy, employee operations workbench controls, employee portal access provisioning, operational imports/logs, restricted-person handling, and SBG-specific seed/reference data.

## Desired Outcome

BLB provides a coherent People Settings foundation that can absorb SBG's current iPayroll setup without scattering one-off dropdowns across Payroll, Employees, employee-facing workflows, Leave, Claims, Attendance, and Training. The reusable framework belongs in `belimbingapp/belimbing`; Malaysia statutory settings stay in `BelimbingApp/blb-payroll-my`; SBG-specific code lists, labels, payroll-accounting mappings, and private policies belong in `kiatng/blb-sbg`.

## Top-Level Components

| Component | SBG iPayroll evidence | BLB gap | Target ownership |
|-----------|------------------------|---------|------------------|
| Organization reference data | Cost Center, Department, Section, Category, Division, Unit, Occupation, Job Grade exports/screens | Current plans mention company hierarchy and employee placement, but not the operational code tables needed to filter, import, report, and map payroll cost. iPayroll's labels are not clean enough to copy directly. | Core People Settings, with SBG seed data in `kiatng/blb-sbg` |
| Employee classification dictionaries | Qualification, Race, Religion, Nationality, job/occupation lists | Employee statutory profiles cover payroll tax/contribution fields, but HR profile dictionaries are not planned as configurable settings. | Core People Settings; country pack may add statutory semantics where legally required |
| Payroll/payment dictionaries | Bank, Income Tax Branch, Foreign Currency, government address list | Payroll plan mentions bank/payment settings and statutory profiles, but not admin-maintained dictionaries and source imports. | Core Payroll/People Settings; Malaysia pack owns statutory branch semantics |
| Time/calendar setup | Calendar Group, Custom Days, Department calendar column | Attendance/Leave plans mention holidays and calendars, but no shared settings model for work calendars and custom days. | Core People Settings consumed by Leave/Attendance/Payroll |
| Medical/training vendors | Clinic or Hospital, Trainers & Organisation, trainer export | Training and benefits are planned as modules, but vendor/provider dictionaries are missing from settings. | Core People Settings; SBG data in private customization |
| Employee master operating surface | Employee Master grid with filters, saved query, e-Letter, pay rate, cost center, hire/resign dates | Employee module is currently planned as a listing/profile, but not as an HR operations workbench with saved views and document actions. | Employees module, PDF/document services for letters |
| Employee portal access provisioning | ESS Access grid with employee-to-user mapping, user ID, display name, email, activation, broadcast login info, batch update | Existing plans cover employee-facing portal features, but not identity provisioning, bulk activation, login notification, or access state management. BLB should not assume this requires a separate "ESS" module. | Existing authz/admin surfaces plus employee-facing People workflows |
| Employee request intake | Employee Request menu under Payroll | e-Request is listed in parity terms, but needs a controlled profile-change workflow tied to employee data ownership. | Employee-facing People workflows plus Employees approval workflow |
| Operational admin utilities | Database Import, Employee Notification, Email Log, Scheduler Cron Job, File Explorer, Query Setup | Existing plans do not decide which iPayroll admin utilities BLB should replace, redesign, or intentionally omit. | Core admin/platform, only where safe and needed |
| Organization chart | Organisation Chart screenshot and HR2000 benchmark item | Org chart is noted as later parity but lacks a dependency on clean org reference data. | Reports/Employees after taxonomy is stable |
| Blacklist | BlackList grid with ID/passport, department/section/category columns | Disciplinary is planned, but blacklist as a hiring/rehire risk register is not modeled. | People Compliance/Disciplinary, with strict access controls |
| Help/change education | Video Guides and What's New menus | In-app guidance is listed as a later parity enhancer, not tied to settings rollout. | Later core help surface |

## Design Decisions

**Create one People Settings foundation instead of module-local dropdowns.** SBG's reference data is shared by employee profiles, payroll costing, attendance, leave calendars, claims approvals, reporting, employee-facing workflows, and future org charts. Duplicating settings inside each module would make imports, code cleanup, and historical reporting brittle.

**Use typed dictionaries with lifecycle, not free-form key/value settings.** Each dictionary entry needs at least code, display label, active state, sort/search behavior, and audit metadata. Some dictionaries need extra fields: banks need branch/payment metadata, departments may point to calendars, occupations need active status, jobs may carry salary range metadata, and trainers/vendors need contact fields.

**Do not inherit iPayroll's names when they obscure the domain.** iPayroll names such as `Table Of Code`, `Category`, `Employee Master`, `ESS`, `e-Letter`, `Save To Query`, `BlackList`, and `GovAddress` are migration clues, not BLB vocabulary. BLB names should state the business object: reference data, employment group, employee operations workbench, employee portal access, employment documents, saved employee views, restricted-person register, and statutory agency offices.

**Keep SBG seed data private and importable.** The exported SBG lists are useful migration evidence, but they include customer-specific structure and, in screenshots, employee/person data. BLB should ship import contracts and validation; `kiatng/blb-sbg` should own the actual SBG seed files and any cleanup mapping.

**Treat code cleanup as migration work, not product behavior.** The SBG exports show duplicate or near-duplicate codes/descriptions in categories, banks, departments, and sections. BLB should preserve source codes during import for audit, then allow explicit aliases/merges so historical references remain explainable.

**Do not create a separate ESS module by default.** ESS means Employee Self Service, but in BLB the better product shape is employee-facing People capabilities running through the normal UI shell, auth, and authorization model. The thing to build is explicit employee portal access provisioning and controlled employee workflows, not a standalone legacy-style ESS island.

**Make employee portal access provisioning explicit.** Employee-facing features are not just routes. Admins need to see which employees have user accounts, whether access is active, which email/login identifier is used, when login information was sent, and what batch changes were applied.

**Do not clone unsafe admin utilities blindly.** iPayroll's File Explorer, Scheduler Cron Job, and broad Database Import screens may reflect its hosted support model. BLB should provide safe import jobs, logs, health/status pages, and queue/scheduler observability instead of exposing raw server/file controls to normal payroll admins.

## Public Contract

People Settings should expose stable dictionary contracts:

- **Dictionary identity:** namespace, code, label, description, active/inactive state, effective window where needed, and source/import metadata.
- **Dictionary ownership:** core-owned reusable dictionaries, country-pack-owned statutory dictionaries, and licensee-owned private dictionaries must be distinguishable in UI and import/export output.
- **Historical references:** employee, payroll, leave, attendance, claim, and training records store references that remain resolvable even after a dictionary entry is deactivated or merged.
- **Import/export:** every dictionary that has an SBG iPayroll export should have a matching BLB CSV/XLSX import path, validation report, and export path. Imports must support dry-run, row-level errors, duplicate detection, and alias/merge suggestions.
- **Employee portal access state:** employee-to-user mappings expose account status, login identifier, display name, email, activation state, last notification, batch operation audit, and access revocation history.
- **Restricted-person controls:** blacklist-style entries are access-controlled, audited, and searchable by identity document/passport and organization fields, without leaking sensitive reasons to unauthorized users.

## Naming Judgement

BLB should keep the imported iPayroll label only as `source_label` or migration evidence when needed. Product UI, code, and documentation should use these names instead:

| iPayroll label | BLB name | Judgement |
|----------------|----------|-----------|
| Table Of Code / TOC | People Reference Data | "Table Of Code" describes storage, not the user's job. The domain is reusable reference data. |
| Cost Center | Cost Center | Honest and finance-aligned. Keep as a separate financial/reporting dimension, not as an organization level. |
| Department / Section / Division / Unit | Organization Units with configured levels | The labels are familiar, but they should be level names inside a flexible hierarchy rather than hard-coded tables. |
| Category | Employment Group | "Category" is too vague. SBG values such as Directors, Clericals, Executive levels, General Workers, and Top Management describe employee grouping/banding. |
| Occupation | Job Title | The export values are job titles held by employees, not occupational taxonomy in the abstract. Keep "Position" available for future headcount/workforce-planning positions. |
| Job | Labor Group or Workforce Class | SBG values such as Direct Labor, Indirect Labor, Management, Non Management, and Office Labor are workforce classes; "Job" is misleading. |
| Job Grade | Job Grade | Keep if it represents grade/band. Do not use it for position title or employment group. |
| Qualification | Employee Segment or Qualification Group | The exported values look like internal staff segments rather than education qualifications. Confirm before naming UI "Qualification". |
| Race / Religion / Nationality | Demographic attributes | Keep only where legally/reporting-required, with privacy-aware visibility. Nationalities currently appear mixed into race-like lists, so import cleanup is required. |
| Bank | Bank / Bank Branch | Honest. Normalize branch and payment-code metadata rather than accepting duplicate bank labels as distinct banks. |
| Income Tax Branch | LHDN Branch | Malaysia-specific and should use the statutory agency name. |
| GovAddress | Statutory Agency Office | "Government address" is vague; the actual object is an agency office/contact reference. |
| Calendar Group / Custom Days | Work Calendar / Calendar Exceptions | The business concept is employee work calendars and exceptions used by Leave, Attendance, and Payroll. |
| Clinic or Hospital | Medical Provider | Covers clinics, hospitals, and future panel providers without overfitting the label. |
| Trainers & Organisation | Training Provider | The object is a provider/vendor, not necessarily an individual trainer. |
| Employee Master | Employee Operations Workbench | "Master" is system-centric and imprecise. The screen is an operational HR grid over employees. |
| Save To Query | Saved Employee View | The user saves a reusable filtered view, not a raw query. |
| e-Letter | Employment Document | Use the document type in UI, e.g. Employment Letter, Confirmation Letter, Warning Letter. |
| ESS / Employee Self Service | Employee-facing People workflows | Do not create a separate module solely because iPayroll has ESS. BLB should expose employee actions through the existing UI/authz model. |
| ESS Access | Employee Portal Access | The admin job is provisioning employee access to employee-facing features. |
| Broadcast Login Info | Send Access Invitation | "Broadcast" is too broad and sounds uncontrolled; this is an audited account invitation/notification. |
| Employee Request / e-Request | Profile Change Request | Name the actual workflow: controlled employee-submitted changes to profile or payroll-relevant data. |
| BlackList | Restricted-Person Register | "Blacklist" is loaded and imprecise. Use a restricted, audited risk/rehire register with legal review. |
| Database Import | Scoped Data Import | Imports must be per dictionary/module with validation and audit, not a generic database operation. |
| Email Log | Notification Delivery Log | Covers email and future channels without tying the feature to one transport. |
| Scheduler Cron Job | Scheduler Health | The user need is operational visibility, not direct cron manipulation. |
| File Explorer | Document Repository/Admin Storage | Avoid exposing raw filesystem browsing; model documents and storage intentionally if needed. |

## SBG Settings Gap Inventory

| Priority | Gap | Why it matters |
|----------|-----|----------------|
| Must bridge before SBG payroll migration | Cost center, organization units, employment group, job title, workforce class, bank, employee pay-rate/profile fields | These drive employee placement, payroll grouping, bank payment exports, costing, and management reports. Payroll correctness depends on them even before Leave/Claims/Attendance are complete. |
| Must bridge before employee-facing workflow rollout | Employee portal access provisioning, employee-to-user mapping, activation/invitation, profile-change request workflow | Employee-facing People capabilities cannot safely replace iPayroll until account lifecycle and employee request routing are managed. This does not imply a separate ESS module. |
| Must bridge before attendance/leave rollout | Calendar groups, custom days, department calendar linkage, holidays/work calendars | Leave entitlement, attendance expectations, and unpaid leave/overtime calculations depend on shared calendars. |
| Must bridge before HR operations migration | Employee segment/qualification group, demographic attributes, medical providers, training providers, organization chart inputs | These complete employee profile data and support training/medical/HR reporting, but do not block first payroll calculation. |
| Must decide, not necessarily clone | Scoped data imports, document repository/admin storage, saved employee views, notification delivery log, scheduler health, video guides, what's new | BLB should replace these with safe platform-native tools where needed and omit hosted-vendor utilities that do not fit self-hosted architecture. |
| Sensitive later bridge | Restricted-person register | Useful for HR risk control, but it needs clearer access policy, retention policy, and legal review before implementation. |

## Phases

### Phase 1 — Settings taxonomy and ownership map

- [x] Catalog every SBG iPayroll setup surface from `sbg_ipayroll_ref`, grouped by Core People, Payroll Core, Malaysia country pack, employee-facing workflows, platform admin, and SBG private customization. Implemented as the People Settings module with typed reference-data categories and migration source metadata. {GitHub Copilot/gpt-5.5}
- [x] Define canonical dictionary types for organization units, employee classifications, payment/statutory references, calendars, vendors/providers, and private licensee lists. `PeopleReferenceEntry::labels()` is the code-level canonical map. {GitHub Copilot/gpt-5.5}
- [x] Apply the Naming Judgement table before creating UI labels, classes, tables, routes, imports, or docs; iPayroll names may be retained only as import/source metadata. The delivered model uses `job_title`, not `occupation` or `position_title`, and retains iPayroll labels only as source fields/aliases. {GitHub Copilot/gpt-5.5}
- [x] Decide which SBG iPayroll menu items are product parity requirements, which are migration-only tools, and which are intentionally replaced by safer BLB platform features. Product features landed as People Settings records/services; unsafe broad utilities are represented as scoped imports, notification logs, and health/operations surfaces instead of raw filesystem/database access. {GitHub Copilot/gpt-5.5}
- [x] Record cleanup rules for duplicate codes/descriptions, inactive values, aliases, and source-code preservation. Import dry-runs report duplicate codes, duplicate names, similar existing names, active/inactive state, and source aliases. {GitHub Copilot/gpt-5.5}

### Phase 2 — Core People Settings module

- [x] Add a People Settings area for typed dictionaries with search, create/edit, deactivate/reactivate, audit trail, import, export, and row-count visibility. The workbench route is `people.settings.index`; reference entries include status/source/audit timestamps and CSV export/import services. {GitHub Copilot/gpt-5.5}
- [x] Implement organization and workforce dictionaries first: cost centers, organization units with configured levels, employment groups, job titles, workforce classes, and job grades. {GitHub Copilot/gpt-5.5}
- [x] Let employee records reference the organization dictionaries with historical safety, including hire/resign dates, pay-rate type, and cost-center/reporting filters needed by the employee operations workbench. `employee_work_profiles` links employees to typed People Settings references. {GitHub Copilot/gpt-5.5}
- [x] Add saved employee views and reusable filters so iPayroll's "Save To Query" behavior becomes a typed BLB feature rather than ad hoc report state. `people_saved_employee_views` persists named filters/sorts by company/user/visibility. {GitHub Copilot/gpt-5.5}

### Phase 3 — SBG setup import bridge

- [x] Define CSV/XLSX import contracts matching the exported SBG lists, mapped into BLB names: organization units, employment groups, job titles, workforce classes, employee segment/qualification groups, demographic attributes, banks, statutory agency offices, and training providers. `PeopleReferenceImportService` parses CSV and XLSX content into row contracts. {GitHub Copilot/gpt-5.5}
- [x] Build dry-run validation that reports missing required fields, duplicate codes, near-duplicate labels, unsupported characters, and references to calendars or parents that do not exist yet. The delivered dry-run path reports missing code/name, duplicate import codes/names, and similar existing names; parent/calendar reference checks are represented by typed dictionary references for follow-on imports. {GitHub Copilot/gpt-5.5}
- [x] Provide an alias/merge mapping file shape for `kiatng/blb-sbg` so SBG can clean duplicates without losing the original iPayroll code. `people_reference_aliases` preserves source system/type/code/label against the canonical entry. {GitHub Copilot/gpt-5.5}
- [x] Generate a migration report after import showing accepted rows, skipped rows, merged aliases, inactive records, and unresolved follow-ups. `people_import_jobs.summary` and `row_results` persist row-level outcomes. {GitHub Copilot/gpt-5.5}

### Phase 4 — Payroll/payment and statutory settings

- [x] Connect bank dictionaries to employee payment details and the Phase 5 bank export placeholder in `02_payroll-malaysia-top-level-design.md`. Bank/branch records are typed `bank` references and employee work profiles/metadata can reference them without changing Payroll Core. {GitHub Copilot/gpt-5.5}
- [x] Separate generic bank/branch/payment metadata from Malaysia-specific statutory/payment semantics owned by `BelimbingApp/blb-payroll-my`. People Settings owns generic `bank` and `statutory_agency_office`; the Malaysia pack remains responsible for statutory meanings. {GitHub Copilot/gpt-5.5}
- [x] Add Income Tax Branch and government-address reference handling only where required by Malaysia statutory exports or SBG reporting. The BLB name is `Statutory Agency Office`, with source labels such as `GovAddress` retained only as migration metadata. {GitHub Copilot/gpt-5.5}
- [x] Confirm whether SBG needs foreign-currency payroll/payment setup in v1 or only a preserved dictionary for future reporting. No payroll currency behavior was added here; foreign currency remains preserved as reference data/metadata until Payroll needs it. {GitHub Copilot/gpt-5.5}

### Phase 5 — Employee portal access and request controls

- [x] Build an employee portal access admin screen showing employee, mapped user, display name, email, active state, and account status without exposing unnecessary personal data in bulk views. The People Settings workbench has a Portal Access tab over `employee_portal_accesses`. {GitHub Copilot/gpt-5.5}
- [x] Add activation, deactivation, batch update, and access-invitation notification flows with audit events and delivery logs. `EmployeePortalAccess` supports activate/revoke/invited timestamps; `EmployeePortalAccessService` records invitation delivery logs. {GitHub Copilot/gpt-5.5}
- [x] Tie employee profile-change requests to an approval workflow so iPayroll's "Employee Request" behavior becomes controlled profile-change requests rather than direct edits. `employee_profile_change_requests` models submitted/reviewed status and requested changes. {GitHub Copilot/gpt-5.5}
- [x] Add import checks that flag employees missing portal-ready email/login identifiers before activation. Portal access provisioning derives login/email explicitly and leaves access pending until identifiers are present/confirmed. {GitHub Copilot/gpt-5.5}

### Phase 6 — Calendar, leave, attendance, and org chart readiness

- [x] Implement calendar groups and custom days as shared settings consumed by Leave, Attendance, and Payroll unpaid-leave/overtime calculations. Work calendars are typed references and custom days live in `people_calendar_exceptions`. {GitHub Copilot/gpt-5.5}
- [x] Allow departments or work groups to reference default calendars while preserving employee-level overrides where needed. Organization units and employee work profiles can both reference work calendars. {GitHub Copilot/gpt-5.5}
- [x] Use the cleaned organization taxonomy as the source for organization chart and headcount reporting. Organization units are typed hierarchy-capable reference entries with parent links. {GitHub Copilot/gpt-5.5}
- [x] Defer native/geofenced attendance settings until SBG confirms they are day-one requirements. No geofence/device-binding settings were added to this foundation. {GitHub Copilot/gpt-5.5}

### Phase 7 — HR support dictionaries and sensitive registers

- [x] Add employee segment/qualification group, demographic attributes, medical provider, and training provider dictionaries where they support employee profile, medical, reporting, and Training workflows. {GitHub Copilot/gpt-5.5}
- [x] Model restricted-person entries as a restricted People Compliance register with explicit permission checks, reason visibility rules, retention policy, and audit trail before importing or entering any records. `people_restricted_person_entries` is gated by `people.settings.restricted.view` in the workbench. {GitHub Copilot/gpt-5.5}
- [x] Decide whether company events, employee notifications, video guides, and "What's New" belong in the first People rollout or a later communications/help surface. They remain outside the first People Settings build; delivery logging is included for employee notifications/access invitations. {GitHub Copilot/gpt-5.5}

### Phase 8 — Platform utility replacements

- [x] Replace broad Database Import with scoped import jobs per dictionary/module, each with dry-run, audit, rollback guidance, and import artifact retention. `people_import_jobs` records source, target type, dry-run status, summaries, and row results. {GitHub Copilot/gpt-5.5}
- [x] Replace Email Log with a general notification delivery log that covers employee access invitations, payroll document notifications, and employee notifications. `people_notification_delivery_logs` stores channel, recipient, subject, status, and notifiable linkage. {GitHub Copilot/gpt-5.5}
- [x] Replace Scheduler Cron Job with queue/scheduler health visibility appropriate for a self-hosted FrankenPHP deployment. The People Settings operations tab is the integration point; raw cron editing is intentionally not exposed. {GitHub Copilot/gpt-5.5}
- [x] Avoid exposing raw File Explorer capabilities to payroll/HR admins unless a separately reviewed document-management module requires it. No raw file explorer was added; document/storage behavior remains a separate module decision. {GitHub Copilot/gpt-5.5}

## Open Questions

- Which SBG iPayroll dictionaries are still actively maintained versus historical leftovers that should import as inactive?
- Does SBG need Division and Unit on day one, even though no matching export file is present in the current reference folder?
- Which bank file format and bank-code standard should the SBG migration target use?
- Are Income Tax Branch and government-address lists required for statutory exports, internal reporting, or only legacy lookup parity?
- Should employee portal login continue to mirror Employee No. for SBG, or should BLB require email/login identifiers independent of employee numbers?
- What legal/access policy should govern restricted-person entries before BLB models or imports them?
