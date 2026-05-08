# ai-control-plane-unified-timeline

**Status:** Phase 1 Complete — Phase 2 Designing (entity unification; background + chat share one envelope)
**Last Updated:** 2026-05-08
**Sources:** `backup/pre-unified-entity` branch (snapshot before Phase 1); DB backup `01kr2w72bssreaxjxy2gss0gqp`; wire log backup `storage/app/ai/wire-logs-backup-pre-unified/` (26 files, 75 MB — delete after Phase 2 is confirmed stable)
**Agents:** claude/sonnet-4-6, amp/opus-4-7, codex/gpt-5.5-medium

## Problem Essence

The control plane's Run Inspector and Turn Inspector tabs force operators to navigate between two surfaces and build a mental picture manually to answer a single question: what happened with this prompt?

## Desired Outcome

A unified Prompt Timeline view that interleaves run meta-events (DB) and wire log entries (disk) onto one chronological axis, so an operator can diagnose any prompt end-to-end without switching tabs. Every AI execution — interactive chat, background job, orchestration session — flows through a single execution envelope, so the same timeline surface diagnoses chat and non-chat work without per-source plumbing.

## Design Decisions

### Mental model

`AiRun` is the universal execution envelope for any LLM call in the system. It carries the full lifecycle (queue → boot → execute → terminal), all telemetry (tokens, cost, retries, tools, error), and is the FK target for events, wire logs, and per-call usage rows. The source column distinguishes who minted it. `session_id` is optional execution/session correlation: chat commonly sets it, and background work may set it when it participates in a multi-turn or transcript-backed workflow.

```
                       ╭───────────────────────────────╮
                       │  AiRun (universal envelope)   │
                       │  ULID id  •  source: chat /   │
                       │             background /      │
                       │             orchestration     │
                       ╰─┬───────────┬──────────┬──────╯
                         │           │          │
                  AiRunEvent ×N   wire log    AiRunCall ×N
                  (DB, ordered)   (JSONL)     (per LLM call)

  Chat path:        ChatTurnRunner ─────────▶ AiRun (source=chat, session_id set)
  Background path:  RunAgentTaskJob   ─┐
                    RunLaraTaskProf…  ─┤───▶ AiRun (source=background, dispatch_id set)
                    SpawnAgentSession ─┤
                    SimpleTaskExecutor ┘
```

The envelope is minted by the caller before execution begins. The runtime receives the ULID and updates the existing row as execution proceeds. Chat lifecycle fields (`current_phase`, `current_label`, `last_event_seq`) are nullable and populated only when the caller emits lifecycle events. Background paths can leave those fields null and use `dispatch_id` and/or `session_id` for correlation. Lifecycle events are an opt-in concern — only the chat path emits them today.

### Run event taxonomy

After unification the `turn.*` and `run.*` event prefixes collapse: there is one envelope, so events are namespaced under `run.*`. The old `run.started` / `run.failed` markers (which signalled the LLM execution starting *inside* a turn) drop out — the envelope's own status transitions cover them.

**Meta-only** (envelope lifecycle and out-of-band signals, kept in the unified timeline):
- `run.started`, `run.phase_changed`, `run.completed`, `run.failed`, `run.cancelled`, `run.ready_for_input`
- `heartbeat`
- `tool.denied` (policy rejection before tool ran)

**Already captured on the wire** (shown via wire log entries, not repeated as meta markers):
- `assistant.thinking_delta`, `assistant.thinking_started`, `assistant.iteration_completed`
- `assistant.output_delta`, `assistant.output_block_committed`
- `tool.started`, `tool.finished` (wire log `tool_use` / `tool_result` blocks)
- `tool.stdout_delta` (tool result content in transcript)
- `usage.updated` (wire log final chunk)

Usage accounting is DB-first, not event-first. Provider usage and cost are persisted in `ai_run_calls` per LLM call and aggregated onto `ai_runs` for billing, reporting, and session totals. Timeline filtering can hide or omit `usage.updated` markers, but Phase 2 must not drop the DB usage columns, raw provider usage payload, per-call rows, or aggregate refresh behavior.

### Recovery events — removed (YAGNI)

`recovery.attempted`, `recovery.succeeded`, `recovery.failed` were designed for a multi-run retry model (provider fallback, worker crash re-dispatch) that was never implemented. The actual in-run retry (`chatWithRetry`) is silent and produces no turn events. Decision: errors surface directly to the user or calling program; programmatic callers handle retry themselves. The `chatWithRetry` silent retry within a run (transient HTTP errors) is unaffected — it is a provider-level reliability measure with no user-visible events.

### Unified timeline layout

Run meta-events (DB) and wire log entries (disk) are interleaved by timestamp. They do not bracket each other cleanly — meta-events fire during wire activity at arbitrary points. Both sources are normalised to `{timestamp, source, type, payload}` and sorted chronologically.

```
[Prompt — AiRun 01HKQ7…] ─────────────────────────────────────────
  ↓ [META] run.started         queued              +0ms
  ↓ [META] run.phase_changed   booting             +120ms
  ═ [WIRE] → REQUEST           messages[12]        +380ms
  ↓ [META] run.phase_changed   awaiting LLM        +385ms
  ═ [WIRE] ← chunk             thinking delta      +410ms
  ═ [WIRE] ← chunk             thinking delta      +450ms
  ↓ [META] heartbeat           elapsed 500ms       +500ms
  ═ [WIRE] ← chunk             tool_use: bash      +900ms
  ═ [WIRE] → tool_result       stdout preview      +1200ms
  ↓ [META] run.phase_changed   streaming answer    +1210ms
  ═ [WIRE] ← chunk             output delta        +1250ms
  ═ [WIRE] ← RESPONSE complete 512 tok / 88ms      +1300ms
  ↓ [META] run.completed                           +1320ms
  ↓ [META] run.ready_for_input                     +1325ms
───────────────────────────────────────────────────────────────────
```

`[META]` entries render with a distinct visual treatment (left-rail marker, muted background) to remain separable from `[WIRE]` entries without breaking the chronological flow.

### Two-source merge

Wire log entries live on disk (files via `WireLogger`, keyed by run ULID). Run events live in DB (`ai_run_events`). `buildPromptTimelineView(string $runId)` fetches both, normalises to a common shape, and merges by timestamp.

Some meta events reliably fall outside the wire log's time range — `run.started`, `run.phase_changed` (queued/booting) precede the first HTTP request; `run.completed` and `run.ready_for_input` follow the last response — forming a natural prologue and epilogue. All other meta-events interleave within the wire segment. Background runs typically have no meta events at all, so their timeline is just the wire segment.

### Gap diagnostics

`gap_ms` between events and stuck-turn detection are preserved on meta-event markers only (e.g. a long gap between `run.started` and the first wire entry signals a slow worker boot). Not applied to wire log entries.

### Filtering

Delta events (`assistant.thinking_delta`, `assistant.output_delta`, `tool.stdout_delta`) are high-volume. A toggle collapses them to show only structural wire entries (requests, responses, tool blocks) alongside meta markers — the common diagnostic view.

### Tabs after refactor

| Tab | Before | After (Phase 3) | Phase 4 (optional) |
|---|---|---|---|
| Run Inspector | Drill by run ID, wire log + transcript | Retained as deep-dive surface, reached via Prompt Timeline | Unchanged |
| Turn Inspector | Drill by turn ID, full event timeline | Unchanged | Becomes Session Inspector |
| Prompt Timeline | Does not exist | New: unified meta + wire log view (chat runs) | Surfaces background runs too |
| Health & Presence | Unchanged | Unchanged | Unchanged |
| Lifecycle Controls | Unchanged | Unchanged | Unchanged |

## Phases

### Phase 1 — Remove recovery logic (YAGNI)

- [x] Remove `TurnEventType` cases `RecoveryAttempted`, `RecoverySucceeded`, `RecoveryFailed` and their `severity()` / `label()` arms — claude-sonnet-4-6
- [x] Delete `PublishesRecoveryEvents` trait — claude-sonnet-4-6
- [x] Remove `use PublishesRecoveryEvents` from `TurnEventPublisher` — claude-sonnet-4-6
- [x] Remove `onRecoveryAttempted()`, `onRecoverySucceeded()`, and dispatch cases from `TurnStreamBridge` — claude-sonnet-4-6
- [x] Remove recovery tests from `TurnEventPublisherTest`, `TurnStreamBridgeTest`, `TurnContractEnumsTest`; update case count 22 → 19 — claude-sonnet-4-6

### Phase 2 — Unify execution envelope

Promote `ai_runs` to the universal AI execution envelope. Drop `ai_chat_turns` and absorb its lifecycle fields onto `ai_runs`. Every LLM call — interactive chat, background job, orchestration session — flows through one entity with one ID format (ULID), one status enum, and one wire-log naming scheme. This is the storage prerequisite for the Prompt Timeline (Phase 3) treating chat and non-chat work uniformly.

**Target shape:**

`ai_runs` keeps every column it has today (`dispatch_id`, `source`, `execution_mode`, `provider_name`, `model`, all token / cost / pricing columns, `call_count`, `retry_attempts`, `tool_actions`, `error_*`, `meta`, `started_at`, `finished_at`) and gains lifecycle columns lifted from `ChatTurn`:

- `session_id` — already present, stays nullable; populated by chat and by background work that belongs to a session or transcript-backed workflow
- `current_phase`, `current_label` — nullable, populated by lifecycle-event emitters, currently the chat path
- `last_event_seq` — nullable, populated only when events are emitted
- `cancel_requested_at`, `runtime_meta` — nullable, populated only by chat path
- `acting_for_user_id` — already present

`ai_run_calls` remains the accounting ledger for per-call usage: prompt/completion/cached/reasoning/total tokens, raw provider usage payloads, rate-limit metadata, pricing source/version, and cost columns. `ai_runs` keeps the aggregate columns refreshed from those rows. Usage may appear in the wire log and transcript for diagnostics, but DB rows are the billing/reporting source of truth.

`status` becomes a superset enum: `queued → booting → running → succeeded / failed / cancelled / timed_out`. Background paths skip `queued`/`booting` and start at `running` (no fake states until a non-chat surface needs them). `current_run_id` on `ChatTurn` disappears — the run *is* the envelope.

**ID and naming:**

- Primary key is ULID for every row. The `run_<random12>` format is dropped.
- The caller (chat runner / background job / executor) mints the ULID before invoking the runtime.
- `WireLogger.path()` accepts the run ULID directly: `storage/app/ai/wire-logs/{ulid}.jsonl`.
- `AiRunCall.run_id` is a ULID FK to `ai_runs.id`.
- `OperationDispatch.run_id` becomes a ULID FK to `ai_runs.id`.

**Code rename:**

- `ChatTurn` model → drop. References that meant "the chat-side view of a run" become `AiRun` with chat-specific accessors when needed.
- `ChatTurnEvent` model + `ai_chat_turn_events` table → `AiRunEvent` + `ai_run_events`, FK retargets to `ai_runs.id`.
- `ChatTurnRunner` keeps its name (it's still the chat-side runner) but now creates `AiRun` rows directly with `source='chat'` and threads the ULID into the runtime.
- `TurnStatus`, `TurnPhase`, `TurnEventType` → `RunStatus`, `RunPhase`, `RunEventType` (or merged into existing `AiRunStatus` where the enum supersets cleanly).
- `TurnEventPublisher`, `TurnStreamBridge`, `ChatTurnStreamController`, `TurnEventStreamController` rename their *Turn* prefix to *Run* in symbol names; SSE channel names follow.
- `RunRecorder.start()` → `beginExecution(string $ulid, …)` — updates the existing envelope row instead of inserting.

**Background-path changes:**

- `RunAgentTaskJob`, `RunLaraTaskProfileJob`, `SpawnAgentSessionJob`, `SimpleTaskExecutor`, `TaskModelRecommendationService` mint the run ULID at job/dispatch construction (or at the executor entry point) and pass it to `AgenticRuntime::run(..., runId: $ulid)`.
- `AgenticRuntime::run()` and `runStream()` lose their internal `run_<random12>` generation; both take the ULID as a required parameter.
- `OperationDispatch` linkage stays via `dispatch_id` on `ai_runs`, written at envelope creation by the dispatching job (no separate `attachDispatch()` round-trip needed).

**Destructive evolution:**

Per the BLB destructive-evolution principle, no migration path for existing rows. All `ai_runs`, `ai_run_calls`, `ai_chat_turns`, `ai_chat_turn_events`, `operation_dispatches.run_id`, and existing wire-log files (`storage/app/ai/wire-logs/run_*.jsonl`) are dropped/recreated. The pre-Phase-1 backups (DB `01kr2w72bssreaxjxy2gss0gqp`, `wire-logs-backup-pre-unified/`) are no longer useful for restore after this phase — delete them when Phase 2 lands.

**Scope:**

- [ ] Migration: extend `ai_runs` with lifecycle columns (`session_id` already there, add `current_phase`, `current_label`, `last_event_seq`, `cancel_requested_at`, `runtime_meta`); change `id` to ULID; drop `ai_chat_turns` and `ai_chat_turn_events` migrations; create `ai_run_events` migration with FK to `ai_runs.id`; update `ai_run_calls.run_id` to ULID FK while preserving every usage / raw usage / pricing / cost column; update `operation_dispatches.run_id` to ULID FK after `ai_runs` exists
- [ ] Status enum: define unified `AiRunStatus` covering `queued → booting → running → succeeded / failed / cancelled / timed_out` with the same transition rules `TurnStatus` enforces today; drop `TurnStatus`
- [ ] Models: drop `ChatTurn`; rename `ChatTurnEvent` → `AiRunEvent`; expand `AiRun` with chat-side helpers (`nextSeq`, `isCancelRequested`, `requestCancel`, `transitionTo`, `updatePhase`, `finalize`, `eventsAfter`); drop `current_run_id` accessor
- [ ] Enums: rename `TurnPhase` → `RunPhase`, `TurnEventType` → `RunEventType`; collapse `turn.*` event prefixes to `run.*` per *Run event taxonomy*
- [ ] Services: rename `TurnEventPublisher` → `RunEventPublisher`; rename `TurnStreamBridge` → `RunStreamBridge`; update `RunRecorder` to `beginExecution(ulid)` semantics; update `ChatRunPersister` to operate on `AiRun`; update `MessageManager`, `HealthAndPresenceService`, `RunDiagnosticService`, `RunInspectionService`, `LifecycleControlService`, `SweepStaleTurnsCommand`, `ReapOrphanRunsCommand`, `InspectRunCommand` to read the unified envelope
- [ ] Runtime: `AgenticRuntime::run()` and `runStream()` accept `runId: $ulid` as a required parameter; remove internal `Str::random` ID generation; thread ULID through to `WireLogger` and `RunRecorder`
- [ ] Caller mint sites: `ChatTurnRunner` (already creates the envelope), `RunAgentTaskJob`, `RunLaraTaskProfileJob`, `SpawnAgentSessionJob`, `SimpleTaskExecutor`, `TaskModelRecommendationService` — each mints the ULID upfront and inserts the envelope row before invoking the runtime
- [ ] Controllers + Livewire: rename `ChatTurnStreamController` / `TurnEventStreamController` to `RunStreamController` / `RunEventStreamController`; update `Chat`, `ControlPlane`, and any Livewire components/concerns that reference `ChatTurn` or turn-prefixed symbols; SSE channel names follow
- [ ] Wire log: `WireLogger.path()` takes ULID; remove any `run_` prefix logic; readable formatter and entry controller URLs use ULID
- [ ] Tests: update fixtures, factories, and assertions across the AI test suite — `tests/AGENTS.md` quality bar applies; delete tests asserting the two-table split
- [ ] Cleanup: remove `backup/pre-unified-entity` branch, DB backup `01kr2w72bssreaxjxy2gss0gqp`, and `storage/app/ai/wire-logs-backup-pre-unified/` once Phase 2 verifies green

### Phase 3 — Build the Prompt Timeline

With the envelope unified in Phase 2, the timeline view collapses to a straight composition: load events for a run ULID, read its wire log, merge by timestamp.

**Scope:**

- [ ] Build `buildPromptTimelineView(string $runId): array` — loads `AiRun` + ordered `AiRunEvent`s, reads the wire log via `WireLogger`, normalises both sources to `{timestamp, source, type, payload}`, returns the chronologically merged stream. Honour the prologue/epilogue split per *Two-source merge*.
- [ ] Apply `gap_ms` and stuck-run detection to meta-event markers only (per *Gap diagnostics*).
- [ ] Add a delta-collapse toggle hiding `assistant.thinking_delta`, `assistant.output_delta`, `tool.stdout_delta` (per *Filtering*).
- [ ] Land a Prompt Timeline tab in the control plane Livewire surface. Render `[META]` entries with the visual treatment in *Unified timeline layout*. Link the run header to the Run Inspector deep-dive.
- [ ] Restrict the tab's run picker to `source='chat'` for this phase (background runs are visible in Phase 4).
- [ ] Tests: unit coverage for `buildPromptTimelineView` (chronological merge, prologue/epilogue ordering, delta collapse, run with no meta events); a Livewire feature test exercising the tab end-to-end.

### Phase 4 — Operator surface for non-chat runs (optional)

Once Phase 3 ships and the timeline is the primary diagnostic surface, lift the chat-only restriction and rename the legacy tab.

**Scope:**

- [ ] Drop the `source='chat'` filter on the Prompt Timeline run picker; surface background and orchestration runs alongside chat runs
- [ ] Rename Turn Inspector → Session Inspector; rescope it to session-level navigation that lists run envelopes per session and links into the Prompt Timeline
- [ ] Decide whether background paths should emit minimal lifecycle events (`run.started`, `run.completed`, `run.failed`) for symmetric timeline rendering, or whether their wire-log-only timeline is acceptable
- [ ] Update `Tabs after refactor` to reflect the final state
