# UI Table Component Migration

**Agents:** Amp; GPT-5.5/gpt-5.5
**Status:** In Progress (Phase 3 complete)
**Last Updated:** 2026-05-26
**Sources:** `resources/core/views/AGENTS.md`, `resources/core/views/components/ui/table.blade.php`, `resources/core/views/components/ui/sortable-th.blade.php`, `resources/core/views/livewire/admin/system/ui-reference/partials/data-display.blade.php`

## Problem Essence

BLB now has enough repeated table markup that keeping each table shell hand-authored will create drift in borders, overflow behavior, captions, row hover treatment, empty states, and sticky-header behavior. A shallow wrapper would not be worth a repo-wide migration, but the current `x-ui.table` direction is deep enough to own recurring presentation concerns while leaving rows, cells, Livewire edit state, sorting, pagination, and domain formatting with the caller.

The migration touches many files. It should be tracked explicitly so an interrupted build does not leave the codebase in an unknown half-migrated state.

## Desired Outcome

Most interactive application tables should use `x-ui.table` for the shared table shell. Callers should keep full ownership of table content: `x-ui.sortable-th`, custom `<th>` cells, row loops, `wire:key`, in-place edit controls, action groups, empty-state copy, and pagination remain local to the page or module.

The finished codebase should have fewer duplicated table wrappers, a clearer canonical table pattern in the UI reference, and no loss of accessibility or Livewire behavior.

## Design Decisions

**Migrate only semantic application tables first.** The first pass should target HTML tables used for admin and module data grids. PDFs, log monospace renderers, calendar grids, and other specialized layouts should be assessed separately because their constraints differ from interactive app tables.

**Keep `x-ui.table` presentational.** The component should own table-level presentation only: container style, caption, text size, sticky header, row hover, striping, dividers, empty state, and slots for head/body/foot. It should not learn about query builders, Eloquent models, pagination, sorting state, Livewire actions, row identity, or in-place editing.

**Prefer local row markup over a generic column schema.** BLB tables contain links, badges, date-time displays, money, expandable rows, row actions, form controls, and module-specific copy. A column-schema table builder would likely become a leaky abstraction. Row and cell markup should remain explicit.

**Preserve in-place editing compatibility.** Editable cells should keep their `x-ui.edit-in-place.*`, form controls, `wire:model`, `wire:blur`, and save handlers directly inside caller-owned `<td>` elements. If hover or striping makes editing noisy, the caller can disable `row-hover` or `striped` for that table.

**Use batches that are easy to verify.** Each batch should be small enough to inspect in diff and validate with `php artisan view:cache`. Broader browser checks can be done for representative pages after several similar tables migrate.

## Public Contract

- `x-ui.table` is the canonical shared shell for normal application tables.
- `x-ui.sortable-th` remains the canonical sortable-header primitive.
- Table rows and cells remain ordinary Blade markup supplied by the caller.
- Empty states can be rendered by `x-ui.table` when simple, or by caller markup when the table needs richer content or unusual row structure.
- Specialized non-application tables may remain raw if `x-ui.table` would make the intent less clear.

## Non-goals

- Do not build a data-table framework in this migration.
- Do not change sorting, filtering, pagination, authorization, or Livewire state behavior while migrating markup.
- Do not convert PDF tables in the first interactive-table pass.
- Do not force calendar-like grids, log viewers, or dense diagnostic tables into the component if the shell does not fit.

## Migration Inventory

Initial scan found 124 `<table>` occurrences across 84 Blade files, including the new `x-ui.table` component itself. The bulk of repeated application shell markup uses `min-w-full divide-y divide-border-default text-sm`.

High-level buckets:

- Core admin Livewire views: 65 tables in 52 files.
- Core shared components: 3 tables in 3 files, including specialized primitives.
- Core PDF payroll views: 13 tables in 5 files; defer from first pass.
- Commerce module views: 6 tables in 4 files.
- Operation module views: 3 tables in 3 files.
- People module views: 34 tables in 17 files; several are dense domain workspaces and should be migrated in smaller slices.

## Phases

### Phase 0 — Confirm Component Contract

- [x] Review `x-ui.table` API before broad adoption: container variants, caption behavior, empty-state slot, sticky-header class, `row-hover`, `striped`, `divided`, `size`, and whether named `body` is necessary in addition to the default slot. {GPT-5.5/gpt-5.5}
- [x] Confirm the UI reference example demonstrates the preferred usage clearly enough for future agents. {GPT-5.5/gpt-5.5}
- [x] Run `php artisan view:cache` after any component API adjustment. {GPT-5.5/gpt-5.5}

### Phase 1 — Low-Risk Core Admin Index Tables

- [x] `resources/core/views/livewire/admin/authz/capabilities/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/authz/decision-logs/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/authz/principal-capabilities/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/authz/principal-roles/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/audit/actions.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/audit/mutations.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/roles/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/sessions/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/scheduled-tasks/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/job-batches/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/failed-jobs/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/logs/index.blade.php` {GPT-5.5/gpt-5.5}

### Phase 2 — Core Admin Detail and System Tables

- [x] `resources/core/views/livewire/admin/addresses/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/addresses/show.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/companies/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/companies/show.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/companies/relationships.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/companies/partials/company-addresses.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/employees/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/employees/show.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/users/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/users/show.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/workflows/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/workflows/show.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-tables/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-tables/partials/show-data-tab.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-tables/partials/show-relationships-tab.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-tables/partials/show-schema-tab.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-queries/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-queries/show.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-incubation/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/database-backups/index.blade.php` {GPT-5.5/gpt-5.5}

### Phase 3 — AI, Integration, GeoNames, and Other Core Tables

- [x] `resources/core/views/livewire/admin/ai/control-plane.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/ai/pricing-overrides.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/ai/control-plane/partials/recent-runs.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/ai/control-plane/partials/run-detail.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/ai/providers/providers.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/ai/providers/partials/model-table.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/ai/tools/catalog.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/integration/outbound-exchanges/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/geonames/admin1/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/geonames/countries/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/geonames/postcodes/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/system/menu-inspector/index.blade.php` {GPT-5.5/gpt-5.5}
- [x] `resources/core/views/livewire/admin/setup/lara.blade.php` {GPT-5.5/gpt-5.5}

### Phase 4 — Module Application Tables

- [ ] `app/Modules/Commerce/Catalog/Views/livewire/commerce/catalog/index.blade.php`
- [ ] `app/Modules/Commerce/Inventory/Views/livewire/commerce/inventory/items/index.blade.php`
- [ ] `app/Modules/Commerce/Marketplace/Views/livewire/commerce/marketplace/ebay/index.blade.php`
- [ ] `app/Modules/Commerce/Marketplace/Views/livewire/commerce/marketplace/ebay/settings.blade.php`
- [ ] `app/Modules/Operation/IT/Views/livewire/it/tickets/index.blade.php`
- [ ] `app/Modules/Operation/Quality/Views/livewire/quality/ncr/index.blade.php`
- [ ] `app/Modules/Operation/Quality/Views/livewire/quality/scar/index.blade.php`
- [ ] `app/Modules/People/Employees/Views/livewire/people/employees/index.blade.php`
- [ ] `app/Modules/People/Settings/Views/livewire/people/settings/index.blade.php`
- [ ] `app/Modules/People/Attendance/Views/livewire/people/attendance/approvals.blade.php`
- [ ] `app/Modules/People/Attendance/Views/livewire/people/attendance/policy-groups.blade.php`
- [ ] `app/Modules/People/Attendance/Views/livewire/people/attendance/roster-employee-history.blade.php`
- [ ] `app/Modules/People/Attendance/Views/livewire/people/attendance/partials/allowance-rules-table.blade.php`
- [ ] `app/Modules/People/Attendance/Views/livewire/people/attendance/partials/approvals-queue.blade.php`
- [ ] `app/Modules/People/Attendance/Views/livewire/people/attendance/partials/shift-templates-table.blade.php`
- [ ] `app/Modules/People/Payroll/Views/livewire/people/payroll/attendance-allowance-mapping.blade.php`
- [ ] `app/Modules/People/Payroll/Views/livewire/people/payroll/claim-type-pay-item-mapping.blade.php`
- [ ] `app/Modules/People/Payroll/Views/livewire/people/payroll/index.blade.php`
- [ ] `app/Modules/People/Payroll/Views/livewire/people/payroll/leave-type-pay-item-mapping.blade.php`

### Phase 5 — Special-Case Assessment

- [ ] Decide whether `resources/core/views/components/ui/template-picker.blade.php` should use `x-ui.table` or remain a self-contained chooser primitive.
- [ ] Decide whether `resources/core/views/components/ui/day-strip.blade.php` should stay raw because it renders only a `<thead>` fragment for calendar-like tables.
- [ ] Decide whether `resources/core/views/livewire/admin/system/logs/show.blade.php` should stay raw because it is a monospace log viewer.
- [ ] Decide whether People claim and leave multi-table workspaces should migrate as-is or wait for a stronger domain-specific table/edit pattern.
- [ ] Decide whether People attendance `rosters-grid` and `attendance-days-card` are true tables or calendar/grid layouts that should remain local.
- [ ] Decide whether all payroll PDF views stay raw permanently or need a separate PDF-table primitive.

### Phase 6 — Cleanup and Verification

- [ ] Re-scan Blade views for raw application table shells that should have migrated.
- [ ] Re-scan for `min-w-full divide-y divide-border-default text-sm` outside `x-ui.table` and document intentional exceptions.
- [ ] Run `php artisan view:cache`.
- [ ] Spot-check representative pages in browser if available: one core index table, one core detail table, one module table, one empty state, and one table with editable cells or row actions.
- [ ] Set plan status to Complete when migrated tables and documented exceptions match the final scan.

## Verification Strategy

Each implementation batch should run `php artisan view:cache`. For batches that touch interactive rows, sorting, Livewire actions, or in-place editing, inspect the diff for preserved `wire:key`, `wire:click`, `wire:model`, `wire:blur`, route links, and pagination. Browser checks should focus on representative pages rather than every table, unless a batch changes the component API.

## Open Questions

- Should `x-ui.table` expose a dedicated `empty-class` prop, or is the `emptyState` slot enough for richer empty states?
- Should `x-ui.table` provide a documented row class helper later, or should row styling remain entirely caller-owned?
- Should PDF tables get their own primitive after application tables are stable?
