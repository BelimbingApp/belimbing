# 18c_roster-self-service.md

**Status:** Complete
**Last Updated:** 2026-05-20
**Sources:**
- `docs/plans/people/18_roster_master.md` — master plan
- `app/Modules/People/Attendance/Livewire/Rosters.php` — existing roster data
- `app/Modules/People/Attendance/Views/livewire/people/attendance/partials/rosters-grid.blade.php`
**Agents:** claude/sonnet-4.6

## Problem Essence

Employees — the people most affected by the roster — currently have no way to view their own schedule in BLB. They ask supervisors verbally, check a WhatsApp group, or look at a laminated sheet in the break room. Supervisors spend time answering "what shift am I on Friday?" instead of doing roster work. The printed or shared roster has no acknowledgment mechanism, so supervisors cannot confirm who has seen their updated shifts.

## Desired Outcome

An employee can open BLB (or follow a notification link) and see their shifts for this week and next in under three seconds, on any device. After a roster is published or amended, supervisors see who has acknowledged. Supervisors can also generate a paper copy of the roster for posting on a physical notice board.

## Design Notes

**My Schedule is not a separate module.** It is the Roster page rendered through the `roster.view` capability lens established in 18a. The same Livewire component, the same grid data, filtered to one employee's row and stripped of management controls. No new route is strictly necessary — the existing `/attendance/roster` renders appropriately based on capability. A dedicated `/my-schedule` or employee-portal route is a routing convenience, not an architectural requirement.

**Acknowledgment is soft.** Publishing a roster does not block on acknowledgment. It is a signal to the supervisor, not a gate. Employees who do not acknowledge are not penalized; supervisors use the count to decide whether to follow up.

**Print layout goal.** The printout should be readable when photocopied and posted at A4 landscape. Large shift codes (at minimum 14pt), department name as a section header, employee names on the left, dates across the top, no background colours (uses border to separate cells instead).

## Phase 4 — Employee My Schedule

- [ ] **My Schedule view.** When the Roster page is accessed by a user with `roster.view` but without `attendance.manage` (established in 18a Phase 2), render the grid filtered to that user's associated employee row only. No form mode CTA, no override buttons, no coverage strip, no filter prose. Period nav (prev / this week / next) stays. Month scope toggle stays.
- [ ] **Mobile layout.** At narrow viewports, collapse the grid to a vertical list of days (one day per row, employee name in a sticky header at top). Each day shows: date, day name, shift code and name, rest/off/holiday label if applicable. Keeps the same Blade partial with responsive classes; no separate template.
- [ ] **"Your schedule has been updated" notification.** When a roster is published or a dated exception affecting a specific employee is saved, emit a notification (via the People notification path) with the affected date range and a deep-link to `/attendance/roster?scope=week&anchor={start_of_affected_week}`. Uses the existing `PeopleNotificationDeliveryLog` pattern from plan 15 Phase 6.
- [ ] **Shift acknowledgment UI.** On the My Schedule view, show an "Acknowledge" button per published week (not per cell). Tapping it records an acknowledgment (`attendance_roster_acknowledgments` table: `employee_id`, `period_start`, `period_end`, `acknowledged_at`, `actor_id`). Once acknowledged, the button changes to "Acknowledged ✓" and cannot be re-triggered for the same period unless the roster is amended (which resets acknowledgment for affected employees).
- [ ] **Acknowledgment count on supervisor grid.** In the Roster grid's period header row (where the week label and period nav live), show "N / M acknowledged" when acknowledgments exist for the current period. Clicking the count opens a popover listing acknowledged and unacknowledged employees. Gated by `attendance.manage`.
- [ ] **Reset acknowledgment on amendment.** When a dated override is saved for a specific employee after the roster is published, clear that employee's acknowledgment for the affected period so they are prompted to re-acknowledge.

## Phase 5 — Notice board print and export

- [ ] **Print stylesheet.** A `@media print` stylesheet on the roster grid page: hides navigation, sidebar, filter prose, summary line, legend badges, and coverage strip. Renders the grid table full-width at A4 landscape, 14pt minimum font size for shift codes, borders between cells instead of background colour fills (photocopier-safe), department section headers bold. No JavaScript needed — works from Ctrl-P / browser print dialog.
- [ ] **"Publish and print" affordance.** After the publish action completes, offer a "Print roster" link (opens `window.print()`) in the success banner. No new page or route.
- [ ] **XLSX / CSV export.** A "Export" button (gated by `attendance.manage`) on the grid toolbar downloads the visible grid as a spreadsheet: columns are dates, rows are employees (grouped by department), cells contain shift codes. Draft cells include a "(draft)" suffix in the export to distinguish from published. Implemented server-side using a simple CSV writer (no heavy Excel library needed for the grid shape); XLSX can be a follow-up if demand warrants it.
