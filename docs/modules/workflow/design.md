# Workflow Engine: Design Document

**Document Type:** Architecture Design
**Status:** Draft — exploring the big picture
**Purpose:** Define BLB's status-centric workflow engine as the backbone for all business process management
**Last Updated:** 2026-03-17

---

## 1. The Problem in One Sentence

Every business runs on processes — leave applications, order fulfillment, school placements, customs clearance — and each process is fundamentally a sequence of **statuses** with **rules about who can move between them**.

---

## 2. Core Insight: Status *Is* the Workflow

BLB does not model workflows as separate flowchart objects that reference entities. Instead, **status is the workflow**. A process is defined entirely by:

1. The **statuses** it can be in (nodes — `base_workflow_status_configs`)
2. The **transitions** allowed between them (edges — `base_workflow_status_transitions`)
3. The **policies** governing each transition (capabilities, guards, actions)

This is deliberately simple. A leave application and an immigration clearance share the same engine — they differ only in their status graph and the policies attached to each node.

---

## 3. Design Principles

| Principle | Implication |
|-----------|-------------|
| **Status-centric** | The status graph *is* the process definition. No separate "workflow definition" abstraction. |
| **Process-agnostic** | One engine drives any process type. The `flow` discriminator scopes status sets. |
| **Config-driven** | Statuses, transitions, permissions, and notifications are database records, not code. Admins configure processes in the UI without deployments. |
| **Single source of truth** | Each fact lives in exactly one place. Edges live in the transitions table, not duplicated on status nodes. Permissions use AuthZ capabilities, not a parallel role-matching system. |
| **Parent class, child specializations** | `StatusConfig` is the base. Process-specific needs (extra attributes, custom validation) are handled by child classes or metadata, not by forking the schema. |

---

## 4. Conceptual Model

### 4.1 Status Graph

A process is a **directed graph** where:
- **Nodes** = statuses (rows in `StatusConfig` for a given `flow`)
- **Edges** = allowed transitions (rows in `base_workflow_status_transitions`)

```
Leave Application:

  ┌──────┐     ┌──────────────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
  │ new  │────▶│ pending_approval │────▶│ approved │────▶│ on_leave │────▶│ complete │
  └──────┘     └──────────────────┘     └──────────┘     └──────────┘     └──────────┘
                        │
                        ├───────────────▶ rejected
                        │
                        └───────────────▶ closed
```

```
Order Fulfillment (multi-department):

  ┌─────────┐     ┌────────────┐     ┌─────────────────┐     ┌──────────┐     ┌───────────┐
  │ created │────▶│ processing │────▶│ customs_review  │────▶│ shipped  │────▶│ delivered │
  └─────────┘     └────────────┘     └─────────────────┘     └──────────┘     └───────────┘
                        │                     │
                        ▼                     ▼
                    cancelled            customs_hold ──▶ customs_cleared ──▶ shipped
```

### 4.2 What a Status Carries

Each status node is not just a label — it carries **policy**:

| Attribute | Purpose | Example |
|-----------|---------|---------|
| `code` | Machine identifier | `pending_approval` |
| `label` | Human display name | `Pending Approval` |
| `pic` | Person(s)-in-charge | `["hr_manager", "department_head"]` |
| `notifications` | Who gets notified on entry | `{"email": ["applicant", "hr_manager"], "in_app": ["applicant"]}` |
| `position` | Display order (lists, kanban columns) | `2` |
| `comment_tags` | Required/available comment categories | `["reason", "internal_note"]` |
| `prompt` | AI guidance for this status | `"Review the leave dates and check for conflicts"` |
| `kanban_code` | Groups statuses into kanban columns | `in_progress` |
| `is_active` | Soft enable/disable | `true` |

**What's not here — and why:**

- **`next_statuses` (removed):** The transitions table (`base_workflow_status_transitions`) is the single source of truth for which edges exist. Storing an adjacency list in StatusConfig would create a dual source of truth requiring two coordinated writes for every edge change. The engine derives available transitions from the transitions table via `SELECT ... WHERE flow = ? AND from_code = ?`. A computed accessor on the model provides `$status->nextStatuses` for convenience.

- **`permissions` (removed):** Node-level "who can view items in this status" is handled by BLB's AuthZ capability system, not by a JSON blob on the status row. Capabilities like `workflow.leave_application.view_pending_approval` or scoped queries handle visibility. Edge-level "who can trigger this transition" also uses AuthZ — see §6.

### 4.3 Process Registry

Every process type is registered in `base_workflow` — a lightweight catalog of all processes configured in the system.

```
base_workflow
├── id                   (bigint PK)
├── code                 (string, unique)  "leave_application", "order_fulfillment", "it_ticket"
├── label                (string)          "Leave Application", "Order Fulfillment"
├── module               (string, null)    owning module: "hr", "logistics", "it"
├── description          (text, null)      what this process is about
├── model_class          (string, null)    the Eloquent model class (for engine lookups)
├── settings             (json, null)      workflow-level config: icon, default view, etc.
├── is_active            (bool, default T)
└── timestamps
```

**This is a registry, not a hard dependency.** The `flow` string remains the discriminator in all other tables — no foreign keys, no joins required for engine operation. Other tables work with the string alone. The workflow table serves two purposes:

1. **Admin UI** — "Show me all workflows" is a simple `SELECT` instead of `DISTINCT` across multiple tables
2. **Workflow-level metadata** — display name, icon, description, owning module, model class

The `flow` column across StatusConfig, transitions, history, and kanban columns references `base_workflow.code` by convention, not by FK constraint. This minimizes dependencies: you can create history rows without the workflow table existing, and the engine never needs to join to it.

```
base_workflow.code = "leave_application"
    ├── StatusConfig rows        WHERE flow = 'leave_application'
    ├── Transition rows          WHERE flow = 'leave_application'
    ├── Kanban column rows       WHERE flow = 'leave_application'
    └── History rows             WHERE flow = 'leave_application'
```

---

## 5. Levels of Complexity

The engine handles three levels of complexity. Each level adds one concept on top of the previous:

### Level 1: Simple Linear Process
**Example:** Bug report — `open` → `in_progress` → `resolved` → `closed`

`StatusConfig` rows define the nodes. `base_workflow_status_transitions` rows define the edges. Permissions are simple (one capability per transition). No guards, no external integrations.

### Level 2: Branching / Decision Points
**Example:** Leave application — `pending_approval` branches to `approved`, `rejected`, or `closed`

Multiple transition rows from the same `from_code`. The UI queries available transitions; the user picks one. AuthZ capabilities on each transition control who can trigger the move.

### Level 3: Conditional Transitions
**Example:** Order fulfillment — transition to `shipped` only if payment is confirmed and inventory is reserved.

Transition rows define the *possible* edges, but **transition guards** (conditions) determine if a specific transition is available *right now* for *this instance*. Edge-level policy — capabilities, guards, actions — lives in the `base_workflow_status_transitions` table.

### 5.1 Composition Patterns (Beyond the Engine)

The engine's scope ends at Level 3 — a single directed graph with capability-gated, guard-protected transitions. More complex scenarios are handled by **composing multiple independent workflow instances** using standard Laravel infrastructure. The engine has no sub-process, orchestration, or external-system machinery.

#### Multi-Department / Multi-Agency Orchestration
**Example:** School placement spanning admissions, finance, immigration, healthcare

Each department runs its own Level 1–3 workflow. A parent transition's `action_class` or `Hooks::fireAfter()` dispatches a Laravel event; listeners in other modules create independent workflow instances with their own status graphs.

- **Parallel tracks:** The placement's `applied → processing` transition fires `PlacementProcessingStarted`. Listeners create independent `immigration` and `health_check` workflow instances.
- **Cross-process coordination:** A guard on the placement's `processing → all_cleared` transition checks that the related immigration and health check processes have both reached their terminal status.
- **No engine coupling:** Each process is a standalone status graph. The parent doesn't know what child processes exist — it only knows about events. Adding a new child process means adding a new listener, not changing the parent's configuration.

#### External System Integration
**Example:** Customs clearance requires API calls to government systems

Transition hooks (before/after) trigger external integrations via Laravel jobs. The status might enter a "waiting" state until an external callback advances it. This is standard Laravel job/event infrastructure, not engine functionality.

---

## 6. Status Transitions — Edge-Level Policy

### Purpose

`StatusConfig` defines **nodes** (what a status means). `base_workflow_status_transitions` defines **edges** (what governs a specific move between two statuses). The transitions table is the **single source of truth** for which edges exist and the rules governing each move.

### Schema

```
base_workflow_status_transitions
├── id                   (bigint PK)
├── flow                 (string)          same discriminator as StatusConfig
├── from_code            (string)          source status code
├── to_code              (string)          target status code
├── label                (string, null)    human label for this transition ("Approve", "Escalate")
├── capability           (string, null)    AuthZ capability key required to trigger this transition
├── guard_class          (string, null)    PHP class that evaluates if transition is allowed
├── action_class         (string, null)    PHP class that executes on transition
├── sla_seconds          (int, null)       expected turnaround time for this transition
├── metadata             (json, null)      additional edge config (priority, conditions, UI hints)
├── position             (int, default 0)  order when multiple transitions exist from same source
├── is_active            (bool, default T) soft enable/disable
└── timestamps
```

### Design Decisions

**`from_code` + `to_code` — the edge definition.**
Each row represents one directed edge in the status graph. The pair references `StatusConfig.code` values for the same `flow`. A unique constraint on `(flow, from_code, to_code)` prevents duplicate edges.

**The transitions table is the single source of truth for edges.**
There is no `next_statuses` column on `StatusConfig`. Adding a new edge means inserting one row in `base_workflow_status_transitions`. Removing an edge means deleting (or deactivating) one row. No dual writes, no consistency validation needed. The engine queries available transitions directly: `SELECT * FROM base_workflow_status_transitions WHERE flow = ? AND from_code = ? AND is_active = true ORDER BY position`. The StatusConfig model exposes a computed `nextStatuses` accessor that derives the list from the transitions table (cached per request).

**`label` — the action name, not the status name.**
The status label is "Approved" (a state). The transition label is "Approve" (an action). This is what the UI button says. If null, the engine can derive it from the target status label.

**`capability` — AuthZ integration, not a bespoke permission system.**
Each transition references a single BLB AuthZ capability key (e.g., `workflow.leave_application.approve`). The `TransitionValidator` delegates to `AuthorizationService::can($actor, $capability)` — the same pipeline used everywhere else in BLB (ActorContextPolicy → KnownCapabilityPolicy → CompanyScopePolicy → GrantPolicy). No parallel permission system, no role-code matching.

If `capability` is null, the transition requires no specific permission — any authenticated actor can trigger it (useful for self-service transitions like "Submit" by the applicant). See §6.1 for the full AuthZ integration design.

**`guard_class` — a PHP class implementing a guard contract.**
Evaluated at transition time: "Is this transition allowed *right now* for *this instance*?" Example: `LeaveBalanceGuard` checks if the employee has enough leave days. The column stores a fully qualified class name; the engine resolves it through Laravel's service container (`app($guardClass)`). No guard registry needed — the container handles discovery, instantiation, and dependency injection. The class must implement a `TransitionGuard` contract:

```php
// Conceptual contract — not code yet
interface TransitionGuard
{
    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult;
}
```

Null means no guard (always allowed if capability check passes).

**`action_class` — a PHP class implementing a transition action contract.**
Executed after the transition succeeds. Example: `NotifyCustomsAgency` sends an API call when entering `customs_review`. Same resolution pattern as guards — container-resolved, no registry. For simple cases, the Hooks system handles post-transition logic; `action_class` is for transition-specific logic tied to a particular edge. Null means no action.

**`sla_seconds` — expected turnaround time.**
How long should items typically take to move through this transition? Combined with `base_workflow_status_history.tat`, enables SLA breach detection: "This leave approval has a 48-hour SLA but TAT is 72 hours." Null means no SLA.

**`position` — transition ordering.**
When a status has multiple outbound transitions, `position` determines the order of action buttons in the UI. "Approve" first, "Reject" second.

### Indexes

```
PRIMARY KEY (id)
UNIQUE      (flow, from_code, to_code)                 -- one rule per edge
INDEX       (flow, from_code, is_active)                -- "what transitions are available from this status?"
```

### Examples

**Leave Application Transitions:**

| flow | from_code | to_code | label | capability | guard_class | sla_seconds |
|------|-----------|---------|-------|------------|-------------|-------------|
| leave_application | new | pending_approval | Submit | — | — | — |
| leave_application | pending_approval | approved | Approve | `workflow.leave_application.approve` | `LeaveBalanceGuard` | 172800 (48h) |
| leave_application | pending_approval | rejected | Reject | `workflow.leave_application.reject` | — | 172800 (48h) |
| leave_application | pending_approval | closed | Close | `workflow.leave_application.close` | — | — |

**Order Fulfillment Transitions:**

| flow | from_code | to_code | label | capability | guard_class | action_class | sla_seconds |
|------|-----------|---------|-------|------------|-------------|--------------|-------------|
| order_fulfillment | processing | customs_review | Send to Customs | `workflow.order_fulfillment.send_to_customs` | — | `NotifyCustomsAgency` | — |
| order_fulfillment | customs_review | customs_hold | Hold | `workflow.order_fulfillment.hold` | — | — | — |
| order_fulfillment | customs_hold | customs_cleared | Clear | `workflow.order_fulfillment.clear_customs` | `HsCodeVerified` | — | 259200 (3d) |
| order_fulfillment | customs_cleared | shipped | Ship | `workflow.order_fulfillment.ship` | `InventoryReserved` | `GenerateShippingLabel` | 86400 (1d) |

### 6.1 AuthZ Integration

The workflow engine delegates all permission decisions to BLB's existing AuthZ system. No parallel permission model.

#### Capability Convention

Workflow capabilities follow the standard `<domain>.<resource>.<action>` grammar:

```
workflow.{process_code}.{action}
```

Where `{process_code}` is the process identifier from `base_workflow.code` and `{action}` is typically the transition's verb (derived from the transition label or target status code). Examples:

| Capability Key | Meaning |
|---------------|---------|
| `workflow.leave_application.approve` | Can trigger the "Approve" transition on leave applications |
| `workflow.leave_application.reject` | Can trigger the "Reject" transition on leave applications |
| `workflow.leave_application.close` | Can trigger the "Close" transition on leave applications |
| `workflow.order_fulfillment.send_to_customs` | Can trigger the "Send to Customs" transition on orders |
| `workflow.order_fulfillment.clear_customs` | Can trigger the "Clear" transition on orders (customs) |
| `workflow.process.manage` | Can configure statuses and transitions for a process |

#### Where Capabilities Are Declared

Workflow capabilities are **declared by the module that owns the process**, not by the Workflow module itself. The Workflow module provides the engine; each business module declares its own transition capabilities:

```php
// app/Modules/Operation/Leave/Config/authz.php
return [
    'capabilities' => [
        'workflow.leave_application.approve',
        'workflow.leave_application.reject',
        'workflow.leave_application.close',
        'workflow.leave_application.submit',
    ],
];
```

This follows the existing pattern where `core.user.*` capabilities are declared in the User module's `Config/authz.php`, not in the base AuthZ module. The `workflow` domain is already registered in `app/Base/Authz/Config/authz.php`.

The Workflow module itself declares only administrative capabilities for process configuration (e.g., `workflow.process.manage`, `workflow.status.manage`, `workflow.transition.manage`).

#### Transition Validation Flow

```
TransitionValidator::validate($transition, $actor)
    │
    ├── 1. Is the transition active? (is_active = true)
    │
    ├── 2. AuthZ capability check:
    │       if ($transition->capability !== null)
    │           AuthorizationService::authorize($actor, $transition->capability)
    │       // null capability = no permission required
    │
    └── 3. Guard evaluation:
            if ($transition->guard_class !== null)
                app($transition->guard_class)->evaluate($model, $transition, $actor)
```

The validator is a thin orchestrator — it does not implement its own permission logic. AuthZ handles capability evaluation (roles, grants, company scope, agent delegation). Guards handle instance-specific business rules (leave balance, inventory checks). These are separate concerns evaluated in sequence.

### 6.2 Reverting to an Earlier Status

A revert is **just another transition** — an explicit reverse edge in the transitions table. The engine has no special revert mechanism.

Moving from `approved` back to `pending_approval` is a row with `from_code = 'approved'`, `to_code = 'pending_approval'`. It carries its own capability, guard, and action like any other transition. The history records it as a normal entry: the timeline shows `pending_approval → approved → pending_approval` — the actual journey.

**Why no special mechanism:**
- The directed graph already supports edges in any direction. A reverse edge is structurally identical to a forward edge.
- "Revert" means different things in different contexts — undo, reject, send back for revision — and each needs its own label, capability, guard, and action. These are distinct transitions, not instances of a generic "revert" operation.
- A separate mechanism would duplicate what transitions already do (capability check, guard evaluation, action execution, history recording) and create two ways to move between statuses.

**Practical patterns:**

| Pattern | Example | Transition |
|---------|---------|------------|
| **Send back for revision** | Approver returns leave request for date changes | `approved → pending_approval`, capability: `workflow.leave_application.revert_approval`, guard: `WithinRevertWindowGuard` |
| **Reopen** | Closed ticket reopened by requester | `closed → open`, capability: null (self-service), action: `NotifyAssignee` |
| **Escalation reversal** | De-escalate after resolution | `escalated → in_progress`, capability: `workflow.it_ticket.de_escalate` |

**Side-effect management:** Reverse transitions often need to undo side effects of the original forward transition. The `action_class` on the reverse edge handles this (e.g., `RestoreLeaveBalance` when reverting an approval). The engine does not attempt automatic rollback — each reverse transition explicitly defines its own cleanup.

**UI hint:** If the admin UI should visually distinguish backward transitions (e.g., different button style, confirmation prompt), a `"direction": "backward"` flag in the transition's `metadata` JSON handles it without a schema change.

---

## 7. Status History — The Process Lifecycle Record

### 7.1 Purpose

Every status transition is recorded. This table is the **complete lifecycle** of any process instance — from creation to completion. It answers:

- "Where is my leave application right now?"
- "Who approved this order and when?"
- "How long did this ticket sit in `awaiting_parts`?"
- "Show me the full timeline of this complaint."

It's the audit trail, the user-facing activity log, and the data source for SLA/performance analysis — all in one.

### 7.2 Schema

```
base_workflow_status_history
├── id                   (bigint PK)
├── flow                 (string)          "leave_application", "order_fulfillment", "it_ticket"
├── flow_id              (bigint)          ID of the specific leave/order/ticket instance
├── status               (string)          the status being entered
├── tat                  (int, null)       turnaround time in seconds spent in the previous status
├── actor_id             (bigint, null)    user who triggered the transition (null = system)
├── actor_role           (string, null)    actor's role at transition time (snapshot)
├── actor_department     (string, null)    actor's department at transition time (snapshot)
├── actor_company        (string, null)    actor's company at transition time (snapshot)
├── assignees            (json, null)      users delegated by the actor to complete the work
├── comment              (text, null)      "Approved — dates confirmed with team"
├── comment_tag          (string, null)    categorizes the comment (ties to StatusConfig.comment_tags)
├── attachments          (json, null)      supporting documents at transition time
├── metadata             (json, null)      process-specific data snapshot at transition time
├── transitioned_at      (timestamp)       when the transition occurred
└── created_at           (timestamp)       record creation time (usually = transitioned_at)
```

### 7.3 Design Decisions

**Single `status` column instead of `from_code` / `to_code`.**
The "from" is always the previous row's `status` for the same `flow` + `flow_id`, ordered by `transitioned_at`. Storing it would be redundant data that can drift out of sync. The first row in a lifecycle is simply the initial status — no special null handling needed. Deriving "from" is a trivial `LAG()` window function when needed for display.

**`tat` (turnaround time) in seconds — denormalized intentionally.**
TAT is computed once at write time: `this_row.transitioned_at - previous_row.transitioned_at`. Once written, it never changes. This makes SLA queries trivial without window functions:
```sql
-- All leave approvals that exceeded 48-hour SLA
SELECT * FROM base_workflow_status_history
WHERE flow = 'leave_application' AND status = 'approved' AND tat > 172800;
```
The first row in a lifecycle has `tat = null` (no previous status to measure from).

**`flow` + `flow_id` instead of polymorphic morphs.**
Morphs store the full class name (`App\Modules\Operation\Leave\Models\LeaveApplication`), which couples the history table to PHP class paths. Using the same `flow` discriminator keeps things decoupled. Queries are simpler: `WHERE flow = 'leave_application' AND flow_id = 42`.

**Actor context is snapshotted, not joined.**
`actor_role`, `actor_department`, and `actor_company` capture the actor's organizational context *at the moment of transition*. People change roles, move departments, switch companies. The history must reflect who they were *then*, not who they are *now*. Joining to live employee/role tables would silently rewrite history.

**`actor_id` references users (not employees).**
The authenticated user is the principal who triggers the transition — consistent with AuthZ's `Actor` DTO which carries a user ID. Null means the system itself triggered the transition (e.g., an automated SLA escalation, a scheduled job).

**`assignees` is JSON — delegated task assignment.**
The actor (e.g., a supervisor) assigns the task to one or more users who are responsible for completing the work at this status. This is a delegation mechanism: the actor transitions the status and decides *who* must carry out the work. JSON because multiple people may share the assignment: `[{"id": 55, "role": "it_technician"}, {"id": 60, "role": "it_technician"}]`.

**`comment_tag` categorizes the comment.**
Ties to `StatusConfig.comment_tags` — the status definition declares which tags are available (e.g., `["reason", "internal_note", "customer_feedback"]`), and the history row records which tag was used. This enables filtered views: "show me only rejection reasons" or "show me only internal notes."

**`attachments` is JSON — file references at transition time.**
Supporting documents attached during the transition: `[{"name": "rejection_letter.pdf", "path": "...", "type": "application/pdf"}]`. The actual files live in storage; this column stores references.

**`metadata` captures process-specific context at transition time.**
Different processes need different data snapshots:
- Leave: `{"days_requested": 5, "leave_balance_remaining": 12}`
- IT ticket: `{"priority": "high", "escalation_level": 2}`
- Order: `{"warehouse": "KUL-01", "shipping_method": "express"}`
- Quality complaint: `{"severity": "critical", "product_batch": "B-2026-0442"}`

**`transitioned_at` vs `created_at`.**
Usually identical. Separated for cases where a transition is recorded retroactively (e.g., importing historical data, or a batch process that records transitions after the fact).

### 7.4 Indexes

```
PRIMARY KEY (id)
INDEX idx_flow_lookup      (flow, flow_id, transitioned_at)       -- lifecycle timeline query
INDEX idx_actor             (actor_id)                              -- "what did this person do"
INDEX idx_flow_status      (flow, status, transitioned_at)         -- "all items that entered status X"
INDEX idx_tat_sla           (flow, status, tat)                    -- SLA breach queries
```

The composite `(flow, flow_id, transitioned_at)` index is the workhorse — it covers the most common query: "show me the full timeline of this specific instance, in order." The `idx_tat_sla` index supports SLA dashboards without scanning the full table.

### 7.5 Lifecycle Examples

**Leave Application — Full Timeline:**

| # | status | tat | actor | actor_role | comment | transitioned_at |
|---|--------|-----|-------|------------|---------|-----------------|
| 1 | `new` | — | User #42 | staff | Applied for annual leave, Dec 20–24 | 2026-12-10 09:15 |
| 2 | `pending_approval` | 60s | User #42 | staff | Submitted for approval | 2026-12-10 09:16 |
| 3 | `approved` | 191,640s (~2.2d) | User #8 | hr_manager | Approved — no conflicts | 2026-12-12 14:30 |
| 4 | `on_leave` | 654,600s (~7.6d) | *system* | — | Auto-transitioned on leave start date | 2026-12-20 00:00 |
| 5 | `complete` | 432,000s (5d) | *system* | — | Auto-transitioned on return date | 2026-12-25 00:00 |

**IT Ticket — With Parts and Assignee Handoff:**

| # | status | tat | actor | assignees | comment | comment_tag | metadata |
|---|--------|-----|-------|-----------|---------|-------------|----------|
| 1 | `open` | — | User #101 | — | Printer on 3rd floor not working | `report` | `{"priority": "medium"}` |
| 2 | `assigned` | 1,800s | User #50 | `[{"id": 55}, {"id": 56}]` | Assign to technicians | — | — |
| 3 | `awaiting_parts` | 7,200s | User #55 | — | Need replacement toner, ordered | `internal_note` | `{"part": "TN-247"}` |
| 4 | `in_progress` | 259,200s (3d) | User #55 | — | Parts arrived, replacing now | — | — |
| 5 | `resolved` | 3,600s | User #55 | — | Toner replaced, test print OK | `resolution` | — |
| 6 | `closed` | 86,400s (1d) | User #101 | — | Confirmed working, thanks! | `feedback` | — |

**Order — Multi-Department with Attachments:**

| # | status | tat | actor | actor_dept | assignees | comment | attachments |
|---|--------|-----|-------|------------|-----------|---------|-------------|
| 1 | `created` | — | User #200 | — | — | — | — |
| 2 | `processing` | 300s | User #12 | warehouse | `[{"id": 12}]` | Payment confirmed, picking | — |
| 3 | `customs_review` | 14,400s | User #12 | warehouse | `[{"id": 30}]` | International order | `[{"name": "invoice.pdf"}]` |
| 4 | `customs_hold` | 43,200s | *system* | — | `[{"id": 30}]` | Missing HS code for item #2 | — |
| 5 | `customs_cleared` | 86,400s | User #30 | logistics | — | HS code added, cleared | `[{"name": "clearance_cert.pdf"}]` |
| 6 | `shipped` | 3,600s | User #30 | logistics | — | Tracking: MY1234567890 | — |
| 7 | `delivered` | 259,200s (3d) | *system* | — | — | Carrier confirmed delivery | — |

### 7.6 UI Consumption

The history table directly feeds several UI patterns:

- **Timeline view:** Vertical list of transitions with actor (name + role + department), comment, attachments, and relative timestamps. The default detail view for any process instance.
- **Current status badge:** The model's own `status` column is the source of truth; history is for the full journey display.
- **TAT / SLA analysis:** "This ticket spent 3 days in `awaiting_parts`" — read directly from `tat`, no computation needed. Dashboard: "Average TAT for `pending_approval` → `approved` this month: 1.8 days."
- **Kanban card detail:** Click a card, see its full journey with assignee handoffs.
- **Filtered views:** "Show only rejection reasons" via `comment_tag = 'reason'`. "Show only entries with attachments" via `attachments IS NOT NULL`.
- **Actor analytics:** "How many transitions did the logistics department handle this week?" via `actor_department`.

---

## 8. Kanban Columns — View-Level Configuration

### Purpose

Each process has its own kanban board. The `kanban_code` on `StatusConfig` maps statuses to columns, but the column itself — its label, position, visual style, WIP limit — needs a definition. Without this table, admins cannot self-service configure kanban boards alongside their process definitions.

This is **process configuration**, not user preferences. It lives in the database alongside StatusConfig and transitions, managed in the same admin UI.

### Schema

```
base_workflow_kanban_columns
├── id                   (bigint PK)
├── flow                 (string)          same discriminator as StatusConfig
├── code                 (string)          the kanban_code referenced by StatusConfig
├── label                (string)          "In Progress", "Blocked", "Done"
├── position             (int, default 0)  column ordering
├── wip_limit            (int, null)       max items allowed in this column
├── settings             (json, null)      visual config: color, icon, collapsed state, etc.
├── description          (text, null)      what this column represents
├── is_active            (bool, default T)
└── timestamps
```

### Design Decisions

**`settings` JSON instead of individual visual columns.**
Visual properties grow over time — color, icon, default collapsed state, card density, badge style. A single `settings` JSON absorbs all of them without schema migrations:
```json
{
    "color": "#f59e0b",
    "icon": "clock",
    "collapsed": false,
    "card_density": "compact"
}
```

**`wip_limit` is a dedicated column, not in `settings`.**
WIP limits are a functional constraint, not a visual preference. The engine can enforce them (prevent transitions that would exceed the limit). Keeping it as a typed integer column enables `WHERE wip_limit IS NOT NULL` queries and constraint logic without JSON parsing.

**`code` ties to `StatusConfig.kanban_code`.**
Multiple statuses can map to the same kanban column: statuses `processing`, `customs_review`, and `customs_hold` all have `kanban_code = 'in_progress'`, pointing to one `workflow_kanban_columns` row.

### Indexes

```
PRIMARY KEY (id)
UNIQUE      (flow, code)                                -- one column definition per code per process
INDEX       (flow, is_active, position)                -- board rendering query
```

### Example

**IT Ticket Kanban Board:**

| flow | code | label | position | wip_limit | settings |
|------|------|-------|----------|-----------|----------|
| it_ticket | backlog | Backlog | 0 | — | `{"color": "#6b7280", "icon": "inbox"}` |
| it_ticket | in_progress | In Progress | 1 | 5 | `{"color": "#3b82f6", "icon": "wrench"}` |
| it_ticket | blocked | Blocked | 2 | — | `{"color": "#ef4444", "icon": "pause"}` |
| it_ticket | done | Done | 3 | — | `{"color": "#22c55e", "icon": "check"}` |

**Corresponding StatusConfig mappings:**

| status code | kanban_code |
|-------------|-------------|
| `open` | `backlog` |
| `assigned` | `in_progress` |
| `awaiting_parts` | `blocked` |
| `in_progress` | `in_progress` |
| `resolved` | `done` |
| `closed` | `done` |

---

## 9. The Engine Components

The Workflow module has five components:

| Component | Responsibility |
|-----------|---------------|
| **WorkflowEngine** | Orchestrates status changes. Entry point for all status operations. |
| **StatusManager** | CRUD and querying of `StatusConfig` records. Loads the status graph for a process. Caches aggressively. |
| **TransitionManager** | CRUD and querying of `base_workflow_status_transitions` records. Loads edge-level policy for a process. |
| **TransitionValidator** | Evaluates whether a transition is allowed: checks transition active state, AuthZ capability, and guard classes. |
| **Hooks/** | Before/after transition hooks. Notifications, external integrations, AI prompts. |

### Call Flow

```
User clicks "Approve" on a leave application
    │
    ▼
WorkflowEngine::transition($leaveApp, 'approved', $context)
    │
    ├── StatusManager::getStatusGraph('leave_application')          // load & cache nodes
    │
    ├── TransitionManager::getTransition('leave_application',       // load edge policy
    │       'pending_approval', 'approved')
    │
    ├── TransitionValidator::validate($transition, $actor)
    │       ├── Is the transition active?
    │       ├── Does $actor have the required capability? (AuthorizationService::authorize)
    │       └── Does guard_class pass? (e.g., LeaveBalanceGuard)
    │
    ├── Hooks::fireBefore('leave_application', 'pending_approval', 'approved')
    │
    ├── $leaveApp->status = 'approved'
    │   $leaveApp->save()
    │
    ├── StatusHistory::record(...)                                  // lifecycle event
    │
    ├── TransitionAction::execute($transition->action_class)        // edge-specific action
    │
    ├── Hooks::fireAfter('leave_application', 'pending_approval', 'approved')
    │       ├── Send notifications (per target status config)
    │       └── Assign PIC (per target status config)
    │
    └── return TransitionResult
```

### Transaction and Failure Policy

The transition call flow wraps the critical path in a **database transaction**:

- **Inside the transaction:** Model status update, history recording, and `action_class` execution. If any of these fail, the entire transition rolls back — the model's status is unchanged, no history is written, and the action's side effects (if DB-only) are reverted.
- **Outside the transaction (best-effort):** `Hooks::fireBefore()` runs before the transaction opens (can abort the transition by throwing). `Hooks::fireAfter()` runs after commit — notifications, event dispatching, and external integrations. These are best-effort: a failed notification does not undo an approved leave application.
- **`action_class` with external side effects:** If an action calls an external API (e.g., `NotifyCustomsAgency`), it should dispatch a queued job rather than making the call synchronously inside the transaction. This prevents holding the transaction open on network I/O and avoids the problem of rolling back a DB change after an external call has already succeeded.
- **Partial failure surfacing:** `TransitionResult` carries success/failure state and a reason. Guards return `GuardResult` with a denial reason. The engine does not silently swallow failures.

### Guard and Action Placement

Engine contracts (`TransitionGuard`, `TransitionAction`) live in `app/Base/Workflow/Contracts/`. Process-specific implementations live in the **owning business module**:

```
app/Base/Workflow/Contracts/
├── TransitionGuard.php
└── TransitionAction.php

app/Modules/Operation/Leave/Workflow/
├── Guards/LeaveBalanceGuard.php        ← implements TransitionGuard
└── Actions/NotifyApplicant.php         ← implements TransitionAction

app/Modules/Operation/Logistics/Workflow/
├── Guards/HsCodeVerified.php
├── Guards/InventoryReserved.php
└── Actions/NotifyCustomsAgency.php
```

The `guard_class` and `action_class` columns in `base_workflow_status_transitions` store fully qualified class names (e.g., `App\Modules\Operation\Leave\Workflow\Guards\LeaveBalanceGuard`). The engine resolves them through Laravel's service container — no registry, no autoloader configuration. The business module is responsible for ensuring its guard/action classes are autoloadable (standard Composer PSR-4).

---

## 10. How Models Participate

A model that participates in the workflow engine needs:

1. A `status` column (string, storing the current status code)
2. A known `flow` identifier (e.g., `'leave_application'`)

That's it. The engine is not invasive. A trait (e.g., `HasWorkflowStatus`) could provide:

```php
// Conceptual — not code yet
trait HasWorkflowStatus
{
    public function flow(): string;                       // returns 'leave_application'
    public function currentStatus(): StatusConfig;       // resolves current status config
    public function availableTransitions(): Collection;  // what can happen next
    public function transitionTo(string $code): void;    // delegates to WorkflowEngine
}
```

Existing models like `Company` (which already has `status` with `active`, `suspended`, etc.) could adopt the engine retroactively by:
1. Creating `StatusConfig` rows for `flow = 'company'`
2. Adding the trait
3. Gradually replacing hardcoded status methods with engine calls

---

## 11. AI Integration Points

### 11.1 Runtime AI Assistance

The `prompt` field on each status enables AI assistance during process execution:

- **Status-aware suggestions:** "This leave application has been in `pending_approval` for 3 days. The prompt says: *Review the leave dates and check for conflicts.*"
- **Transition guidance:** AI can suggest the next action based on the current status and available transitions
- **Auto-classification:** AI can recommend which status an incoming item should start in
- **SLA monitoring:** Combined with timestamps, AI can flag items that are stuck

### 11.2 AI-Assisted Workflow Configuration

Workflow configuration — defining statuses, transitions, capabilities, guards, kanban columns — is extensive and error-prone. Lara assists administrators through **artisan commands** exposed via the existing `ArtisanTool`. No dedicated workflow tool is needed; commands serve CLI users, AI, and scripts with one implementation.

#### Commands

| Command | Purpose |
|---------|---------|
| `blb:workflow:create` | Register a new flow in `base_workflow` |
| `blb:workflow:add-status` | Add a `StatusConfig` node to a flow |
| `blb:workflow:add-transition` | Add a transition edge between two statuses |
| `blb:workflow:add-kanban-column` | Add a kanban column definition |
| `blb:workflow:describe` | Dump the current status graph (nodes, edges, kanban) for a flow |
| `blb:workflow:validate` | Check graph integrity: orphan statuses, unreachable nodes, missing capabilities, disconnected edges |

Each command accepts arguments/options for all relevant fields (e.g., `--flow`, `--code`, `--label`, `--capability`, `--guard-class`). Output is structured text that Lara can parse and reason about.

#### Conversational Flow

An administrator asks Lara: *"Set up a leave application workflow with new, pending_approval, approved, rejected, and closed statuses."*

Lara orchestrates via `ArtisanTool`:

1. `blb:workflow:create --code=leave_application --label="Leave Application" --module=hr`
2. `blb:workflow:add-status --flow=leave_application --code=new --label=New --position=0` (repeated for each status)
3. `blb:workflow:add-transition --flow=leave_application --from=new --to=pending_approval --label=Submit` (repeated for each edge)
4. `blb:workflow:describe --flow=leave_application` (Lara inspects the result, confirms with admin)
5. `blb:workflow:validate --flow=leave_application` (catches misconfigurations before they affect users)

The admin refines conversationally: *"Add a guard on the approve transition that checks leave balance"* → Lara calls `blb:workflow:add-transition` with `--guard-class`.

#### Why Commands, Not a Dedicated AI Tool

- **Lara already has `ArtisanTool`** — any `blb:workflow:*` command is automatically available without building or registering a new tool.
- **Wider audience** — developers use the same commands in CLI, seeding scripts, and CI pipelines. One implementation serves all consumers.
- **Simpler maintenance** — no separate tool class, schema builder, capability registration, or action dispatch to maintain.
- **Escape hatch** — if conversational UX later needs structured JSON responses or multi-step atomic operations, a thin `WorkflowTool` can be layered on top, delegating to the same service layer the commands use. Commands first; dedicated tool if and when needed.

---

## 12. Open Questions

| # | Question | Notes |
|---|----------|-------|
| 1 | ~~**Is Workflow a Core module (`0200`) or Base infrastructure (`0100`)?**~~ | **Resolved.** Base infrastructure (`0100`). Module path: `app/Base/Workflow/`. Migration prefix: `0100_01_15_*`. Table prefix: `base_workflow_*`. The engine is process-agnostic infrastructure; child classes (process-specific guards, actions, models) live in business modules. |
| 2 | ~~**How do sub-processes (Level 4) relate to parent processes?**~~ | **Resolved.** No sub-process support in the engine. Related processes are independent workflow instances linked by Laravel events. A parent transition's `action_class` or `Hooks::fireAfter()` dispatches an event; a listener in the child module creates/advances its own process. Same pattern as OpenMage's event-observer. The engine stays simple; orchestration is event-driven. |
| 3 | **Should `StatusConfig` support versioning?** | When an admin changes a process definition, should in-flight items keep their original definition? |
| 4 | **Should the engine enforce that `status` column values match `StatusConfig` codes?** | Strict enforcement vs. advisory. Strict is safer but less flexible during migration. |
| 5 | ~~**How does this integrate with AuthZ?**~~ | **Resolved.** Transitions carry a `capability` column referencing an AuthZ capability key. The `TransitionValidator` delegates to `AuthorizationService::authorize()`. Each business module declares its own `workflow.{process_code}.{action}` capabilities. See §6.1. |
| 6 | **History table growth strategy?** | High-volume processes (orders) will generate many rows. Partitioning by `flow`? Archival policy? Or acceptable at scale with proper indexing? |

---

## 13. What's Next

This document captures the big picture. Before writing code:

1. **Define the public interface** — `WorkflowEngine`, `StatusManager`, `TransitionManager`, `TransitionValidator` method signatures
2. **Walk through IT tickets end-to-end** against this design (first use case), followed by quality assurance control (second use case)
3. **Write migrations** — all five tables, following BLB naming and timestamp conventions
4. **Then build** — models first, engine second, UI last

---

*"A process is just a graph of statuses. Keep the graph simple; let the policies be rich."*
