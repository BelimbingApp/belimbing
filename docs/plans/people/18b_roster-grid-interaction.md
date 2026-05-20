# 18b_roster-grid-interaction.md

**Status:** Built
**Last Updated:** 2026-05-20
**Sources:**
- `docs/plans/people/18_roster_master.md` — master plan
- `app/Modules/People/Attendance/Livewire/Rosters.php` — `saveCellOverride()` to be extended to batch
- `resources/core/views/livewire/people/attendance/partials/rosters-grid.blade.php` — grid cell rendering
- `resources/core/views/components/ui/day-tile.blade.php` — cell tile component
**Agents:** claude/sonnet-4.6

## Problem Essence

The grid currently supports one interaction: click a cell to open a per-cell override popover. That is sufficient for corrections but not for the job most supervisors actually do — filling a week's worth of shifts for 30 people. In Excel, that job is three gestures: select a range, drag the fill handle, done. Until BLB provides those gestures at comparable speed, supervisors will keep the spreadsheet open alongside BLB.

## Desired Outcome

A supervisor can select a cell, drag the fill handle across an employee's week (or down a shift column), release, and see the grid updated without leaving it. Keyboard users can navigate and edit without a mouse. Copy/paste moves patterns between rows or periods. Undo covers any batch operation. The fill handle is the single most important interaction on this page — it should feel fast and deliberate.

## Design Notes

**Selection model.** Selection state lives entirely in Alpine.js client-side; no Livewire round-trip until the user commits (releases the fill handle, pastes, or presses Delete). This keeps the interaction instant.

**Cell identification.** Each `<td>` already carries `wire:key="roster-grid-cell-{employee_id}-{date}"`. The interaction layer reads `data-employee` and `data-date` attributes (to be added) for range math without parsing the wire key.

**Batch action.** `saveCellOverrides(array $overrides)` where each entry is `{employee_id, date, shift_template_id, policy_group_id}`. Handles fill, paste, and delete (delete passes `shift_template_id: 0, policy_group_id: 0`). Wraps in one transaction and triggers the same validation/state refresh as `saveCellOverride()`.

**Cycle detection.** Given a source selection of N cells for one employee, compare the sequence modulo candidate periods 2–14. If the sequence repeats with period P (all cells at positions i and i+P have the same shift), treat P as the fill period and continue the cycle into the drag target range rather than copying just cell 0.

**Undo.** Extend the existing draft-undo mechanism (plan 15 Phase 5) to cover any batch op from this phase. Store the before-state of affected cells; undo restores them in one batch write.

**Scope.** This phase is draft-only. Published cells: the fill handle and paste are blocked; Delete on a published cell shows a confirmation ("This cell is published. Clear it and return to draft?") before proceeding.

## Phase 3 — Grid interaction layer

### Selection
- [ ] Add `data-employee="{{ $employee->id }}"` and `data-date="{{ $day['date'] }}"` to every grid `<td>` so the interaction layer can identify cells without parsing wire keys.
- [ ] Alpine `x-data="rosterGrid()"` on the `<tbody>` (or the grid wrapper): manages `selection` (set of `{employee, date}` pairs), `copyBuffer`, `dragState`.
- [ ] Click on a cell sets selection to that single cell (clears previous selection). Visual: highlight ring on the selected cell.
- [ ] Shift-click extends selection to the rectangular range between the previously selected cell and the clicked cell.
- [ ] Shift-Arrow (up/down/left/right) extends or shrinks selection by one cell in the given direction, clamped to the visible grid.
- [ ] Tab / Shift-Tab moves focus one cell right / left within the row (standard browser tab order is already correct if cells are focusable).
- [ ] Arrow keys (without Shift) move focus — and selection — one cell in the given direction.
- [ ] Escape clears selection and any in-progress drag.

### Fill handle
- [ ] Render a small circle element (4×4 px, accent color) at the bottom-right corner of the last cell in the current selection. Visible only when at least one cell is selected. `cursor: crosshair` on hover.
- [ ] Mousedown on the fill handle starts drag: `dragState = { active: true, source: currentSelection, anchor: cell under cursor }`.
- [ ] Mousemove during drag: compute the target range (source selection extended to the hovered cell, clamped to same employee rows or same date columns depending on drag direction); highlight target cells with a ghost preview (muted accent tint).
- [ ] Mouseup commits: build the `overrides` array from the target range (applying cycle-aware fill to determine each cell's shift/policy), call `$wire.saveCellOverrides(overrides)`, clear drag state.
- [ ] Cycle detection: given the source cell sequence (ordered by date), detect the smallest repeating period P in [2..14]; continue the cycle for each target cell at position `sourceLength + i` as `source[i % P]`.
- [ ] Touch support: touchstart/touchmove/touchend on the fill handle mirror the mouse events for tablet use.

### Copy / paste
- [ ] Ctrl-C (or Cmd-C): copy current selection into Alpine `copyBuffer` (array of `{employee, date, shift_template_id, policy_group_id}`); draw a dashed border around the source cells ("marching ants" style — CSS animation on the selection border).
- [ ] Ctrl-V (or Cmd-V) with a target cell selected: paste the buffer starting at the target cell. If the buffer is wider than the target row's remaining columns, clamp. If multi-row buffer, paste into subsequent employee rows in the same visual order. Call `saveCellOverrides()`.
- [ ] Escape clears the copy buffer and removes the dashed border.
- [ ] Copy buffer is scoped to the page (Alpine state); it does not write to the OS clipboard (roster assignments are not text).

### Delete and clear
- [ ] Delete key on a selection of draft cells: call `saveCellOverrides()` with `shift_template_id: 0, policy_group_id: 0` for each selected cell (clears the override).
- [ ] Delete key on a selection that includes published cells: show a confirmation ("X published cells will revert to draft and be cleared. Continue?") before proceeding.
- [ ] Backspace is treated identically to Delete.

### Undo
- [ ] Before any `saveCellOverrides()` call, capture the current state of all affected cells into a before-snapshot.
- [ ] Add the snapshot to the existing draft-undo stack.
- [ ] The existing undo affordance (plan 15 Phase 5) restores the snapshot via `saveCellOverrides()` with the before-state.

### Backend
- [ ] Add `saveCellOverrides(array $overrides): void` to `Rosters.php`. Each entry: `{employee_id: int, date: string, shift_template_id: int, policy_group_id: int}`. Zero values mean "clear the override for this cell." Wraps all writes in one DB transaction. Re-runs `enrichGridDays()` and returns updated grid state.
- [ ] Validate each entry: employee belongs to the current company, date is within the current grid period, shift and policy (when non-zero) belong to the current company.
- [ ] Add feature tests: batch fill across a date range; cycle-aware fill with a 7-day source; paste into a target cell; delete clears overrides; published-cell delete requires confirmation flag.
