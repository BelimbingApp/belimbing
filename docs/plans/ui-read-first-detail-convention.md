# UI Read-First Detail Convention

**Status:** Complete
**Last Updated:** 2026-06-13
**Sources:** `DESIGN.md`, `resources/core/views/AGENTS.md`, `docs/plans/ui-reference-catalog.md`, `resources/core/views/livewire/admin/system/ui-reference/partials/interaction-patterns.blade.php`, `resources/core/views/livewire/admin/addresses/show.blade.php`
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
