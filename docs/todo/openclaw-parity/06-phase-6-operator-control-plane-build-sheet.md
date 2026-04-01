# Phase 6 - Operator Control Plane & Policy Depth Build Sheet

**Parent:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`  
**Scope:** Turn BLB's current diagnostics, readiness checks, and scattered safeguards into a unified operator control plane with deeper policy and lifecycle controls  
**Status:** Planned  
**Phase Owner:** Core AI / Base AI  
**Last Updated:** 2026-04-01

---

## 1. Problem Essence

Phase 6 should not be implemented as "add nicer admin pages"; it should be implemented as an **operator control plane** that gives BLB trustworthy context inspection, explicit pruning and compaction controls, richer telemetry and presence signals, and layered policy evaluation beyond coarse capability checks.

---

## 2. Why the Current Phase 6 Description Is Too Thin

The current Phase 6 in `00-capability-gap-audit.md` says:

1. context inspection UI
2. pruning and compaction controls
3. richer audit/presence/telemetry
4. optional layered policy controls beyond capability checks

That is directionally correct, but it still reads like an admin backlog.

If implemented too literally, the likely outcome is:

- a few pages expose raw logs or JSON blobs
- each subsystem invents its own prune button and status language
- presence is inferred from whichever signal is easiest to find
- policy grows as one-off guards embedded inside tools
- operators still cannot answer "what happened, why, and what is safe to do next?" from one place

That would polish surfaces without delivering operational trust.

BLB should instead build a deep control-plane module with clear boundaries:

- runtime inspection
- health and presence
- telemetry and audit
- lifecycle controls
- layered policy evaluation

---

## 3. Current Code Snapshot

BLB already has several strong ingredients for Phase 6, but they are fragmented.

### What exists now

- `AiRuntimeLogger` writes structured, sanitized AI runtime events to a dedicated log channel and already captures failed runs, unhandled exceptions, retries, stream failures, and provider test results: `app/Base/AI/Services/AiRuntimeLogger.php`
- `RuntimeResponseFactory` standardizes success/error metadata with provider, model, latency, token, and fallback details, which gives BLB a real run-metadata foundation: `app/Modules/Core/AI/Services/RuntimeResponseFactory.php`
- `AgenticRuntime` already returns rich metadata such as `tool_actions`, `retry_attempts`, and `fallback_attempts`, and tests explicitly cover those contracts: `app/Modules/Core/AI/Services/AgenticRuntime.php`, `tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php`
- `SessionManager` and `MessageManager` persist run metadata per session, and `Chat` / `Playground` expose `lastRunMeta` for immediate operator visibility after a run: `app/Modules/Core/AI/Services/SessionManager.php`, `app/Modules/Core/AI/Services/MessageManager.php`, `app/Modules/Core/AI/Livewire/Chat.php`, `app/Modules/Core/AI/Livewire/Playground.php`
- `ChatStreamController` persists structured error messages and successful streamed responses, so SSE runs still land in durable session history: `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php`
- `ToolReadinessService`, `ToolMetadataRegistry`, `Catalog`, and per-tool workspace pages already distinguish readiness from verification and provide a real "Try It" operator workflow for tools: `app/Modules/Core/AI/Services/ToolReadinessService.php`, `app/Modules/Core/AI/Livewire/Tools/Catalog.php`, `app/Modules/Core/AI/Livewire/Tools/Workspace.php`
- `ProviderTestService` and `HandlesProviderDiagnostics` provide concrete provider connectivity diagnostics instead of purely static configuration checks: `app/Modules/Core/AI/Services/ProviderTestService.php`, `app/Modules/Core/AI/Livewire/Concerns/HandlesProviderDiagnostics.php`
- `WorkspaceValidator`, `WorkspaceValidationResult`, `LaraPromptFactory`, and `KodiPromptFactory` already enforce workspace prompt policy and fail fast on invalid prompt composition inputs: `app/Modules/Core/AI/Services/Workspace/WorkspaceValidator.php`, `app/Modules/Core/AI/DTO/WorkspaceValidationResult.php`, `app/Modules/Core/AI/Services/LaraPromptFactory.php`, `app/Modules/Core/AI/Services/KodiPromptFactory.php`
- `AgentToolRegistry` already enforces coarse authz gating per tool through required capabilities, and `BrowserSsrfGuard` demonstrates subsystem-specific policy depth beyond simple capability checks: `app/Modules/Core/AI/Services/AgentToolRegistry.php`, `app/Modules/Core/AI/Services/Browser/BrowserSsrfGuard.php`
- `ToolHealthState` exists as a type, which hints at the intended distinction between readiness and runtime health, but it is not yet wired into a real health pipeline: `app/Modules/Core/AI/Enums/ToolHealthState.php`

### Structural problems in the current shape

1. **Operational signals are scattered across logs, session metadata, UI fragments, and enums rather than surfaced through one control plane.**
2. **Context inspection is shallow — operators can see recent run metadata, but not the assembled prompt/context/memory/tool picture for a run.**
3. **Readiness, verification, health, and presence are not yet modeled as distinct but related concepts.**
4. **Layered policy exists only in pockets; most enforcement is still "can this user call this tool?"**
5. **Pruning and compaction are phase goals for memory/browser/runtime state, but there is no shared lifecycle-control substrate yet.**

---

## 4. From-Scratch Design: What BLB Should Build Instead

### 4.1 Public interface first

Phase 6 should expose these stable operations:

1. `inspectRun(runId)` — show the normalized context, model path, tool actions, and outcome for a run
2. `inspectSession(sessionId)` — show durable session state, recent runs, and linked subsystem state
3. `inspectAgentState(agentId)` — show readiness, health, presence, and active work for an agent
4. `evaluatePolicy(subject, action, context)` — explain which policy layers allow or deny an action
5. `requestCompaction(scope, policy)` — compact memory or other derived stores under explicit rules
6. `requestPrune(scope, policy)` — prune stale sessions, artifacts, or derived state with previewability
7. `recordTelemetry(event)` — append normalized operational telemetry keyed by durable identifiers
8. `getHealthSnapshot(target)` — return readiness, health, and presence in one structured view

The UI should be a client of these operations. It should not be where core operational semantics live.

### 4.2 Architectural decomposition

#### A. Run Inspection Service

Responsibility:

- assembles one coherent view of a run from runtime metadata, session state, dispatch state, and subsystem context
- explains provider fallback, retries, tool calls, and final outcome
- exposes safe operator diagnostics without leaking secrets or raw user content unnecessarily

Key invariant:

- operators inspect normalized run facts, not a pile of unrelated logs

#### B. Health and Presence Service

Responsibility:

- computes readiness, health, and presence distinctly
- reports whether an agent/tool/subsystem is configured, functioning, and actively available
- powers dashboards and operational decisions

Key invariant:

- readiness, health, and presence are separate dimensions

#### C. Telemetry and Audit Pipeline

Responsibility:

- records normalized events for runs, streams, tool calls, provider tests, dispatches, and subsystem actions
- ties events together through durable IDs
- supports later dashboards and incident review

Key invariant:

- telemetry should be correlateable across runs, sessions, and dispatches

#### D. Lifecycle Control Service

Responsibility:

- owns pruning and compaction requests
- previews what will be changed or deleted
- delegates work to subsystem-specific engines with consistent policy and audit

Key invariant:

- destructive lifecycle actions are explicit, previewable, and logged

#### E. Policy Evaluation Service

Responsibility:

- evaluates layered policy beyond authz capability checks
- explains why a call is allowed, denied, or degraded
- centralizes subsystem policies such as network, workspace, data, and operator constraints

Key invariant:

- policy should be inspectable, not hidden inside many tools

#### F. Operator Surface

Responsibility:

- presents run inspection, health, telemetry, and lifecycle controls coherently
- lets operators move from a symptom to the relevant context quickly
- remains a veneer over services, not the source of truth

Key invariant:

- the UI should reveal operational truth, not invent it

---

## 5. Core Design Decisions

### 5.1 Make run inspection a first-class object

BLB already stores runtime metadata, but it is mostly consumed as "last run info" in the UI.

From scratch, Phase 6 should treat a run as an inspectable operational object with:

- IDs
- timing
- provider/model path
- tool activity
- fallback and retry history
- linked session/dispatch/agent context

### 5.2 Distinguish readiness, health, and presence explicitly

BLB already distinguishes readiness in `ToolReadinessService`, and `ToolHealthState` suggests a health dimension, but the concepts are not yet unified.

Use:

- **readiness** = can this thing be used in principle?
- **health** = is it behaving correctly right now?
- **presence** = is it live/active/reachable at the moment?

Those should not collapse into one badge.

### 5.3 Prefer policy explanations over silent denials

Capability checks are necessary, but Phase 6 should go further by surfacing which policy layer blocked or constrained an action.

Examples:

- authz capability denied
- workspace invalid
- network target blocked
- tool unconfigured
- operator policy requires confirmation

That makes the system easier to debug and safer to operate.

### 5.4 Treat compaction and pruning as operations, not utility methods

Memory compaction, artifact pruning, session cleanup, and similar actions should not be hidden inside cron jobs or arbitrary commands.

They should become explicit operator-visible operations with:

- scope
- preview
- policy
- execution record
- outcome summary

### 5.5 Never let diagnostics leak secrets

Phase 6 increases visibility, so it also increases risk.

Any control-plane design must preserve BLB's current good habit:

- do not log API keys
- do not dump full prompts by default
- do not surface raw sensitive payloads unless a trust boundary explicitly permits it

### 5.6 Reuse subsystem-native facts rather than re-deriving everything from logs

The control plane should read from:

- session/run metadata
- dispatch records
- workspace validation results
- provider test results
- tool readiness snapshots

Logs are useful, but they should not be the only source of operational truth.

### 5.7 Keep policy layering explicit

Phase 6 should not pile more ad hoc checks into tools.

A useful layered model is:

1. actor capability
2. tool or operation readiness
3. subsystem policy
4. data/network/workspace policy
5. operator confirmation or escalation policy

That structure is easier to explain, test, and evolve.

---

## 6. Proposed Module Shape

Recommended service set:

- `RunInspectionService`
- `AgentPresenceService`
- `ToolHealthService`
- `OperationalTelemetryService`
- `LifecycleControlService`
- `PolicyEvaluationService`
- `OperatorDashboardService`

Recommended job/command set:

- `blb:ai:inspect:run {run}`
- `blb:ai:health:snapshot`
- `blb:ai:lifecycle:preview`
- `blb:ai:lifecycle:execute`
- `CompactAgentMemoryJob`
- `PruneAiArtifactsJob`

Recommended UI evolution:

- add a run inspector that can pivot from session to run to dispatch
- add an agent state view that separates readiness, health, and presence
- add preview-first pruning and compaction panels
- keep Tool Catalog and per-tool workspace pages as focused subviews inside the broader control plane

---

## 7. Storage and Persistence Model

### 7.1 Run inspection records

BLB already has session run metadata, but Phase 6 likely needs a richer normalized run view.

Suggested record shape:

- run ID
- agent ID
- session ID
- dispatch ID if any
- provider/model
- timing
- retry/fallback summary
- tool action summary
- outcome status
- links to relevant subsystem artifacts

### 7.2 Telemetry events

Telemetry should be normalized, not just log-line text.

Suggested fields:

- event ID
- event type
- run/session/dispatch/agent IDs
- target subsystem
- severity
- structured payload
- occurred at

### 7.3 Health and presence snapshots

Health snapshots should support trend and current-state views.

Suggested fields:

- target type (`agent`, `tool`, `provider`, `browser_session`, etc.)
- target ID/name
- readiness state
- health state
- presence state
- explanation
- measured at

### 7.4 Lifecycle control requests

Pruning and compaction need explicit request records.

Suggested fields:

- request ID
- operation type (`compact_memory`, `prune_sessions`, `prune_artifacts`, etc.)
- scope
- preview summary
- policy used
- status
- result summary
- requested by
- executed at

---

## 8. Main Execution Flows

### 8.1 Run inspection flow

1. operator selects a run or session
2. `RunInspectionService` assembles normalized run facts
3. linked fallback, retry, tool, dispatch, and session context are attached
4. operator sees one coherent explanation instead of many fragments

### 8.2 Health snapshot flow

1. control plane asks for agent/tool/provider state
2. readiness is computed from configuration/authz
3. health is computed from recent verification and subsystem outcomes
4. presence is computed from live activity or heartbeat-style evidence
5. snapshot is returned with explanation

### 8.3 Policy evaluation flow

1. operator or runtime requests a policy decision
2. `PolicyEvaluationService` evaluates layered checks in order
3. resulting allow/deny/degraded decision is returned with reasons
4. decision is attached to run or lifecycle metadata when relevant

### 8.4 Pruning or compaction flow

1. operator requests a lifecycle action
2. preview is generated first
3. policy confirms that the action is allowed
4. subsystem-specific job performs the work
5. result is recorded in the lifecycle request ledger

---

## 9. Build Plan

### Phase 6 status board

| Workstream | Goal | Status | Notes |
|---|---|---|---|
| 6.1 | Define control-plane contracts | Not started | Stabilize inspection, lifecycle, and policy interfaces before building screens |
| 6.2 | Build unified run inspection | Not started | Promote `lastRunMeta` into a real inspection model |
| 6.3 | Build health and presence model | Not started | Wire `ToolHealthState` into a live service instead of leaving it unused |
| 6.4 | Build lifecycle controls | Not started | Compaction and prune flows need previewability and audit |
| 6.5 | Add layered policy evaluation | Not started | Move beyond coarse capability checks and isolated guards |
| 6.6 | Compose the operator UI | Not started | UI should sit on top of services, not become the implementation |

### 9.1 Stage A - Core control-plane contracts

Sub-todos:

1. define run inspection DTOs and service contract
2. define readiness/health/presence state model
3. define lifecycle control request model
4. define policy evaluation response shape
5. decide which identifiers are mandatory across telemetry and inspection

Exit condition:

- Phase 6 has a stable operational vocabulary shared by UI, jobs, and services

### 9.2 Stage B - Run inspection and telemetry

Sub-todos:

1. promote runtime/session metadata into a coherent run-inspection view
2. normalize telemetry event capture
3. connect stream failures, provider tests, dispatches, and tool actions under shared IDs
4. prove the model on both normal and error runs

### 9.3 Stage C - Health and presence

Sub-todos:

1. wire `ToolHealthState` into a real health computation path
2. define agent/tool/provider/browser presence signals
3. combine readiness, health, and presence into explainable snapshots
4. expose snapshots to the UI and CLI/admin surfaces

### 9.4 Stage D - Lifecycle controls

Sub-todos:

1. define compaction/prune request workflows
2. add preview support before destructive execution
3. connect memory/artifact/session lifecycle jobs to one control service
4. record outcomes for later inspection

### 9.5 Stage E - Policy depth

Sub-todos:

1. inventory current policy checks already embedded in tools/services
2. extract shared policy evaluation where repetition is growing
3. make denials and degradations explainable to operators
4. keep trust-boundary enforcement explicit and testable

### 9.6 Stage F - Operator UI composition

Sub-todos:

1. add a run inspector view
2. add agent/tool state dashboards
3. add lifecycle-control panels
4. link Tool Catalog, provider diagnostics, and workspace validation into the same operational narrative

---

## 10. Scope-Sharpening Notes

These are the decisions most likely to sharpen during implementation:

### 10.1 Should Phase 6 add a dedicated run ledger?

My bias: yes, if session metadata alone becomes too awkward for cross-run inspection.

`SessionManager` metadata is a strong starting point, but a broader run ledger may be the cleaner substrate once dispatches, streamed errors, and cross-subsystem artifacts need to correlate cleanly.

### 10.2 What counts as "presence" in BLB?

Presence should not be guessed from readiness.

Possible signals include:

- active session or recent heartbeat
- queue worker or browser session activity
- recent successful provider or subsystem checks

Pick explicit signals and keep them explainable.

### 10.3 Which lifecycle actions belong in Phase 6 first?

My recommendation:

- memory compaction
- stale session pruning
- artifact pruning

That is enough to prove the control-plane shape before widening to every possible cleanup action.

### 10.4 How much raw context should operators be allowed to inspect?

This is a trust-boundary decision, not just a UI question.

Default stance should be:

- summaries and normalized facts by default
- raw content only when explicitly justified and policy-allowed

### 10.5 Should policy evaluation surface to end users or operators only?

The answer may differ by policy layer.

Good default:

- operator-facing explanations are detailed
- end-user-facing explanations are safe and simpler

---

## 11. Exit Criteria

Phase 6 is complete when:

- operators can inspect a run/session/agent through one coherent control-plane model
- readiness, health, and presence are distinct and visible
- pruning and compaction are explicit, previewable, and audited
- policy decisions beyond capability checks are explainable and testable
- telemetry connects the major AI subsystems through shared identifiers
- Phase 6 improves trustworthiness without leaking secrets or making the runtime opaque

---

## 12. Anti-Patterns to Avoid

- do not build "inspection" as a page that mostly dumps logs
- do not equate readiness with health or presence
- do not add prune or compaction buttons that execute opaque destructive work with no preview or audit
- do not bury more policy rules directly inside unrelated tool classes
- do not expose raw prompts, secrets, or sensitive payloads by default in the name of observability
- do not make the operator UI the only place where operational truth exists

