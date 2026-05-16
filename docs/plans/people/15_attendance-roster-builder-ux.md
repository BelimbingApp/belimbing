# 15_attendance-roster-builder-ux.md

Status: In Progress
Last Updated: 2026-05-16
Sources: `docs/plans/people/09_attendance-module-design.md`, `docs/plans/people/11_attendance-shift-and-allowance-coverage.md`, `app/Modules/People/Attendance/Livewire/Rosters.php`, `resources/core/views/livewire/people/attendance/partials/rosters-form.blade.php`
Agents: {codex/gpt-5}

## Problem Essence

The current Roster Builder creates one employee assignment at a time. That is acceptable for proving the attendance data path, but it does not match the real supervisor job of building and publishing rosters for a few hundred production and office workers across repeating shifts, departments, cost centers, leave conflicts, holidays, and special date ranges.

The screen should become a workforce scheduling surface, not a single-record admin form.

## Desired Outcome

Supervisors and HR users can create a complete roster for hundreds of workers with a few high-confidence actions: filter a workforce, apply a reusable pattern or shift template over a date range, review coverage and validation warnings, make swaps or exceptions, then publish with an auditable snapshot and notification intent.

The fastest path should be "select this population, apply this pattern, review exceptions, publish." Single-employee assignment remains available, but it is no longer the primary workflow.

## Top-Level Components

**Roster Grid**

A dense calendar grid with employees as rows and dates as columns. Cells show shift code, rest/off state, leave conflict, override marker, or warning state. The grid should support week and month scopes, keyboard-friendly editing, and compact scanning for 100-300 employees.

**Population Selector**

A filterable employee picker backed by existing employee and work-profile dimensions: department, supervisor, cost center, organization unit, workforce class, employment group, work calendar, pay rate type, status, and search by employee number/name. Selection must support "all filtered employees" and manually checked exceptions.

**Bulk Fill Panel**

A side panel for applying a shift, roster pattern, policy group, or saved roster template to the selected population over a date range. It should preview the affected employee count and conflict count before writing a draft.

**Coverage View**

A per-date and per-shift summary showing required, assigned, shortage, surplus, leave conflict, unassigned, and warning counts. Production teams need coverage counts first; office teams need exception visibility first.

**Validation and Publish Review**

A deterministic review step that explains gaps, overlaps, leave clashes, policy mismatches, excessive consecutive days, cross-midnight clashes, likely overtime, rest/off/holiday handling, and notification effects before publish.

**Spreadsheet Intake**

A paste/import path for employee-number/date/shift-code rosters from Excel or CSV. Imported rows remain drafts until validated and reviewed.

## Design Decisions

**Use the grid as the main workspace.** The current form-first layout scales linearly with headcount. A grid lets supervisors inspect patterns, spot gaps, and make exceptions without opening one form per person.

**Optimize for bulk actions before fine-grained edits.** Few-hundred-person roster work is mostly applying known patterns to known groups, then fixing exceptions. The UI should make the bulk path obvious and reserve cell-level editing for corrections, swaps, and one-off overrides.

**Separate production and office mental models without creating separate modules.** Production needs shift coverage, rotations, rest-day/off-day routing, and cross-midnight warnings. Office needs fixed weekday schedules, holiday visibility, and exception handling. A mode or saved view can tune the same builder for each job.

**Treat saved templates as operational assets.** Common rosters such as "Office Monday-Friday", "Production Team A rotation", "Production Team B rotation", "Ramadan office hours", and "plant shutdown week" should be reusable draft inputs with visible shift/policy assumptions.

**Reuse People work-profile dimensions for population targeting.** The system already has cost center, organization unit, employment group, workforce class, work calendar, pay basis, department, and supervisor. The roster builder should use these dimensions before adding new grouping concepts.

**Keep publish behind deterministic validation.** AI or spreadsheet intake may help prepare a draft later, but roster publish remains a human-confirmed Attendance operation with stable warning codes, actor attribution, revision history, and immutable published snapshots.

## Public Contract

A roster draft should support:

- employee and cohort targets selected from employee/work-profile filters;
- date range, shift template, roster pattern, policy group, and saved-template inputs;
- ad hoc cell overrides for swapped shifts, rest/off-day changes, holiday work, leave cover, and employee-specific exceptions;
- draft validation results with stable codes, severity, affected employee/date/path, and human-readable explanation;
- coverage summaries by date and shift;
- publish preview with changed employees, changed dates, warnings accepted, notification intent, and revision note;
- spreadsheet import rows preserved with source row references and unresolved-code warnings.

The roster grid should show, at minimum:

- employee number and display name;
- department or organization unit;
- workforce class or cost center;
- selected period dates;
- assigned shift/rest/off/leave/conflict state per date;
- row-level warning count;
- published vs draft distinction.

## Phases

### Phase 1 - Make Selection Scalable

- [x] Remove the practical 100-employee picker ceiling from the current roster workflow by replacing the single select with a searchable, paginated employee selection surface. {codex/gpt-5}
- [x] Add filters for department, supervisor, organization unit, cost center, workforce class, employment group, work calendar, pay rate type, status, and text search. {codex/gpt-5}
- [x] Support "select all filtered employees" with a clear selected count and manual remove/add exceptions. {codex/gpt-5}
- [x] Preserve single-employee assignment as a narrow path inside the larger selection workflow. {codex/gpt-5}

### Phase 2 - Bulk Draft Creation

- [x] Add a bulk-fill action: selected employees + date range + shift template or roster pattern + policy group + draft/publish intent. {codex/gpt-5}
- [ ] Preview affected employees, existing-overlap conflicts, missing shift/policy prerequisites, and employee count before saving.
- [x] Save bulk results as draft roster assignments with enough metadata to explain the source filter/template used. {codex/gpt-5}
- [x] Keep overlap validation employee-specific so one conflicting worker does not silently block unrelated valid workers. {codex/gpt-5}

### Phase 3 - Roster Grid

- [ ] Build a week/month grid with employees as rows and dates as columns.
- [ ] Show shift code, rest/off/holiday state, draft/published state, leave conflict marker, and override marker in each cell.
- [ ] Add row grouping or sticky separators for production vs office, department, organization unit, or workforce class.
- [ ] Support quick cell override for one employee/date without leaving the grid.
- [ ] Add keyboard-friendly navigation for editing many adjacent cells.

### Phase 4 - Coverage and Validation

- [ ] Add coverage counters by date and shift: assigned, required, shortage, surplus, leave conflict, unassigned, and warning totals.
- [ ] Add validation findings for gaps, overlaps, leave conflicts, policy mismatches, excessive consecutive days, cross-midnight clashes, rest/off/holiday conflicts, and likely overtime.
- [ ] Show validation warnings inline on grid cells and summarized in a review panel.
- [ ] Allow publish only after validation is run and blocking findings are resolved or explicitly accepted where policy permits acceptance.

### Phase 5 - Operational Editing

- [ ] Add copy previous period for week and month scopes.
- [ ] Add apply saved roster template to selected population.
- [ ] Add swap flow for two employees across one or more dates.
- [ ] Add bulk override for special date ranges such as Ramadan hours, festive half-days, plant shutdowns, maintenance days, and temporary team transfers.
- [ ] Add undo for the latest draft-only bulk operation before publish.

### Phase 6 - Publish, Revision, and Notifications

- [ ] Add publish preview with changed employees, changed dates, accepted warnings, revision note, and notification intent.
- [ ] Store revision history so a published roster can be explained after later changes.
- [ ] Show draft vs published snapshots distinctly in the grid.
- [ ] Emit or queue roster-published and roster-changed notification intents through the People notification path when that event contract is available.

### Phase 7 - Spreadsheet Intake and Operator Contracts

- [ ] Add spreadsheet paste/import for employee number, date, shift code, policy group code, and optional notes.
- [ ] Validate unknown employee numbers, unknown shift codes, date gaps, overlaps, and policy mismatches before import rows become draft assignments.
- [ ] Preserve source row references for audit and troubleshooting.
- [ ] Add optional Artisan/operator commands for roster draft, validate, explain, and publish dry-run with stable JSON output, aligned with the Attendance public contract.
