# operation-it-tickets-workspace.md

Status: Complete — built and verified; not yet committed
Last Updated: 2026-07-16
Sources: `app/Modules/Operation/IT/*`, `app/Base/Workflow/*`, `DESIGN.md`, root `AGENTS.md`
Agents: claude/claude-fable-5

## Problem Essence

`it/tickets` is a scaffold, not a product: the list is a bare table with only
text search, the ticket page has no way to assign, edit, or comment, there is
no crew-facing board, and workflow notifications are wired in Base but have no
`notifications` table, no per-status config, and no UI — stakeholders cannot
track progress at all.

## Desired Outcome

An IT crew and its reporters can run their real support queue in BLB:

- The **list** is a work queue: scope lenses (Open / Mine / Unassigned / Done /
  All), dropdown filters (status, priority, category, assignee), a compact
  queue-health stat strip, and an assignee column.
- The **board** shows the same queue as kanban columns backed by the workflow
  engine's `KanbanColumn` config; cards move via drag & drop that honors the
  transition graph (illegal drops bounce with an honest explanation).
- The **ticket page** is a conversation: description + timeline + composer in
  the main column; facts (priority, category, location, assignee) editable
  in place in a side column; transitions carry the composer text as comment.
- **Assignment** is a first-class verb: picking an assignee on an `open`
  ticket transitions it to `assigned`; reassignment is recorded in history.
- **Notifications** reach stakeholders: database notifications on workflow
  transitions (per-status `on_enter` config) and on posted comments; a bell in
  the top bar shows unread count and recent items, deep-linking to tickets.
- **Dev seeds** produce a believable office: ~20 tickets across all statuses,
  priorities, categories, several assignees, threaded comments, aged
  timestamps.

## Top-Level Components

- `Operation/IT` module (nested repo): Index filters, Board Livewire page,
  Show workspace, `TicketService` verbs (`assign`, `updateDetails`), module
  config (`it.php` categories/priorities), workflow seeder (kanban columns,
  per-status notifications), dev seeder scenarios, authz (`update` capability,
  `it_agent` role), tests.
- `Base/Workflow` (main repo): `SendTransitionNotification` resolves Employee
  recipients to their linked User; `TransitionNotification` payload gains a
  human summary (title, url) via an optional model seam.
- `Core/User` (main repo): standard Laravel `notifications` table migration;
  notification bell Livewire component in the top bar with unread count,
  recent list, mark-read.

## Design Decisions

**Board columns.** Options: (a) keep seeded Backlog/Active/Done, (b) columns
per status (8 columns), (c) refine the flow's `KanbanColumn` rows to the real
stages: Open / Up Next / In Progress / Waiting / Review / Done. (a) buries six
working statuses in one "Active" column — a board that answers nothing. (b)
wastes width on terminal states. (c) wins: it uses the engine's own
column-mapping mechanism (low entropy, no parallel concept) and matches how a
crew actually scans work. `blocked` + `awaiting_parts` share "Waiting";
`resolved` + `closed` share "Done".

**Drag & drop semantics.** Dropping on a column with exactly one legal
transition fires it; with two candidates (Waiting, Done) a small chooser pops;
with none the card snaps back and a toast says why. Honest about the graph
instead of pretending every move is legal. Plain Alpine HTML5 DnD — no new JS
dependency.

**Comment notifications.** Transitions notify via existing Base listener +
per-status `on_enter` config. Comments don't fire transitions, so the module's
`TicketService::postComment` notifies reporter + assignee users directly with
a module-owned notification. Alternative — generalizing comment events into
Base — is speculative; deferred until a second flow needs it.

**Employee vs User recipients.** Ticket reporter/assignee are Employees;
Laravel notifications target Users. The Base listener gains one seam: when a
recipient relation returns a model exposing a notifiable `user`, notify that
user. Fixes silently-dropped notifications for every Employee-anchored flow.

**Company scoping.** Ticket queries (index, board, show, assignable employees)
scope to the actor's company. Quality module does not scope yet — noted as
follow-up entropy, not copied.

## Phases

### Phase 1 — Base/Core foundations (main repo)

- [x] `notifications` table migration under `Core/User/Database/Migrations` {claude/claude-fable-5}
- [x] `SendTransitionNotification` resolves Employee → linked User recipients {claude/claude-fable-5}
- [x] `TransitionNotification::toArray` carries `title` + `url` when the model exposes them {claude/claude-fable-5}
- [x] Top-bar notification bell: unread badge, recent list, mark read / mark all read, deep links {claude/claude-fable-5}

Affected pages: every authenticated page (top bar).
Validation: `php artisan migrate`, bell renders with zero state; Pest feature test for listener Employee→User resolution.

### Phase 2 — Ticket list as a queue (IT repo)

- [x] Scope lenses Open / Mine / Unassigned / Done / All (segmented control, URL-persisted) {claude/claude-fable-5}
- [x] Dropdown filters: status, priority, category, assignee; reset page on change {claude/claude-fable-5}
- [x] Stat strip: open, in progress, waiting, resolved-this-week {claude/claude-fable-5}
- [x] Assignee column; company-scoped query {claude/claude-fable-5}
- [x] List ↔ Board switcher in page header {claude/claude-fable-5}

Affected pages: `/it/tickets`.
Goal: a supervisor finds "unassigned criticals" in two clicks; filters combine with search.

### Phase 3 — Ticket workspace (IT repo)

- [x] Two-column layout: conversation (description, timeline, composer) + facts rail {claude/claude-fable-5}
- [x] Composer posts comments; transition buttons carry composer text {claude/claude-fable-5}
- [x] Edit-in-place: priority, category, location (authz `operations.it.ticket.update`) {claude/claude-fable-5}
- [x] Assignee combobox: assign transitions open→assigned, reassign records history {claude/claude-fable-5}
- [x] `resolved_at` maintained on resolve/reopen {claude/claude-fable-5}

Affected pages: `/it/tickets/{id}`.
Goal: a technician can run a ticket end-to-end without leaving the page.

### Phase 4 — Kanban board (IT repo)

- [x] `Board` Livewire page at `/it/tickets/board` + menu entry {claude/claude-fable-5}
- [x] Columns from `KanbanColumn` config; refined seeder columns Open/Up Next/In Progress/Waiting/Review/Done {claude/claude-fable-5}
- [x] Cards: title, priority, assignee, age; click-through to ticket {claude/claude-fable-5}
- [x] Drag & drop honoring transition graph with chooser and honest rejection {claude/claude-fable-5}
- [x] Mine toggle + priority filter {claude/claude-fable-5}

Affected pages: `/it/tickets/board`.

### Phase 5 — Notifications config + comment notifications (IT repo)

- [x] Seed per-status `notifications` (`on_enter` reporter/assignee, database channel) {claude/claude-fable-5}
- [x] `TicketCommentPosted` notification from `postComment` to reporter + assignee users {claude/claude-fable-5}
- [x] Ticket implements `PresentsWorkflowNotifications` (title + deep-link seam) {claude/claude-fable-5}

### Phase 7 — Homepage widget + menu naming (follow-up request)

- [x] `TicketQueue` dashboard widget: open/mine/waiting numbers + attention list (unassigned by severity, then blocked), discovered via `IT/Config/dashboard.php`, gated by `operations.it.ticket.list` {claude/claude-fable-5}
- [x] Menu labels renamed Tickets → IT Tickets, Board → IT Board (pinned items and future boards from other flows keep context) {claude/claude-fable-5}
- [x] Widget tests (render + discovery); verified live on the dashboard {claude/claude-fable-5}

Affected pages: `/` (dashboard), sidebar menu.

### Phase 6 — Seeds, tests, verification

- [x] Dev seeder: ~20 tickets, all statuses/priorities/categories, several assignees, comments, aged timestamps; fix reporter-as-User bug {claude/claude-fable-5}
- [x] Pest feature tests: filters, assignment, comment notify, transition notify, board transition {claude/claude-fable-5}
- [x] Pint clean; `bun run build` {claude/claude-fable-5}
- [x] Browser e2e: list filters, board drag, ticket conversation, bell {claude/claude-fable-5}

Evidence: `php artisan test app/Modules/Operation/IT/Tests` green (39 passed, 115
assertions) and `tests/Feature/Workflow` green (17 passed); Pint clean on both
repos; `bun run build` clean. Browser e2e at `local.blb.lara` as
`aiman.rahman@blb.my`: list lenses + dropdown filters + stat strip; bell badge,
dropdown, deep link, mark-read (badge 8→7→…); workspace comment, Submit-for-Review
transition carrying the composer text, edit-in-place priority (High→Critical),
assignee reassign via entangled binding ("Reassigned to Sofia" in timeline +
notification); board columns from KanbanColumn config, legal drop (Up Next→In
Progress persisted), illegal drop rejected with toast, Up Next drop chooser
assigning with notification to the reporter. Dev DB reseeded to the deterministic
scenario afterwards.

Notes:
- The `notifications` table pk must stay `uuid` — Laravel's DatabaseChannel
  inserts client-generated UUID ids; an `id()` pk fails every insert (proven by
  test run mid-build).
- DevTicketSeeder heals stale dev data where a user from another company points
  at a licensee employee (it hijacked Employee→user notification routing).
