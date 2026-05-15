# people/11_attendance-shift-and-allowance-coverage

**Status:** Complete (2026-05-15) — all four originally scheduled changes shipped, including the previously deferred work-minute deduction. (1) multi-break shift template with per-break paid flag, (2) shift-scoped allowance rule with nullable `attendance_shift_template_id` FK, (3) `day_type_overrides` column dropped, (4) `AttendanceDayProjectionService` now reads per-break `paid` and deducts only the unpaid breaks from worked minutes.
**Last Updated:** 2026-05-15
**Sources:**
- `docs/plans/people/09_attendance-module-design.md` — parent Attendance design. Splits the legacy "TMS Group" monolith into `AttendanceShiftTemplate` (schedule) + `AttendancePolicyGroup` (rules) + `AttendanceAllowanceRule` (allowance triggers) + `AttendanceRosterPattern` (rotation) + `AttendanceRosterAssignment` (per-employee assignment). That split is deliberate and not under review here.
- `docs/plans/people/sbg_attendance_ref/app.hrserver.com.my_Core.QtmsSetup_QtmsGroup.png` — one real-world example of how a legacy product (HR2000) exposes rotation, day-type variation, multi-break, and per-shift allowance on a single screen. Used to surface candidate requirements, not as a parity target.
- `app/Modules/People/Attendance/Models/AttendanceShiftTemplate.php` — `break_windows` JSON array, `day_type_overrides` JSON object (column reserved, never read or written by code today).
- `app/Modules/People/Attendance/Models/AttendancePunchWindow.php` — independent `earliest_at` / `expected_at` / `latest_at` per event type.
- `app/Modules/People/Attendance/Models/AttendanceAllowanceRule.php` — scoped today by nullable `attendance_policy_group_id` only.
- `app/Modules/People/Attendance/Livewire/ShiftTemplates.php` — UI handles one break; ignores `day_type_overrides`.

**Agents:** claude-code/opus-4.7

## Problem Essence

Legacy attendance products like HR2000 ship a single "TMS Group" table where one row carries rotation slot + day-of-week + day-type schedule + two breaks + OT boundaries + allowance codes. That looks like a long list of features, but most of it is one product's way of squeezing a rotation pattern into one screen. The underlying real-world need is smaller.

BLB's Attendance module splits the same domain across five tables. The question this survey answers: do real-world rotation/shift/allowance setups still fit?

Answer: yes for everything surveyed, with three follow-ups:

1. SBG production operators have two breaks per shift, with at least one of them paid. The model holds two breaks in `break_windows`, but (a) the UI exposes one slot and (b) the paid/unpaid distinction is currently a single policy-group boolean (`daily_exclude_break_hours`) that applies to all breaks together. We need both a multi-break form **and** a per-break `paid` attribute on `break_windows` entries.
2. SBG has different allowances for day and night shifts on the same workforce. Today that forces one policy group per shift just to attach distinct allowance rules. Add a nullable `attendance_shift_template_id` foreign key to `AttendanceAllowanceRule` so a single policy group can carry shift-conditional allowances.
3. The reserved `day_type_overrides` column on `AttendanceShiftTemplate` has no surviving use case. Ramadan, half-day-on-rest-day, holiday schedule changes all decompose into "separate shift template + roster reassignment." Any future day-type-aware behaviour (Holiday OT multipliers, Rest-day rate scaling) belongs on the policy group, not on the shift template — the column is reserving space on the wrong axis. Drop it on the next migration that touches `people_attendance_shift_templates`.

## What was surveyed

| Real-world need | BLB approach today | Action |
|---|---|---|
| Rotation across multiple shifts | `AttendanceRosterPattern` + roster assignments per employee | None |
| Day-of-week scoping ("day shift Mon–Fri, weekend shift Sat") | Roster pattern routes day-of-week to a shift template | None |
| Schedule varies by day type (Normal/Off/Rest/Holiday) | Define a separate shift template for the variant ("PROD-REST-HALF") and route it via roster pattern / assignment | None (legacy products embed this into the rotating shift row; BLB's separate-template + roster-pattern path is the natural BLB expression) |
| OT boundary expressed as time-of-day vs. minute delta | Minute deltas in policy group; functionally equivalent | None |
| Allowance triggered by which shift was worked (e.g., SBG day vs. night allowance) | Today: define a policy group per shift; allowance rules attach to the policy. | **Confirmed needed by SBG** — add a nullable `attendance_shift_template_id` FK to allowance rules so one policy group can carry shift-conditional allowances |
| Two breaks per shift (long production shifts), with mixed paid/unpaid | `break_windows` JSON is an array; UI exposes one slot. Paid/unpaid is a single policy-group boolean covering all breaks together. | **Confirmed needed by SBG** — multi-break form + per-break `paid` attribute |
| Per-break tolerance (separate accept ranges for break-out vs break-in) | `AttendancePunchWindow` rows are independent per event; UI collapses both to the break window itself | UI completion candidate — ship only on customer complaint |
| Mid-shift transition ("Change Shift From/To") | Not modelled | None — roster reassignment handles shift changes; intra-assignment transitions not observed in real use |

## Confirmed work (SBG-driven)

### Multi-break shift template with per-break paid flag

SBG production operators have two breaks per shift, and at least one is paid (typical pattern: 1-hour unpaid lunch + a shorter paid tea break). This iteration changes the **data shape + UI** so the information is captured. Applying per-break `paid` to work-minute deduction is a separate future iteration that lands together with the production attendance-day evaluator (which currently doesn't deduct break time at all — see "Break deduction not yet implemented" below).

- **Form / blade**: state becomes an array of `{label, starts_at, ends_at, paid}` rows. "Add another break" / "Remove break" affordances; cap at 2 (lift the cap only on a future ask).
- **Model / casting**: `break_windows` is already JSON. Document the canonical entry shape; `paid` is an optional boolean (treated as `null` when absent so we know "old data, unknown intent").
- **Punch windows**: `syncPunchWindows` emits `BREAK_OUT_2` / `BREAK_IN_2` event types when a second break exists. Add the constants to `AttendancePunchWindow`.
- **Lateness offset**: `less_break_lateness` (already on policy group) keeps working unchanged.

No migration required.

### Break deduction not yet implemented

`AttendancePolicySimulationService::simulate()` computes worked minutes as `clockOut − clockIn` with no break deduction. The policy group's `daily_exclude_break_hours` boolean is stored but not read by any calculation today. Once the production attendance-day evaluator is built, that's when:

1. Break time deduction enters the work-minute calc.
2. Per-break `paid` (set by the multi-break UI above) becomes the per-shift source of truth, with `daily_exclude_break_hours` as a policy-group fallback for breaks lacking the flag.

This plan stops at "store the shape." The behaviour change happens with the evaluator work.

### Per-break tolerance windows (backlog)

`AttendancePunchWindow.earliest_at` / `latest_at` are already independent per row. The current `syncPunchWindows` collapses them to the break window itself. Completion work, if ever needed: expose `breakOutBefore/After`, `breakInBefore/After` on the form, and let `syncPunchWindows` honour them. Not requested by SBG yet — leave on backlog.

### Shift-scoped allowance rule

SBG has different allowances on day vs. night shifts for the same workforce, so the allowance trigger must distinguish the two without forcing distinct policy groups. Work:

- **Migration**: add nullable `attendance_shift_template_id` to `people_attendance_allowance_rules`, foreign-keyed to `people_attendance_shift_templates` with `nullOnDelete()`.
- **Model**: fillable + `shiftTemplate()` belongsTo relationship.
- **Form**: AllowanceRules adds an optional "Apply only when this shift is worked" select, populated from the company's shift templates. Empty = applies to all shifts (current behaviour).
- **Evaluator**: when the rule has a non-null `attendance_shift_template_id`, it fires only on attendance days whose resolved shift template matches. Combined with the existing `attendance_policy_group_id` predicate as AND.

## Cleanup candidate

### Reserved `day_type_overrides` column → drop

`AttendanceShiftTemplate.day_type_overrides` is declared in the migration and cast to JSON, but no code reads or writes it. Use cases considered:

- **Half-day on Rest Day.** Solved by a separate shift template ("PROD-REST-HALF") and roster routing.
- **Different break window on Holiday.** Same — separate template.
- **Ramadan working hours for Muslim employees.** Ramadan is a *date range*, not a Normal/Off/Rest/Holiday "day type"; a day-type override is structurally wrong for it. The natural fit is a separate shift template ("OFFICE_RAMADAN") + roster reassignment over the Ramadan effective range. (Bulk reassignment by employee attribute is a separate roster-UI concern — flagged in 09.)

Every real case decomposes into "separate shift template + roster reassignment." Future day-type-aware behaviour (Holiday OT multipliers, Rest-day rate scaling) belongs on the policy group's day-type rules, not on the shift template. The column is reserving space on the wrong axis.

**Drop it** on the next migration that touches `people_attendance_shift_templates`. Adding a JSON column back in Laravel is a one-line migration if a real need emerges later.

## Non-needs

- Time-of-day OT boundary input (minute deltas are functionally equivalent and already implemented).
- Mid-shift transition / "Change Shift From/To" (no real-world use seen; roster reassignment covers shift changes between assignments).
- Single-screen TMS-Group-style editor. The five-table split is deliberate; combining them into one screen would conflict with 09's architectural choice.

## Checklist

- [x] Confirmed: SBG production operators need two breaks per shift with mixed paid/unpaid.
- [x] Confirmed: SBG has different allowances for day vs. night shifts.
- [x] Confirmed: drop `day_type_overrides` (no surviving use case; future day-type behaviour belongs on policy group).
- [x] Multi-break (data + UI): form state, blade, per-break `paid`, `syncPunchWindows` emits `BREAK_OUT_2` / `BREAK_IN_2`, `AttendancePunchWindow` constants. {claude-code/opus-4.7, commit 0953a2e7}
- [x] Shift-scoped allowance: migration `0320_02_05_000002_*`, `AttendanceAllowanceRule.attendance_shift_template_id` + relationship, AllowanceRules form select, simulator filter. {claude-code/opus-4.7, commit acc31975}
- [x] Drop `day_type_overrides`: migration `0320_02_05_000001_*` + cast/fillable removed. {claude-code/opus-4.7, commit 8ceaf4e2}
- [x] Cross-reference items added to 09 roster-builder checklist: day-type-aware resolver, pattern routing by day type, bulk reassignment by employee attribute. {claude-code/opus-4.7, commit a6e4ba08}
- [x] Apply per-break `paid` to work-minute deduction. `AttendanceDayProjectionService` now reads `shift.break_windows[*].paid` and subtracts the overlap of each unpaid break against the (first clock-in → last clock-out) span. Paid breaks stay in worked time. Dev seeder break entries normalized to the `{label, starts_at, ends_at, paid}` shape used by the form. {claude-code/opus-4.7}
