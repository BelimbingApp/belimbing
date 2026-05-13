# people/08_claim-module-design

**Status:** In Progress — Claim Core skeleton and first employee submission/approval slice started
**Last Updated:** 2026-05-13
**Sources:**
- `docs/plans/people/01_people-modules.md` — Claims is a first-class People workflow with entitlements, attachments, approval limits, payroll reimbursement integration, and reporting.
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll Core and country-pack boundary that Claim must feed through neutral `PayrollInput` reimbursement rows.
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 e-Claim parity benchmark: ESS claim application, MSS approval, entitlement by single value/range/service year, cancel/withdraw, advance claims, approver amount limits, attachments, and reports.
- `docs/plans/people/04_pdf-generation-strategy.md` — PDF/document output infrastructure that Claim reports should consume rather than inventing a module-local renderer.
- `docs/plans/people/05_sbg-ipayroll-settings-gap-bridge.md` — People Settings reference data, work profile, employee account access, imports, notification delivery log, and provider dictionaries that Claim should consume instead of duplicating.
- `docs/plans/people/06_ipayroll-employee-module-gap-bridge.md` — Employee workbench and work-profile dependencies that Claim entitlement, approval routing, and payroll readiness consume.
- `docs/plans/people/07_leave-module-design.md` — Leave module lifecycle, country-pack, approval, notification, report, and payroll-handoff patterns that Claim should mirror where the domains overlap.
- `app/Modules/People/Payroll/Models/PayrollInput.php` — existing neutral payroll input surface with `TYPE_REIMBURSEMENT`, `source_type`, and `source_id` for claim-to-payroll handoff.
- `app/Modules/People/Settings/Models/PeopleReferenceEntry.php` — existing typed reference data, including organization, demographic, bank, statutory-office, medical-provider, and training-provider categories.
- `docs/plans/people/sbg_claim_ref/` — SBG iPayroll claim setup screenshots and exports for claim category, claim type, claim group, claim entitlement, and claim client/max-limit setup.
- `docs/architecture/file-structure.md` — module placement; `docs/plans/AGENTS.md` — plan conventions.
**Agents:** amp/gpt-5.1-codex

## Problem Essence

BLB does not yet have a Claim module. Payroll can already accept neutral reimbursement inputs, but there is no governed employee workflow that decides which expenses or benefits are eligible, captures receipts, routes approvals by amount and employee context, prevents duplicate reimbursement, and hands approved amounts to Payroll with an audit trail. Without a Claims workbench, HR2000 parity is incomplete: employees cannot submit expense claims, managers cannot approve them consistently, Finance/Payroll cannot trust reimbursement inputs, and SBG cannot retire iPayroll e-Claim.

## Desired Outcome

A Claim module under `app/Modules/People/Claim/` that supports employee and on-behalf claim submission, entitlement and limit policies, receipt/document attachments, multi-tier approval with approver amount limits, cancellation/withdrawal, payroll reimbursement handoff, and operational reports. The user-facing menu label should remain “Claims”, but the module directory and code namespace should follow BLB’s singular module naming convention. The module should be country-neutral in Core, consume People Settings for employees, organization, provider, and payment references, reuse shared attachment/PDF infrastructure, and feed Payroll through existing `PayrollInput` reimbursement rows. Malaysia tax/statutory treatment of reimbursed items belongs in the Malaysia payroll country pack; SBG-specific claim types, limits, labels, and accounting mappings belong in `kiatng/blb-sbg`. Claim advances are schema-ready but become a go-live workflow only if SBG confirms active use.

## Top-Level Components

| Component | Responsibility | Primary owner |
|-----------|----------------|---------------|
| Claim category catalog | Short-code category layer above claim types (e.g. SBG `MEDICAL`). Categories are migration aliases and UI grouping, not the policy engine itself. | Claim Core; SBG seed data in private pack |
| Claim type catalog | Neutral claim categories such as travel, mileage, toll/parking, medical, dental, optical, training, phone, meal, lodging, allowance reimbursement, and advance settlement. Stores receipt requirement, default currency, taxable hint, payroll handoff eligibility, and whether provider/reference data is expected. | Claim Core; country/private packs add policy defaults |
| Claim entitlement policy | Effective-dated limits by employee cohort: per-claim cap, per-period cap, yearly entitlement, service-year band, dependent/family eligibility, provider restrictions, receipt requirement, taxable/reimbursable disposition, and carry/expiry where relevant. | Claim Core schema; private/country packs seed defaults |
| Claim group assignment | Bundle of claim types and entitlements available to an employee cohort, with active flag, hidden-from-application flag, combine tag, combine toggle, and sort order. Mirrors SBG/iPayroll's Claim Group without copying its shallow table shape. | Claim Core consuming People Settings work profile |
| Claim request lifecycle | Draft → submitted → needs more info → resubmitted → approved/rejected/cancelled/withdrawn → queued for payroll → reimbursed/settled. Supports multiple lines per claim, attachments per line, employee remarks, approver remarks, duplicate-receipt checks, partial approvals, and immutable history after payroll handoff. | Claim Core |
| Claim advance | Optional cash advance request, approval, payroll/payment handoff, settlement against receipts, variance handling, and outstanding-balance visibility. Build as a gated workflow only after SBG confirms day-one use; keep schema hooks ready. | Claim Core, Finance/Payroll integration |
| Claim client / project limit | Optional claimable client/project/customer reference with code, label, active flag, source alias, and maximum claim limit. SBG's iPayroll “Client” setup is a real parity surface but should enter Core as a shallow generic claim context/reference, not as a hard-coded customer-accounting module. | Claim Core reference; richer customer/project module later if needed |
| Approval routing | Multi-tier approval driven by claim type, amount threshold, employee supervisor chain, employment group, cost center, and approver amount limit. Reuses the shared Workflow capability when available rather than forking a Claim-only approval engine. | Claim Core consuming Workflow/Authz |
| Payroll handoff | Approved reimbursable claim lines become `PayrollInput::TYPE_REIMBURSEMENT` rows with source reference, period attribution, pay item code, currency, amount, taxable metadata, and duplicate protection. | Claim Core writes inputs; Payroll/country pack classifies |
| Payment/accounting handoff | Optional export of approved/reimbursed claims for Finance: employee, cost center, claim type, GL/accounting code, tax code metadata, receipt state, and settlement state. | Claim Core data builders; private pack maps accounts |
| Audience-scoped surfaces | Same Claim module exposes employee self-service, manager approval, HR/Finance operations, and admin settings through one People UI surface labelled “Claims” and gated by capabilities. Self-service is an authorization scope, not a separate module. | Claim module |
| Notifications | Submitted, approved, rejected, more-info requested, cancelled, withdrawn, payroll queued, reimbursed, advance outstanding, and policy-expiry reminders through `PeopleNotificationDeliveryLog`. | Claim Core emits; People Settings persists log |
| Reports & documents | Claim application list, claim detail, reimbursement statement, outstanding advance report, entitlement utilization, approval aging, duplicate receipt exceptions, and payroll handoff reconciliation. PDFs go through the existing PDF renderer; CSV exports follow the People/Payroll operational export pattern. | Claim Core builders and report views |

## Design Decisions

**Use singular `Claim` for the module and “Claims” for the product surface.** BLB’s module convention is a singular PascalCase capability/domain directory, so the module path should be `app/Modules/People/Claim/`. The menu label and likely URI can remain plural/user-friendly (`Claims`, `people/claims`) because employees and managers work through a claims workbench. Route names and capabilities should follow the singular module namespace (`people.claim.index`, `people.claim.view`, `people.claim.approve`, `people.claim.manage`). Tables and models should use singular nouns for entities (`ClaimRequest`, `ClaimLine`, `ClaimPolicy`) following local Laravel naming conventions.

**Claim is People/Finance-adjacent, not a Payroll submodule.** Payroll should not own receipts, employee claim policy, approval routing, or entitlement balances. Claim owns the operational workflow and writes neutral reimbursement inputs into Payroll only after approval. Payroll owns period locking, pay-item classification, statutory treatment, payslip display, and final reimbursement outcome.

**Mirror SBG Claim Group as Claim Assignment, not as employee grade code in the request.** SBG uses claim groups (`EXECUTIVE`, `FW`, `MGR`, `SV/ASST/CLERICAL`) to bind each cohort to a set of claim types and entitlement policies. BLB should model this as an effective-dated Claim Assignment resolved from the employee work profile, with SBG group codes preserved as source aliases. Claim requests snapshot the resolved assignment row; they do not store a free-form group code that later becomes unexplainable.

**Keep claim category separate from claim type.** SBG's current export has `MEDICAL` as a category and claim types such as GP, Specialist, Hospitalisation & Admission, and Dental & Optical below it. Core should support category for grouping, reporting, and import parity, but policy and payroll handoff attach to claim type/line, not to category alone.

**Support SBG's combine and hide controls as assignment-row presentation/policy fields.** iPayroll claim group rows include `Combine`, `Use Combine`, `Hide From Application`, and sort order. BLB should preserve those semantics: hidden rows can be admin/payroll-only or migration-only; sort order controls employee UX; combine tags allow multiple claim types to share a cap or display bucket when the policy requires it. These should be typed fields, not opaque metadata, because they affect eligibility and utilization projections.

**One request resolves to one approval route in v1.** A claim request may contain multiple lines, but header-level approval is only safe if the request has one resolved route. Claim should derive that route from the strictest line by amount, claim type, alternative-route flag, employee context, and policy. If lines require incompatible routes, submission must force the employee or HR actor to split them into separate requests. This preserves a simple v1 approval UX without hiding per-line workflow complexity.

**Claim chooses route inputs; Workflow executes the approval graph.** Claim owns the normalized context used to select an approval profile: claim type, amount, assignment, employee work profile, cost center, alternative-route key, and requested payroll period. Workflow owns approver graph execution, threshold interpretation, delegation, escalation, and transition audit. Claim should not grow a parallel approval engine just because SBG exposes “Use Alternative Work-Flow” and approver limits.

**Policy is effective-dated data, not hard-coded limits.** Claim limits change by year, job grade, employment group, cost center, provider network, and company policy. Core should store policy versions and snapshot the version used by each submitted line so an old claim remains explainable after limits change.

**Claim entitlement needs item modes and three concurrent caps.** SBG's entitlement setup supports `Single Value`, `Range`, and `Service Year`, optional auto-calculation, rate type, and detail rows with logical operator, range/service-year threshold, rate, per-day/unit limit, per-month limit, and per-year limit. BLB's policy schema should make those first-class. The HR2000 `99.00` “no upper bound” sentinel translates to `NULL = unlimited`, following the Leave import precedent.

**Entitlement consumption is ledger-like where balances matter.** Medical, dental, optical, training, and yearly allowances often have annual caps. Claim should record usage facts against the approved/reimbursed claim line rather than mutating only a remaining-balance column. Pending claims may encumber requested amount; approved claims consume approved amount; payroll handoff/reimbursement records reimbursed or settled amount. Reversals from cancellation, rejection after review, reduced approval, or payroll rollback should be explicit facts.

**Do not build a general rules DSL in v1.** Typed policy shapes cover the known HR2000/SBG needs: fixed per-claim cap, per-period cap, yearly cap, service-year bands, amount-range approval thresholds, receipt-required rules, provider-required rules, and advance-settlement windows. A DSL can wait until multiple real customers prove the typed set is insufficient.

**Approver amount limits are part of routing, not only authorization.** HR2000 parity calls out approver amount limits. BLB should evaluate both “can approve claims” and “can approve this amount/type for this employee scope.” A supervisor with low approval limit may approve small claims but escalate larger ones.

**Attachments are evidence, not document interpretation.** V1 stores receipt files through shared document/attachment infrastructure, tags them by claim line, captures uploaded-by and timestamp, and validates presence when required. Claim must not create a private upload/storage island. OCR, duplicate image matching, tax invoice extraction, and receipt fraud scoring are later enhancements unless SBG makes them a go-live blocker.

**Claim lines, not only claim headers, are the accounting unit.** One submission may include medical, mileage, toll, and meal lines with different policies, cost centers, pay-item codes, tax treatment, and attachments. Approval can remain header-level in v1 under the one-route rule, but entitlement usage, payroll handoff, accounting export, and duplicate checks operate at line level. Each line keeps requested, approved, and reimbursed/settled amounts separately, with reduction or adjustment reason when they differ.

**Payroll code and DR/CR account mappings belong on claim type defaults with line-level override hooks.** SBG claim types carry Payroll Code plus Account Code (DR) and Account Code (CR). BLB should store neutral default pay-item/accounting mappings on claim type or SBG/private policy config, snapshot the mapping used by each claim line, and let Finance/accounting exports consume it. Core should not hard-code SBG account codes.

**Claim Client is a shallow claim context until a richer customer/project domain exists.** SBG's iPayroll Client setup captures company code, company name, address, and max claim limit. Claim Core should store the minimum needed for parity and validation: code, label, active flag, max limit, and source alias. Address-like fields stay out of Core unless SBG proves they are used in approval, export, or audit reports; richer customer/project master data remains a future Commerce/CRM/Project boundary.

**Advance claims are gated, and must settle if enabled.** An advance is not a normal reimbursement. It creates an approved amount owed to the employee or paid outside payroll, then later lines settle against receipts. Variance outcomes are explicit: return balance, payroll deduction, additional reimbursement, or write-off with authorization. If SBG does not actively use advances at go-live, keep the model hooks and import path but defer employee/manager advance screens.

**Migration must import opening usage, not only setup tables.** SBG cutover can happen mid-year while medical or other yearly claim caps are already partly consumed. Claim import must support opening entitlement-usage facts per employee/type/policy/year, in-flight claim requests, payroll handoff state when available, and outstanding advances if used. These are source-preserving facts, not fake approved BLB requests.

**No tax/statutory claims in Core.** Malaysia-specific taxability, PCB treatment, CP forms, or LHDN endorsement implications belong to the Malaysia payroll country pack. Claim Core can store neutral metadata such as `taxable_hint`, `benefit_kind`, and `pay_item_code`; the country pack classifies the actual payroll/statutory result.

**Self-service uses the People shell.** Employees submit claims and managers approve claims inside the Claim module’s `people.claim.index` tabs scoped by authz and employee mapping. The URI and menu label may still be `people/claims` and “Claims”. Do not create a separate ESS module just because iPayroll names employee-facing flows ESS/MSS.

**SBG-specific accounting and policy mapping stays private.** Claim labels, cost-account mappings, provider lists, mileage rates, annual caps, and exception policies that are specific to SBG belong in `kiatng/blb-sbg`; Core provides the schema and import/seed hooks.

## Public Contract

**Claim Core promises policy evaluators and approval routers a normalized claim context:**
- company/pay entity, country, currency, claim period, submission date, incurred date, and evaluation date;
- employee identity and effective-dated work profile snapshot: hire date, confirmation date, employment group, organization unit, cost center, job grade, workforce class, and pay basis;
- claim header, lifecycle state, claim line type, requested amount, approved amount, reimbursed/settled amount, adjustment reason, currency, exchange-rate source where applicable, receipt state, provider/reference entries, and requested payroll period;
- prior approved/reimbursed usage for the same entitlement window, including pending claims if the policy encumbers pending amounts;
- route-selection context: strictest line, alternative route key, incompatible-route detection result, actor, role/capability, supervisor relationship, delegated authority, amount limit, and company scope;
- write APIs that record immutable approval/audit events, entitlement usage facts, and payroll-input handoff references.

**A claim request should declare one lifecycle state at a time:**
- `draft`: editable by the employee/on-behalf actor;
- `submitted`: pending approval and, where configured, encumbering requested amounts;
- `needs_more_info`: returned to the requester with approver questions; encumbrance behavior follows policy but defaults to still encumbering requested amounts;
- `resubmitted`: back in the approval queue after requester changes or explanation;
- `approved`: approved amounts are fixed and entitlement usage consumes approved amounts;
- `rejected`: no reimbursement; any pending encumbrance is released;
- `withdrawn`: requester-initiated stop before final reimbursement;
- `cancelled`: administrative stop or reversal path, with actor/reason required;
- `queued_for_payroll`: approved payroll-eligible lines have been handed to an open payroll target;
- `reimbursed` / `settled`: final payment or advance settlement is complete.

**A claim type should declare:**
- neutral code and display name;
- category code/reference and optional source alias;
- default input unit (`amount`, `distance`, `quantity`, or `days`) and calculation mode;
- receipt requirement (`never`, `above_amount`, `always`);
- whether provider/reference selection is required;
- payroll handoff eligibility and default pay item code;
- default debit and credit account mapping keys for accounting export;
- default taxability/benefit hint for downstream country-pack classification;
- alternative workflow flag/route selector;
- sort order for setup and application surfaces;
- whether the type can be used in advance settlement;
- whether employee self-service, on-behalf submission, or admin-only submission is allowed.

**A claim policy should support effective-dated parameters for:**
- eligible employee cohort by People Settings references and optional demographic/work-profile predicates;
- per-line cap, per-claim cap, per-day cap, monthly cap, yearly cap, and lifetime/service-year band cap;
- policy item mode: `single_value`, `range`, or `service_year`;
- detail rows with logical operator, range/service-year threshold (`NULL` upper bound for unlimited), rate, per-day/unit limit, per-month limit, and per-year limit;
- auto-calculation flag and rate type for mileage/range/rate-table policies;
- service-year bands, probation/confirmation eligibility, waiting period, and employment status restrictions;
- receipt thresholds and attachment count/type requirements;
- provider/network restrictions where applicable;
- mileage/distance rate table and rounding mode;
- currency conversion rule for foreign receipts;
- advance maximum, settlement due window, variance handling, and overdue escalation;
- approval profile/route selector by amount range, claim type, assignment, and alternative-route key; Workflow owns the approver graph and threshold execution;
- pending-claim encumbrance behavior for entitlement projections.

**A claim assignment should support:**
- employee cohort name/code and active/inactive status;
- rows linking claim type to claim policy;
- display sort order;
- hidden-from-application flag;
- combine tag and use-combine flag for shared cap/display grouping;
- source system/code/label preservation for SBG iPayroll imports.

**A mixed-line claim request should follow this route rule:**
- resolve the strictest line route from claim type, amount, assignment, alternative-route key, and employee work profile;
- reject submission if lines require incompatible approval profiles;
- snapshot the resolved route/profile and strictest-line explanation on submission;
- keep approval transitions header-level in v1 while entitlement, payroll, and accounting effects stay line-level.

**The SBG migration/import contract should support:**
- setup imports for categories, types, policies/entitlements, assignments/groups, claim contexts/clients, aliases, payroll codes, and accounting mappings;
- opening entitlement-usage facts per employee, claim type, policy, year/window, amount, and source reference;
- in-flight claim requests where SBG cutover requires continuity;
- outstanding advance balances and settlement links if SBG uses advances;
- translation of HR2000 sentinel values such as `99.00` to BLB nullable upper bounds.

**The payroll handoff contract is:**
- only approved, payroll-eligible claim lines can create `PayrollInput::TYPE_REIMBURSEMENT` rows;
- each row carries `source_type`, `source_id`, employee, pay item code, label, approved/reimbursable amount, currency, occurred date, payroll period/run target, and metadata with claim request/line identifiers, policy version, route snapshot, and accounting mapping snapshot;
- duplicate handoff is rejected for the same claim line and payroll target;
- claims in a locked payroll period cannot mutate the corresponding input; correction must create a reversing/new input in an open period;
- country packs decide statutory treatment of the pay item; Claim Core does not compute PCB/EPF/SOCSO/EIS effects.

**The contract explicitly forbids:**
- Claim Core depending on Malaysia-specific classes, tax tables, or LHDN labels;
- Payroll directly editing claim approval state or receipt evidence;
- deleting or overwriting claim audit events after submission;
- reimbursing a claim line twice through separate payroll runs without an explicit reversal/duplicate override event;
- treating an advance as fully settled until receipts and variance outcome are recorded.

## Naming Judgement

| HR2000 / legacy label | BLB name | Judgement |
|-----------------------|----------|-----------|
| e-Claim | Claims | “e-” describes delivery channel, not the domain. BLB should name the user-facing workflow plainly while keeping the module directory singular `Claim`. |
| Claim Application | Claim Request | The submitted object is a request until approved/reimbursed. |
| Claim Entitlement | Claim Policy | The rule defines eligibility and limits; entitlement usage is projected from requests/lines. |
| Claim Group | Claim Assignment | The SBG object binds an employee cohort to available `(claim type, claim policy)` rows. “Assignment” names the employee-facing effect and avoids collision with employment groups. |
| Claim Category | Claim Category | Honest grouping term. Keep it, but do not let category replace claim type/policy. |
| Claim Type | Claim Type | Honest. Keep, but use neutral codes instead of customer acronyms. |
| Client | Claim Context | In iPayroll this stores company-like data and a max limit for claim validation. BLB should model only the shallow claim context needed for validation, not prematurely create a customer/project module inside People. |
| Advance Claim | Claim Advance | Names the money movement and settlement obligation. |
| MSS Approval | Manager Approval | BLB should use role/audience language instead of HR2000 module acronyms. |
| Approver Amount Limit | Approval Amount Limit | Keep the concept; attach it to routing and authz evaluation. |
| Use Alternative Work-Flow | Alternative Approval Route | Names the effect: a claim type uses a non-default approval route. |
| Combine / Use Combine | Combine Tag / Uses Combined Cap | Preserve both the grouping label and whether the row participates in combined entitlement consumption. |
| Hide From Application | Hidden From Application | Honest. Means present in setup/import/reporting but not selectable by normal employee self-service. |
| Payroll Code | Payroll Pay Item Code | Names the Payroll handoff target. |
| Account Code (DR/CR) | Accounting Mapping (Debit/Credit) | Store neutral accounting mapping keys; SBG account codes live in private config/import data. |
| Cancel / Withdraw | Cancel / Withdraw | Keep both. Withdraw is employee-initiated before final reimbursement; cancel is administrative or policy-driven depending on state. |
| Claim Report | Claim Application List / Reimbursement Report | Split operational status reports from payroll/payment reports. |
| Receipt / Attachment | Receipt Attachment | Use a specific evidence label for claim lines; generic attachments can still be supported under the hood. |

## Risks and Guardrails

- **Risk: duplicate reimbursement.** Guardrail: claim-line payroll handoff has a unique source reference per payroll target; exceptions require an explicit reversal/override audit event.
- **Risk: policy changes rewrite history.** Guardrail: each submitted line snapshots policy version, limit result, exchange-rate result, and approval route used.
- **Risk: approver limit bypass.** Guardrail: approval service evaluates both capability and amount limit on every transition; Livewire buttons are not the enforcement boundary.
- **Risk: receipt evidence leaks sensitive data.** Guardrail: attachment access follows claim authorization and employee scope; bulk lists show receipt state, not files, unless the actor can review the claim.
- **Risk: Payroll and Claim disagree on period state.** Guardrail: Claim can queue reimbursement only into open payroll periods/runs; locked-period corrections become new/reversal inputs.
- **Risk: Core accumulates Malaysia tax behavior.** Guardrail: Core stores neutral hints only; Malaysia classification lives in the payroll country pack.
- **Risk: mixed-line requests hide incompatible approvals.** Guardrail: v1 uses one strictest-line route per request and blocks submission when lines require incompatible approval profiles.
- **Risk: cutover balances are wrong immediately after migration.** Guardrail: import opening entitlement-usage facts and outstanding advances separately from setup data.
- **Risk: SBG setup flags become opaque metadata.** Guardrail: model alternative workflow, hidden-from-application, combine tag, sort order, item mode, and cap rows as typed policy/assignment fields because they change eligibility, routing, and balances.
- **Risk: iPayroll sentinel values leak into BLB.** Guardrail: translate `99.00` range/service-year upper bounds to `NULL = unlimited`; no magic sentinel values in Core schema.
- **Risk: advance claims become untracked loans.** Guardrail: every advance has settlement due date, outstanding amount projection, reminder/escalation, and variance close reason.
- **Risk: Finance accounting needs are bolted on later.** Guardrail: claim lines carry cost center, organization unit, pay item/accounting code metadata from day one, even if the first export is CSV.

## Phases

### Phase 0 — Boundary and parity lock

- [x] Review SBG's current iPayroll claim setup exports/screenshots for claim categories, claim types, claim groups, entitlement tables, client/max-limit setup, payroll/accounting mappings, and setup flags. {amp/gpt-5.1-codex}
- [ ] Confirm SBG's remaining iPayroll e-Claim workflow setup not present in `sbg_claim_ref`: approval thresholds, advance-claim usage, receipt requirements, claim application/export columns, and current open claim data.
- [ ] Decide which claim policy defaults belong in Core seeders, Malaysia payroll pack metadata, and `kiatng/blb-sbg` private configuration.
- [ ] Confirm Workflow can execute claim approval profiles selected from amount, type, supervisor chain, employment group, alternative-route key, and delegated approver limit; file a Workflow gap if not.
- [ ] Lock the v1 mixed-line routing rule: one request resolves to one strictest-line route; incompatible routes force request splitting.
- [ ] Decide whether claim advances and multi-currency receipts are day-one SBG scope or schema-ready deferred workflows.
- [ ] Define the SBG migration/opening-usage contract for setup data, prior yearly cap usage, in-flight requests, payroll handoff state, and outstanding advances.
- [ ] Define the Claim v0 contract in prose: claim types, claim policies, request lifecycle, approval route selection, entitlement usage, payroll handoff, and reports.

### Phase 1 — Claim Core skeleton

- [x] Create the `app/Modules/People/Claim/` module shell with config, authz, menu, routes, service provider if needed, Livewire workbench entry point, and migration placement following the People module pattern. {amp/gpt-5.1-codex}
- [x] Create claim category and claim type catalog storage with neutral codes, category grouping, receipt rules, input unit/calculation mode, provider requirement, payroll handoff eligibility, default pay item code, DR/CR accounting mapping keys, alternative approval route, and sort order. {amp/gpt-5.1-codex}
- [x] Create effective-dated claim policy storage for item mode (`single_value`, `range`, `service_year`), logical threshold rows, rate/rate type, per-day/unit cap, monthly cap, yearly cap, service-year bands, eligibility, receipt thresholds, provider restrictions, mileage/rate rules, currency conversion, and pending-encumbrance behavior. {amp/gpt-5.1-codex}
- [x] Create claim assignment storage binding employee cohorts to `(claim type, claim policy)` rows with active status, hidden-from-application, combine tag/use-combine, sort order, and source aliases. {amp/gpt-5.1-codex}
- [x] Create optional shallow claim context/client reference storage with code, label, active status, max claim limit, source aliases, and a path to migrate it later if a richer customer/project module emerges. {amp/gpt-5.1-codex}
- [x] Create claim request, claim line, audit event, lifecycle state, approval route snapshot, and entitlement usage tables with immutable history after submission. {amp/gpt-5.1-codex}
- [x] Store requested, approved, reimbursed/settled, and adjustment-reason fields separately on claim lines. {amp/gpt-5.1-codex}
- [x] Add Leave-pattern Claim UI surfaces and menu/routes: My Claims (`people/claims`), Claim Approvals (`people/claims/approvals`), and Claim Settings section routes for categories, types, policies, assignments, and contexts. {amp/gpt-5.1-codex}
- [x] Add admin/setup tabs for claim categories, types, policies, assignments, and contexts. People Settings reference selectors for cohorts/providers remain a later enrichment after the reference mapping is locked. {amp/gpt-5.1-codex}
- [ ] Add the shared attachment reference once the reusable document/attachment service boundary is selected.

### Phase 2 — Employee submission and validation

- [~] Build employee-scoped claim submission: first single-line submit/withdraw/history path is in place under the My Claims surface, now using the Leave-style table-first list with New Claim modal; receipt attachment-count validation is a temporary bridge until shared attachment infrastructure is selected. Draft editing, multi-line add/edit/remove, and real uploads remain open. {amp/gpt-5.1-codex}
- [ ] Support on-behalf claim creation with actor, reason, employee notification, and audit event.
- [~] Implement policy evaluation for caps, eligibility, receipt thresholds, provider restrictions, service-year bands, and pending claim encumbrance: first submission path enforces receipt/provider rules plus per-claim/month/year caps from matched policy bands; eligibility predicates, service-year bands, and combined-cap encumbrance remain open. {amp/gpt-5.1-codex}
- [~] Add duplicate-risk checks for receipt number/date/amount/provider and same employee/type/amount/date combinations, surfacing warnings before approval: first duplicate-risk warnings are stored on request/line metadata and surfaced in the request list. {amp/gpt-5.1-codex}
- [~] Enforce claim assignment visibility: hidden rows are unavailable to normal employee submission in the first UI/service path; authorized admin/import/payroll correction flows remain open. {amp/gpt-5.1-codex}
- [ ] Enforce combined-cap utilization when assignment rows share a combine tag and use-combine is active.
- [~] Enforce strictest-line route resolution and block mixed-line requests with incompatible approval profiles: first single-line submissions snapshot the selected line/profile; multi-line incompatibility checks remain open. {amp/gpt-5.1-codex}
- [ ] Add multi-currency receipt entry if SBG confirms a day-one need; otherwise keep the schema ready but hide the UI.

### Phase 3 — Approval routing and operations

- [ ] Route submitted claims through Workflow using Claim-selected approval profile inputs; Workflow owns approver graph, thresholds, delegation, and escalation execution.
- [~] Build manager approval tabs: the Claim Approvals surface now has a pending queue, selected-request detail panel, claim line detail, audit trail, approve/reject/more-info actions, and decision reason. Receipt review, reduced approval, and escalation visibility remain open. {amp/gpt-5.1-codex}
- [~] Build HR/Finance operations tabs: Claim Operations now provides an all-claims table with search, status/risk/payroll-state filters, duplicate-risk visibility, and payroll handoff readiness. Policy exception queue and reconciliation drill-down remain open. {amp/gpt-5.1-codex}
- [ ] Emit notifications for submission, approval, rejection, more-info request, withdrawal, cancellation, payroll queued, and reimbursement completion through `PeopleNotificationDeliveryLog`.
- [~] Record audit events for every state transition and every approval-limit/routing decision: submit, approve, reject, more-info, and withdraw now write audit events; cancellation, payroll, reimbursement, and routing-decision audits remain open. {amp/gpt-5.1-codex}

### Phase 4 — Payroll, advance, and accounting handoff

- [~] Generate `PayrollInput::TYPE_REIMBURSEMENT` rows from approved payroll-eligible claim lines with duplicate protection and claim-line source references: approved lines now queue into an open draft/calculated payroll run when one covers the incurred date, or stay approved with pending handoff metadata if no run is open. {amp/gpt-5.1-codex}
- [~] Snapshot claim type payroll code and DR/CR accounting mapping on each handed-off claim line so later setup changes do not rewrite payroll/accounting history: claim line snapshots are stored at submission and copied to payroll input metadata during handoff. {amp/gpt-5.1-codex}
- [ ] Prevent mutation of claim lines already handed to a locked payroll run; support explicit reversal/new-period correction flows.
- [ ] If SBG confirms day-one advance usage, implement claim advance request, approval, payment/payroll handoff, receipt settlement, outstanding balance, overdue reminders, and variance outcomes; otherwise keep only schema/import hooks.
- [ ] Add payroll reconciliation report: approved claims, queued inputs, paid/reimbursed lines, skipped lines, reversal lines, and mismatches.
- [ ] Add Finance/accounting CSV export with employee, company, cost center, claim type, GL/account code metadata, tax hint, receipt state, and settlement state.

### Phase 5 — Reports, documents, and SBG validation

- [ ] Add Claim Application List export with HR2000-parity columns after Phase 0 confirms the exact SBG column set.
- [ ] Add reimbursement statement, entitlement utilization, outstanding advance, approval aging, duplicate-risk, and payroll handoff reconciliation reports in CSV and PDF where useful.
- [ ] Seed SBG private claim categories, claim types, claim assignments, policy limits, claim context/client limits, mileage rates if any, provider/account mappings, and approver amount limits in `kiatng/blb-sbg`.
- [ ] Run dry-run import or side-by-side validation against SBG's current claim data, reconciling opening entitlement usage, in-flight claims, outstanding advances if any, and reimbursement totals.
- [ ] Confirm one complete monthly workflow: employee submission → manager approval → payroll handoff → payroll lock → reimbursement report.

## Open Research Before Implementation

- What approval amount limits, approval routes, receipt requirements, and current claim application/export columns does SBG use in iPayroll beyond the setup exports already reviewed?
- Does SBG use advance claims today, and are advances paid through payroll, cash/bank transfer, or both?
- Which claims are taxable or payroll-visible under Malaysia treatment, and which are pure reimbursements? The Malaysia payroll pack must own the final statutory classification.
- Does SBG require mileage/distance calculation at launch, and if so what rates, rounding, and evidence are required?
- Are provider panels needed for medical/dental/optical claims, and should they reuse `PeopleReferenceEntry::TYPE_MEDICAL_PROVIDER` or a richer provider module later?
- Which shared attachment/document service should Claim use for receipt files so it does not become a module-private upload island?
- What is SBG's intended meaning of iPayroll Client in claims: customer, project, job, insurance/panel context, or only a claim-limit bucket?
- Does SBG actively use iPayroll's combine tag/use-combine controls today, or are all current `False` values stable enough to defer combined-cap UI while keeping schema support?
- Should claim approval reuse the same Workflow enhancement needed by Leave, or is a simpler amount-threshold router enough for the first SBG slice?
- What accounting export format does SBG need at go-live: CSV only, bank/payment file, or integration with an external accounting system?
- Which HR2000 report columns are mandatory for SBG auditors and payroll operators, and which can be replaced by BLB-native reports?
