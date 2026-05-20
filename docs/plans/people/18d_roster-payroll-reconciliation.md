# 18d_roster-payroll-reconciliation.md

**Status:** Complete
**Last Updated:** 2026-05-20
**Sources:**
- `docs/plans/people/18_roster_master.md` — master plan
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 chain reference
- `docs/plans/people/09_attendance-module-design.md` — attendance module design
- `app/Modules/People/Attendance/Livewire/Rosters.php`
**Agents:** claude/sonnet-4.6

## Problem Essence

The roster has no connection to payroll processing. There is no mechanism to prevent retroactive edits after a period has been processed, no surface to verify that what was planned matches what was actually worked, and no signal at draft time when a shift assignment will generate overtime. HR2000 closes these gaps through period locking and an Employee Timecard view; BLB currently has neither, meaning a roster that has already fed payroll can still be silently edited.

## Desired Outcome

A completed period is locked automatically (or by explicit HR action) after payroll cut-off. The grid shows the lock visually so supervisors do not attempt edits they cannot make. When clock-in data is available, a toggle on the grid overlays actual attendance outcomes against the planned shifts, so discrepancies are visible before payroll runs. Shifts that will generate OT are flagged during draft, not discovered post-fact.

## Design Notes

**Lock is period-scoped, not cell-scoped.** A lock covers an entire period (the company's pay period or a manually specified date range) for one company. Individual cells in a locked period cannot be overridden; the period nav shows a lock icon; the bulk fill handle and form mode CTA are hidden. An unlock requires `people.attendance.roster.unlock` capability and a mandatory reason.

**Planned vs actual is a grid mode, not a separate screen.** The same grid partial gains a mode toggle ("Planned / Actual"). In Actual mode, each cell's visual state is augmented with attendance outcome data from clock-in events — no separate route or Livewire component. This matches how HR2000's timecard is conceptually the roster read through actual events.

**OT flag is a draft-time warning, not a block.** When a shift extends beyond contracted hours or falls on a rest/off/holiday day, the cell carries an amber ⏱ marker in draft mode. Supervisors can acknowledge the flag (which logs the intentional OT) or change the shift. The flag does not prevent publish; it creates an audit record when it is published over.

**Dependencies.** Phases 6 and 7 depend on:
- Clock-in event data being available in a queryable form (Phase 7 — can be built speculatively with a null-safe fallback; grid simply shows no actual overlay when no clock data exists).
- Payroll module emitting a cut-off event or exposing a queryable period table (Phase 6 — the lock can also be triggered manually by HR admin without waiting for the Payroll module).

## Phase 6 — Roster lock

- [ ] **`attendance_roster_locks` table.** Columns: `company_id`, `period_start` (date), `period_end` (date), `locked_by` (actor FK), `locked_at`, `unlock_reason` (nullable text), `unlocked_by` (nullable actor FK), `unlocked_at` (nullable). Unique on `(company_id, period_start, period_end)`.
- [ ] **Lock check in `Rosters.php`.** `isLockedPeriod(CarbonImmutable $date): bool` — returns true if any lock record covers the given date for the current company. Called in `rosterGridCell()` and used to suppress override controls.
- [ ] **Grid visual for locked periods.** Date column headers in a locked period show a lock icon (🔒) beside the date label. Cells in locked columns render read-only regardless of `canManage`. The fill handle and form mode CTA are hidden when the selected date range overlaps a locked period.
- [ ] **`lockRosterPeriod(string $from, string $to): void` action.** Gated by `people.attendance.manage`. Creates the lock record. Emits a `RosterPeriodLocked` event for downstream consumers (e.g., payroll confirmation).
- [ ] **`unlockRosterPeriod(string $from, string $to, string $reason): void` action.** Gated by `people.attendance.roster.unlock` (new capability, higher trust than `manage`). Updates the lock record with reason, actor, and timestamp. Reason is mandatory (empty string blocked at validation).
- [ ] **Payroll cut-off hook.** When the Payroll module is available and emits a `PayrollPeriodClosed` event (or equivalent), a listener calls `lockRosterPeriod()` automatically for that period. Until the Payroll module is ready, lock is manual-only.
- [ ] **Feature tests.** Override on a locked cell returns a 403 / validation error. Unlock requires the higher capability. Lock record survives a `Rosters` re-render.

## Phase 7 — Planned vs actual reconciliation and OT flag

### OT flag at draft stage

- [ ] **Contracted hours reference.** Add a method (or reuse an existing attendance-policy query) to retrieve an employee's daily contracted hours for a given date, derived from their work calendar and policy group.
- [ ] **`computeOtFlag(int $employeeId, string $date, int $shiftTemplateId): bool` helper.** Returns true if the shift's duration exceeds contracted hours, or if the day type is rest/off/holiday and a shift is assigned. Called in `buildAssignedCell()`.
- [ ] **Cell OT marker.** When `computeOtFlag` returns true, set `ot_flag: true` in the cell data. The grid renders an amber ⏱ icon inside the cell (alongside the shift code, below it in compact mode). Marker persists on published cells.
- [ ] **Acknowledgment of intentional OT.** A supervisor can click the ⏱ marker to record an explicit OT acknowledgment (`attendance_roster_ot_flags` table: `employee_id`, `date`, `acknowledged_by`, `acknowledged_at`). After acknowledgment, the marker changes to a muted ⏱ (acknowledged state) but remains visible.

### Planned vs actual overlay

- [ ] **`AttendanceOutcome` value object.** Shape: `{employee_id, date, status: matched|late|absent|early|no_record, clocked_in_at: ?datetime, clocked_out_at: ?datetime, variance_minutes: int}`. Populated from clock-in events when available; returns `no_record` gracefully when no clock data exists for a date.
- [ ] **`rosterActualOutcomes(Collection $employees, array $dates): array` method.** Batch-fetches outcomes keyed by `{employee_id}-{date}`. Falls back to all `no_record` when the clock-in data source is unavailable (null-safe — this method can be built before clock-in is implemented).
- [ ] **Grid mode toggle.** A "Planned / Actual" toggle button on the grid toolbar (gated by `attendance.manage`). Actual mode passes the outcomes array into the grid partial via a `$actualOutcomes` variable.
- [ ] **Cell rendering in Actual mode.** Each cell overlays the outcome status: matched = no change; absent = red tint on the tile surface; late = amber tint; early departure = amber tint; no record yet (future date) = neutral. Outcome values are read from `$actualOutcomes["{employee_id}-{date}"]` in the Blade partial; the day-tile component gains an optional `actualStatus` prop for the tint.
- [ ] **Actual mode is read-only.** Override controls, fill handle, and form mode CTA are hidden when Actual mode is active — the overlay shows what happened, not what can be changed.
- [ ] **Feature tests.** Grid in Actual mode renders correct tints for matched/absent/late outcomes; no_record dates show neutral; mode toggle is hidden without `attendance.manage`.
