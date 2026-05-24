# 18e_roster-form-consolidation.md

**Status:** Complete
**Last Updated:** 2026-05-21
**Sources:**
- `docs/plans/people/18_roster_master.md` — master plan
- `docs/plans/base-audit-subject-index.md` — delegated audit replacement; adds `subject_name`, `subject_id`, `subject_identifier`, and expanded roster rows to `base_audit_mutations`. Phase 2's `people_attendance_roster_cell_log` table and `AttendanceRosterAssignmentObserver` are replaced by enriched rows on the mutations table.
- `docs/plans/people/18b_roster-grid-interaction.md` — grid interaction layer (complete)
- `resources/core/views/livewire/people/attendance/partials/rosters-form.blade.php` — form to be retired
- `resources/core/views/livewire/people/attendance/partials/rosters-grid.blade.php` — grid to receive folded features
- `resources/core/views/livewire/people/attendance/partials/rosters-list.blade.php` — list view to receive folded sections
- `app/Modules/People/Attendance/Livewire/Concerns/ManagesRosterOperations.php` — backend operations
- `app/Modules/People/Attendance/Services/AttendanceDayResolverService.php` — resolver to be updated
**Agents:** claude/sonnet-4.6; amp/gpt-5

## Problem Essence

The roster page splits the manager's workflow across two modes: a grid view and a separate form reachable only by "New roster assignment". The form holds bulk operations, publish control, and range-based assignment that the grid cannot reach. Separately, the draft/published state machine creates a false two-truth problem — a cell visible on the grid may be invisible to the attendance resolver if it was never published — while providing no real safety guarantee that justifies the extra step.

## Desired Outcome

The form mode is eliminated. Every operation a manager needs is reachable from the single grid surface. Draft and published are removed as user-visible concepts: whatever is on the grid is the roster, for all stakeholders. Changes are never lost — a full audit trail answers who changed what, when, and to what value, dissolving disputes without needing a publish gate. Edit access is controlled by the payroll lock event, not a manual publish step.

## Design Decisions

**One truth: drop draft/published entirely.** The `publish_state` column is removed from user-facing behaviour. `AttendanceDayResolverService` drops its `where('publish_state', 'published')` filter and reads all assignments regardless of state. Cell edits no longer carry a publish state. The column can be retained in the DB temporarily as dead metadata while data is migrated, then dropped. The legend simplifies: assigned cells have one visual style; the green/amber pill distinction goes away.

**Audit trail replaces publish as the accountability mechanism.** Every write to an assignment (create, update, delete, lock) is logged to a roster change log: employee, date, who changed it, previous shift and policy, new shift and policy, and timestamp. This gives supervisors, HR, and payroll a factual record to resolve disputes. It also enables a per-cell history drawer in the grid. The `revision` column on `AttendanceRosterAssignment` already increments on each change; the new log table stores the full before/after per revision.

**Edit access is controlled by lock_state only.** When payroll processes a period, it fires an event that sets `lock_state = 'locked'` on the affected assignments. Locked cells are already shown with a lock icon and block further edits. This is the correct semantic lock — payment has been made, the record is frozen. No manual publish step precedes it.

**Employee row selection via name column click.** Clicking the sticky employee name cell selects all of that employee's visible cells. Shift-click extends to a contiguous range of rows; Ctrl-click toggles individual rows. This replaces the form's checkbox list and keeps a single selection state across the grid, formula bar, and all modals.

**Coverage and validation panel dropped.** All three validation checks in `rosterValidationFindings()` are artefacts of the form's select-configure-save flow and do not apply to the direct-edit grid model. The grid is the coverage view.

**Toolbar row for bulk operations.** A slim action bar between the formula bar and the grid table carries: Select all visible, Clear selection, Copy previous period, Undo, Swap (opens modal), and Bulk assign (opens modal).

**Shift swap as selection-first modal.** Select one employee's row, click Swap, a modal lists all other visible employees as targets with the date inferred from the selected cells.

**Bulk assign as selection-first modal.** Select employee rows, click Bulk assign, a modal scoped to those rows exposes: repeat pattern, shift, policy, and effective-from/to date range. Internally calls the existing `saveRosterAssignment()` method.

**Preview state is dropped.** The direct-edit grid model has no unsaved pending state to preview.

**Mode toggle is removed last.** `$mode`, `startNewRosterAssignment()`, `cancelRosterForm()`, and `editingRosterAssignmentId` stay untouched until Phase 6 so the form remains functional while earlier phases ship.

## Phases

### Phase 1 — Drop draft/published

- [x] Remove `where('publish_state', 'published')` from `AttendanceDayResolverService`; resolver reads all assignments regardless of state. {claude/sonnet-4.6}
- [x] Update `day-tile.blade.php`: all assigned cells use a single neutral accent pill; `$state` prop retained for callers but no longer drives styling. {claude/sonnet-4.6}
- [x] Update the grid legend: remove Published and Draft entries; legend is now: Assigned / Rest / Off / Holiday. {claude/sonnet-4.6}
- [x] Update `BuildsRosterGrid::buildAssignedCell()`: state is now `'assigned'`; remove `variant` key; title no longer mentions draft/published. {claude/sonnet-4.6}
- [ ] Remove `publishReviewedRosters()` and `rosterPublishState` Livewire property; remove the "Save as" select from the form UI. *(deferred to Phase 6 — form stays until then)*
- [ ] Remove hardcoded `'publish_state' => 'draft'` writes from all operation methods. *(deferred to Phase 6 — column drop)*
- [ ] Drop `publish_state` column in a follow-up migration once all code references are removed. *(deferred to Phase 6)*

### Phase 2 — Audit trail

- [x] New migration: `people_attendance_roster_cell_log` table — domain-specific per-cell index on top of the existing `base_audit_mutations` global audit. Contains: `company_id`, `assignment_id`, `employee_id`, `date`, `changed_by`, `changed_at`, `action`, `previous_shift_id`, `previous_policy_id`, `new_shift_id`, `new_policy_id`, `note`, `job`. {claude/sonnet-4.6}
- [x] A range assignment that covers N dates expands atomically into N log rows — one row per affected date — capped at 366 days for open-ended assignments. {claude/sonnet-4.6}
- [x] `AttendanceRosterAssignmentObserver` handles created, updated (detecting exception vs. non-exception changes), and deleted events; registered in `ServiceProvider::boot()`. {claude/sonnet-4.6}
- [ ] `saveCellOverride()` and `saveCellOverrides()` accept optional `note` and `job` parameters. *(deferred to Phase 5 bulk assign modal)*
- [ ] Lock event writes a log row with `action = 'locked'` when `lock_state` transitions to locked. *(deferred — payroll lock event wiring out of scope)*
- [x] Grid UI: "History" button in the formula bar when a single cell is selected; opens a side drawer listing log rows for that employee × date — timestamp, actor, action badge, from → to shift/policy, note, job. {claude/sonnet-4.6}
- [x] History drawer has an "Open full history" link (new tab) → `RosterEmployeeHistory` Livewire component with date filter and paginated table. {claude/sonnet-4.6}
- [x] Cleanup: batch user-name lookup in `ManagesRosterCellHistory` (was N+1); removed stale draft/published wording from grid intro and dead `hasPublished` guard from `deleteSelection()`. {claude/sonnet-4.6}
- [x] **Superseded by audit delegation**: `people_attendance_roster_cell_log`, `AttendanceRosterAssignmentObserver`, and `ManagesRosterCellHistory` queries are replaced with enriched `base_audit_mutations` rows. {amp/gpt-5}
- [ ] **Note/job not surfaced in history drawer**: `ManagesRosterCellHistory::loadCellHistory()` hardcodes `note: null, job: null` because `AuditMutation.new_values` only carries `shift_code` / `policy_code`. The note/job live in the assignment's `metadata` or exception entry. Fix: include `note` and `job` keys in the per-date `new_values` written by `AttendanceRosterAssignment::buildExceptionAuditEntries()` and the range builders, then read them in `loadCellHistory()`. {planned}

### Phase 3 — Employee row selection via name column

- [x] Clicking the sticky employee name `<td>` selects that row (deselects if it was the only selection). {claude/sonnet-4.6}
- [x] Shift-click extends selection to all employee rows between the row anchor and the clicked row. {claude/sonnet-4.6}
- [x] Ctrl-click toggles that row without clearing others. {claude/sonnet-4.6}
- [x] Alpine `rosterGrid()` tracks `selectedRows` (employee ID strings) and `rowAnchor` (index) alongside the existing `selection` (cells); `clearSelection()` resets both. {claude/sonnet-4.6}
- [x] Selected employee name cell receives a 3px accent left border and 8% accent background tint via `.roster-row-selected`; does not interfere with cell-level selection outline. {claude/sonnet-4.6}

### Phase 4 — Grid toolbar

- [x] Slim toolbar between formula bar and grid table, visible to managers only; shows selected row count badge. {claude/sonnet-4.6}
- [x] "All" — `selectAllRows()` sets `selectedRows` to all rendered employee IDs. {claude/sonnet-4.6}
- [x] "Clear" — calls `clearSelection()` (resets both `selectedRows` and cell `selection`); disabled when nothing selected. {claude/sonnet-4.6}
- [x] "Copy previous" — `toolbarCopyPrevious()` syncs `selectedRows` (or all visible rows if none selected) to `selectedRosterEmployeeIds`, then calls `copyPreviousPeriod()`. {claude/sonnet-4.6}
- [x] "Undo" — calls `undoLastDraftRosterOperation()`; disabled when `lastDraftAssignmentIds` is empty. {claude/sonnet-4.6}
- [x] "Swap" — dispatches `open-swap-modal` with `empId`; disabled unless exactly one row is selected. Wired to modal in Phase 5. {claude/sonnet-4.6}
- [x] "Bulk assign" — dispatches `open-bulk-assign-modal` with `empIds`; disabled when no rows selected. Wired to modal in Phase 5. {claude/sonnet-4.6}

### Phase 5 — Swap and bulk assign modals

- [x] Swap modal: lists all other visible employees as targets (name from DOM); confirming calls the existing `swapRosterCells()`. {claude/sonnet-4.6}
- [x] Bulk assign modal: exposes repeat-pattern selector (when patterns exist), shift selector, policy selector, effective-from/to date inputs; submit calls the existing `saveRosterAssignment()`. {claude/sonnet-4.6}
- [x] Both modals surface validation errors inline; close on success (`dispatch('close-swap-modal')` / `dispatch('close-bulk-modal')`) and flash confirmation. {claude/sonnet-4.6}
- [x] Bulk assign modal passes an optional note field stored in assignment metadata (`rosterBulkNote` Livewire property); forwarding to audit log rows deferred pending audit observer update. {claude/sonnet-4.6}

### Phase 6 — Retire the form, preview state, and dead validation code

- [x] Remove `$mode`, `startNewRosterAssignment()`, `cancelRosterForm()`, and the "New roster assignment" / "Back" buttons. {claude/sonnet-4.6}
- [x] Delete `rosters-form.blade.php`. {claude/sonnet-4.6}
- [x] Remove `buildPreviewCell()`, the `selectedLookup` / `proposedShift` preview path from `BuildsRosterGrid`. `day-tile.blade.php` already clean (state prop retained as no-op). {claude/sonnet-4.6}
- [x] Remove `$showPreviewLegend` from grid include; no preview references remain in the grid partial. {claude/sonnet-4.6}
- [x] Remove `rosterValidationFindings()`, `validateRosterDraft()`, `acceptRosterWarnings()`, `rosterCoverageRows()`, `rosterCoverageMatrix()`, `rosterTemplates()`, `applyRosterTemplate()`, `publishReviewedRosters()`, and associated dead properties (`rosterRequiredPerShift`, `rosterTemplateKey`, `rosterValidationRan`, `rosterWarningsAccepted`, `rosterRevisionNote`, `rosterPublishState`). {claude/sonnet-4.6}
- [x] Remove `editingRosterAssignmentId` form-edit path: `editRosterAssignment()`, `updateExistingRosterAssignment()`, and the editing property removed. Edit button removed from assignment list. {claude/sonnet-4.6}
- [x] Keep `publish_state` as transitional schema metadata because current roster operations, the command surface, and dev seeders still reference it; user-facing draft/published behaviour remains removed. Dropping the column requires a separate code sweep. {claude/sonnet-4.6; amp/gpt-5}
