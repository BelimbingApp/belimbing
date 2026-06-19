# base-audit-source-history-rollout.md

**Status:** In progress — backend subject coverage is in place for Wave 1; Codex-ready UI bridge and visible rollout tasks are queued.
**Last Updated:** 2026-06-19
**Sources:**
- `docs/plans/base-audit-log-usability.md` — completed User-management audit usability rollout and current Codex UI/UX workstream.
- `app/Base/Audit/AGENTS.md` — zero-coupling Audit module rules, subject metadata contract, semantic action boundary, and UI ownership.
- `app/Base/Audit/Livewire/AuditLog/SourceHistory.php` — current local-history Livewire island and authorization gate.
- `app/Base/Audit/Services/AuditSourceHistory.php` — current mutation lookup by subject metadata plus direct auditable fallback.
- `resources/core/views/livewire/admin/audit/source-history.blade.php` and `resources/core/views/livewire/admin/audit/partials/source-history-drawer.blade.php` — current trigger and drawer rendering.
- `resources/core/views/livewire/admin/users/show.blade.php` — first source-record history integration precedent.
- Oracle architecture check in the current Amp thread — lightweight review of rollout boundaries and risks.
**Agents:** amp/gpt-5

## Problem Essence

The User-management rollout proved the record-level History drawer, but its current integration is a one-off that directly references Audit from the page. A system-wide rollout must avoid module-to-Audit coupling, inconsistent placement, partial histories, and repeated UI churn while Codex finishes the shared UI/UX polish.

## Desired Outcome

High-value record/detail pages expose a consistent History trigger that answers what changed, who changed it, when it happened, and which trace caused it. Modules adopt the drawer through a neutral framework-owned bridge, with each page marked complete only after direct and related subject coverage, redaction/noise review, authorization, trace behavior, and page-weight impact are proven.

## Current Baseline

- `admin/users/{user}` is the working precedent: it passes a title, user subject, direct auditable fallback, full-history link, and source capability to the Audit `SourceHistory` Livewire island.
- `SourceHistory` currently requires both `admin.audit.log.list` and the page's source capability before rendering or opening history.
- `AuditSourceHistory` reads mutation rows by `subject_name` / `subject_id` / optional `subject_identifier`, with direct `auditable_type` / `auditable_id` fallback for old rows and direct model changes.
- Existing subject metadata coverage now includes the first-wave direct record subjects and several high-value related records. Remaining gaps are page-by-page field strategy review, the neutral bridge, and visible page integration.
- Codex has finished the shared UI/UX improvement, so the next fastest parallel lane is bridge implementation, User-page migration, and first-wave page integration/browser verification.

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

### Treat string-key support as outside this rollout unless needed

The current lookup accepts `int|string` inputs but casts subject and auditable IDs to integers. This rollout targets numeric-key records unless a phase explicitly updates the Audit query/index contract for UUID or string-key subjects.

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
| Precedent | `admin/users/{user}` | Working proof; migrate from direct Audit Livewire usage to the neutral bridge after Codex UI/UX settles. |
| 1 | `admin/companies/{company}` | Backend subject coverage added for direct company, departments, relationships, external accesses, and linked address mutations; page field-strategy review and UI bridge still pending. |
| 1 | `admin/employees/{employee}` and `people/employees/{employee}` | Backend subject coverage added for direct employee, linked user mutations, linked address mutations, and existing roster subject expansion; page field-strategy review and UI bridge still pending. |
| 1 | `admin/addresses/{address}` | Backend subject coverage added for direct address and addressable owner links; page field-strategy review and UI bridge still pending. |
| 1 | `commerce/inventory/items/{item}` | Backend subject coverage added in the nested Commerce repo for direct item, fitments, photos, catalog values, listings, and listing drafts; listing/draft noisy snapshot fields excluded; page field-strategy review and UI bridge still pending. |
| 2 | `it/tickets/{ticket}`, `quality/ncr/{ncr}`, `quality/scar/{scar}` | Operational records with existing workflow/status histories; add Audit history only after deciding how it complements, not duplicates, domain timelines. |
| 2 | `admin/workflows/{workflow}` and `admin/integration/outbound-exchanges/{exchange}` | Framework/admin records where global audit may help operators; lower priority than business source records. |
| 3 | Leave, Claim, Attendance, Payroll, Marketplace, and IBP workbenches | Use only where a stable one-record detail context exists. Do not add one history Livewire island per table row or roster cell. |
| Deferred | System logs, database-table viewers, UI reference, generic settings/list pages | Not source-record history targets unless a future phase identifies a stable auditable record and business value. |

## Phases

### Parallel Codex work queue — bridge and first visible rollout

Goal: let Codex accelerate the UI/page work now that the shared drawer/trigger polish is complete, without duplicating backend subject-metadata work.

- [ ] Codex: fold the completed UI/UX treatment into the neutral record-history bridge so page callers never reference `App\Base\Audit\*`, Audit Livewire classes, Audit routes, or Audit search syntax directly.
- [ ] Codex: migrate `admin/users/{user}` from direct `SourceHistory` usage to the bridge and preserve the existing History trigger behavior, authorization gating, drawer empty state, trace opening, and full-history affordance.
- [ ] Codex: add the bridge to the Core Wave 1 pages that are in the parent repo (`admin/companies/{company}`, `admin/employees/{employee}`, `people/employees/{employee}` if distinct, and `admin/addresses/{address}`), using only the already-added subject handles/direct fallbacks and source capabilities.
- [ ] Codex: if taking `commerce/inventory/items/{item}`, treat `app/Modules/Commerce` as a nested repo and keep all Commerce changes inside that repo; otherwise leave the Commerce page for a separate nested-repo pass.
- [ ] Codex: add/adjust UI-facing tests for the bridge and migrated pages, favoring authorization/disclosure boundaries over brittle markup snapshots.
- [ ] Codex: run browser verification and page-weight checks for User plus at least one representative Wave 1 page, then update this plan with evidence and any page-specific follow-up rows.

Validation: bridge tests, migrated User history test, parent `git diff --check`, Pint on touched PHP/Blade files, page-weight checks, and manual browser evidence for the pages Codex touches.

### Phase 1 — Stabilize the shared integration contract

Goal: make one safe integration point that can be reused across Core, modules, and extensions without copying Audit internals.

- [ ] Capture Codex's final trigger, drawer, responsive, focus-management, and empty-state choices as the shared visual contract.
- [ ] Add a neutral framework-owned record-history bridge that accepts only plain title, subject, direct fallback, capability, and label inputs.
- [ ] Centralize full-history URL/search generation behind the bridge or Audit implementation instead of requiring page callers to build Audit routes.
- [ ] Ensure the bridge renders lazily or otherwise preserves the initial page-weight budget for detail pages.
- [ ] Migrate the existing User page to the bridge and verify behavior matches the direct Audit Livewire precedent.
- [ ] Add focused tests proving the bridge preserves the dual capability gate and does not expose history to unauthorized users.

Validation: User page browser check, targeted Audit/User feature tests, and page-weight check for the User show page.

### Phase 2 — Turn the inventory into a page-by-page build sheet

Goal: prevent trigger-first rollout by documenting what each page must cover before it is exposed.

- [ ] Expand the inventory with concrete route names, Livewire classes, view paths, source capabilities, owning models, and current direct mutation availability.
- [ ] For each candidate, list required direct subjects, related subject entries, sensitive fields, noisy fields, and semantic-action needs.
- [ ] Mark pages out of scope when they are lists, bulk grids, dashboards, settings-only screens, or do not have stable record identity.
- [ ] Prioritize Wave 1 by business value, mutation volume, and low coupling risk rather than by easiest markup changes.
- [ ] Record one manual verification URL per selected page so later agents can prove the drawer in-browser.

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

- [ ] Complete or re-verify `admin/users/{user}` after migration to the bridge.
- [ ] Add the bridge to `admin/companies/{company}` only after company-related subject coverage is present.
- [ ] Add the bridge to `admin/employees/{employee}` and/or `people/employees/{employee}` only after employee-related subject coverage is present.
- [ ] Add the bridge to `admin/addresses/{address}` only after owner/subject behavior is explicit.
- [ ] Add the bridge to `commerce/inventory/items/{item}` only after item-related subject coverage and noisy field rules are present.
- [ ] For every Wave 1 page, verify auditors can open history and trace links, non-auditors cannot see/open history, empty state is useful, and the bounded result set is clear.

Validation: targeted feature tests, browser checks for each page, `git diff --check`, Pint on touched PHP files, and page-weight checks for representative pages.

### Phase 5 — Roll out operational/workflow records without duplicating domain timelines

Goal: add Audit history where it complements existing status/audit timelines rather than creating two competing histories.

- [ ] Review IT ticket history, Quality NCR/SCAR history, Workflow detail, and Integration outbound-exchange detail pages for existing domain timelines.
- [ ] Decide the local Audit drawer's job on each page: field-level data changes, security trace, workflow transition provenance, or operator diagnostics.
- [ ] Add missing subject metadata, related entries, and field strategies for selected Wave 2 records.
- [ ] Add the bridge only where the page has a stable record context and the Audit drawer adds information not already present in the domain timeline.
- [ ] Add semantic actions for status transitions or workflow commands where mutation diffs alone do not explain intent.

Validation: one browser check per operational pattern, domain lifecycle tests, and trace-timeline tests where semantic actions are added.

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
