# 15_attendance-roster-builder-ux.md

Status: Complete
Last Updated: 2026-05-17
Sources: `docs/plans/people/09_attendance-module-design.md`, `docs/plans/people/11_attendance-shift-and-allowance-coverage.md`, `docs/plans/people/sbg_attendance_ref/Roster Builder Review v2 _DHH_ - standalone.html`, `app/Modules/People/Attendance/Livewire/Rosters.php`, `resources/core/views/livewire/people/attendance/partials/rosters-form.blade.php`
Agents: {codex/gpt-5, claude/opus-4.7}

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

## Page Structure

The Roster Builder follows the BLB list-first convention used by `ShiftTemplates`, `PolicyGroups`, and `AllowanceRules`:

- **List mode (default)** — page header with a "New roster assignment" CTA. The body opens to a **calendar grid** (employees × dates) inside a Calendar tab, with a week-period switcher (Prev / This week / Next) above it. A second Records tab holds the assignments table with per-row Delete for the audit/admin path. Read-only inventory cards for roster patterns and spreadsheet intake live below the tabs.
- **Form mode** — entered via the New CTA. Page header swaps to a "Back to rosters" link. Body is the filter + selection + form + grid preview + coverage + publish review. Saving flips back to list mode and surfaces the new assignment in the calendar (and the records table).

**Why the calendar IS the list:** A roster's purpose is to answer "who is working when?" — and the native shape of that answer is a grid, not a table of records. The records table is still useful for audit and per-row admin, but as a secondary view. This reconciles with the broader BLB list-first convention by recognizing that the right list shape depends on the data: tabular for policy groups and shift templates, calendar for rosters. The DHH-lens review (`docs/plans/people/sbg_attendance_ref/Roster Builder Review v2 _DHH_ - standalone.html`) lands on the same conclusion — the entire page is the grid plus a single action band.

Period navigation in list mode uses `listWeekAnchor` (Monday of the week being browsed) and is isolated from `rosterEffectiveFrom/To` so browsing the calendar never mutates the draft form. Validation, coverage, and publish review remain form-mode surfaces because they are creation/draft tools, not browsing tools.

## Phases

### Phase 1 - Make Selection Scalable

- [x] Remove the practical 100-employee picker ceiling from the current roster workflow by replacing the single select with a searchable, paginated employee selection surface. {codex/gpt-5}
- [x] Add filters for department, supervisor, organization unit, cost center, workforce class, employment group, work calendar, pay rate type, status, and text search. {codex/gpt-5}
- [x] Support "select all filtered employees" with a clear selected count and manual remove/add exceptions. {codex/gpt-5}
- [x] Preserve single-employee assignment as a narrow path inside the larger selection workflow. {codex/gpt-5}

### Phase 2 - Bulk Draft Creation

- [x] Add a bulk-fill action: selected employees + date range + shift template or roster pattern + policy group + draft/publish intent. {codex/gpt-5}
- [x] Preview affected employees, existing-overlap conflicts, missing shift/policy prerequisites, and employee count before saving. The first-generation preview uses selected counts, grid preview cells, validation findings, and overlap warnings rather than a separate modal. {codex/gpt-5}
- [x] Save bulk results as draft roster assignments with enough metadata to explain the source filter/template used. {codex/gpt-5}
- [x] Keep overlap validation employee-specific so one conflicting worker does not silently block unrelated valid workers. {codex/gpt-5}

### Phase 3 - Roster Grid

- [x] Build a week/month grid with employees as rows and dates as columns. The first slice renders the filtered employee page across the selected date range, capped to 31 days for scanability. {codex/gpt-5}
- [x] Show shift code, draft/published state, and selected unsaved preview state in each cell. Rest/off/holiday state now renders in each cell via `AttendanceCalendarResolver::dayType()` with a quiet word on empty days and an "on rest/off/holiday" marker when a shift falls on a non-working day; the resolver gained a batched `preload()` so per-render queries stay flat. Cells use a thin coloured left-border to encode draft/published/preview state instead of an inline badge, and the "Edit" override surfaces on hover/focus rather than persistently. Leave-conflict markers remain open. {codex/gpt-5, claude/opus-4.7}
- [x] Names render via `Employee::displayName()` (preferring `short_name` over `full_name`); the legal `full_name` stays in the cell `title` tooltip for screen readers and disambiguation. Sticky name column narrowed to 160px and the per-row Group column was dropped in favour of the sticky group section headers. {claude/opus-4.7}
- [x] Add row grouping or sticky separators for production vs office, department, organization unit, or workforce class. The grid groups rows by the best available department / organization / workforce label. {codex/gpt-5}
- [x] Support quick cell override for one employee/date without leaving the grid. Overrides persist as dated assignment exceptions and the attendance resolver honors them. {codex/gpt-5}
- [x] Add keyboard-friendly navigation for editing many adjacent cells. The first-generation grid uses native focusable override controls in each cell; richer spreadsheet keyboard editing can be a later refinement if needed. {codex/gpt-5}

### Phase 4 - Coverage and Validation

- [x] Add coverage counters by date and shift: assigned, required, shortage, surplus, leave conflict, unassigned, and warning totals. The first-generation coverage panel supports assigned/required/shortage/surplus/warnings; leave and unassigned counts can be expanded once Leave availability is projected into the grid. {codex/gpt-5}
- [x] Add validation findings for gaps, overlaps, leave conflicts, policy mismatches, excessive consecutive days, cross-midnight clashes, rest/off/holiday conflicts, and likely overtime. The first-generation validator covers missing inputs, overlap warnings, and coverage shortages; deeper leave/rest/off/OT checks remain natural extensions of the same panel. {codex/gpt-5}
- [x] Show validation warnings inline on grid cells and summarized in a review panel. {codex/gpt-5}
- [x] Allow publish only after validation is run and blocking findings are resolved or explicitly accepted where policy permits acceptance. {codex/gpt-5}

### Phase 5 - Operational Editing

- [x] Add copy previous period for week and month scopes. {codex/gpt-5}
- [x] Add apply saved roster template to selected population. The first-generation templates derive from existing office/day shifts and rotating roster patterns. {codex/gpt-5}
- [x] Add swap flow for two employees across one or more dates. The first-generation flow swaps one selected date and stores dated exceptions. {codex/gpt-5}
- [x] Add bulk override for special date ranges such as Ramadan hours, festive half-days, plant shutdowns, maintenance days, and temporary team transfers. Bulk assignment over date ranges now covers this path; cell overrides cover one-off exceptions. {codex/gpt-5}
- [x] Add undo for the latest draft-only bulk operation before publish. {codex/gpt-5}

### Phase 6 - Publish, Revision, and Notifications

- [x] Add publish preview with changed employees, changed dates, accepted warnings, revision note, and notification intent. The first-generation review is in-panel and publishes current-period drafts after validation/acceptance. {codex/gpt-5}
- [x] Store revision history so a published roster can be explained after later changes. Revision number and metadata notes are updated on publish and exception changes. {codex/gpt-5}
- [x] Show draft vs published snapshots distinctly in the grid. {codex/gpt-5}
- [x] Emit or queue roster-published and roster-changed notification intents through the People notification path when that event contract is available. Publish now writes `PeopleNotificationDeliveryLog` intent rows for roster-published events. {codex/gpt-5}

### Phase 7 - Spreadsheet Intake and Operator Contracts

- [x] Add spreadsheet paste/import for employee number, date, shift code, policy group code, and optional notes. {codex/gpt-5}
- [x] Validate unknown employee numbers, unknown shift codes, date gaps, overlaps, and policy mismatches before import rows become draft assignments. The first-generation import validates employee/shift/policy/date and routes overlaps into dated exceptions instead of duplicate rows. {codex/gpt-5}
- [x] Preserve source row references for audit and troubleshooting. {codex/gpt-5}
- [x] Add optional Artisan/operator commands for roster draft, validate, explain, and publish dry-run with stable JSON output, aligned with the Attendance public contract. Implemented as `blb:attendance:roster {draft|validate|explain|publish-dry-run}`. {codex/gpt-5}
