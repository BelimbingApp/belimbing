# 18_roster_master.md

**Status:** In Progress
**Last Updated:** 2026-05-20
**Sources:**
- `docs/plans/people/15_attendance-roster-builder-ux.md` — foundation UX (complete)
- `docs/plans/people/16_attendance-roster-builder-dhh-followups.md` — DHH follow-ups (partially deferred)
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 parity benchmark
- `docs/plans/people/sbg_attendance_ref/Roster Builder Review v2 _DHH_ - standalone.html`
**Agents:** claude/sonnet-4.6

---

## Problem Essence

"Roster Builder" names a tool for one stakeholder — the supervisor who constructs the roster — while in practice the roster is a shared operational document that every worker, manager, and HR administrator touches daily. The current surface also requires Excel for the grid-editing gestures users already know: select a range, drag to fill, paste a pattern, undo. Until BLB owns those gestures and serves all stakeholders in one place, migration from spreadsheets stalls and workers keep asking supervisors "what shift am I on Friday?" instead of checking for themselves.

## Desired Outcome

Every stakeholder who touches a roster can do their job in BLB without resorting to Excel, WhatsApp, or a laminated sheet on the wall. Supervisors build faster than they did in Excel. Employees see their own schedule without asking anyone. Managers scan coverage gaps before they happen. Payroll locks the period with confidence. The surface is calm and immediate — it feels like a well-designed duty notice board, not an enterprise form.

---

## Rename: "Roster Builder" → "Roster"

Drop "Builder" from all navigation, headings, routes, and copy.

"Builder" scopes the page to its construction phase and excludes employees and managers who never build anything. The physical analogue — a duty roster posted on the break-room wall — has no "builder" in its name. The page is the roster. Editing is what a supervisor does on it, not what the page is.

- Sidebar nav: **Roster** (was "Roster Builder")
- Page `<h1>`: **Roster** with period label as subtitle
- Route: `/attendance/roster` (singular, canonical alias alongside existing)
- Internal language: "Save draft", "Publish" — never "Build"

The mode distinction is in page state, not page name: viewing a published week and editing a draft week are two states of the same surface, as a notice board has a "posted" state and a "chalk draft" state.

---

## Stakeholders and Their Jobs

| Stakeholder | Job on the Roster | Current support | Gap |
|---|---|---|---|
| **Supervisor / scheduler** | Build draft, fill patterns, fix conflicts, publish | Strong | Excel parity in grid editing; deferred day drawer |
| **Employee / worker** | Check my shifts for this week and next | None | No self-service view; employees ask supervisors verbally |
| **HR administrator** | Audit, override published rosters, correct errors | Partial (`canManage`) | No post-publish correction with audit trail |
| **Operations / dept manager** | Coverage gap scan across departments | Partial (heatmap in form mode only) | Heatmap unreachable in browse mode; no "today" snapshot |
| **Payroll** | Lock roster after cut-off; confirm planned vs actual | None | No lock; no reconciliation surface |
| **Security / reception** | Who is expected on-site today? | None | No live working-now view |

---

## Top-Level Components

**Roster (browse mode — the surface).** The week/month grid is the home of the Roster module, not a tab inside a form. Prose summary, filter sentence, and period switcher orbit the grid without competing with it.

**Grid Interaction Layer.** A client-side selection and editing model layered onto existing cells: single-cell select, rectangular range select, drag-to-fill handle, copy/paste, Delete-to-clear, arrow-key navigation, cycle-aware fill. One new batch server action handles all writes.

**My Schedule (employee self-service).** A read-only, employee-scoped view of the grid filtered to the logged-in employee. Shows this week and next, navigable by week/month. Works on mobile. A "your schedule has changed" notification deep-links here.

**Shift Acknowledgment.** Employees confirm they have seen their published schedule. Supervisor grid shows acknowledgment counts per period. Replaces the WhatsApp broadcast supervisors send after posting a physical roster.

**Day Drawer** (deferred from plan 16). A panel scoped to one date: every assigned employee, shifts, leave/conflict flags, coverage roll-up. Reached from a date column header or a coverage heatmap cell.

**Roster Lock (payroll integration).** A lock per company per period triggered at payroll cut-off. Locked periods show grayed cells and a lock icon. Unlock requires a dedicated capability and logs a reason.

**Planned vs Actual Reconciliation.** A toggle mode on the existing grid overlaying attendance outcomes (matched / late / absent / early-departure) from clock-in events. The timecard view HR2000 calls "Employee Timecard," delivered as a grid mode rather than a separate screen.

**Notice Board Print Layout.** A print stylesheet for the grid: A4 landscape, department-grouped rows, large shift codes, no web chrome. Designed to be printed and posted. "Publish and print" offered as a single action.

---

## Design Decisions

**The grid is the product, not a container for features.** Coverage, validation warnings, prose summary, and filter sentence annotate the grid rather than frame it. Nothing competes with the grid for attention. Consequences: the heading "Roster grid" is removed (the grid introduces itself); the coverage heatmap moves to a toggleable strip or second tab; validation findings appear as cell-level markers; publish collapses to a bottom action band when there are no blocking findings.

**Excel parity in three gestures.** Most roster work in Excel comes down to: select a range, drag to fill, paste a pattern. BLB should own these three before adding anything novel. The one place BLB can exceed Excel: **cycle-aware fill** — if the source selection contains a repeating rotation (e.g., AM AM PM PM REST REST REST), the fill detects the period and continues the cycle rather than repeating only the first cell. This matters every week for production shift teams.

**Draft = whiteboard; published = posted notice.** The visual weight of published cells vs draft cells should feel the difference between printed and penciled. The publish action should feel ceremonial: a supervisor does not casually post a duty roster. The fill-handle drag is the most performance-sensitive interaction on the page — a smooth 200 ms preview before commit builds trust.

**Calm over comprehensive for employees.** My Schedule is the simplest possible thing: the employee's own row, week by week, readable on a phone in the car park. No coverage numbers, no other employees, no filters. One sentence per day at most.

**HR2000 parity means the full chain, not just a prettier grid.** HR2000's operational value is roster → clock → timecard → payroll. BLB has the roster; the timecard reconciliation (planned vs actual overlay) and the payroll lock are what close the chain. OT flagging at draft stage — when a shift extends beyond contracted hours or falls on a rest/off day — is something BLB can do better than HR2000, which handles OT post-fact through an application workflow.

---

## Excel Features to Adopt

| Feature | Status |
|---|---|
| Single-cell select | Done (via override button) |
| Rectangular range select (Shift-click / Shift-Arrow) | Done — 18b |
| Drag-to-fill handle | Done — 18b |
| Cycle-aware fill (BLB exceeds Excel here) | Done — 18b |
| Copy / paste (Ctrl-C / Ctrl-V, client-side clipboard) | Done — 18b |
| Delete / clear selected cells | Done — 18b |
| Arrow-key cell navigation | Done — 18b |
| Undo for batch cell ops | Done — 18b |
| Print layout (A4 notice board) | Done — 18c |
| Export to XLSX / CSV | Done — 18c |
| Freeze panes (sticky name col + date header) | Done |

Not adopting: arbitrary formula cells, merge cells, multi-sheet files.

---

## HR2000 Features to Reference

| HR2000 | BLB gap / target |
|---|---|
| QtmsGroup — shift/TMS group config | BLB covers with shift templates + policy groups; roster should surface group-level conflict warnings |
| Employee Timecard — planned vs actual per employee per day | Missing — planned as grid overlay in 18d |
| ClockTran — clock transaction log | Feeds the overlay when clock-in data is available |
| OTAppTran — OT application workflow | BLB should flag at-risk OT during draft, not post-fact — planned in 18d |
| Absent — absence register | Absent employees should show as a coverage gap; today leave conflicts are tracked but post-fact absences are not |
| GeoFence / GeoGroup — location-based clock-in | Post-roster; roster should know the geofence group for each shift when clock-in is added |

---

## Features to Invent

**Shift acknowledgment.** Employees tap "Got it" on their published schedule. Supervisor grid shows acknowledgment counts. Replaces the WhatsApp blast after posting a physical roster. Planned — 18c.

**Swap marketplace.** Employee requests a swap for one or more dates. System identifies eligible candidates (same shift type, same qualifications, no leave conflict). Candidate accepts; supervisor approves. Grid updates via dated exception. The digital version of the break-room swap board. Planned — future plan.

**Cycle-aware fill.** The fill handle detects a repeating rotation in the source selection and continues the cycle. No equivalent in Excel or HR2000 for roster work. Planned — 18b.

**Payroll lock with audit trail.** Period lock triggered at payroll cut-off. Unlock requires a capability and a reason, logged with actor attribution. Planned — 18d.

**Notice board mode.** Print stylesheet designed to be posted physically. "Publish and print" as one action. Planned — 18c.

**Live "working now" snapshot.** Minimal read-only view: clocked in and scheduled now, expected but not arrived, on leave today. Built on roster + clock-in data; suitable as a dashboard widget. Future plan.

**AI pattern suggestion.** Lara suggests "Apply last month's pattern?" when opening a new draft with prior history. Supervisor reviews and confirms; no autonomous save. Future plan — requires Lara's tool surface.

---

## Phase Index

| Companion | Scope | Status |
|---|---|---|
| [18a — Surface Completion](./18a_roster-surface-completion.md) | Complete deferred plan-16 work; rename and routing | Complete |
| [18b — Grid Interaction](./18b_roster-grid-interaction.md) | Excel-parity grid interaction layer | Complete |
| [18c — Self-Service](./18c_roster-self-service.md) | Employee My Schedule, acknowledgment, print, export | Complete |
| [18d — Payroll Reconciliation](./18d_roster-payroll-reconciliation.md) | Payroll lock + planned vs actual reconciliation + OT flag | Identified |

Future — not yet planned: swap marketplace, live working-now snapshot, AI pattern suggestion.
