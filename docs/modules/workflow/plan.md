# Workflow Engine: Implementation Plan

**Status:** In Progress
**Design Doc:** `docs/modules/workflow/design.md`
**Module Path:** `app/Base/Workflow/`
**Migration Prefix:** `0100_01_15_*`
**Table Prefix:** `base_workflow_*`

---

## Decisions Log

| # | Decision | Date |
|---|----------|------|
| 1 | Workflow is **Base infrastructure** (`0100`), not Core (`0200`). Child classes (flow-specific models, guards, actions) live in business modules. | 2026-03-17 |
| 2 | `next_statuses` removed from StatusConfig — transitions table is single source of truth for edges. | 2026-03-17 |
| 3 | `permissions` JSON removed — replaced by `capability` column (string) referencing AuthZ capability keys. | 2026-03-17 |
| 4 | `actor_id` references users, not employees. | 2026-03-17 |
| 5 | No sub-flow support in engine — related flows are independent workflow instances linked by Laravel events (event-observer pattern). | 2026-03-17 |
| 6 | Engine complexity capped at Level 3 (conditional transitions). Multi-department orchestration and external integration are composition patterns using standard Laravel events/jobs, not engine features. | 2026-03-17 |
| 7 | AI-assisted configuration via `blb:workflow:*` artisan commands, not a dedicated AI tool. Lara uses existing `ArtisanTool` to invoke them. Commands serve CLI, AI, and scripts with one implementation. | 2026-03-17 |

---

## Phase 1: Schema & Models

### 1.1 Migrations
Create all five tables under `app/Base/Workflow/Database/Migrations/`.

| # | Migration File | Table | Status |
|---|---------------|-------|--------|
| 1 | `0100_01_15_000000_create_base_workflow_table.php` | `base_workflow` | ✅ |
| 2 | `0100_01_15_000001_create_base_workflow_status_configs_table.php` | `base_workflow_status_configs` | ✅ |
| 3 | `0100_01_15_000002_create_base_workflow_status_transitions_table.php` | `base_workflow_status_transitions` | ✅ |
| 4 | `0100_01_15_000003_create_base_workflow_status_history_table.php` | `base_workflow_status_history` | ✅ |
| 5 | `0100_01_15_000004_create_base_workflow_kanban_columns_table.php` | `base_workflow_kanban_columns` | ✅ |

### 1.2 Models
Eloquent models under `app/Base/Workflow/Models/`.

| # | Model | Table | Status |
|---|-------|-------|--------|
| 1 | `Workflow` | `base_workflow` | ✅ |
| 2 | `StatusConfig` | `base_workflow_status_configs` | ✅ |
| 3 | `StatusTransition` | `base_workflow_status_transitions` | ✅ |
| 4 | `StatusHistory` | `base_workflow_status_history` | ✅ |
| 5 | `KanbanColumn` | `base_workflow_kanban_columns` | ✅ |

### 1.3 Contracts
Interfaces under `app/Base/Workflow/Contracts/`.

| # | Contract | Purpose | Status |
|---|----------|---------|--------|
| 1 | `TransitionGuard` | Guard contract: `evaluate(Model, StatusTransition, Actor): GuardResult` | ✅ |
| 2 | `TransitionAction` | Action contract: `execute(Model, StatusTransition, Actor): void` | ✅ |

### 1.4 DTOs / Value Objects

| # | Class | Purpose | Status |
|---|-------|---------|--------|
| 1 | `GuardResult` | Result of guard evaluation (allowed/denied + reason) | ✅ |
| 2 | `TransitionResult` | Result of a transition attempt (success/failure + history record) | ✅ |
| 3 | `TransitionContext` | Context passed to engine: actor, comment, attachments, metadata | ✅ |

---

## Phase 2: Engine

| # | Component | Location | Responsibility | Status |
|---|-----------|----------|---------------|--------|
| 1 | `WorkflowEngine` | `Services/` | Orchestrates transitions. Entry point for all status operations. | ✅ |
| 2 | `StatusManager` | `Services/` | CRUD + caching of StatusConfig records. Loads status graph per flow. | ✅ |
| 3 | `TransitionManager` | `Services/` | CRUD + caching of StatusTransition records. Loads edge policy per flow. | ✅ |
| 4 | `TransitionValidator` | `Services/` | Validates: active check → AuthZ capability → guard evaluation. | ✅ |

---

## Phase 3: Commands (AI-Assisted Configuration)

Artisan commands under `app/Base/Workflow/Console/Commands/`. Lara uses these via `ArtisanTool` — no dedicated AI tool needed.

| # | Command | Purpose | Status |
|---|---------|---------|--------|
| 1 | `blb:workflow:create` | Register a new flow in `base_workflow` | ✅ |
| 2 | `blb:workflow:add-status` | Add a `StatusConfig` node to a flow | ✅ |
| 3 | `blb:workflow:add-transition` | Add a transition edge between two statuses | ✅ |
| 4 | `blb:workflow:add-kanban-column` | Add a kanban column definition | ✅ |
| 5 | `blb:workflow:describe` | Dump the status graph (nodes, edges, kanban) for a flow | ✅ |
| 6 | `blb:workflow:validate` | Check graph integrity: orphans, unreachable nodes, missing capabilities | ✅ |

---

## Phase 4: Integration

| # | Item | Location | Status |
|---|------|----------|--------|
| 1 | `HasWorkflowStatus` trait | `Concerns/` | ✅ |
| 2 | Service provider + wiring | `ServiceProvider.php` | ✅ |
| 3 | Workflow admin AuthZ capabilities | `Config/authz.php` | ✅ |
| 4 | Hooks system (before/after transition events) | `Events/` | ✅ (`TransitionCompleted` event; listeners handle after-hooks) |

---

## Phase 5: Tests

| # | Test Suite | Scope | Status |
|---|-----------|-------|--------|
| 1 | Unit: `StatusConfig` model | Computed accessors, relationships | ✅ |
| 2 | Unit: `TransitionValidator` | Capability check, guard evaluation, active state | ✅ |
| 3 | Feature: `WorkflowEngine::transition()` | Full call flow with DB (using real Ticket model) | ✅ |
| 4 | Feature: AuthZ integration | Capability-gated transitions | ✅ |

---

## Deferred (not in scope for initial implementation)

| # | Item | Open Question | Notes |
|---|------|---------------|-------|
| 1 | StatusConfig versioning | #3 | Decide when in-flight items become a real concern |
| 2 | Strict status code enforcement | #4 | Start advisory, tighten later |
| 3 | History table growth strategy | #6 | Acceptable with indexes until volume warrants partitioning |
| 4 | Admin UI for flow configuration | — | After engine is stable |
| 5 | Kanban board UI | — | After engine + admin UI |
