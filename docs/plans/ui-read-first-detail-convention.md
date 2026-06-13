# UI Read-First Detail Convention

**Status:** Complete
**Last Updated:** 2026-06-13
**Sources:** `DESIGN.md`, `resources/core/views/AGENTS.md`, `docs/plans/ui-reference-catalog.md`, `resources/core/views/livewire/admin/system/ui-reference/partials/interaction-patterns.blade.php`, `resources/core/views/livewire/admin/addresses/show.blade.php`, `resources/core/views`, `app/Modules/**/Views`, `extensions/**/Views`
**Agents:** Codex/GPT-5

## Problem Essence

BLB detail pages already point toward a read-first editing convention, but the pattern is enforced by scattered examples rather than a clear page-level contract. As pages add comboboxes, grouped fields, modal forms, and relationship editors, detail screens can drift into mixed read/edit surfaces that make workflow pages harder to scan.

## Desired Outcome

Detail pages should consistently present facts first, then expose editing deliberately through field-level edit-in-place, grouped inline editors, or full forms based on coupling and risk. The convention should be cataloged in the UI reference, encoded in reusable primitives where repetition appears, and migrated across existing Blade detail pages in small verifiable slices.

## Top-Level Components

| Piece | Responsibility |
|---|---|
| UI reference interaction patterns | Shows the canonical read-first contract and examples for field, grouped, and full-form editing |
| `x-ui.edit-in-place.*` | Owns low-risk single-field fact edits where the saved value is obvious |
| Grouped inline editors | Own coupled factual changes such as address location where fields must be reviewed and applied together |
| Detail page Blade views | Compose read-first sections, local save methods, relationship tables, and domain-specific actions |
| Livewire detail components | Own validation, persistence, draft state, authorization, and side effects for edits |

## Design Decisions

**Catalog the convention before broad migration.** The UI reference already describes field-level edit-in-place, but it does not yet define the broader decision tree for when a detail page should use field-level edit, grouped inline edit, modal edit, or full-form edit. That decision tree should become the first artifact so future migrations are consistent.

**Treat read-first as the default for detail pages, not create/edit forms.** Index pages, create pages, import flows, setup wizards, and task workbenches can remain form-first when the page's primary job is data entry. The convention applies to detail/show pages where review and occasional correction are the main workflow.

**Use field-level edit-in-place only for independent low-risk facts.** A field belongs in `x-ui.edit-in-place.*` when changing it alone is meaningful, validation is local, save-on-blur is acceptable, and the value itself confirms what was saved.

**Use grouped inline editors for coupled facts.** Fields that depend on each other, trigger side effects, or need review before persistence should read as facts first and open a compact editor with Apply/Cancel. Address country/state/postcode/locality is the current reference example.

**Use full forms or modals when editing is a workflow.** Destructive edits, multi-step changes, permission-sensitive changes, bulk association edits, and changes that require explanation or confirmation should not be hidden behind tiny edit affordances.

**Do not turn every static fact into an editable control.** Read-first still permits read-only operational facts such as parser version, confidence, computed values, audit stamps, and relationship summaries. The contract is clarity, not universal inline editing.

**Prefer reusable primitives after two real examples.** The first grouped editor can remain page-owned when domain behavior is still settling. Once a second page repeats the same structure, extract a shared wrapper or component rather than copying raw panel markup.

## Public Contract

- A detail page's default state is readable: values render as text, badges, links, or summary blocks rather than full-height form controls.
- Independent low-risk facts use `x-ui.edit-in-place.text`, `x-ui.edit-in-place.select`, `x-ui.edit-in-place.combobox`, or `x-ui.edit-in-place.textarea`.
- Combobox-backed facts open from the displayed value row; a separate Edit button belongs only to grouped workflows.
- Coupled facts use a grouped inline editor with a visible edit action, clear read summary, stable inputs, Apply, and Cancel.
- Form controls inside grouped editors use existing `x-ui.*` primitives with explicit stable ids.
- Livewire components keep draft state separate from persisted state until the grouped editor applies.
- Side effects such as timezone suggestions happen after the grouped save, not while the user is still drafting unrelated values.
- The UI reference documents this decision tree and includes rendered examples for all three edit modes: field, grouped, and form/modal.

## Initial Inventory

Current read-first/detail candidates found by scanning `resources/core/views`, with implementation disposition:

- `resources/core/views/livewire/admin/addresses/show.blade.php` — grouped Location editor is the first production reference; location side effects apply after the grouped save.
- `resources/core/views/livewire/admin/companies/partials/company-details.blade.php` — Company identity/contact/legal fields are independent low-risk facts and stay field-level edit-in-place; activities and metadata remain page-owned grouped micro workflows.
- `resources/core/views/livewire/admin/employees/show.blade.php` — Employee identity/employment facts stay field-level edit-in-place; subordinate and address sections are relationship workflows, not detail facts.
- `resources/core/views/livewire/admin/roles/show.blade.php` — Role metadata stays read-first with field-level edit where allowed; capabilities and assigned users are association workflows.
- `resources/core/views/livewire/admin/users/show.blade.php` — User name/email/company stay field-level facts; roles, capabilities, password, employee links, and external access sections are workflows or read-only summaries.
- `resources/core/views/livewire/admin/system/database-backups/index.blade.php` — settings/index-style surface, intentionally outside the detail-page migration.
- `resources/core/views/livewire/admin/companies/partials/company-addresses.blade.php` — address create/edit modals stay form-first; table pivot edits align with the shared micro-editor treatment; Company timezone now reads as a fact first and opens an in-place combobox that persists on selection without a save button.

### 2026-06-13 Follow-Up Sweep

Roots swept in this pass:

- `resources/core/views/**/*.blade.php`
- `app/Modules/**/Views/**/*.blade.php`
- `extensions/**/Views/**/*.blade.php`

Affected Blade files and dispositions from the sweep:

| Blade file | Disposition |
|---|---|
| `resources/core/views/livewire/ai/chat.blade.php` | Implemented. Session title editing is a true inline edit: Enter/blur saves, Escape cancels, and the title suggestion remains a separate icon action. |
| `resources/core/views/livewire/admin/workflows/show.blade.php` | Implemented. PIC and Notify now use shared edit-in-place text fields with a Livewire adapter that stores comma-separated input as the existing arrays. |
| `app/Modules/Operation/IT/Views/livewire/it/tickets/show.blade.php` | No immediate drift. Ticket facts are read-first; transition comment and transition buttons are workflow actions. If status, priority, category, or assignee become editable outside transitions, use field-level select/combobox edit-in-place. |
| `app/Modules/Operation/Quality/Views/livewire/quality/ncr/show.blade.php` | Implemented. Stage-specific triage, assignment, CAPA, and review payloads now read as summaries first and open grouped draft editors; transition buttons remain explicit workflow actions. |
| `app/Modules/Operation/Quality/Views/livewire/quality/scar/show.blade.php` | Implemented. Supplier response payloads now read first and open grouped draft editors; evidence upload and transition buttons remain explicit workflows. |
| `extensions/sb-group/ibp/Views/livewire/data-entry/raw-material-cost.blade.php` | No immediate drift. Editable numeric cells already use field-level edit-in-place. |
| `extensions/sb-group/ibp/Views/livewire/settings/assumption-settings.blade.php` | No immediate drift. Formula constants already use field-level edit-in-place. |
| `extensions/sb-group/ibp/Views/livewire/settings/safety-stock-settings.blade.php` | Implemented. Policy rows remain readable while a grouped editor expands below the row; Apply/Cancel remains because the policy fields are coupled. |
| `extensions/sb-group/ibp/Views/livewire/projection/monthly-board.blade.php` | Implemented. Historical actual close entry now uses field-level edit-in-place with direct persistence. |
| `extensions/sb-group/ibp/Views/livewire/pricing/decision-support.blade.php` | Implemented. BA cost and selling price read first and use grouped editors for value-plus-notes; coating cost uses field-level edit-in-place. |
| `extensions/sb-group/ibp/Views/livewire/pricing/margin-review.blade.php` | No immediate drift. Editable finance/pricing cells already use field-level edit-in-place. |
| `extensions/sb-group/ibp/Views/livewire/planning/sales-forecast-entry.blade.php` | Decision recorded. Leave as an intentional form-first data-entry grid for now; revisit table-cell edit-in-place only if existing-row corrections become a frequent workflow. |
| `extensions/sb-group/ibp/Views/livewire/planning/production-usage.blade.php` | Decision recorded. Leave as an intentional form-first feedback-entry grid for now; revisit table-cell edit-in-place only if existing-row corrections become a frequent workflow. |
| `extensions/sb-group/ibp/Views/livewire/planning/weekly-grid.blade.php` | No immediate drift. Alert acknowledgement requires a note plus a confirm action, so explicit Confirm/Cancel remains appropriate. |
| `extensions/sb-group/ibp/Views/livewire/procurement/import-workbench.blade.php` | No immediate drift. Import approval, manual BA line creation, and mapping creation are workbench/create workflows rather than detail fact edits. |
| `extensions/sb-group/ibp/Views/livewire/dashboard/executive-dashboard.blade.php`, `extensions/sb-group/ibp/Views/livewire/pricing/pricing-history.blade.php`, `extensions/sb-group/ibp/Views/livewire/settings/audit-trail.blade.php` | No immediate drift. Dashboard, history, and audit surfaces are read-only or navigation/filter oriented in this convention. |
| `resources/core/views/livewire/admin/integration/outbound-exchanges/show.blade.php` | No immediate drift. Detail facts and payloads are read-only inspection data. |
| `resources/core/views/livewire/admin/system/database-queries/show.blade.php` | No immediate drift. This is a query workbench/document editor, so explicit Save/Discard remains intentional. |
| `resources/core/views/livewire/admin/companies/partials/company-details.blade.php` | No immediate drift. Company metadata is a multi-line JSON workflow where Save/Cancel is justified; existing independent company facts already use edit-in-place. |

## Phases

### Phase 1 — Catalog the Contract

Affected pages: `admin/system/ui-reference`
Goal: The UI reference clearly answers which edit mode to use before contributors touch production pages.

- [x] Expand the interaction-patterns page with a read-first detail decision tree. {Codex/GPT-5}
- [x] Add a rendered grouped inline editor example with Apply/Cancel, using existing `x-ui.*` controls. {Codex/GPT-5}
- [x] Add a rendered combobox edit-in-place example for searchable independent facts. {Codex/GPT-5}
- [x] Clarify that field-level edit-in-place is for independent low-risk facts only. {Codex/GPT-5}
- [x] Cross-reference create/edit forms as form-first exceptions, not violations. {Codex/GPT-5}

### Phase 2 — Inventory and Classification

Affected pages: Core admin detail/show Blade views listed above.
Goal: Every current detail candidate has a disposition before migration begins.

- [x] Classify each page section as read-only fact, field-level edit, grouped inline edit, modal/full-form workflow, or relationship table/workflow. {Codex/GPT-5}
- [x] Record any repeated grouped editor shape that should become a reusable component. {Codex/GPT-5}
- [x] Identify explicit-id gaps in existing edit-in-place fields and controls. {Codex/GPT-5}
- [x] Separate true drift from intentional form-first pages. {Codex/GPT-5}

### Phase 3 — Core Detail Page Migration

Affected pages: `admin/companies/*`, `admin/employees/*`, `admin/users/*`, `admin/roles/*`, `admin/addresses/*`
Goal: Core admin detail pages feel like one product family while preserving each domain's behavior.

- [x] Align Company detail sections with the read-first contract. {Codex/GPT-5}
- [x] Align Company timezone with the read-first contract. Timezone now displays as a summary by default and uses an in-place combobox for direct persistence on selection. {Codex/GPT-5}
- [x] Align Employee detail sections with the read-first contract. {Codex/GPT-5}
- [x] Align User detail sections, especially association controls, with the read-first contract. {Codex/GPT-5}
- [x] Align Role detail sections with the read-first contract. {Codex/GPT-5}
- [x] Revisit Address detail after one more grouped-editor example proves whether a reusable primitive is justified. The UI Reference example validates the contract, but production still has only one domain-specific grouped editor, so extraction is deferred. {Codex/GPT-5}

### Phase 4 — Component Extraction

Goal: Repeated markup becomes deep enough to prevent future drift without hiding domain behavior.

- [x] Extract a grouped inline editor wrapper if two or more pages repeat the same read-summary plus Apply/Cancel shell. No production repetition yet, so no component was extracted. {Codex/GPT-5}
- [x] Consider an `x-ui.detail-section` or `x-ui.fact-grid` only if repeated section scaffolding remains noisy after page migration. Existing card/detail grids remain readable without a new wrapper. {Codex/GPT-5}
- [x] Keep validation, persistence, authorization, and side effects in Livewire components. {Codex/GPT-5}
- [x] Update `resources/core/views/AGENTS.md` if the convention needs authoring rules beyond the UI reference. {Codex/GPT-5}

### Phase 5 — Verification and Drift Controls

Goal: The convention is testable, visually reviewed, and hard to accidentally regress.

- [x] Run targeted Livewire/feature tests for migrated save workflows. Evidence: Address UI, Country combobox, Company, Role UI, User UI, Company UI, Company relationships, External Access, and Company timezone suites passed. {Codex/GPT-5}
- [x] Run `php artisan view:cache` after Blade/component changes. {Codex/GPT-5}
- [x] Browser-check representative pages in desktop and narrow viewports when an authenticated local session is available. Attempted `https://local.blb.lara/admin/system/ui-reference#interaction-patterns`; the documented local fixture login was rejected by this database, so no authenticated browser pass was available without mutating local users. {Codex/GPT-5}
- [x] Update this plan as each page is migrated, including evidence and completed agent/model suffixes. {Codex/GPT-5}

### Phase 6 — Follow-Up Sweep Migration

Affected pages: `resources/core/views/livewire/ai/chat.blade.php`, `resources/core/views/livewire/admin/workflows/show.blade.php`, `app/Modules/Operation/Quality/Views/livewire/quality/ncr/show.blade.php`, `app/Modules/Operation/Quality/Views/livewire/quality/scar/show.blade.php`, `extensions/sb-group/ibp/Views/livewire/settings/safety-stock-settings.blade.php`, `extensions/sb-group/ibp/Views/livewire/projection/monthly-board.blade.php`, `extensions/sb-group/ibp/Views/livewire/pricing/decision-support.blade.php`, with optional list/grid follow-up for `extensions/sb-group/ibp/Views/livewire/planning/sales-forecast-entry.blade.php` and `extensions/sb-group/ibp/Views/livewire/planning/production-usage.blade.php`.
Goal: Remove form-first drift from detail/review surfaces while preserving explicit workflow saves where the action is coupled, destructive, or state-transitioning.

- [x] Sweep `resources/core/views`, `app/Modules/**/Views`, and `extensions/**/Views` for read-first, in-place edit, and YAGNI save-button opportunities. {Codex/GPT-5}
- [x] Record affected Blade files, no-change exceptions, and workflow-save justifications in this plan. {Codex/GPT-5}
- [x] Convert AI chat session title editing to true inline edit without a Save button. {Codex/GPT-5}
- [x] Rework NCR action context fields into read-first summaries plus grouped inline draft editors, keeping workflow transition buttons explicit. {Codex/GPT-5}
- [x] Rework SCAR supplier response fields into read-first summaries plus grouped inline draft editors, keeping evidence upload and workflow transitions explicit. {Codex/GPT-5}
- [x] Convert monthly projection actual-close entry to field-level edit-in-place and remove the single-value Save/Cancel pair. {Codex/GPT-5}
- [x] Rework IBP pricing decision-support assumptions so current BA cost, selling price, and coating cost read first; use direct edit for coating and grouped editors for value-plus-notes pairs. {Codex/GPT-5}
- [x] Rework safety-stock policy rows into a read-first grouped editor shell; keep Apply/Cancel because the policy fields are coupled. {Codex/GPT-5}
- [x] Align workflow status PIC/Notify inline editors with shared read-first primitives when that surface is next touched. {Codex/GPT-5}
- [x] Decide whether forecast and production feedback list-row corrections are frequent enough to justify table-cell edit-in-place; decision: leave both as intentional form-first data-entry flows until repeated row-correction usage proves otherwise. {Codex/GPT-5}

Evidence: `php artisan view:cache`; `php artisan test tests\Feature\Modules\Core\AI\ChatViewTest.php`; `php artisan test app\Modules\Operation\Quality\Tests\Feature\QualityWorkflowUiTest.php`; `php artisan test tests\Feature\Workflow\WorkflowShowTest.php`; `php artisan test extensions\sb-group\ibp\Tests\Feature\IbpReadFirstEditsTest.php`.
