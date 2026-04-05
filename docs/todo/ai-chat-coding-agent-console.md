# AI Chat as a Live Coding-Agent Console

## Problem Essence

The current AI chat is still built like a request/response messenger with a fallback background job. That is the wrong mental model. A coding agent UI should feel like a live console session: once a turn starts, the user should see the agent remain busy, emit activity in real time, stream partial output, and stay observable until the turn reaches a terminal state.

## Status

In Progress — Phases 1–3 complete (contract enums, schema, runtime bridge, materializer). Phase 4 UI rewrite next.

## Desired Outcome

BLB chat should behave like Copilot CLI, Claude Code, Amp, OpenCode, and similar coding-agent tools:

1. Sending a prompt immediately creates a visible live turn, not a hidden job.
2. The UI shows agent state continuously: waiting, thinking, invoking tools, reading results, drafting, retrying, finishing, failing, or cancelled.
3. The assistant response appears progressively during the run, not only after completion.
4. If the browser disconnects or the user reloads, the same live turn can be resumed from durable server-side events.
5. Queueing remains an internal execution mechanism, not a UX mode exposed as “you’ll see it later.”

## Public Contract

Every agent turn must satisfy this contract.

1. **Turn creation:** submitting a message creates a durable `turn_id` immediately.
2. **Live attachment:** the client can subscribe to a single ordered stream of turn events from event `0` onward, and can resume from the last seen event.
3. **Busy signal:** while a turn is non-terminal, the UI always has an explicit busy state with a current phase label.
4. **Progressive visibility:** tool actions, policy denials, retries, run starts, run failures, and assistant output deltas are surfaced as first-class events.
5. **Input gating:** the UI only returns to a ready state when the turn emits an explicit ready/terminal transition, not merely when tokens stop arriving.
6. **Durable replay:** refreshing the page rehydrates the exact activity timeline and partial output from persisted events.
7. **Clear termination:** every turn ends as `completed`, `failed`, or `cancelled`; stalled queueing is surfaced as a failure mode, not silent waiting.

## Top-Level Components

### 1. Turn Ledger

Owns the lifecycle of a single user prompt and its visible agent response.

Recommended shape:

- `ai_chat_turns`
  - `id`
  - `employee_id`
  - `session_id`
  - `acting_for_user_id`
  - `status` (`queued`, `booting`, `running`, `completed`, `failed`, `cancelled`)
  - `current_phase` (`waiting_for_worker`, `thinking`, `running_tool`, `streaming_answer`, `finalizing`, `failed`, `cancelled`)
  - `current_label`
  - `started_at`
  - `finished_at`
  - `last_event_seq`
  - `current_run_id` nullable
  - `meta`

- `ai_chat_turn_events`
  - `id`
  - `turn_id`
  - `seq`
  - `event_type`
  - `payload` JSON
  - `created_at`

This becomes the UX source of truth. The timeline is not reconstructed from side effects later; it is written intentionally as the run progresses.

### 2. Run Ledger

`ai_runs` remains the canonical record of individual LLM executions, retries, provider/model usage, and error details.

Recommended changes:

- keep `ai_runs`
- add `turn_id` nullable FK
- keep `dispatch_id` only for non-chat async orchestration or as a lower-level execution detail
- stop treating `ai_runs` as the primary UI event source

The user watches a **turn**. Operators inspect **runs**.

### 3. Runtime Event Publisher

The runtime should emit structured events while work is happening, not only persist a final assistant message.

Recommended representation:

- define a PHP `BackedEnum` for turn event types (string-backed)
- store the enum backing value directly in `ai_chat_turn_events.event_type`
- treat `event_type` as the durable contract key used by SSE replay, DB persistence, and UI discriminated unions

Recommended event families:

- `turn.started`
- `turn.phase_changed`
- `turn.ready_for_input`
- `run.started`
- `run.failed`
- `assistant.thinking_started`
- `assistant.iteration_completed`
- `tool.started`
- `tool.stdout_delta` optional
- `tool.finished`
- `tool.denied`
- `assistant.output_delta`
- `assistant.output_block_committed`
- `usage.updated`
- `heartbeat`
- `recovery.attempted`
- `recovery.succeeded`
- `recovery.failed`
- `turn.completed`
- `turn.failed`
- `turn.cancelled`

This follows the same core pattern seen in terminal agents: the runtime loop emits semantically meaningful state transitions, not just a final message blob.

Recommended payload rules:

- use enums/discriminated unions for status and event type, not freeform strings
- keep events append-only and immutable
- include `elapsed_ms` on heartbeat and progress events
- prefer discrete steps (`step`, `total_steps` nullable) over percentages
- keep telemetry such as tokens/cost separate from content deltas
- define a single canonical event envelope, e.g. `{turn_id, seq, event_type, payload, occurred_at}`

### 4. Live Transport

The browser needs a durable, resumable event stream.

Recommended transport order:

1. **SSE for turn events** as the default
2. **Broadcast/WebSocket optional later** for same-user multi-tab or multi-device continuity
3. **Polling only as degraded fallback**, never as the primary experience

The client should subscribe to `/ai/chat/turns/{turn}/events?after_seq=N` and receive ordered events until terminal state. Turn streams are private to the authenticated user and their own tabs/devices; they are not a shared conversation channel across users.

Heartbeat should be emitted on a steady cadence during quiet long-running phases so the UI can distinguish “alive but thinking” from “stalled.”

Resume semantics must be decided up front:

- `seq` is application-assigned, strictly increasing per `turn_id`, and written transactionally with each event
- on connect/reconnect, the server first replays persisted events with `seq > after_seq`, then switches the same SSE response into live tail mode
- if the client asks for an `after_seq` older than the retained floor or beyond the current max in a way that indicates a gap, the server returns a resync-required response and the client reloads the full persisted turn timeline before reattaching
- the client must treat the event stream as authoritative and idempotent: replayed events are normal, not an error condition

### 5. Web Chat Surface

The chat UI should stop being a message thread with a small “background run” banner. It should become a console-like activity timeline with a composer attached.

Recommended layout:

1. **Transcript rail** — previous user/assistant turns
2. **Active turn rail** — live activity timeline for the currently running turn
3. **Sticky status bar** — model, phase label, elapsed time, cancel state, reconnect state
4. **Composer** — disabled only when the current policy forbids parallel turns

The active turn rail should feel like a terminal session:

- spinner / busy state at top
- visible tool cards appearing in order
- policy events shown inline
- assistant output streaming in place
- terminal completion marker at the end

Worker-heavy sections must be **collapsible by default when they become noisy**. The user should see a compact summary first, then expand for detail on demand.

Recommended collapse behavior:

- active worker groups render as expandable cards with a one-line summary
- summary line shows worker name, current phase, elapsed time, and latest status
- verbose inner events stay collapsed unless the user expands that worker
- currently active worker can auto-expand while other completed/noisy workers auto-collapse
- collapse state should persist across rerenders and reconnects for the current user

## Design Decisions

### Remove implicit “background later” chat UX

The user-facing phrase “you’ll see the response when it’s ready” should be removed from normal chat. Long turns are still allowed to run on workers, but the UI stays attached and visibly busy the whole time. The worker must publish live turn events while it runs so the user can watch tool activity, phase changes, retries, and assistant output inside the conversation timeline itself.

### Make turn events append-only and durable

The current transcript is too coarse for a coding-agent UX. We need an append-only event store for live replay and reconnect safety.

### Make Phase 1 produce reviewable artifacts

Phase 1 should not stop at document hygiene. It should produce the actual turn contract artifacts that later phases implement: the turn lifecycle state machine, the event type enum list, and the SSE replay/resume contract.

### Separate user-facing turns from operator-facing runs

`ai_runs` answers “what LLM execution happened?”
`ai_chat_turns` answers “what is the user currently watching?”

Merging them makes the UI awkward. Splitting them makes the contract clean.

### Queueing is an implementation detail, not a UX state

Workers and queues are still fine, but the user should not have to understand them. If the queue is stalled, the turn should surface `waiting_for_worker` and then fail explicitly after a threshold.

### Visible does not mean fully expanded

Worker activity should remain available in-thread, but the default presentation must protect the user from cognitive overload. Summaries stay visible; verbose worker internals expand only when useful.

### Stream assistant output progressively

The final assistant text should be visible as it is generated. The current behavior where background runs append a complete answer only at the end is not acceptable for the target UX.

### Buffer streamed output at safe boundaries

The UI should not render broken markdown/code structures on every byte. The renderer should accumulate deltas and flush only at safe boundaries, then flush any remaining content on terminal events.

### Show retries and recovery as visible progress

Silent retry loops are hostile to this UX. Retry and recovery should be first-class events with attempt counts, short labels, and backoff visibility.

### Use terminal-agent patterns explicitly

Patterns worth copying from coding-agent CLIs and Claw Code:

- a dedicated busy renderer separate from final output rendering
- an event-first runtime model (`TextDelta`, tool uses, usage, stop events)
- structured session traces for tool start/finish and turn start/finish
- streaming text flush only at safe boundaries so markdown/code blocks do not visibly break mid-stream
- explicit ready/busy/trust-required state transitions
- heartbeat and recovery events during long or unstable operations

### Keep `ChatRunPersister`, but demote it beneath the turn event layer

`ChatRunPersister` already knows how to materialize thinking, tool status, errors, and final assistant content into the transcript. The new design should not delete that knowledge. Instead:

- the new **turn-event publisher** becomes the primary live-write path
- `ChatRunPersister` becomes a lower-level transcript materializer fed from terminal or projection-worthy turn events
- `ChatRunPersister` must no longer define the live UX contract directly

## Current-State Verdict

The current architecture is close enough to salvage parts of the backend, but not the UX contract.

Keep:

- `AgenticRuntime`
- `RunRecorder` / `ai_runs`
- transcript storage for finalized conversational history
- tool-status semantics already emitted in streaming mode

Replace or demote:

- `HandlesBackgroundChat` as the core long-run UX
- the “background progress banner”
- transcript-refresh polling as the primary live-update mechanism
- the assumption that final assistant content is the main thing worth persisting during the run

## AI Schema Relevance Review

Claude Code parity does **not** mean every existing `ai_*` table is part of the critical path. For the live coding-agent console, the tables split into four groups.

| Group | Tables | Relevance to Claude Code parity |
|---|---|---|
| **Core** | `ai_providers`, `ai_provider_models`, `ai_runs` | Needed for provider/model resolution and run-level execution telemetry |
| **New core to add** | `ai_chat_turns`, `ai_chat_turn_events` | Needed for the user-visible live turn model this plan introduces |
| **Supporting but not primary** | `ai_operation_dispatches`, `ai_browser_sessions`, `ai_browser_artifacts`, `ai_orchestration_sessions` | May support worker execution, browser tooling, or multi-agent orchestration, but should not define the primary chat UX model |
| **Not relevant to parity-critical chat UX** | `ai_channel_accounts`, `ai_conversations`, `ai_conversation_messages`, `ai_inbound_signals`, `ai_schedule_definitions`, `ai_lifecycle_requests` | External messaging, schedules, and lifecycle/control-plane workflows are not part of Claude Code-style chat parity |
| **Needs consolidation review** | `ai_telemetry_events` | May overlap with the proposed turn event log and risk duplicating truth |

### Table-by-table notes

- **`ai_conversations` / `ai_conversation_messages`** — external channel conversation storage keyed by `channel_account_id` and platform `external_id`; relevant to omnichannel AI, not to Claude Code-style in-product agent chat. The current names are too generic and invite confusion with the primary chat UX model.
- **`ai_channel_accounts` / `ai_inbound_signals`** — inbound/outbound channel plumbing; useful for messaging integrations, irrelevant to the parity-critical live coding-agent console.
- **`ai_schedule_definitions`** — scheduled agent execution; useful control-plane feature, not part of interactive coding-agent parity.
- **`ai_lifecycle_requests`** — admin preview/execute lifecycle actions; unrelated to the user-facing chat/thread model.
- **`ai_telemetry_events`** — may remain valuable for operator telemetry, but cannot compete with `ai_chat_turn_events` for ownership of live turn history.
- **`ai_operation_dispatches`** — acceptable as an internal worker envelope if retained, but it should not remain the user-facing unit of progress.
- **`ai_browser_sessions` / `ai_browser_artifacts`** — parity-adjacent only if browser tooling remains part of the agent toolset; not a blocker for the console UX itself.
- **`ai_orchestration_sessions`** — useful only if BLB keeps explicit multi-agent delegation; not required for first-pass parity with the core Claude Code chat loop.

## Reference Patterns from Claw Code

Claw Code is the practical Rust reference for Claude Code-style behavior, and BLB should treat it as a parity benchmark rather than a loose inspiration.

The Claw Code Rust workspace reinforces the direction above:

- `ConversationRuntime` is event-first: streamed assistant text, tool-use blocks, usage, and terminal message-stop are separate runtime events.
- `SessionTracer` records turn start, assistant iteration completion, tool start, tool finish, turn complete, and turn failure as distinct structured trace records.
- the CLI renderer has a dedicated spinner lifecycle (`tick`, `finish`, `fail`) instead of treating “busy” as implied by missing output.
- streaming text is rendered progressively with safe-boundary flushing so markdown stays readable during generation.
- progress is expressed as phases and steps, not fake percentages.
- recovery/retry state is treated as user-visible workflow, not hidden control flow.

Those patterns map cleanly to a web chat:

- spinner/status bar instead of terminal spinner
- append-only turn-event timeline instead of session trace log lines
- streaming markdown pane instead of terminal streaming renderer
- explicit ready-for-input state instead of guessing from stream silence

### File references for cross-checking implementation details

Future implementation work should cross-reference these files directly:

- `rust/crates/runtime/src/conversation.rs`
  - `AssistantEvent` event model (`TextDelta`, `ToolUse`, `Usage`, `MessageStop`)
  - `ConversationRuntime` loop
  - turn/tool tracing hooks such as `record_turn_started()`, `record_tool_started()`, `record_tool_finished()`, `record_turn_completed()`
- `rust/crates/telemetry/src/lib.rs`
  - `SessionTracer`
  - `SessionTraceRecord`
  - append-only structured trace emission
- `rust/crates/rusty-claude-cli/src/render.rs`
  - `Spinner` busy renderer (`tick`, `finish`, `fail`)
  - markdown streaming and safe-boundary flushing
- `rust/crates/rusty-claude-cli/src/main.rs`
  - CLI wiring for live rendering, progress reporting, and how runtime events are surfaced in the interactive experience

These are not BLB implementation constraints, but they are the clearest concrete references for the behavior parity we want.

## Phase 1 — Establish the New Chat Contract

### Goal

Make “live turn with durable event stream” the official product contract.

### Scope

- define the turn lifecycle
- define the event taxonomy
- define the event enum/storage representation
- define replay/resume semantics
- decide what belongs in turn ledger vs run ledger vs transcript

### Deliverables

- a reviewable turn lifecycle state machine (`queued` → `waiting_for_worker` → `thinking` → `running_tool` → `streaming_answer` → terminal states)
- a reviewable turn event type enum list with durable storage representation
- a reviewable SSE replay/resume contract for `after_seq`
- a reviewable write-path decision for `TurnEventPublisher` vs `ChatRunPersister` vs transcript projection

### Progress

- [x] Reject the current “background later” UX as the target model.
- [x] Define the replacement model as a live coding-agent console.
- [x] Mark `docs/todo/ai-run-ledger.md` as deprecated and superseded by `docs/todo/ai-chat-coding-agent-console.md`.
- [x] Freeze the turn lifecycle state machine as `TurnStatus` enum — `queued → booting → running → completed|failed|cancelled`.
- [x] Freeze the turn event enum/storage contract as `TurnEventType` BackedEnum (21 events) stored in `ai_chat_turn_events.event_type`.
- [x] Freeze the SSE replay/resume contract — `ChatTurn::eventsAfter($seq)`, unique `(turn_id, seq)`, application-assigned seq.
- [x] Decide and document `ChatRunPersister` demotion — `TurnEventPublisher` is the primary write path; `ChatRunPersister` retained as transcript materializer (dual-write during transition).
- [x] **Phase 1 complete.**

## Phase 2 — Introduce a Durable Turn Event Model

### Goal

Make live agent activity replayable, resumable, and queryable.

### Scope

- add `ai_chat_turns`
- add `ai_chat_turn_events`
- link `ai_runs.turn_id`
- define event ordering and resume semantics

### Progress

- [x] Create `ai_chat_turns` as the visible unit of chat execution — `0200_02_01_000012`.
- [x] Create `ai_chat_turn_events` as an append-only ordered event log — `0200_02_01_000014`.
- [x] Store `ai_chat_turn_events.event_type` as the string backing value of a PHP `BackedEnum`.
- [x] Store `seq` as an application-assigned per-turn sequence with unique (`turn_id`, `seq`) constraint.
- [x] Add `turn_id` directly to `ai_runs` create migration — `0200_02_01_000013` (destructive evolution, no alter migration).
- [x] Create `ChatTurn` and `ChatTurnEvent` Eloquent models with full relationship wiring.
- [x] Update `AiRun` model with `turn_id` field and `turn()` relationship.
- [x] 41 tests passing (22 unit enum tests + 19 feature model/publisher tests).
- [x] `OperationDispatch` kept as internal worker envelope for `RunAgentChatJob`. Not used in interactive chat path (`ChatStreamController`). Not user-facing.
- [x] `ai_telemetry_events` kept separate — operator-grade telemetry with different audience and lifecycle from user-facing `ai_chat_turn_events`.
- [x] `ai_channel_accounts`, `ai_conversations`, `ai_conversation_messages`, `ai_inbound_signals` fenced — external messaging plumbing, not imported or referenced in chat flow.
- [x] `ai_schedule_definitions`, `ai_lifecycle_requests` fenced — control-plane features, not part of interactive chat.
- [x] `ai_operation_dispatches`, `ai_orchestration_sessions` kept as internal support tables.
- [x] Rename `ai_conversations` / `ai_conversation_messages` to `ai_channel_conversations` / `ai_channel_conversation_messages` to eliminate naming confusion with the primary chat UX.
- [x] Clean up background-offload user-facing language in `HandlesBackgroundChat`, `Chat.php`, `OperationType`, `RunAgentChatJob`.
- [ ] Stale queued-turn handling — moved to Phase 5 (operational guardrail).
- [x] **Phase 2 complete.**

## Phase 3 — Rewrite Runtime Emission Around Turn Events

### Goal

Publish all meaningful runtime state as events while the turn is active.

### Scope

- runtime hooks
- tool execution lifecycle
- assistant delta streaming
- heartbeat and reconnect safety

### Recommended execution path

1. User submits prompt.
2. Server creates `ai_chat_turns` row and emits `turn.started`.
3. Runtime begins on worker or inline executor, but always writes turn events as work happens.
4. UI subscribes immediately and stays attached until terminal state.
5. Final assistant message is materialized into transcript from the same event stream after completion.

### Tasks

- [x] Add a turn-event publisher abstraction beside `RunRecorder` — `TurnEventPublisher` created.
- [x] Create `TurnStreamBridge` to map runtime events → durable turn events for both interactive and background flows.
- [x] Integrate bridge into `ChatStreamController` — turn created before SSE stream, wrapped with bridge, turnId linked to AiRun.
- [x] Integrate bridge into `RunAgentChatJob` — turn created when job starts, wrapped with bridge, cancellation emits `turn.cancelled`.
- [x] Add `turnId` parameter to `RunRecorder::start()` and `AgenticRuntime::runStream()` for run↔turn linking.
- [x] Emit assistant delta events during `runStream()` — bridge maps every `delta` to `AssistantOutputDelta` turn event.
- [x] Emit explicit phase-change events — thinking, running_tool, streaming_answer, finalizing mapped from runtime status events.
- [x] Emit heartbeats after tool completion — liveness signal before quiet LLM calls.
- [x] Emit `output_block_committed` and `usage_updated` on turn completion.
- [x] Safety net: bridge fails the turn if stream ends without terminal event or throws an exception.
- [x] 56 tests passing — 22 unit enum + 19 publisher + 15 bridge (4031 assertions total).
- [x] Rework `ChatRunPersister` to consume turn events as a transcript projection helper — rewritten as `materializeFromTurn()` that reads turn events post-stream. 6 materializer tests passing.
- [x] Emit recovery/retry events — `AgenticRuntime::runStream()` yields recovery_attempted on provider fallback; `runStreamingToolLoop()` yields recovery_attempted/succeeded on retries. Bridge maps to `RecoveryAttempted`/`RecoverySucceeded` turn events.
- [x] Materialize the final transcript from the completed turn stream — `ChatStreamController` and `RunAgentChatJob` both call `materializeFromTurn()` after stream completes (with best-effort fallback in catch blocks).
- [x] Enriched `toolFinished` with `resultLength` and `errorPayload` for richer tool observability.
- [x] **Phase 3 complete** — 62 tests across enums/publisher/bridge/materializer; 1365 total tests passing.

## Phase 4 — Replace the Web UI with a Console-Like Active Turn Surface

### Goal

Give the user the same observability they expect from coding-agent CLIs.

### Scope

- active turn timeline
- sticky status and busy signal
- reconnect behavior
- cancellation and failure display

### Recommended UI behavior

- [x] Show a sticky live status row with phase label, elapsed time, model, reconnect state, and cancel action.
- [x] Render tool cards inline as soon as `tool.started` arrives.
- [x] Render assistant output progressively from `assistant.output_delta`.
- [ ] Buffer assistant deltas at safe markdown boundaries before rendering.
- [x] Group verbose worker activity into collapsible cards with a concise summary row.
- [x] Auto-collapse inactive/noisy worker groups while keeping the currently active group easy to inspect.
- [x] Preserve completed tool cards and streamed text in the same ordered timeline after the turn finishes.
- [x] Keep the timeline visible on refresh by replaying persisted events before reattaching to the live stream.
- [x] Replace the current background progress banner entirely.

### Implementation progress

- [x] SSE resume endpoint (`TurnEventStreamController`) — replays + follows turn events.
- [x] TurnStreamBridge refactored to yield unified turn event SSE payloads — both ChatStreamController and TurnEventStreamController emit identical format.
- [x] ChatStreamController updated to emit turn events via `emitTurnEvent()`.
- [x] Alpine.js chat state machine rewritten — turn lifecycle state, `connectToTurnStream()` handles all 20 event types.
- [x] Sticky status bar — shows phase label, elapsed time, cancel button; visible when turn is active.
- [x] Collapsible tool groups — auto-collapse completed tools when new tool starts; summary row to expand.
- [x] Reconnect/resume — on EventSource disconnect, reconnects via resume endpoint with `after_seq`.
- [x] Resume on page load — detects active turns via Livewire, reconnects to resume endpoint.
- [x] `agentChatComposer` simplified to delegate streaming to section-level `connectToTurnStream()`.
- [x] 1371 tests passing. Pint clean. Vite builds.
- [x] Background dispatch creates turn upfront — `dispatchBackgroundChat()` creates `ChatTurn` before dispatch, passes `turn_id` in dispatch meta.
- [x] `RunAgentChatJob` reuses pre-created turn from dispatch meta (with fallback for backwards compat).
- [x] Alpine handler `@agent-chat-background-started.window` connects to turn event stream immediately — no polling needed.
- [x] Old background progress banner removed from `chat.blade.php`.
- [x] Dead code removed: `pollBackgroundChat()` and `backgroundStatusLabel()` from `HandlesBackgroundChat`.
- [x] Timeout notice updated to reference live status bar instead of "you'll see progress here".
- [x] **Phase 4 complete** — all Phase 4 tasks done (delta buffering deferred to Phase 5 as enhancement). 1371 tests passing.

## Phase 5 — Add Operational Guardrails

### Goal

Make failures explicit and diagnosable without degrading the live UX.

### Scope

- queue stall detection
- worker health
- turn cancellation
- admin observability

### Tasks

- [ ] Surface `waiting_for_worker` as a first-class phase.
- [ ] Fail turns that remain unclaimed beyond a threshold with a clear user-facing explanation (stale queued-turn escalation — moved from Phase 2).
- [ ] Add worker/queue health indicators to admin surfaces.
- [ ] Expose per-turn and per-run drill-down from the control plane.
- [ ] Ensure cancellation stops both the live stream and the underlying executor cooperatively.
- [ ] Buffer assistant deltas at safe markdown boundaries before rendering (deferred from Phase 4 — enhancement).

## Non-Goals

- Do not optimize for message-bubble chat minimalism.
- Do not preserve the current background-offload UX for normal chat.
- Do not make polling the long-term primary transport.
- Do not let the final assistant message be the only meaningful artifact of a turn.
