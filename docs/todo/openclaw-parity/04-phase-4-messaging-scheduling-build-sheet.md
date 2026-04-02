# Phase 4 - Messaging, Scheduling & Background Work Build Sheet

**Parent:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`  
**Scope:** Turn BLB's messaging, inbound triggers, scheduled work, and background command execution into a durable operations fabric  
**Status:** Complete  
**Phase Owner:** Core AI / Base AI  
**Last Updated:** 2026-04-02

---

## 1. Problem Essence

Phase 4 should not be implemented as "make `MessageTool`, `ScheduleTaskTool`, and background `ArtisanTool` stop returning stubs"; it should be implemented as an **operations fabric** that gives BLB agents durable external communication, inbound event intake, proactive scheduling, and tracked background execution through one coherent dispatch model.

---

## 2. Why the Current Phase 4 Description Is Too Thin

The current Phase 4 in `00-capability-gap-audit.md` says:

1. real channel adapters
2. inbound message normalization
3. scheduled task persistence and execution
4. artisan background jobs

That is directionally correct, but it is still a list of visible features rather than a system design.

If implemented too literally, the likely outcome is:

- `MessageTool` starts calling channel APIs directly
- webhook endpoints appear with ad hoc per-channel routing logic
- `ScheduleTaskTool` gets its own table but no real execution model
- background artisan commands invent a separate polling/status mechanism
- each capability works in isolation but none share lifecycle, audit, or operator visibility

That would close surface-level gaps while still leaving BLB structurally behind OpenClaw-style agent operations.

BLB should instead build a deep module with clear boundaries:

- outbound delivery
- inbound signal normalization
- schedule definition and due-work triggering
- durable async dispatch and status tracking
- operator-visible auditability and control

---

## 3. Current Code Snapshot

The current implementation provides several useful primitives, but they do not yet form a real subsystem.

### What exists now

- `MessageTool` exposes a rich action surface and validates channel capabilities, but every action still returns stub payloads rather than calling real transports: `app/Modules/Core/AI/Tools/MessageTool.php`
- `ChannelAdapter`, `ChannelAdapterRegistry`, and `InboundMessage` define a clean messaging contract, but `BaseChannelAdapter` resolves no accounts, sends nothing, and parses no inbound payloads: `app/Modules/Core/AI/Contracts/Messaging/ChannelAdapter.php`, `app/Modules/Core/AI/Services/Messaging/ChannelAdapterRegistry.php`, `app/Modules/Core/AI/Services/Messaging/Adapters/BaseChannelAdapter.php`, `app/Modules/Core/AI/DTO/Messaging/InboundMessage.php`
- WhatsApp, Telegram, Slack, and Email adapters currently contribute capability metadata only; they are transport stubs: `app/Modules/Core/AI/Services/Messaging/Adapters/*.php`
- `ScheduleTaskTool` validates cron syntax and task IDs, but all CRUD/status responses are explicitly stubbed because persistence and scheduler integration do not exist yet: `app/Modules/Core/AI/Tools/ScheduleTaskTool.php`
- `ArtisanTool` foreground execution is real, but background execution only returns a synthetic `artisan_*` dispatch ID and never creates durable work to poll: `app/Modules/Core/AI/Tools/ArtisanTool.php`
- `DelegationStatusTool`, `AgentTaskDispatch`, its migration, and `RunAgentTaskJob` show BLB already has a durable dispatch pattern for asynchronous agent work: `app/Modules/Core/AI/Tools/DelegationStatusTool.php`, `app/Modules/Core/AI/Models/AgentTaskDispatch.php`, `app/Modules/Core/AI/Database/Migrations/0200_02_01_000002_create_ai_agent_task_dispatches_table.php`, `app/Modules/Core/AI/Jobs/RunAgentTaskJob.php`
- No webhook or inbound messaging routes were found under `routes/`, so inbound normalization exists as a DTO contract but not yet as a running entrypoint
- Current tests mainly lock in validation and stub behavior for `MessageTool`, `ScheduleTaskTool`, and background `ArtisanTool`, which means Phase 4 will need a deliberate test-model shift from stubs to subsystem behavior: `tests/Unit/Modules/Core/AI/Tools/MessageToolTest.php`, `tests/Unit/Modules/Core/AI/Tools/ScheduleTaskToolTest.php`, `tests/Unit/Modules/Core/AI/Tools/ArtisanToolTest.php`

### Structural problems in the current shape

1. **Messaging, scheduling, and async execution are tool-shaped instead of subsystem-shaped.**
2. **There is no durable model for channel accounts, conversations, inbound events, or sent messages.**
3. **Inbound normalization has a DTO contract but no intake pipeline, routing policy, or audit surface.**
4. **Scheduled work has input validation but no persistent source of truth, due-work planner, or execution lifecycle.**
5. **Background artisan work advertises delegation-style polling without actually participating in a real dispatch ledger.**

---

## 4. From-Scratch Design: What BLB Should Build Instead

### 4.1 Public interface first

Phase 4 should expose these stable operations:

1. `sendMessage(agentId, channel, target, payload, options)` — deliver outbound communication through a configured account
2. `ingestInboundSignal(channel, request)` — normalize an inbound webhook/request into a canonical internal signal
3. `routeInboundSignal(signalId)` — assign inbound work to the correct conversation, domain flow, or agent dispatch
4. `upsertSchedule(agentId, definition)` — create or update a durable schedule definition
5. `dispatchDueSchedules(at)` — find due schedules and turn them into tracked execution work
6. `dispatchBackgroundCommand(command, options)` — run an operator-approved artisan command through durable async execution
7. `getOperationStatus(operationId)` — inspect current state, result, and failure details for any long-running operation

Tools should wrap these operations. They should not invent lifecycle, persistence, or routing behavior on their own.

### 4.2 Architectural decomposition

#### A. Messaging Delivery Service

Responsibility:

- resolves channel accounts
- validates outbound payloads against channel capabilities
- dispatches through concrete transport adapters
- persists outbound delivery intent and result

Key invariant:

- sending a message is a tracked operation, not just a best-effort API call

#### B. Inbound Signal Intake

Responsibility:

- accepts webhook or poll-based channel input
- verifies authenticity
- normalizes transport-specific payloads into a canonical signal
- stores raw and normalized forms for audit

Key invariant:

- agent logic never parses channel-specific webhook payloads directly

#### C. Inbound Routing Engine

Responsibility:

- decides whether inbound work maps to an existing conversation, a domain workflow, or a fresh agent task
- enforces account, company, and policy boundaries
- creates durable dispatch work for follow-up execution

Key invariant:

- transport identity and business routing stay separate

#### D. Schedule Registry and Planner

Responsibility:

- stores schedule definitions with policy, scope, and target behavior
- computes due work deterministically
- prevents duplicate firing and handles recovery after downtime

Key invariant:

- schedules are canonical definitions, not ephemeral tool responses

#### E. Operations Dispatch Ledger

Responsibility:

- tracks queued, running, succeeded, failed, and cancelled operations
- captures result summaries, errors, timestamps, and related entities
- provides one status surface for proactive and asynchronous work

Key invariant:

- asynchronous work is visible through one durable lifecycle model

#### F. Background Command Executor

Responsibility:

- executes approved artisan commands through the dispatch ledger
- captures stdout, stderr, exit code, and operator-safe summaries
- applies explicit policy around which commands are allowed

Key invariant:

- background command execution is an audited operations feature, not a hidden side path

---

## 5. Core Design Decisions

### 5.1 Prefer one dispatch model for async work

From scratch, BLB should not have one dispatch story for agent delegation, a second for schedules, and a third for background artisan commands.

Phase 4 should either:

- promote the current `AgentTaskDispatch` pattern into a more general operations dispatch ledger, or
- build a neutral dispatch core that agent task dispatches become a specialization of

What BLB should not do is multiply parallel status models.

### 5.2 Separate transport adapters from routing policy

Channel adapters should only know how to:

- authenticate to a platform
- send payloads
- parse inbound payloads
- describe platform capabilities

They should not decide:

- which company account is allowed to respond
- which agent should handle an inbound message
- whether an inbound event should create a new task

Those decisions belong in higher-level services.

### 5.3 Normalize inbound signals before dispatch

Inbound traffic should become a canonical internal record before any downstream logic runs.

That record should capture:

- channel
- account
- sender
- conversation identifier
- content/media
- raw payload reference
- authenticity check result
- received timestamp

This keeps downstream logic stable even when channel payload shapes differ wildly.

### 5.4 Treat schedules as intent definitions, not cron strings

The current tool surface makes schedules look like a cron string plus free text. That is too shallow.

A real schedule definition should also model:

- target agent or target operation
- execution payload/task
- timezone
- enabled state
- deduplication/concurrency policy
- ownership and provenance
- last fired / next due metadata

### 5.5 Make external side effects auditable

Messaging and background commands both create external side effects.

BLB should persist enough information to answer:

- what was attempted
- from which account or actor
- why it was triggered
- whether it succeeded
- what the platform or process returned

This matters for operator trust and incident review.

### 5.6 Background artisan execution needs policy boundaries

Not every artisan command should become agent-invokable background work.

From scratch, BLB should define:

- which commands are allowed
- whether arguments are templated or free-form
- who can dispatch them
- whether they run as agent work, operator work, or both

### 5.7 Messaging and schedules are related, but not the same subsystem

It is tempting to collapse everything into a giant "automation tool."

Instead:

- messaging should own transport and conversations
- schedules should own proactive triggering
- the dispatch ledger should own async lifecycle

This keeps each module deep without turning Phase 4 into a grab bag.

---

## 6. Proposed Module Shape

Recommended service set:

- `ChannelAccountRepository`
- `OutboundMessageService`
- `InboundSignalVerifier`
- `InboundSignalNormalizer`
- `InboundRoutingService`
- `ScheduleDefinitionRepository`
- `SchedulePlanner`
- `OperationsDispatchService`
- `BackgroundCommandService`
- `OperationsHealthService`

Recommended job/command set:

- `ProcessInboundSignalJob`
- `DispatchDueSchedulesJob`
- `RunBackgroundCommandJob`
- `blb:ai:operations:sweep`
- `blb:ai:schedules:tick`
- `blb:ai:operations:status {operation}`

Recommended tool evolution:

- `MessageTool` becomes a thin router over outbound messaging and conversation-facing actions
- `ScheduleTaskTool` becomes a thin wrapper over schedule definitions and status
- `ArtisanTool` keeps real foreground mode, but background mode becomes a thin wrapper over `BackgroundCommandService`
- `DelegationStatusTool` either expands into a broader operation-status surface or becomes the agent-task-specific view over a wider dispatch ledger

---

## 7. Storage and Persistence Model

### 7.1 Channel account records

BLB needs durable records for configured channel accounts.

Suggested fields:

- channel
- company ID
- account identifier
- label
- credentials reference or secret key reference
- enabled state
- inbound configuration metadata
- created/updated timestamps

### 7.2 Conversation and message records

Even if Phase 4 starts small, BLB should avoid treating messaging as stateless fire-and-forget.

Suggested record shape:

- conversation ID
- company ID
- channel
- account ID
- external conversation identifier
- participant references
- last inbound/outbound activity

Suggested message shape:

- message ID
- conversation ID
- direction (`inbound` / `outbound`)
- external message ID
- normalized content
- raw payload reference
- delivery status
- timestamps

### 7.3 Inbound signal records

Before routing, inbound inputs should land in a durable intake record.

Suggested fields:

- signal ID
- channel
- account ID
- authenticity status
- sender identifier
- conversation identifier
- normalized payload
- raw payload storage reference
- received at
- routed at
- resulting operation/dispatch ID

### 7.4 Schedule definition records

Schedules should persist as canonical definitions.

Suggested fields:

- schedule ID
- employee ID or operation target
- description
- execution payload
- cron expression
- timezone
- enabled state
- concurrency policy
- last fired at
- next due at
- created by / provenance

### 7.5 Dispatch and execution records

Phase 4 should not return synthetic IDs without durable backing.

Every asynchronous operation should have a real record with:

- operation ID
- operation type (`agent_task`, `scheduled_task`, `background_command`, `inbound_routing`)
- target identity
- status
- queue/runtime metadata
- result summary
- error details
- started/finished timestamps

If BLB keeps `AgentTaskDispatch` as the underlying ledger, Phase 4 should make that reuse explicit and coherent. If the name becomes too narrow, refactor early rather than layering new tables beside it.

---

## 8. Main Execution Flows

### 8.1 Outbound message flow

1. Tool or application requests an outbound send
2. `OutboundMessageService` resolves company/account policy
3. payload is validated against channel capabilities
4. transport adapter sends the payload
5. conversation/message records are updated
6. result is surfaced to the caller with durable identifiers

### 8.2 Inbound message flow

1. webhook endpoint receives request
2. `InboundSignalVerifier` checks authenticity
3. `InboundSignalNormalizer` creates canonical signal record
4. `InboundRoutingService` maps signal to conversation/domain/agent work
5. dispatch ledger records resulting work
6. operator can inspect raw and normalized history

### 8.3 Scheduled task flow

1. schedule definition is created or updated
2. planner computes next due occurrence
3. scheduler tick finds due schedules
4. due work becomes dispatch ledger entries
5. execution result updates schedule metadata and operation status

### 8.4 Background artisan flow

1. caller requests background command
2. command policy is checked
3. durable operation/dispatch record is created
4. queued job runs the artisan command
5. stdout/stderr/exit summary are stored
6. status surface reports completion or failure

---

## 9. Build Plan

### Phase 4 status board

| Workstream | Goal | Status | Notes |
|---|---|---|---|
| 4.1 | Define shared dispatch and data model | Complete | Generalized `AgentTaskDispatch` → `OperationDispatch`; 4 enums, 5 models, 4 migrations |
| 4.2 | Build outbound messaging foundation | Complete | Email channel real via Laravel Mail; `OutboundMessageService` + `ChannelOutboundMail` |
| 4.3 | Build inbound intake and routing | Complete | Webhook → `InboundSignalService` → `ProcessInboundSignalJob` → `InboundRoutingService`; authenticity verification deferred to real adapters |
| 4.4 | Build schedule registry and due-work planner | Complete | `ScheduleDefinitionService` CRUD + `SchedulePlanner` + `DispatchDueSchedulesJob`; `ScheduleTaskTool` fully rewritten |
| 4.5 | Build background artisan execution | Complete | `BackgroundCommandService` + `RunBackgroundCommandJob` with configurable allowlist policy |
| 4.6 | Add operator visibility and status tooling | Complete | `OperationsDispatchService` + `DelegationStatusTool` get/list + `OperationsSweepCommand` + `OperationsStatusCommand` |

### 9.1 Stage A - Dispatch and persistence foundations

Sub-todos:

1. choose the canonical async lifecycle model
2. define records for schedules, inbound signals, and message/conversation state
3. decide whether `AgentTaskDispatch` is promoted, wrapped, or replaced
4. define result/error metadata contracts before implementing transports

Exit condition:

- Phase 4 has one explicit persistence and status model for async work

### 9.2 Stage B - Outbound messaging MVP

Sub-todos:

1. implement channel account persistence and resolution
2. choose one first-class channel for real delivery
3. route `MessageTool` send/reply through a real service
4. persist outbound conversation/message records
5. keep unsupported actions explicit until the transport truly supports them

Recommended narrowing:

- start with one channel that has the cleanest API and clearest business value

### 9.3 Stage C - Inbound intake and routing

Sub-todos:

1. add webhook entrypoints
2. implement authenticity verification and raw payload capture
3. normalize into canonical inbound signal records
4. route signals to conversation/domain/agent execution
5. attach resulting dispatch IDs so inbound activity is traceable end-to-end

### 9.4 Stage D - Schedule registry and execution

Sub-todos:

1. create durable schedule definition model
2. replace tool stubs with real CRUD/status operations
3. add planner logic for due work and replay-safe triggering
4. dispatch due schedules through the shared async lifecycle
5. record last-fired/next-due metadata

### 9.5 Stage E - Background artisan execution

Sub-todos:

1. define command allowlist/policy
2. create real dispatch-backed background execution
3. capture stdout, stderr, exit code, and summary
4. expose status through the shared operation-status path
5. make `ArtisanTool` stop returning synthetic stub IDs

### 9.6 Stage F - Operator surfaces and recovery

Sub-todos:

1. expose operation and schedule health to UI/commands
2. add sweeper/recovery logic for stale queued or running work
3. show inbound/outbound audit trails
4. provide diagnostics for misconfigured channels and failed schedules

---

## 10. Scope-Sharpening Notes

These are the questions most likely to sharpen the plan during implementation:

### 10.1 Should BLB generalize `AgentTaskDispatch` now?

My bias: yes, if Phase 4 will route schedules, inbound work, and background commands through the same lifecycle.

If the model name and fields are already too agent-specific, Phase 4 is the right time to fix that structurally instead of creating parallel ledgers.

### 10.2 What should the first real messaging channel be?

Do not implement four half-real adapters.

Pick one first:

- the channel with the cleanest delivery/inbound API
- the channel with the most immediate framework value
- the channel whose auth/account story BLB can model cleanly

Then make the other adapters follow the same abstractions.

### 10.3 Should schedules target only agents?

The current tool suggests "target agent plus task text," but Phase 4 may need broader scheduling later.

A good compromise is:

- Phase 4 scheduler stores a neutral execution target model
- first implementation only allows agent task targets

That keeps the model deep without widening the first release too much.

### 10.4 Should background artisan remain agent-facing?

Background artisan can be useful, but it is also high risk.

BLB should decide whether it is:

- an operator-only escape hatch
- an agent capability behind strict policy
- or both with different allowlists

### 10.5 Where should inbound routing terminate?

Not every inbound event should immediately become an agent chat task.

Some inbound signals may eventually route to:

- a conversation thread
- a domain workflow
- a queue of human review
- an agent dispatch

The routing interface should leave room for that without forcing all ingress through one narrow behavior.

---

## 11. Exit Criteria

Phase 4 is complete when:

- at least one channel supports real outbound delivery through durable account configuration
- inbound traffic is accepted, verified, normalized, and traceable through routing
- schedules are durable definitions with real due-work execution
- background artisan execution is real, tracked, and policy-bound
- async work has one coherent status/audit surface
- stub-only Phase 4 behavior is removed from the runtime path

---

## 12. Anti-Patterns to Avoid

- do not let `MessageTool` become a giant switchboard that owns transport, persistence, and routing rules
- do not add webhook controllers that parse channel payloads inline and dispatch directly to agents
- do not create a `scheduled_tasks` table without also designing due-work execution and replay safety
- do not keep synthetic background command IDs that have no durable backing
- do not implement multiple unrelated polling/status systems for similar async work
- do not make all messaging state ephemeral just because the first channel can send successfully

