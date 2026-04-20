# AI Current State

**Document Type:** Implementation Reference
**Status:** Active
**Purpose:** Source of truth for BLB AI features implemented in code
**Coverage:** OpenClaw parity Phases 1-6, Claw Code runtime parity (hook transcript entries + approval visibility), AI Run Ledger Phases 0-3, and Lara direct-stream transport delivery
**Last Updated:** 2026-04-14
**Related:** [agent-model.md](agent-model.md), [lara.md](lara.md), [capability-map.md](capability-map.md), `docs/Base/AI/tool-framework.md`

---

## 1. Problem Essence

BLB's AI documentation needs one implementation-first reference that describes what the system actually does today, not just the intended design.

---

## 2. Scope

This document describes the AI system that is implemented in code under:

- `app/Base/AI/`
- `app/Modules/Core/AI/`
- `resources/core/views/livewire/admin/ai/`
- `tests/Unit/Modules/Core/AI/`

It covers the completed OpenClaw parity work through Phase 6, the delivered Claw Code runtime parity work, and the delivered AI Run Ledger work:

1. Workspace-driven runtime
2. Memory and recall
3. Browser operator
4. Messaging, scheduling, and background work
5. Orchestration and extension kernel
6. Operator control plane and policy depth
7. Claw Code runtime parity gaps around hook visibility, transcript fidelity, and usage reconstruction
8. Run ledger, activity stream, execution policy, and Lara direct-stream chat UX

---

## 3. Browser Surfaces

These are the authenticated browser-visible AI surfaces in the product today.

| URL | Route Name | Purpose |
|-----|------------|---------|
| `/admin/setup/lara` | `admin.setup.lara` | Lara provisioning and activation |
| `/admin/ai/task-models` | `admin.ai.task-models` | Lara task-model configuration |
| `/admin/ai/providers` | `admin.ai.providers` | Company-scoped provider and model management |
| `/admin/ai/providers/setup/{providerKey}` | `admin.ai.providers.setup` | Provider-specific setup flow |
| `/admin/ai/tools/{toolName?}` | `admin.ai.tools` | Tool catalog and per-tool workspace |
| `/admin/ai/control-plane` | `admin.ai_control_plane.view` | Operator control plane |
| `/admin/ai/runs/{runId}` | `admin.ai.runs.show` | Standalone run detail page |

Legacy compatibility redirects still exist for removed product surfaces:

| URL | Route Name | Current Behavior |
|-----|------------|------------------|
| `/admin/ai/playground` | `admin.ai.playground` | Redirects to Task Models |

The unauthenticated integration endpoint currently exposed is:

| URL | Route Name | Purpose |
|-----|------------|---------|
| `/api/ai/messaging/webhook/{channel}/{accountId?}` | `ai.messaging.webhook` | Inbound messaging webhook entry |

The authenticated chat transport endpoints currently exposed are:

| URL | Route Name | Purpose |
|-----|------------|---------|
| `/api/ai/chat/turns/{turnId}/stream` | `ai.chat.turn.stream` | Direct NDJSON stream for fresh interactive turns |
| `/api/ai/chat/turns/{turnId}/events` | `ai.chat.turn.events` | Persisted turn-event replay and gap-fill via `after_seq` |

---

## 4. Menu Structure

AI appears in the admin menu with these entries:

1. `AI`
2. `Lara`
3. `Task Models`
4. `AI Providers`
5. `Tools`
6. `Control Plane`

---

## 5. Implemented Phases

### 5.1 Phase 1 - Workspace-Driven Runtime

Implemented outcome:

- agent execution is driven by a shared workspace pipeline rather than agent-specific ad hoc prompt assembly
- runtime prompt construction now flows through:
  - `WorkspaceResolver`
  - `WorkspaceValidator`
  - `PromptPackageFactory`
  - `PromptRenderer`
- Lara and delegated agent-task runtimes are thin callers over the same workspace-driven prompt substrate
- run metadata includes prompt-package diagnostics for operator visibility

Primary user-visible effect:

- the Lara setup flow and delegated agent-task runtimes run on a consistent workspace contract
- workspace validation failures fail clearly instead of silently degrading

### 5.2 Phase 2 - Memory & Recall

Implemented outcome:

- BLB now has a real per-agent memory subsystem based on markdown source files and per-agent SQLite indexes
- chunking, indexing, retrieval, compaction, and health reporting exist as separate services
- memory is no longer just a loose directory read pattern

Implemented services:

- `MemorySourceCatalog`
- `MemoryChunker`
- `MemoryIndexStore`
- `MemoryIndexer`
- `MemoryRetrievalEngine`
- `MemoryCompactor`
- `MemoryHealthService`

User-visible features:

- `memory_get`
- `memory_search`
- `memory_status`
- tool workspace support for memory health and setup visibility

Added commands:

- `blb:ai:memory:index {agent} [--force]`
- `blb:ai:memory:compact {agent}`

### 5.3 Phase 3 - Browser Operator

Implemented outcome:

- browser automation is now a persistent operator subsystem, not just one-shot Playwright execution
- browser sessions, page state, tabs, refs, and artifacts are durably tracked

Implemented services and models:

- `BrowserPoolManager`
- `BrowserSessionManager`
- `BrowserSessionRepository`
- `BrowserRuntimeAdapter`
- `BrowserArtifactStore`
- `BrowserSsrfGuard`
- `BrowserSession` model
- browser artifact metadata DTOs and session state DTOs

User-visible features:

- the `browser` tool can navigate, snapshot, act, screenshot, evaluate, export PDF, and manage tabs
- tool workspace surfaces browser readiness and setup requirements
- multi-turn browser work can persist through stored browser sessions

Added commands:

- `blb:ai:browser:sweep`
- `blb:ai:browser:status`

### 5.4 Phase 4 - Messaging, Scheduling, and Background Work

Implemented outcome:

- BLB now has a unified operations dispatch layer for queued AI work
- outbound email messaging, inbound webhook intake, scheduled work, and background command execution are operational rather than stubbed

Implemented services:

- `OperationsDispatchService`
- `OutboundMessageService`
- `InboundSignalService`
- `InboundRoutingService`
- `BackgroundCommandService`
- `ScheduleDefinitionService`
- `SchedulePlanner`

User-visible features:

- the `message` tool can send real outbound messages through configured channels
- the `schedule_task` tool creates persistent scheduled operations
- background command execution creates durable dispatch records
- delegation and operation status are inspectable

Added commands:

- `blb:ai:operations:sweep`
- `blb:ai:schedules:tick`
- `blb:ai:operations:status {operation}`

### 5.5 Phase 5 - Orchestration & Extension Kernel

Implemented outcome:

- delegation moved from Lara-specific shortcut behavior to a shared orchestration substrate
- task routing, child session spawning, skill packs, and runtime hooks are now first-class concepts

Implemented services:

- `AgentCapabilityCatalog`
- `TaskRoutingService`
- `SessionSpawnManager`
- `OrchestrationPolicyService`
- `SkillPackRegistry`
- `SkillContextResolver`
- `RuntimeHookRegistry`
- `RuntimeHookRunner`

Implemented packaged extension:

- `KnowledgeSkillPack`

User-visible features:

- Lara can discover agents with structured capability descriptors
- `/delegate` and tool-backed delegation operate on the shared routing kernel
- Lara chat delegation stores its queued receipt in the current session transcript and appends a second assistant follow-up when the queued delegated task succeeds or fails
- Lara chat polls only while the selected session has pending delegated work, so those async follow-up messages appear without a manual refresh
- runtime context can be extended by skill packs and runtime hooks

Added commands:

- `blb:ai:orchestration:status {id}`
- `blb:ai:skills:list`
- `blb:ai:skills:verify {pack}`

### 5.6 Phase 6 - Operator Control Plane & Policy Depth

Implemented outcome:

- BLB now has a unified operator control plane that connects run inspection, health/presence, lifecycle operations, telemetry, and layered policy evaluation

Implemented services:

- `RunInspectionService`
- `OperationalTelemetryService`
- `HealthAndPresenceService`
- `LifecycleControlService`
- `PolicyEvaluationService`

Implemented persistence:

- `ai_telemetry_events`
- `ai_lifecycle_requests`
- `TelemetryEvent` model
- `LifecycleRequest` model

User-visible features:

- `ControlPlane` Livewire page with three tabs:
  - Run Inspector
  - Health & Presence
  - Lifecycle Controls
- run/session inspection backed by normalized DTOs rather than raw logs
- preview-first lifecycle actions with audit trail
- separate readiness, health, and presence indicators

Added commands:

- `blb:ai:inspect:run {run}`
- `blb:ai:health:snapshot`
- `blb:ai:lifecycle:preview`
- `blb:ai:lifecycle:execute`

### 5.7 AI Run Ledger, Activity Stream, Execution Policy, and Direct Chat Streaming

Implemented outcome:

- BLB now persists each runtime execution as a first-class run ledger entry instead of relying on transient UI state or ad hoc logs
- session transcripts use a richer activity-stream format with explicit `thinking`, `tool_call`, `tool_result`, and `hook_action` entries
- run inspection is available both inside the control plane and through a deep-linkable standalone run page
- runtime execution now uses explicit execution policies for interactive, heavy foreground, and background workloads
- hook outcomes (tool removal, tool denial) are persisted as canonical `hook_action` transcript entries with source attribution (authz vs hook)
- approval visibility: denied or prevented actions appear as first-class timeline events in chat, run detail, and transcript replay
- fresh Lara turns now stream directly from the HTTP response while the same events are persisted for replay and recovery
- resumed active turns rebuild from persisted events and continue through short-interval replay polling rather than a brokered socket channel

Implemented services and runtime pieces:

- `AiRun` ledger-backed runtime persistence
- `ExecutionPolicy` and `ExecutionMode`
- `ChatTurnRunner` for shared turn execution
- `ChatTurnStreamController` for direct NDJSON transport
- `TurnStreamBridge` and `TurnEventPublisher` for canonical persisted turn events
- `TurnEventStreamController` for replay and `after_seq` gap-fill
- `RuntimeHookCoordinator::preToolUse()`
- `MessageManager::sessionUsage()` and `appendHookAction()`
- standalone `RunDetail` Livewire page
- `HandlesStreaming` Livewire concern

User-visible features:

- transcript timeline renders thinking, tool call, tool result, hook action, and assistant entries from the persisted transcript
- hook actions show stage, affected tools, denial reason, and source (authz vs hook) in the activity stream
- run metadata and retry/fallback details are visible in run inspection
- `/admin/ai/runs/{runId}` provides a standalone, deep-linkable run-inspection surface for the owning user
- fresh interactive turns render phase changes, tool progress, and assistant deltas immediately in chat during the streaming response
- page reloads and disconnects recover through persisted turn-event replay plus polling from the last seen sequence
- the chat composer shows cumulative session token usage derived from `ai_runs`, with transcript fallback for older sessions

Implementation notes:

- `AgenticRuntime::run()` and `runStream()` accept an optional `ExecutionPolicy`
- `ExecutionPolicy::forMode()` resolves timeout tiers for `interactive`, `heavy_foreground`, and `background`
- timeout retry logic skips same-budget retries once a call has already consumed a material portion of the budget
- denied tools surface in both the transcript and streaming events, rather than disappearing into internal hook logic
- the live transport for Lara chat is direct HTTP streaming, not Reverb/Echo
- `ai_chat_turn_events` is the durable source of truth for replay, resume, and ordered recovery

---

## 6. Tool Inventory

The current AI runtime registers these implemented tool surfaces.

Core/general tools:

- `artisan`
- `bash`
- `navigate`
- `visible_nav_menu`
- `write_js`
- `system_info`

Data and knowledge tools:

- `query_data`
- `guide`
- `memory_get`
- `memory_search`
- `memory_status`
- `web_search`
- `web_fetch`
- `document_analysis`
- `image_analysis`

Coordination and operations tools:

- `agent_list`
- `delegate_task`
- `delegation_status`
- `schedule_task`
- `notification`
- `message`

Browser and editing tools:

- `browser`
- `edit_file`
- `edit_data`
- `ticket_update`

The tool framework itself is documented in `docs/Base/AI/tool-framework.md`. This document is concerned with which tools are implemented and how they fit the runtime.

---

## 7. Core Runtime Shape

The implemented runtime is layered like this:

1. **Base AI (`app/Base/AI/`)**
   - stateless tool contract and base classes
   - model catalog and provider discovery
   - OpenAI-compatible LLM transport

2. **Core AI (`app/Modules/Core/AI/`)**
   - company-scoped provider and model management
   - agent sessions, messages, and runtime execution
   - orchestration, browser, memory, messaging, and control-plane subsystems

3. **Livewire surfaces**
   - setup pages
   - providers
   - playground
   - tools
   - operator control plane

---

## 8. Persistence Model In Use

Implemented persistent AI state now spans:

- workspace directories under `storage/app/ai/workspace/{employee_id}/`
- `ai_runs` canonical run ledger rows
- session `.jsonl` transcript files
- session `.meta.json` metadata files
- transcript activity-stream entries (`message`, `thinking`, `tool_call`, `tool_result`)
- per-agent memory indexes
- provider and model database tables
- `ai_operation_dispatches` operations dispatch ledger
- browser sessions and browser artifacts
- telemetry events and lifecycle request ledgers

This means BLB has moved well beyond a transient playground and now operates as a real agent platform with durable runtime state.

---

## 9. What This Document Does Not Cover

This document intentionally does not restate every foundational design decision or future roadmap item.

Use the companion docs for that:

- [agent-model.md](agent-model.md) for domain and workspace fundamentals
- [lara.md](lara.md) for Lara-specific system-agent behavior
- [capability-map.md](capability-map.md) for OpenClaw comparison, roadmap framing, and remaining gaps

---

## 10. Status Summary

As of 2026-04-09, BLB's AI system includes:

1. provider and model management
2. workspace-driven prompt assembly
3. persistent sessions and transcripts
4. memory indexing and recall
5. persistent browser operations
6. queued delegation and orchestration
7. messaging, scheduling, and background work
8. unified operator control plane
9. canonical AI run ledger persistence
10. transparent transcript activity stream and standalone run detail views
11. explicit execution policies for interactive, heavy, and background runs
12. direct Lara turn streaming with persisted event replay and gap-fill recovery
13. session token usage reconstruction from `ai_runs` with transcript fallback
14. per-agent backup model configuration (max 2 entries in `llm.models[]`) with UI-configurable backup provider+model, inline fallback notices, and thread-level fallback banners

This is the current implementation baseline.
