# 18a_roster-surface-completion.md

**Status:** Identified
**Last Updated:** 2026-05-20
**Sources:**
- `docs/plans/people/18_roster_master.md` — master plan
- `docs/plans/people/16_attendance-roster-builder-dhh-followups.md` — origin of deferred items
- `app/Modules/People/Attendance/Livewire/Rosters.php`
- `resources/core/views/livewire/people/attendance/partials/rosters-grid.blade.php`
- `resources/core/views/livewire/people/attendance/partials/rosters-list.blade.php`
**Agents:** claude/sonnet-4.6

## Problem Essence

Plan 16 shipped most of the DHH-inspired surface but left four items deferred: the day drawer, coverage-matrix → drawer link, inline validation markers, and the publish action band. These leave the current surface incomplete — the heatmap has no drill-through, validation findings still live in a separate card, and publish has no "all clear" moment. Before new interaction layers are added (18b–18d), this surface should be finished, and the rename from "Roster Builder" to "Roster" should land.

## Phase 1 — Complete deferred plan-16 items

- [ ] **Day drawer.** A panel that opens when a date column header is clicked in the grid. Lists every employee assigned on that date (name, shift code, coverage status), any leave or conflict flags, and a coverage roll-up (required / assigned / gap). Scoped to one date; close on Escape or click-outside. Shares the same data computed by `rosterGridRows` and `rosterCoverageRows` — no extra queries beyond what the page already runs.
- [ ] **Coverage heatmap → day drawer.** Clicking any cell in the coverage heatmap matrix opens the day drawer for that date. The heatmap cell and the date column header lead to the same panel.
- [ ] **Inline validation markers.** Move validation findings from the standalone findings card into the grid: small per-cell badge (⚠) on cells with a finding, tooltip on hover with the finding text. Add a single summary strip below the grid ("3 warnings — 1 blocking") that expands inline to the finding list. Remove the separate validation card from form mode.
- [ ] **Publish action band.** When there are no blocking findings (or all blockings have been accepted), collapse the publish card to a single bottom action band: revision-note field inline, one "Publish to the team →" button. The full publish card remains visible only when blocking findings are present.

## Phase 2 — Rename and stakeholder routing

- [ ] Rename all user-visible copy from "Roster Builder" to "Roster": sidebar nav entry, page `<h1>`, breadcrumbs, notification copy, any modal headings.
- [ ] Add a `people.attendance.roster.view` authz capability (read-only grid access) distinct from the existing `people.attendance.manage`. Seed it in the authz config alongside the existing capabilities.
- [ ] When a user with `roster.view` but not `attendance.manage` opens the Roster page: show the grid filtered to their own employee row only (no other employees, no override buttons, no form mode CTA). This is the foundation for My Schedule in 18c.
- [ ] Add `/attendance/roster` as a canonical route alias; keep `/attendance/rosters` working so existing bookmarks do not break.
- [ ] Remove the heading "Roster grid" from the grid card header — the grid introduces itself.
