# base-audit-source-history-rollout.md

**Status:** Complete for current stable record/detail pages — the neutral bridge is live on Wave 1 plus IT/Quality, Workflow, Outbound Exchange, and Authz Role Wave 2 detail pages; the shared drawer has dense-history search/sort/progressive loading; workflow transitions now record semantic actions; stable Audit guidance documents the bridge contract. Codex completed the in-app-browser/manual closeout, route-specific page-weight evidence, parent/child propagation rule, and log-explosion guard review. Later-wave route inventory found no additional stable one-record detail pages to promote; broader detail-page weight reduction stays with `docs/plans/performance-page-rendering.md`.
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

The User-management rollout proved the record-level History drawer, and the shared UI/UX polish has now landed. The remaining system-wide rollout work must avoid module-to-Audit coupling, inconsistent placement, partial histories, log explosion, and repeated UI churn.

## Desired Outcome

High-value record/detail pages expose a consistent History trigger that answers what changed, who changed it, when it happened, and which trace caused it. Modules adopt the drawer through a neutral framework-owned bridge, with each page marked complete only after direct and related subject coverage, redaction/noise review, log-explosion guardrails, authorization, trace behavior, and page-weight impact are proven.

## Current Baseline

- `admin/users/{user}` is the working precedent: it passes a title, user subject, direct auditable fallback, and source capability to the neutral `x-ui.record-history` bridge, which owns Audit Livewire mounting and full-history URL generation.
- `SourceHistory` currently requires both `admin.audit.log.list` and the page's source capability before rendering or opening history.
- `AuditSourceHistory` reads mutation rows by `subject_name` / normalized string `subject_id` / optional `subject_identifier`, with direct `auditable_type` / normalized string `auditable_id` fallback for old rows and direct model changes.
- Existing subject metadata coverage now includes the first-wave direct record subjects and several high-value related records. Visible Wave 1 bridge integration is in place for parent, People, and Commerce item detail pages, and IT Ticket/NCR/SCAR, Workflow, Outbound Exchange, and Authz Role detail pages now have Wave 2 coverage. Workflow transitions also record retained semantic actions so trace timelines can explain the user intent behind status changes. Later-wave selection is closed for the current app shape because the remaining named surfaces are list/grid/workbench/settings pages without stable one-record routes or inspectors.
- Codex has finished the shared UI/UX improvement. Amp implemented the neutral bridge, User-page migration, first-wave page integration, and shared dense-history drawer behavior; automated evidence is captured below. Codex completed the immediate browser/manual evidence pass, route-specific page-weight proof, parent/child propagation rule, and log-explosion guard review on 2026-06-20.

## Top-Level Components

### Neutral record-history bridge

Create a small framework-owned presentation bridge, recommended as an `x-ui.record-history`-style component, that accepts only plain primitives from pages and is the only reusable page-integration point allowed to reference the Audit Livewire island, Audit routes, or full-history search syntax.

### Audit source-history read model

Keep `AuditSourceHistory` Audit-owned. It remains responsible for bounded mutation lookup, direct historical fallback, presenter formatting, and trace affordances.

### Subject metadata and field strategy

Each owning model supplies duck-typed `getAuditSubject()` and, where parent pages need related changes, `getAuditSubjectEntries(...)`. Redaction, exclusion, truncation, and high-churn/non-material field handling are reviewed before local history exposes those diffs outside the global Audit pages.

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

### Guard against log explosion before wider visibility

History sidebars make Audit noise more visible, so every promoted page needs an explicit materiality pass. Globally non-material fields such as `created_at` and `updated_at`, cache or counter snapshots, last-seen/sync markers, retained payload blobs, generated metadata, and other high-churn implementation fields should be excluded or truncated unless they are product-relevant evidence for that page.

### Do not bubble every child mutation into parent history

Parent histories include direct parent mutations plus explicitly declared related subjects whose changes are material to understanding the parent record. There is no automatic descendant propagation. For the company/address example, an address phone change should appear in Company history only when the Company page intentionally treats that address/contact data as part of the company profile; otherwise child-only address churn stays on the Address history page.

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
- Non-material or implementation-maintained fields are excluded from local history unless the owning page documents why they matter to the business record.
- Related child changes appear in a parent history only through explicit subject-entry rules, not by generic table ancestry or foreign-key discovery.
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
| 3 | Leave, Claim, Attendance, Payroll, Marketplace, and IBP workbenches | Current route inventory found no additional stable one-record detail contexts. These remain out of scope until a future page introduces a one-record route or inspector; do not add one history Livewire island per table row, roster cell, batch row, period row, or grid cell. |
| Deferred | System logs, database-table viewers, UI reference, generic settings/list pages | Not source-record history targets unless a future phase identifies a stable auditable record and business value. |

## Phases

Rows tagged `[codex]` were Codex-owned handoff rows and are complete where checked. The remaining rollout scope is owned by this plan only when a stable one-record detail page exists.

### Immediate Codex handoff — browser evidence and log-explosion guard

Goal: let Codex use its in-app browser to close the remaining manual evidence and policy gaps while Amp's landed implementation stays stable.

- [x] [codex] Use the in-app browser to verify selected detail pages: `admin/users/{user}`, one Core Wave 1 page (`admin/companies/{company}` or `admin/addresses/{address}`), `people/employees/{employee}`, `commerce/inventory/items/{item}`, and one Wave 2 page (`admin/workflows/{workflow}`, an IT ticket, or a Quality NCR/SCAR). Capture URL, actor/capability, trigger visibility, drawer open/empty/search/sort/load-more behavior, trace affordance, and screenshot or note evidence back into this plan. {codex/gpt-5}
- [x] [codex] Add route-specific page-weight evidence for parameterized detail pages. If no automated route-parameter harness exists, capture browser/network rendered-document evidence and document the limitation; do not close the 150 KB target for `admin/users/{user}` until its unrelated pre-existing page weight is reduced or separately accepted. {codex/gpt-5}
- [x] [codex] Complete an audit/log-explosion guard review before ticking field-strategy closeout: inventory `auditExclude`, `auditRedact`, and `auditTruncate` coverage; confirm non-material fields such as `created_at`, `updated_at`, cache/snapshot/counter fields, sync markers, generated metadata, retained payload/header data, and other high-churn implementation fields are excluded or bounded wherever they would bury useful history. {codex/gpt-5}
- [x] [codex] Apply the explicit-material-related-subject rule to parent histories. Do not bubble all child mutations into parents by default; review the company/address phone example and record whether company history intentionally includes linked address/contact changes as company-profile evidence or whether those changes should remain address-only. {codex/gpt-5}
- [x] [codex] Add or adjust focused tests for any new exclusion/truncation or related-subject decisions so excluded-only noise does not create visible sidebar churn and parent history includes only the intended child records. {codex/gpt-5}
- [x] [codex] Update this plan with Codex evidence, any page-specific follow-up rows, and the final parent/child propagation rule before marking the handoff complete. {codex/gpt-5}

Codex evidence — 2026-06-20:

Browser actor was the signed-in Administrator session with `admin.audit.log.list` plus each page's source capability. Browser verification used the in-app browser and selected route-bound records that the current company context could open.

| Page | Source capability | Browser result | Initial browser DOM KB | Notes |
|---|---|---|---:|---|
| `https://local.blb.lara/admin/users/3` | `admin.user.view` | History trigger visible; drawer opens to `Record history · user#3`; empty state, search input, and Data Mutations link present. | 1690.8 | Lazy proof: no drawer or mutation-table markup before open. Over 150 KB before history opens. |
| `https://local.blb.lara/admin/companies/2` | `admin.company.view` | Drawer opens to `Record history · company#2`; table view shows the linked `Address#5` phone change; Time/Actor/Event/Trace sort controls and trace button present. | 1869.2 | Confirms company history intentionally includes explicit linked address/contact evidence. |
| `https://local.blb.lara/admin/employees/6` | `admin.employee.view` | History trigger visible; drawer opens to `Record history · employee#6`; empty state, search input, and Data Mutations link present. | 1395.7 | Lazy proof: no drawer or mutation-table markup before open. |
| `https://local.blb.lara/admin/addresses/5` | `admin.address.view` | Drawer opens to `Record history · address#5`; table view shows one phone mutation; search for `phone` keeps the row and a no-match search shows `No matching mutations found.`; sort controls and trace button present. | 1363.9 | This is the dense-row sample for the high-signal table layout. |
| `https://local.blb.lara/people/employees/2` | `people.employee.view` | History trigger visible; drawer opens to `Record history · employee#2`; empty state, search input, and Data Mutations link present. | 1354.1 | `people/employees/6` was not in the signed-in company context, so the verified People route-bound record is employee `2`. |
| `https://local.blb.lara/commerce/inventory/items/1` | `commerce.inventory.item.view` | After the local schema catch-up migration, History trigger is visible; drawer opens to `Record history · item#1`; empty state, search input, and Data Mutations link present. | 1530.4 | Browser initially exposed a pre-existing dev schema drift (`description` column missing locally) and a noisy generated `ListingDraft` mutation. Codex added a guarded migration and excluded ListingDraft readiness/cache rows; reload showed `0 mutations`. |
| `https://local.blb.lara/admin/workflows/2` | `admin.workflow.manage` | History trigger visible; drawer opens to `Record history · workflow#2`; empty state, search input, and Data Mutations link present. | 1417.3 | Wave 2 sample; complements workflow timeline rather than replacing it. |

Route-specific page-weight limitation: the project `blb:perf:page-weights` command still skips parameterized routes, so Codex used in-browser rendered DOM size as route-bound evidence. These values include the current app shell and pinned/sidebar state and are not a pure HTTP response-size substitute. They are still useful for rollout risk: every selected detail page is well above the initial 150 KB target before history opens, while all selected pages proved `hasDrawerBeforeOpen=false` and `hasMutationTableBeforeOpen=false`. The 150 KB target remains open until detail-page weight is reduced or explicitly accepted.

Load-more behavior was not forced through browser data because the selected records did not have more than 50 visible history rows. Automated coverage in `tests/Feature/Audit/AuditLogUiTest.php` verifies search, sorting, and progressive loading with the 50-row initial bound and +50 increment.

Log-explosion guard review:

| Scope | Decision |
|---|---|
| Global Audit config | `created_at` and `updated_at` are globally excluded; password/token/secret/API-key fields are globally redacted; strings are bounded by the 2000-character default truncation; `OperationDispatch` is excluded as an implementation model. |
| Core User | `password` and `remember_token` are model-redacted in addition to global redaction. |
| Core ExternalAccess | `metadata` is model-redacted so expanded company/user histories do not expose generated access metadata. |
| Commerce Listing | `last_synced_at` and `raw_payload` remain excluded so channel sync markers and retained marketplace payloads do not bury item history. |
| Commerce ListingDraft | Generated readiness/cache/projection fields are now excluded, and excluded-only create/delete events are skipped by the listener. ListingDraft readiness refreshes no longer create visible item-history churn. |
| Integration OutboundExchange | Trace headers, request/response headers, bodies, truncation flags, original byte counts, and metadata are excluded; retained payload inspection stays in the integration detail surface. |
| Wave 1 direct record models | Company, Employee, Address, Item, ItemFitment, and catalog AttributeValue have no payload/header/snapshot fields in the visible rollout path beyond fields already covered by global timestamp exclusion and default truncation. |

Final parent/child propagation rule: parent history must include only explicit material related subjects declared by the owning model. Do not auto-bubble all child mutations. Company history intentionally includes linked address/contact changes because those are company-profile evidence; unrelated companies do not receive the row. Address history remains address-local, with company projections represented only when reading the linked company history and shown with a target label such as `Address#5`. Generated readiness/cache records such as Commerce ListingDraft rows stay out of item history unless a future semantic action records a user-intent event.

Validation: `php artisan test tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php tests/Feature/Audit/AuditLogUiTest.php`; `php artisan test tests/Feature/Audit/AuditableTraitTest.php`; `php artisan test app/Modules/Commerce/Inventory/Tests/Feature/ItemWorkbenchTest.php --filter="authenticated users can view an inventory item detail page"`.

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
- [x] [codex] Run manual browser verification and route-specific page-weight checks for User plus at least one representative Wave 1 page, then update this plan with evidence and any page-specific follow-up rows. Codex handoff above owns the next pass. {codex/gpt-5}

Evidence: `tests/Feature/Audit/AuditLogUiTest.php` verifies the bridge trigger appears on first-wave pages, is not mounted without audit permission, and supports source-history search/sort/progressive loading; `php artisan blb:perf:page-weights --max-kb=150 --limit=120` rendered 106 no-param route-inventory pages with 5 existing over-budget pages (`sbg/ibp/planning`, `commerce/marketplace/ebay/settings`, `commerce/inventory/items/create`, `admin/ai/control-plane`, `commerce/catalog`). Parameterized detail-page weights need separate measurement; the first local check found `admin/users/{user}` already around 1.5 MB, so that cleanup remains open and should not be hidden by the sidebar rollout.

### Phase 1 — Stabilize the shared integration contract

Goal: make one safe integration point that can be reused across Core, modules, and extensions without copying Audit internals.

- [x] Capture Codex's final trigger, drawer, responsive, focus-management, and empty-state choices as the shared visual contract. {amp/gpt-5}
- [x] Add a neutral framework-owned record-history bridge that accepts only plain title, subject, direct fallback, capability, and label inputs. {amp/gpt-5}
- [x] Centralize full-history URL/search generation behind the bridge or Audit implementation instead of requiring page callers to build Audit routes. {amp/gpt-5}
- [x] Keep the bridge from querying or rendering history entries until the drawer is opened. {amp/gpt-5}
- [x] Keep high-volume drawer interactions Audit-owned by adding source-history search, sorting, result counts, related-record target labels, and load-more pagination inside the shared Audit read model and Livewire island. {codex/gpt-5, amp/gpt-5}
- [x] [codex] Prove selected detail pages keep history markup lazy and document that route-bound page weight is not caused by the bridge; broader detail-page weight reduction remains performance-plan debt or accepted exception work, not an Audit rollout blocker. {codex/gpt-5, amp/gpt-5}
- [x] Migrate the existing User page to the bridge and verify behavior matches the direct Audit Livewire precedent. {amp/gpt-5}
- [x] Add focused tests proving the bridge preserves the dual capability gate and does not expose history to unauthorized users. {amp/gpt-5}

Evidence: `php artisan test tests\Feature\Audit\AuditLogUiTest.php`; `php artisan test tests\Feature\Authz\RoleUiTest.php tests\Feature\Base\Integration\OutboundExchangesUiTest.php tests\Feature\Workflow\WorkflowShowTest.php tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php`; `vendor\bin\pint app\Base\Audit\Livewire\AuditLog\Concerns\InteractsWithSourceHistory.php app\Base\Audit\Services\AuditSourceHistory.php tests\Feature\Audit\AuditLogUiTest.php`; parent `git diff --check`. Earlier evidence: `php artisan test tests\Feature\Audit\AuditLogUiTest.php tests\Feature\Audit\AuditSourceHistorySubjectCoverageTest.php tests\Feature\Audit\AuditableTraitTest.php app\Modules\People\Attendance\Tests\Feature\RosterAuditSubjectTest.php`; `php artisan view:clear`; `php artisan blb:perf:page-weights --max-kb=150 --limit=120`. Route-bound browser evidence proves `hasDrawerBeforeOpen=false` and `hasMutationTableBeforeOpen=false`; the 150 KB response-size budget and accepted residuals are owned by `docs/plans/performance-page-rendering.md`.

### Phase 2 — Turn the inventory into a page-by-page build sheet

Goal: prevent trigger-first rollout by documenting what each page must cover before it is exposed.

- [x] Expand the inventory with concrete route names, Livewire classes, view paths, source capabilities, owning models, and current direct mutation availability. {amp/gpt-5}
- [x] For each candidate, list required direct subjects, related subject entries, sensitive fields, noisy fields, and semantic-action needs. {amp/gpt-5}
- [x] Mark pages out of scope when they are lists, bulk grids, dashboards, settings-only screens, or do not have stable record identity. {amp/gpt-5}
- [x] Prioritize selected pages by business value, mutation volume, and low coupling risk rather than by easiest markup changes; current Wave 1/2 detail pages are complete and later waves wait for real one-record detail contexts. {amp/gpt-5}
- [x] [codex] Record one manual verification URL per selected page so later agents can prove the drawer in-browser. {codex/gpt-5}
- [x] [codex] Add route-specific page-weight evidence for selected detail pages; `admin/users/{user}` currently needs a separate weight-reduction pass before it can honestly meet the 150 KB target. {codex/gpt-5}

Later-wave route inventory closeout:

| Route family | Livewire/view | Record context | Disposition |
|---|---|---|---|
| `people/leave`, `people/leave/approvals`, `people/leave/settings*` | `App\Modules\People\Leave\Livewire\Index`; `app/Modules/People/Leave/Views/livewire/people/leave/index.blade.php` | Surface/section workbench over leave requests and setup records; no `LeaveRequest` detail route or one-record inspector. | Out of scope. Do not add row-level history to the list/settings surface. |
| `people/claims*` | `App\Modules\People\Claim\Livewire\Index`; `app/Modules/People/Claim/Views/livewire/people/claim/index.blade.php` | My/approvals/operations/settings workbench over claim requests and setup records; no `ClaimRequest` detail route or one-record inspector. | Out of scope. Future claim-detail page should add subject metadata and the bridge in the Claim module. |
| `people/payroll*` | `App\Modules\People\Payroll\Livewire\Index` and mapping pages under `app/Modules/People/Payroll/Livewire/` | Payroll dashboard/mapping workbenches; no pay-run/payslip detail route in the current module. | Out of scope. Future pay-run detail would be the first valid target. |
| `people/attendance*` | Attendance Livewire workbenches under `app/Modules/People/Attendance/Livewire/` | My attendance, approvals, rosters, policy groups, shifts, allowance rules, locations; current specialized roster/cell history remains the right UX. | Out of scope for record-history bridge until a stable attendance record inspector exists. |
| `commerce/marketplace/ebay*` | `App\Modules\Commerce\Marketplace\Livewire\Ebay\Index` and `Settings` | Marketplace list/settings with quick edit modal; no listing detail route. Item history already includes material listing changes through the Commerce item page. | Out of scope. Do not add per-listing islands to the marketplace table. |
| `sbg/ibp*` private extension | SBG IBP dashboard/procurement/projection/pricing/planning/settings Livewire pages under `extensions/sb-group/ibp/` | Dashboard and dense planning/procurement workbenches use selected snapshots, batches, periods, and tabs; no stable one-record detail route. Nested repo boundary checked clean. | Out of scope for parent rollout. Future SBG one-record inspector work belongs in the nested extension repo and must use extension-owned views plus the neutral bridge. |

Validation: reviewed route files in Core, People, Commerce, Operation, and the SBG IBP extension with no page promoted to implementation without subjects, capability, and field strategy notes.

### Phase 3 — Add subject metadata and safety rules per domain

Goal: make local histories truthful before exposing them.

- [x] Add or verify `getAuditSubject()` for each Wave 1 direct record model. {amp/gpt-5}
- [x] Add or verify `getAuditSubjectEntries(...)` for related records whose mutations belong in a parent record's history. {amp/gpt-5}
- [x] Exclude the highest-noise marketplace listing/draft snapshot fields that would bury item history. {amp/gpt-5}
- [x] [codex] Complete page-by-page `auditRedact`, `auditExclude`, and `auditTruncate` review for every Wave 1 model before visible rollout, explicitly covering timestamps, cache/snapshot/counter fields, sync markers, generated metadata, payload/header blobs, and any other high-churn implementation fields. {codex/gpt-5}
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
- [x] [codex] For every Wave 1 page, verify auditors can open history and trace links, non-auditors cannot see/open history, empty state is useful, and the bounded result set is clear. {codex/gpt-5}

Evidence: parent Audit bridge tests passed; Commerce item detail trigger passed with `php artisan test app\Modules\Commerce\Inventory\Tests\Feature\ItemWorkbenchTest.php --filter="authenticated users can view an inventory item detail page"`; full Commerce `ItemWorkbenchTest.php` had two unrelated PhotoRoom cleanup failures at rollout time, while the item-detail/history assertion was green. Codex browser evidence now covers selected Wave 1 pages, route-specific detail evidence, and the lazy-before-open contract.

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

- [x] Identify workbench screens that can open a single stable record detail/inspector before showing history; current Leave, Claim, Attendance, Payroll, Marketplace, and SBG IBP routes do not expose an unrolled stable one-record detail context. {amp/gpt-5}
- [x] Do not add per-row or per-cell history islands to lists, grids, roster boards, dashboards, marketplace rows, IBP snapshot rows, batch rows, period rows, or planning cells; reuse existing specialized cell-history patterns until a one-record drawer is designed. {amp/gpt-5}
- [x] For private extensions, check nested repository boundaries before changing files and keep extension views under their module `Views/` directory; SBG IBP was inspected as a nested clean repo and no extension file changes were made in this parent rollout. {amp/gpt-5}
- [x] Add no new subject metadata in this pass because no Phase 6 page was promoted; future one-record detail or inspector work must add metadata in the owning module or extension without Audit imports before exposing history. {amp/gpt-5}
- [x] Add no new semantic actions in this pass because no Phase 6 workflow was promoted; future extension workflows that need user-intent history must use the Foundation `SemanticActionRecorder` contract. {amp/gpt-5}

Validation: route sweep across People, Commerce, Operation, and SBG IBP; parent and nested repository status checks.

### Phase 7 — Close out and promote stable guidance

Goal: leave a durable convention and accurate status surface.

- [x] Update `app/Base/Audit/AGENTS.md` after the bridge and rollout pattern are stable. {amp/gpt-5}
- [x] Tick completed page rows in this plan with `{agent}/{model}` suffixes and evidence. {amp/gpt-5}
- [x] Move deferred or low-value pages to explicit later-wave rows instead of leaving stale prose. {amp/gpt-5}
- [x] Run the affected Audit/module tests, Pint, `git diff --check`, and broad route-inventory page-weight checks. {amp/gpt-5}
- [x] [codex] Add route-specific page-weight tooling/evidence for selected parameterized detail pages; the current `blb:perf:page-weights` command skips required route parameters and cannot synthesize bound records. {codex/gpt-5}
- [x] Mark this plan complete for current stable record/detail pages; future workbench/extension expansion requires a new one-record detail or inspector target before this bridge is applied again. {amp/gpt-5}

Validation: plan status reflects reality, evidence is linked per completed wave, no page integration bypasses the bridge, Codex verified lazy route-bound drawer behavior, and broader page-weight debt remains in `docs/plans/performance-page-rendering.md` instead of blocking this Audit rollout.

## Oracle Use

Oracle was useful for a lightweight architecture review of the rollout boundary. No further Oracle help is needed for Codex's browser/manual closeout or the normal log-explosion materiality review. Revisit Oracle only if the team considers relaxing the global audit permission for local-only history, changing action/mutation retention, replacing the explicit related-subject rule with automatic parent propagation, adding cross-process trace propagation, or refactoring the Audit read model/schema.
