# base-audit-source-history-rollout.md

**Status:** In progress — the neutral bridge is live on Wave 1 plus IT/Quality, Workflow, Outbound Exchange, and Authz Role Wave 2 detail pages; the shared drawer has dense-history search/sort/progressive loading; workflow transitions now record semantic actions. Manual browser verification, route-specific page weights, page-by-page field strategy closeout, and later waves remain open.
**Last Updated:** 2026-06-20
**Sources:**
- `docs/plans/base-audit-log-usability.md` — completed User-management audit usability rollout and current Codex UI/UX workstream.
- `app/Base/Audit/AGENTS.md` — zero-coupling Audit module rules, subject metadata contract, semantic action boundary, and UI ownership.
- `app/Base/Audit/Livewire/AuditLog/SourceHistory.php` — current local-history Livewire island and authorization gate.
- `app/Base/Audit/Services/AuditSourceHistory.php` — current mutation lookup by subject metadata plus direct auditable fallback.
- `resources/core/views/livewire/admin/audit/source-history.blade.php` and `resources/core/views/livewire/admin/audit/partials/source-history-drawer.blade.php` — current trigger and drawer rendering.
- `resources/core/views/components/ui/record-history.blade.php` — neutral page-facing bridge that owns Audit Livewire mounting, authorization gating, and full-history URL generation.
- `resources/core/views/livewire/admin/users/show.blade.php`, `resources/core/views/livewire/admin/companies/show.blade.php`, `resources/core/views/livewire/admin/employees/show.blade.php`, `resources/core/views/livewire/admin/addresses/show.blade.php`, and `app/Modules/People/Employees/Views/livewire/people/employees/show.blade.php` — first visible rollout pages using the bridge.
- `tests/Feature/Audit/AuditLogUiTest.php` and `tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php` — bridge visibility, authorization, and subject metadata coverage.
- Oracle architecture check in the current Amp thread — lightweight review of rollout boundaries and risks.
**Agents:** amp/gpt-5, codex/gpt-5

## Problem Essence

The User-management rollout proved the record-level History drawer, but its current integration is a one-off that directly references Audit from the page. A system-wide rollout must avoid module-to-Audit coupling, inconsistent placement, partial histories, and repeated UI churn while Codex finishes the shared UI/UX polish.

## Desired Outcome

High-value record/detail pages expose a consistent History trigger that answers what changed, who changed it, when it happened, and which trace caused it. Modules adopt the drawer through a neutral framework-owned bridge, with each page marked complete only after direct and related subject coverage, redaction/noise review, authorization, trace behavior, and page-weight impact are proven.

## Current Baseline

- `admin/users/{user}` is the working precedent: it passes a title, user subject, direct auditable fallback, and source capability to the neutral `x-ui.record-history` bridge, which owns Audit Livewire mounting and full-history URL generation.
- `SourceHistory` currently requires both `admin.audit.log.list` and the page's source capability before rendering or opening history.
- `AuditSourceHistory` reads mutation rows by `subject_name` / normalized string `subject_id` / optional `subject_identifier`, with direct `auditable_type` / normalized string `auditable_id` fallback for old rows and direct model changes.
- Existing subject metadata coverage now includes the first-wave direct record subjects and several high-value related records. Visible Wave 1 bridge integration is in place for parent, People, and Commerce item detail pages, and IT Ticket/NCR/SCAR, Workflow, Outbound Exchange, and Authz Role detail pages now have Wave 2 coverage. Workflow transitions also record retained semantic actions so trace timelines can explain the user intent behind status changes. Remaining gaps are page-by-page field strategy review, manual verification/page-weight proof, and later-wave page selection.
- Codex has finished the shared UI/UX improvement. Amp implemented the neutral bridge, User-page migration, first-wave page integration, and shared dense-history drawer behavior; automated evidence is captured below, while manual browser spot checks remain open.

## Top-Level Components

### Neutral record-history bridge

Create a small framework-owned presentation bridge, recommended as an `x-ui.record-history`-style component, that accepts only plain primitives from pages and is the only reusable page-integration point allowed to reference the Audit Livewire island, Audit routes, or full-history search syntax.

### Audit source-history read model

Keep `AuditSourceHistory` Audit-owned. It remains responsible for bounded mutation lookup, direct historical fallback, presenter formatting, and trace affordances.

### Subject metadata and field strategy

Each owning model supplies duck-typed `getAuditSubject()` and, where parent pages need related changes, `getAuditSubjectEntries(...)`. Redaction, exclusion, and truncation are reviewed before local history exposes those diffs outside the global Audit pages.

### Page rollout inventory

Maintain a page matrix in this plan covering route, owning model, source capability, subjects, related records, semantic action need, risk notes, and evidence. The matrix is the adoption queue; ad hoc page additions are not considered complete.

### Semantic action enrichment

Use `App\Base\Foundation\Contracts\SemanticActionRecorder` only for workflows where mutation diffs cannot explain the user's intent, such as assignments, approvals, imports, status transitions, and bulk operations. Sidebar placement does not require semantic actions unless the page would otherwise be misleading.

## Design Decisions

### Use a sibling rollout plan

`base-audit-log-usability.md` remains the completed usability/User rollout source. This plan owns the system-wide adoption contract, page inventory, wave tracking, and cross-module risks so the completed plan does not become an endless omnibus.

### Stabilize the bridge before broad page edits

The current direct `@livewire(\App\Base\Audit\...)` pattern is acceptable as proof on the Core/User page, but non-Core modules must not copy it. The first implementation step is the neutral bridge, then migrating the User page to that bridge as the regression test.

### Roll out only to stable record contexts

The History trigger belongs on detail/show pages and one-record inspectors where the user is clearly viewing one source record. It does not belong on every list row, dense grid cell, settings page, or bulk workbench by default.

### Define page completion by truthfulness, not by trigger presence

A page is complete only when direct mutations, important related mutations, sensitive/noisy fields, trace links, empty state, capability gating, and bounded rendering have been verified. A trigger that shows only a narrow direct row diff while omitting important related changes is partial, not complete.

### Keep authorization conservative

The rollout keeps the current requirement that viewers have both `admin.audit.log.list` and the page's source capability. Trace timelines may reveal other rows in the same trace, so local history is still an auditor/admin feature until a separate narrowed authorization model is designed.

### Separate old-row fallback from future semantic capture

Direct auditable fallback is required for old rows and direct model changes. Old action rows cannot gain missing semantics after the fact; future workflows that need user-intent language must record semantic actions at the workflow boundary.

### Normalize Audit record IDs as strings

Audit mutation `auditable_id` and `subject_id` values are normalized bounded strings. Numeric records keep their current behavior while string-key records such as Outbound Exchanges can participate in source history without ad hoc page exceptions.

## Public Contract

- Page callers pass a title or label, one or more subject handles (`name`, `id`, optional `identifier`), optional direct auditable fallback, a source capability, and optional trigger copy.
- Page callers do not import `App\Base\Audit\*`, reference Audit Livewire classes, query Audit tables, write Audit rows, or build Audit full-history URLs.
- Non-Core modules may expose subject metadata with duck-typed model methods and may record semantic product actions only through the Foundation `SemanticActionRecorder` contract.
- The bridge renders nothing for users who fail either the global audit-log capability or the source-page capability.
- The drawer loads a bounded latest-history result set only when opened and links to the full Audit page only through bridge/Audit-owned URL generation.
- Redacted values remain redacted everywhere, excluded fields remain absent, and truncated fields remain bounded.
- Missing or pruned action rows are normal; mutation history must stay useful without action context.
- Trace links are available only when a mutation has a trace and the viewer passes the same authorization gate.

## Initial Rollout Inventory

This inventory is intentionally a starting matrix. Phase 2 owns turning each row into verified page-level work before implementation.

| Wave | Page or route family | Initial disposition |
|---|---|---|
| Precedent | `admin/users/{user}` | Migrated from direct Audit Livewire usage to the neutral bridge; remains the regression precedent for trigger behavior, dual authorization gating, lazy drawer loading, and full-history links. |
| 1 | `admin/companies/{company}` | Bridge added after backend subject coverage for direct company, departments, relationships, external accesses, and linked address mutations; page field-strategy closeout remains. |
| 1 | `admin/employees/{employee}` and `people/employees/{employee}` | Bridge added to both admin Core and People workbench detail pages after backend subject coverage for direct employee, linked user mutations, linked address mutations, and existing roster subject expansion; page field-strategy closeout remains. |
| 1 | `admin/addresses/{address}` | Bridge added after backend subject coverage for direct address and addressable owner links; page field-strategy closeout remains. |
| 1 | `commerce/inventory/items/{item}` | Backend subject coverage added in the nested Commerce repo for direct item, fitments, photos, catalog values, listings, and listing drafts; listing/draft noisy snapshot fields excluded; bridge is present on the detail page and page field-strategy closeout remains. |
| 2 | `it/tickets/{ticket}`, `quality/ncr/{ncr}`, `quality/scar/{scar}` | Bridge added after direct subject coverage; Audit history complements workflow timelines by exposing field-level diffs and trace links. SCAR mutations also expand into parent NCR history. |
| 2 | `admin/workflows/{workflow}` and `admin/integration/outbound-exchanges/{exchange}` | Workflow history is live for direct workflow, status, transition, and kanban-column configuration rows. Outbound Exchange history is live after string-key Audit support and payload/header field exclusions, so local history does not bypass the retained-payload inspection capability. |
| 2 | `admin/roles/{role}` | Authz role history is live for direct role edits, role-capability rows, and user-role assignment rows; single-row removals now use model deletes so deletion events are captured. |
| 3 | Leave, Claim, Attendance, Payroll, Marketplace, and IBP workbenches | Use only where a stable one-record detail context exists. Do not add one history Livewire island per table row or roster cell. |
| Deferred | System logs, database-table viewers, UI reference, generic settings/list pages | Not source-record history targets unless a future phase identifies a stable auditable record and business value. |

## Phases

### Completed bridge and first visible rollout queue

Goal: complete the UI/page work after the shared drawer/trigger polish landed, without duplicating backend subject-metadata work.

- [x] Fold the completed UI/UX treatment into the neutral record-history bridge so page callers never reference `App\Base\Audit\*`, Audit Livewire classes, Audit routes, or Audit search syntax directly. {amp/gpt-5}
- [x] Add shared drawer controls for high-volume histories: record handle context, search across actor/event/field/value/trace, sortable time/actor/event/trace columns, and progressive loading without page callers wiring Audit internals. {codex/gpt-5, amp/gpt-5}
- [x] Migrate `admin/users/{user}` from direct `SourceHistory` usage to the bridge and preserve the existing History trigger behavior, authorization gating, drawer empty state, trace opening, and full-history affordance. {amp/gpt-5}
- [x] Add the bridge to the Core Wave 1 pages that are in the parent repo (`admin/companies/{company}`, `admin/employees/{employee}`, and `admin/addresses/{address}`), using only the already-added subject handles/direct fallbacks and source capabilities. {amp/gpt-5}
- [x] Add the bridge to the People employee detail page in the nested People repo. {amp/gpt-5}
- [x] Complete `commerce/inventory/items/{item}` in a separate nested-repo pass because Commerce has its own repository boundary. {amp/gpt-5}
- [x] Add/adjust UI-facing tests for the bridge and migrated pages, favoring authorization/disclosure boundaries over brittle markup snapshots. {amp/gpt-5}
- [x] Run the route-inventory page-weight scan and update this plan with evidence. {amp/gpt-5}
- [ ] Run manual browser verification and route-specific page-weight checks for User plus at least one representative Wave 1 page, then update this plan with evidence and any page-specific follow-up rows.

Evidence: `tests/Feature/Audit/AuditLogUiTest.php` verifies the bridge trigger appears on first-wave pages, is not mounted without audit permission, and supports source-history search/sort/progressive loading; `php artisan blb:perf:page-weights --max-kb=150 --limit=60` rendered 106 route-inventory pages with 5 existing over-budget pages. Parameterized detail-page weights need separate measurement; the first local check found `admin/users/{user}` already around 1.5 MB, so that cleanup remains open and should not be hidden by the sidebar rollout.

### Phase 1 — Stabilize the shared integration contract

Goal: make one safe integration point that can be reused across Core, modules, and extensions without copying Audit internals.

- [x] Capture Codex's final trigger, drawer, responsive, focus-management, and empty-state choices as the shared visual contract. {amp/gpt-5}
- [x] Add a neutral framework-owned record-history bridge that accepts only plain title, subject, direct fallback, capability, and label inputs. {amp/gpt-5}
- [x] Centralize full-history URL/search generation behind the bridge or Audit implementation instead of requiring page callers to build Audit routes. {amp/gpt-5}
- [x] Keep the bridge from querying or rendering history entries until the drawer is opened. {amp/gpt-5}
- [x] Keep high-volume drawer interactions Audit-owned by adding source-history search, sorting, result counts, related-record target labels, and load-more pagination inside the shared Audit read model and Livewire island. {codex/gpt-5, amp/gpt-5}
- [ ] Prove selected detail pages meet the initial page-weight budget after the bridge; `admin/users/{user}` currently needs unrelated weight reduction before this can be closed.
- [x] Migrate the existing User page to the bridge and verify behavior matches the direct Audit Livewire precedent. {amp/gpt-5}
- [x] Add focused tests proving the bridge preserves the dual capability gate and does not expose history to unauthorized users. {amp/gpt-5}

Evidence: `php artisan test tests\Feature\Audit\AuditLogUiTest.php`; `php artisan test tests\Feature\Authz\RoleUiTest.php tests\Feature\Base\Integration\OutboundExchangesUiTest.php tests\Feature\Workflow\WorkflowShowTest.php tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php`; `vendor\bin\pint app\Base\Audit\Livewire\AuditLog\Concerns\InteractsWithSourceHistory.php app\Base\Audit\Services\AuditSourceHistory.php tests\Feature\Audit\AuditLogUiTest.php`; parent `git diff --check`. Earlier evidence: `php artisan test tests\Feature\Audit\AuditLogUiTest.php tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php tests\Feature\Audit\AuditableTraitTest.php app\Modules\People\Attendance\Tests\Feature\RosterAuditSubjectTest.php`; `php artisan view:clear`; `php artisan blb:perf:page-weights --max-kb=150 --limit=60`.

### Phase 2 — Turn the inventory into a page-by-page build sheet

Goal: prevent trigger-first rollout by documenting what each page must cover before it is exposed.

- [ ] Expand the inventory with concrete route names, Livewire classes, view paths, source capabilities, owning models, and current direct mutation availability.
- [ ] For each candidate, list required direct subjects, related subject entries, sensitive fields, noisy fields, and semantic-action needs.
- [ ] Mark pages out of scope when they are lists, bulk grids, dashboards, settings-only screens, or do not have stable record identity.
- [ ] Prioritize Wave 1 by business value, mutation volume, and low coupling risk rather than by easiest markup changes.
- [ ] Record one manual verification URL per selected page so later agents can prove the drawer in-browser.
- [ ] Add route-specific page-weight evidence for selected detail pages; `admin/users/{user}` currently needs a separate weight-reduction pass before it can honestly meet the 150 KB target.

Validation: reviewed inventory table in this plan with no page promoted to implementation without subjects, capability, and field strategy notes.

### Phase 3 — Add subject metadata and safety rules per domain

Goal: make local histories truthful before exposing them.

- [x] Add or verify `getAuditSubject()` for each Wave 1 direct record model. {amp/gpt-5}
- [x] Add or verify `getAuditSubjectEntries(...)` for related records whose mutations belong in a parent record's history. {amp/gpt-5}
- [x] Exclude the highest-noise marketplace listing/draft snapshot fields that would bury item history. {amp/gpt-5}
- [ ] Complete page-by-page `auditRedact`, `auditExclude`, and `auditTruncate` review for every Wave 1 model before visible rollout.
- [x] Add focused tests proving direct and related mutation rows resolve to the intended source subject. {amp/gpt-5}
- [x] Add focused tests proving selected noisy/snapshot fields do not bury item history. {amp/gpt-5}

Evidence: `tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php`; subject metadata added in Core/parent repo and nested Commerce repo. Validation passed with `php artisan test tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php tests\Feature\Audit\AuditLogUiTest.php tests\Feature\Audit\AuditableTraitTest.php app\Modules\People\Attendance\Tests\Feature\RosterAuditSubjectTest.php`, Pint on touched PHP files, parent `git diff --check`, and nested Commerce `git diff --check`.

### Phase 4 — Roll out Wave 1 detail pages

Goal: expose consistent local history on the first high-value record pages using only the bridge.

- [x] Complete or re-verify `admin/users/{user}` after migration to the bridge. {amp/gpt-5}
- [x] Add the bridge to `admin/companies/{company}` only after company-related subject coverage is present. {amp/gpt-5}
- [x] Add the bridge to `admin/employees/{employee}` and/or `people/employees/{employee}` only after employee-related subject coverage is present. {amp/gpt-5}
- [x] Add the bridge to `admin/addresses/{address}` only after owner/subject behavior is explicit. {amp/gpt-5}
- [x] Add the bridge to `commerce/inventory/items/{item}` only after item-related subject coverage and noisy field rules are present. {amp/gpt-5}
- [ ] For every Wave 1 page, verify auditors can open history and trace links, non-auditors cannot see/open history, empty state is useful, and the bounded result set is clear.

Evidence: parent Audit bridge tests passed; Commerce item detail trigger passed with `php artisan test app\Modules\Commerce\Inventory\Tests\Feature\ItemWorkbenchTest.php --filter="authenticated users can view an inventory item detail page"`; full Commerce `ItemWorkbenchTest.php` currently has two unrelated PhotoRoom cleanup failures, while the item-detail/history assertion is green. Remaining validation: browser checks for each page, route-specific page weights, and full-suite cleanup of unrelated failures.

### Phase 5 — Roll out operational/workflow records without duplicating domain timelines

Goal: add Audit history where it complements existing status/audit timelines rather than creating two competing histories.

- [x] Review IT ticket history and Quality NCR/SCAR history for existing domain timelines. {amp/gpt-5}
- [x] Decide the local Audit drawer's job on IT Ticket/NCR/SCAR pages: field-level data changes and security trace links, not replacement workflow timelines. {amp/gpt-5}
- [x] Add missing subject metadata for IT Ticket, NCR, and SCAR; SCAR mutations expand into parent NCR history. {amp/gpt-5}
- [x] Add the bridge to IT Ticket, NCR, and SCAR pages where the page has a stable record context and the Audit drawer adds field-level history not already present in the workflow timeline. {amp/gpt-5}
- [x] Review Workflow detail and Integration outbound-exchange detail pages for existing operator diagnostics; complete Outbound Exchange only after string-key subjects/direct fallback are supported. {amp/gpt-5}
- [x] Add direct Workflow subject metadata plus workflow-subject coverage for status, transition, and kanban configuration rows. {amp/gpt-5}
- [x] Add the bridge to `admin/workflows/{workflow}` with `admin.workflow.manage` as the source capability. {amp/gpt-5}
- [x] Update the Audit mutations schema/read model/search contract so `auditable_id` and `subject_id` support normalized string IDs. {amp/gpt-5}
- [x] Add Outbound Exchange subject metadata and exclude retained payload/header/metadata fields from mutation diffs before exposing local history. {amp/gpt-5}
- [x] Add the bridge to `admin/integration/outbound-exchanges/{exchange}` with `admin.system.outbound-exchange.list` as the source capability. {amp/gpt-5}
- [x] Add Authz Role subject metadata, expand user-role assignments into role history, and switch single-row role assignment removals to event-firing model deletes. {amp/gpt-5}
- [x] Add the bridge to `admin/roles/{role}` with `admin.authz.role.view` as the source capability. {amp/gpt-5}
- [x] Add semantic actions for status transitions where mutation diffs alone do not explain intent, keeping comments/attachments/metadata sanitized to presence/count/key summaries. {amp/gpt-5}

Evidence: `php artisan test app\Modules\Operation\IT\Tests\Feature\WorkflowEngineTest.php`; `php artisan test app\Modules\Operation\Quality\Tests\Feature\QualityWorkflowUiTest.php`; nested Operation `git diff --check`; `php artisan test tests\Feature\Workflow\WorkflowShowTest.php tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php`; `php artisan test tests\Feature\Audit\AuditableTraitTest.php tests\Feature\Audit\AuditLogUiTest.php tests\Feature\Base\Integration\OutboundExchangesUiTest.php app\Modules\People\Attendance\Tests\Feature\RosterAuditSubjectTest.php`; `php artisan test tests\Feature\Authz\RoleUiTest.php tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php`; `php artisan test tests\Feature\Audit\AuditLogUiTest.php --filter="records semantic audit actions for workflow transitions"`; `vendor\bin\pint` on touched Workflow/Audit/Integration/People/Authz files; parent and nested People `git diff --check`.

### Phase 6 — Extend to workbenches and private extensions carefully

Goal: support module and extension workflows without creating performance or repository-boundary problems.

- [ ] Identify workbench screens that can open a single stable record detail/inspector before showing history.
- [ ] Do not add per-row or per-cell history islands to lists, grids, roster boards, or dashboards; reuse existing specialized cell-history patterns until a one-record drawer is designed.
- [ ] For private extensions, check nested repository boundaries before changing files and keep extension views under their module `Views/` directory.
- [ ] Add subject metadata in the owning module or extension without Audit imports.
- [ ] Add semantic actions through Foundation for extension workflows that need user-intent history.

Validation: extension-specific tests or manual browser checks, plus parent/nested repository status checks before any commit request.

### Phase 7 — Close out and promote stable guidance

Goal: leave a durable convention and accurate status surface.

- [ ] Update `app/Base/Audit/AGENTS.md` and UI guidance only after the bridge and rollout pattern are stable.
- [ ] Tick completed page rows in this plan with `{agent}/{model}` suffixes and evidence.
- [ ] Move deferred or low-value pages to explicit later-wave rows instead of leaving stale prose.
- [ ] Run the affected Audit/module tests, Pint, `git diff --check`, and representative page-weight checks.
- [ ] Mark this plan complete only when the selected system-wide waves are implemented, verified, and documented.

Validation: plan status reflects reality, evidence is linked per completed wave, and no page integration bypasses the bridge.

## Oracle Use

Oracle was useful for a lightweight architecture review of the rollout boundary. No further Oracle help is needed for the normal plan or implementation waves. Revisit Oracle only if the team considers relaxing the global audit permission for local-only history, adding string/UUID subject support, changing action retention, adding cross-process trace propagation, or refactoring the Audit read model/schema.
