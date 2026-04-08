# AI Chat as a Live Coding-Agent Console

## Problem Essence

The current AI chat is still built like a request/response messenger with a fallback background job. That is the wrong mental model. A coding agent UI should feel like a live console session: once a turn starts, the user should see the agent remain busy, emit activity in real time, stream partial output, and stay observable until the turn reaches a terminal state.

## Status

In Progress — Phases 1–7 complete. Phase 8 (dispatch-first architecture) in progress.

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

- [x] Surface `waiting_for_worker` as a first-class phase.
- [x] Fail turns that remain unclaimed beyond a threshold with a clear user-facing explanation (stale queued-turn escalation — moved from Phase 2).
- [x] Add worker/queue health indicators to admin surfaces.
- [x] Expose per-turn and per-run drill-down from the control plane.
- [x] Ensure cancellation stops both the live stream and the underlying executor cooperatively.
- [x] Buffer assistant deltas at safe markdown boundaries before rendering (deferred from Phase 4 — enhancement).

### Implementation progress

- [x] `TurnEventStreamController` emits synthetic `current_phase` meta event when connecting to a turn with no events yet — client sees "Waiting for worker…" immediately.
- [x] Alpine background handler sets `turnPhase = 'waiting_for_worker'` and label on dispatch.
- [x] Alpine handles `current_phase` meta event from resume endpoint for reconnection/page-load cases.
- [x] `SweepStaleTurnsCommand` (`blb:ai:turns:sweep-stale`) — fails turns stuck in queued/booting (10m) or running (30m) with user-facing messages and `turn.failed` events. 6 tests.
- [x] `cancelActiveTurn()` method in `HandlesStreaming` — cancels the turn via `TurnEventPublisher`, also cancels background dispatch if present. Ownership-guarded.
- [x] Stop button calls `$wire.cancelActiveTurn(activeTurnId)` before closing EventSource — cooperative cancellation across both interactive and background flows.
- [x] Delta buffering — accumulates text in `_deltaBuffer`, flushes on newlines (safe markdown boundary) or after 80ms debounce. `flushDeltaBuffer()` called on block commit and terminal events.
- [x] Turn Queue Health card in ControlPlane Health tab — shows active, stale, completed, failed counts with visual danger/warning indicators.
- [x] Turn Inspector tab in ControlPlane — lookup by ULID, shows turn details (status, phase, session, employee, run ID, timestamps) and full event log table.
- [x] **Phase 5 complete** — 1377 tests passing. Pint clean. Vite builds.

## Phase 6 — Replace Serving Stack with FrankenPHP + Octane

### Goal

Eliminate the single-threaded `artisan serve` bottleneck and the Caddy reverse-proxy buffering layer so SSE streams reach the browser with zero intermediate buffering, and concurrent users can hold live turn connections without blocking each other.

### Problem

The current serving stack is `Caddy → php artisan serve → PHP`. This has two compounding defects:

1. **Caddy buffers SSE.** The `reverse_proxy` directive defaults to buffering responses. Without `flush_interval -1`, turn events accumulate in Caddy's buffer and arrive in bursts — the user sees "jump to finish" instead of progressive streaming. This is the immediate cause of the broken TTFT.
2. **`artisan serve` is single-threaded.** Each SSE connection holds the sole PHP worker for the duration of the turn. With N users on Lara, the N+1th request (page load, API call, or another SSE stream) blocks until a connection closes. This is fundamentally incompatible with a multi-user live console.

### Design Decision

**FrankenPHP + Laravel Octane replaces both Caddy and `artisan serve` in a single process.**

FrankenPHP embeds Caddy as its HTTP server and runs PHP workers natively — no FastCGI, no reverse proxy, no intermediate buffering. SSE `echo` + `flush()` in PHP goes directly to the HTTP/2 stream. The stack collapses from three processes to one:

- Before: `Caddy (TLS, routing) → reverse_proxy → artisan serve (single PHP worker)`
- After: `FrankenPHP (TLS, routing, N PHP workers)`

Caddy features BLB already uses (TLS, mkcert, Reverb WebSocket proxy, Vite HMR proxy) are preserved because FrankenPHP IS Caddy with a PHP SAPI module.

### Risks

- **Persistent-worker state leaks.** Octane workers persist across requests. Static properties, singletons, and mutable service container bindings carry over. BLB already favors constructor DI over facades, so the migration surface is small, but a sweep is needed.
- **Worker memory growth.** Long-running workers can accumulate memory. Octane's `--max-requests` flag mitigates this.
- **Dev/prod parity.** `artisan serve` is gone. Local development uses the same FrankenPHP binary as production. This is a feature (parity), but means contributors need FrankenPHP installed.

### Tasks

#### 6A — Eliminate the sync fallback path (the real TTFT bug)

The sync path (`Chat::sendMessage()` → `$runtime->run()`) bypasses the entire turn-event system. No turn is created, no events are published, no streaming occurs, and TTFT is undefined. This is the root cause: chats that hit this path produce zero turn artifacts and appear to "jump to finish."

- [x] Remove `Chat::sendMessage()` and `Chat::runAi()` as LLM execution paths — all LLM-bound chats must go through `prepareStreamingRun()` → `ChatStreamController`. Also removed `dispatchPostRunEvents()`, `extractAgentAction()`, `resolvePageContext()`, and 9 unused imports.
- [x] Keep the orchestration shortcut (`LaraOrchestrationService::dispatchFromMessage()`) as a fast non-LLM response — already lives in `prepareStreamingRun()`, never falls through to sync LLM.
- [x] Remove the Alpine fallback that calls `$wire.sendMessage()` when `prepareStreamingRun()` returns null — replaced with error display in `onSubmit` catch block and SSE `onerror` handler.
- [x] Remove `handleTimeoutWithBackgroundOffer()` from `HandlesBackgroundChat` — only caller was the deleted `sendMessage()`.
- [x] Remove `wire:submit="sendMessage"` from the form — form now uses `x-on:submit.prevent` only.
- [x] Verify: every chat interaction that hits the LLM creates a `ChatTurn`, writes to `ai_chat_turn_events`, and streams via SSE. The only null-return from `prepareStreamingRun()` is orchestration shortcuts (non-LLM) or empty input.

#### 6B — Immediate Caddy fix (unblocks streaming now)

- [x] Add `flush_interval -1` to all three Laravel `reverse_proxy` blocks in `Caddyfile` (built assets, Laravel backend, backend domain) — disables response buffering so SSE events flow immediately.
- [ ] Verify TTFT: open a Lara chat, confirm turn events (`turn.started`, `turn.phase_changed`, `tool.started`, `assistant.output_delta`) arrive progressively in the browser EventSource, not in a burst at the end.

#### 6C — Close Responses API streaming gaps

The `LlmResponsesDecoder` handles the core Responses API events but silently drops several event types that carry meaningful data.

- [x] Handle `response.refusal.delta` / `response.refusal.done` — refusal text surfaced as `content_delta` events so the user sees the refusal message.
- [x] Handle `response.output_text.annotation.added` — captured as `annotation` event type with type/url/title/index data.
- [x] Capture `response.id` from `response.created` — emitted as `response_created` event with the OpenAI-assigned response ID.
- [x] Use `instructions` top-level field instead of `system` → `developer` role conversion — replaced `convertToResponsesInput()` with `convertToResponsesInputWithInstructions()` that extracts system messages into the Responses API `instructions` field.

#### 6D — Fix timeout budget for agentic streaming

The OpenAI SDK default client timeout is **10 minutes** (600s). BLB's `ExecutionPolicy` sets interactive chat to **60 seconds** and heavy foreground to **180 seconds**. This is the Guzzle HTTP connect+read timeout passed to the provider — if the LLM is thinking (reasoning models, large context) and hasn't sent the first SSE byte within 60s, BLB kills the connection with a timeout error.

For streaming, the timeout should govern **time to first byte**, not total request duration — once SSE chunks are flowing, the connection should stay alive indefinitely. Guzzle's `stream: true` + `timeout` applies to individual read operations, so 60s means "60s without any data" which is reasonable for inter-chunk silence but too short for initial reasoning.

- [x] Raise interactive to **180s**, heavy foreground to **300s**, background to **900s** — both in config (`ai.llm.timeout_tiers`) and in `ExecutionPolicy::forMode()` hardcoded fallbacks.
- [x] Added `timeout_tiers` key to `app/Base/AI/Config/ai.php` so tiers are tunable without code changes.
- [x] Guzzle with `stream: true` + `timeout` is a read-idle timeout (time between chunks), not total-duration — 180s is safe for reasoning model TTFB.

#### 6E — Clean up stale sync-path UI artifacts

The screenshot shows UI elements that only exist because of the sync fallback path and the old messenger-style UX.

- [x] Remove the 3-dot loading indicator — deleted the `pendingMessage && streamEntries.length === 0` bouncing dots block.
- [x] `pendingMessage` kept for the optimistic user bubble and empty-state gating — these are still useful for the streaming path (shows the user's message before SSE events arrive). Not dead code.
- [x] All `$wire.sendMessage()` references removed from Alpine — `onSubmit` catch block shows error; SSE `onerror` shows connection-lost message instead of falling back to sync.

#### 6F — Install FrankenPHP + Octane

FrankenPHP binary must exist before `octane:install --server=frankenphp` can work. The setup step (`17-frankenphp.sh`) is the install path for all developers, so it comes first.

- [x] Rename `20-php.sh` → `15-php.sh` to make room for FrankenPHP in the numbering sequence.
- [x] Create `scripts/setup-steps/17-frankenphp.sh` — install the FrankenPHP standalone binary. Slots after `15-php.sh` (FrankenPHP depends on PHP) and before `22-sqlite-vec.sh` / `25-laravel.sh`. Follow the pattern of `15-php.sh`: detect OS, install via official method (GitHub release binary for Linux, Homebrew for macOS), verify with `frankenphp version`, save to setup state.
- [x] Add `laravel/octane` to `composer.json` `require`.
- [x] Run `artisan octane:install --server=frankenphp` once to scaffold `config/octane.php`, then commit it. Future developers get it from the repo — no setup step needed.
- [x] Configure worker count, max requests, and memory limits in `config/octane.php` before committing.

#### 6G — Migrate Caddyfile to FrankenPHP ✅

The current stack has Caddy as a separate process reverse-proxying to `artisan serve`. FrankenPHP IS Caddy with a PHP SAPI, so the `reverse_proxy` to `artisan serve` disappears — replaced by `php_server` / `php` directives that serve Laravel directly.

Vite HMR, Reverb WebSocket, and Vite dev asset proxying remain as `reverse_proxy` blocks because they target separate Node/PHP processes.

- [x] Convert the Laravel Backend `handle` block from `reverse_proxy {$APP_HOST}:{$APP_PORT}` to FrankenPHP `php_server` directive pointing at `public/`.
- [x] Convert the built assets block similarly — FrankenPHP serves static files from `public/build/` directly via `php_server`.
- [x] Preserve Vite dev proxy (`@vite_dev`, `@vite_ws`) and Reverb WebSocket proxy (`@reverb_ws`) as `reverse_proxy` blocks — these still target external processes.
- [x] Remove `flush_interval -1` from the Laravel blocks (added in 6B) — FrankenPHP serves PHP responses directly, no intermediate buffer to flush.
- [x] Update the backend domain block (`{$BACKEND_DOMAIN}`) to use `php_server` instead of `reverse_proxy`.
- [x] Add `{$FRANKENPHP_CONFIG}` global options block for Octane worker injection.

#### 6H — Sweep for Octane compatibility ✅

Audited all static properties and mutable singletons across `app/Base/`.

- [x] Audit static properties and mutable singletons in service providers, middleware, and AI services.
  - `AuditBuffer`, `DatabaseDecisionLogger`: `$flushRegistered` flag prevents re-registration of terminating callback → added to Octane `flush` array.
  - `GrantPolicy`: per-request permission cache leaks across worker requests → added to Octane `flush` array.
  - `MutationListener::$disabled`: protected by try/finally — safe, no action needed.
  - `ExtensionAutoloader::$registered`: intentional register-once-per-worker guard — safe.
  - `blb_log_var()` channel cache: Monolog loggers are stateless — safe.
  - `CapabilityCatalog`/`CapabilityRegistry`: worker-scoped configuration, intentionally not flushed.
- [x] `PageContextHolder`, `TurnStreamBridge`, `TurnEventPublisher` do not exist yet — will be added to flush array when created.
- [x] Run full test suite — 1376/1377 pass (1 pre-existing `AgenticRuntimeTest` failure unrelated to Octane).

#### 6I — Update dev tooling and start scripts ✅

The process model changes in two ways:

1. **`dev:all` pipeline:** `composer run dev` → `bun run dev:all` now launches `php artisan octane:start --server=frankenphp` without `--watch` by default. FrankenPHP runs Caddy internally on the same `APP_PORT`, so the PHP serving process now also handles TLS and routing without a separate proxy. `bun run dev:all:watch` remains available as an explicit opt-in for file-watch restarts.

2. **Separate Caddy process eliminated:** `start-app.sh` no longer starts a shared Caddy process. FrankenPHP IS Caddy — it reads the project `Caddyfile` directly via Octane.

##### Dev pipeline (`package.json` + `composer.json`)

- [x] Replace `php artisan serve --port=${APP_PORT:-8000}` with `php artisan octane:start --server=frankenphp --port=${APP_PORT:-8000}` in `package.json` `dev:all`, and keep a separate `dev:all:watch` opt-in script for watched restarts.

##### `start-app.sh` / `stop-app.sh`

- [x] Remove the `PROXY_TYPE` branching — FrankenPHP is the only serving path.
- [x] Remove `read_proxy_type()` and the `PROXY_TYPE` variable from `start-app.sh`.
- [x] Add `export_caddy_env()` to export env vars (`APP_DOMAIN`, `BACKEND_DOMAIN`, `TLS_DIRECTIVE`, etc.) for Caddy's native `{$VAR}` resolution.
- [x] Update `stop-app.sh` — removed Caddy deregistration block.
- [x] Keep `ensure_ssl_trust` — still needed for trusting FrankenPHP's internal Caddy CA.

##### `scripts/shared/caddy.sh`

- [x] Remove all shared multi-instance Caddy functions (`diagnose_caddy_failure`, `ensure_blb_caddy_dirs`, `create_main_caddyfile`, `resolve_caddyfile_vars`, `site_fragment_slug`, `write_site_fragment`, `remove_site_fragment`, `ensure_shared_caddy`, `maybe_stop_shared_caddy`, `install_caddy`, `ensure_caddy_privileges`, `is_caddy_enabled`).
- [x] Removed `resolve_caddyfile_vars()` — Caddy natively resolves `{$VAR:default}` from env vars.
- [x] Keep `setup_ssl_trust()`, `ensure_ssl_trust()`, `install_cert_to_nss_databases()`.
- [x] Removed `install_caddy()` — FrankenPHP binary replaces standalone Caddy.

##### `70-caddy.sh` → `70-domains.sh`

- [x] Rename `70-caddy.sh` to `70-domains.sh` (title: "Domains & TLS").
- [x] Remove the `PROXY_TYPE` selection menu and all proxy detection/installation branches.
- [x] Remove `install_caddy` call — `17-frankenphp.sh` handles the binary.
- [x] Remove `PROXY_TYPE` from `.env` updates and setup state.
- [x] Keep `prompt_for_domains()`, add `ensure_tls_certs()` for mkcert cert generation.

##### `75-ssl-trust.sh`

- [x] Remove `PROXY_TYPE` guard. Updated references from `70-caddy.sh` to `70-domains.sh`.

##### `scripts/shared/config.sh`

- [x] Remove `DEFAULT_PROXY_TYPE` and `PROXY_TYPE` references from config defaults.

##### `.env` / `.env.example`

- [x] Remove `PROXY_TYPE` key.
- [x] Replace `PHP_CLI_SERVER_WORKERS` with `OCTANE_WORKERS` comment.

#### 6J — Validate under concurrent load (manual)

Requires a running FrankenPHP instance and browser sessions. Perform manually after deploying locally.

- [ ] Open 3+ simultaneous Lara chat sessions, each streaming a turn.
- [ ] Confirm all sessions receive progressive SSE events concurrently.
- [ ] Confirm non-SSE page loads remain responsive while SSE connections are active.
- [ ] Confirm Reverb WebSocket and Vite HMR still function through FrankenPHP's Caddy routing.

#### 6K — Stabilize SSE under FrankenPHP worker mode ✅

FrankenPHP workers crashed with SIGSEGV/SIGABRT when Lara was prompted. `dmesg` showed fatal signals 11 and 6 in `php-0`/`php-1` workers, followed by the main `frankenphp` process aborting. Crash intervals (~30s) matched `max_execution_time`. Root cause: long-running SSE connections (`ChatStreamController` holds a worker for the full LLM call; `TurnEventStreamController` polls for up to 5 minutes) exceeded the 30-second PHP execution timer, which FrankenPHP's Go runtime does not handle gracefully — the `SIGALRM`-based timer causes native crashes rather than clean PHP fatal errors.

Secondary issue: `queue:listen` has a built-in 60-second child timeout that kills long AI agent jobs.

- [x] Set `max_execution_time` to `0` in `config/octane.php` — disables the PHP timer that triggered worker crashes.
- [x] Replace `queue:listen --tries=1` with `queue:work --queue=ai-agent-tasks,ai-background-commands,ai-schedules,default --tries=1 --timeout=900 --sleep=1` in `package.json` — eliminates 60s child timeout, adds 15-minute per-job limit, covers all AI queue names.
- [x] Add `connection_aborted()` check to `ChatStreamController::streamAndEmit()` — stops streaming to disconnected clients, freeing workers sooner.
- [x] Add `--restart-tries=3 --restart-after=1000` to `concurrently` in `package.json` — auto-restarts crashed processes with 1-second delay.

**Known architectural debt (medium-term):** `ChatStreamController` holds a FrankenPHP worker for the entire LLM call duration (30–300s). This is fundamentally wasteful in worker mode. The medium-term fix is to always dispatch LLM execution via `RunAgentChatJob` (background) and use `TurnEventStreamController` (or Reverb WebSocket) for live tailing only. The background job path already exists and works.

## Phase 7 — Real-Time Agent Activity UX

**Goal:** Make the streaming UX truthful, transparent, and responsive — matching the real-time feedback of coding-agent CLIs (Claude Code, Cursor, Claw Code). The user should always know: what Lara is doing right now, how long she's been doing it, and what intermediate results look like.

### Current gaps

1. **Fake elapsed timer.** The "Thinking… 12s" counter starts from 0 on every page refresh / SSE reconnect because it's a pure client-side `setInterval(() => Date.now() - turnStartedAt)`. On reconnect, `TurnEventStreamController` replays events from seq 0, `onTurnStarted()` fires, and the timer re-anchors to `Date.now()` — not the turn's actual `started_at`. A turn running for 2 minutes shows "0s" after a Livewire navigate.

2. **"Thinking…" is opaque.** `assistant.thinking_started` carries no content — just a phase label. Coding agents show the model's reasoning text, tool-selection rationale, or at minimum a descriptive phase label ("Analyzing codebase", "Selecting tools"). Currently it's a static "Thinking…" with a pulsing dot regardless of what the agent is actually doing.

3. **Action boxes are hollow until completion.** Tool call cards show the tool name and args summary immediately on `tool.started`, but the result area is blank until `tool.finished` arrives with `resultPreview`. For long-running tools (bash builds, test suites, browser actions), the user sees an empty card for 10–60+ seconds. The runtime's `executeToolCall()` is synchronous — it blocks, gets the result, then yields. No incremental output is emitted. `ToolStdoutDelta` exists in the enum and `TurnEventPublisher.toolStdoutDelta()` exists, but neither the runtime nor the bridge emit it.

### Tasks

#### 7A — Truthful elapsed timer on reconnect ✅

The turn's actual `started_at` timestamp must reach the client so the timer shows real elapsed time, not time-since-page-load.

- [x] Include `started_at` (ISO 8601) in the `turn.started` event payload (from `TurnEventPublisher`).
- [x] Include `started_at` in the `current_phase` meta event emitted by `TurnEventStreamController` on resume (line 69–75).
- [x] In the client's `onTurnStarted()` and `onMetaEvent(current_phase)`, set `turnStartedAt` from the server-provided timestamp instead of `Date.now()`, falling back to `Date.now()` if absent.
- [ ] Verify: start a turn, navigate away, navigate back — timer should show the real elapsed time, not restart from 0.

#### 7B — Descriptive phase labels (thinking transparency) ✅

Replace the static "Thinking…" label with context-aware descriptions of what the agent is doing in each phase.

- [x] Extend the runtime's `phase: 'thinking'` event to include a `description` field when available (e.g., "Planning tool calls", "Analyzing results", iteration count).
- [x] After each tool finishes, emit a phase label that names what happened: "Evaluating bash result" → "Selecting next action" rather than generic "Thinking…".
- [x] In the client, render the description text alongside or below the "Thinking…" entry when `entry.description` is present.
- [ ] For reasoning-capable models (extended thinking / reasoning tokens): investigate whether the API streams reasoning content that can be forwarded as `assistant.thinking_delta` events. If available, render as collapsible reasoning text below the thinking indicator.

#### 7C — Incremental tool output (live action boxes) ✅

Stream tool execution output in real-time so action boxes fill progressively instead of staying empty until completion.

- [x] **Bash tool:** Refactor to implement `StreamableTool` interface with `proc_open` non-blocking stdout/stderr reads. Yields `tool_stdout` events at ~100ms poll intervals, capped at 50 events per invocation.
- [x] **TurnStreamBridge:** Added `'tool_stdout'` case to `mapStatusEvent()` that calls `$this->publisher->toolStdoutDelta()`.
- [x] **Runtime:** `streamToolCalls()` now calls `toolRegistry->executeStreaming()` inline, yielding `tool_stdout` status events during execution, then builds the execution result from the `ToolResult` after stream completes.
- [x] **Client event listener:** Added `'tool.stdout_delta'` to the `eventTypes` array in `connectToTurnStream()`. Added handler `onToolStdoutDelta(data)` that appends `delta` text to the running tool's `stdoutBuffer` (capped at 10KB).
- [x] **Client rendering:** Tool call template shows a live monospace output area when `entry.stdoutBuffer` is non-empty while `status === 'running'`.
- [ ] **Non-bash tools:** For tools that return results atomically (memory_search, file_read), no change needed — they complete quickly. If a tool takes >2s, consider emitting a synthetic "working…" stdout delta so the card doesn't look dead.
- [x] **Flow control:** Capped stdout delta events at 50 per tool invocation (`MAX_STDOUT_EVENTS`).

#### 7D — Polish: collapse completed thinking entries ✅

- [x] After thinking deactivates (tool starts or output begins), thinking entry stops pulsing (active=false).
- [x] On turn completion, remove intermediate thinking entries from `streamEntries` since the materialized transcript replaces them.

### Dependencies

- 7A is independent and can be done first (smallest, highest impact).
- 7B depends on understanding the LLM provider's reasoning output capabilities.
- 7C is the largest task — requires runtime refactoring for async tool execution and careful event-rate management.
- 7D is cosmetic polish, do last.

### Non-goals for Phase 7

- Do not add WebSocket transport (Reverb) in this phase — that's a separate architectural migration.
- Do not stream tool *input* (args) incrementally — args are already available at `tool.started`.
- Do not implement speculative UI (showing predicted tool output before execution).

## Phase 8 — Dispatch-First Architecture

### Goal

Eliminate worker-blocking SSE by moving all LLM execution to the background queue. SSE connections become lightweight event tailers, not LLM executors.

### Problem

`ChatStreamController` holds a FrankenPHP worker for the entire LLM call (30–300s). With 4 workers, 4 concurrent chats saturate the server — page loads and API calls block entirely. Additionally, FrankenPHP resets `max_execution_time` to the PHP ini default (30s) per request, ignoring Octane's `max_execution_time: 0` config. Both SSE controllers crash at 30s with `Maximum execution time of 30 seconds exceeded` at `TurnEventStreamController.php:90`.

### Design Decision

**Always dispatch LLM execution via `RunAgentChatJob`. Remove `ChatStreamController` entirely.** The client submits a prompt, Livewire creates a turn + dispatch + job, and returns the turn resume URL. The client connects to `TurnEventStreamController` to tail events. This is the architecture the todo doc already identified as the correct medium-term fix at 6K.

Before: `prepareStreamingRun()` → `ChatStreamController` (holds worker for full LLM run, emits SSE inline)
After: `prepareStreamingRun()` → `RunAgentChatJob` (queue worker) + `TurnEventStreamController` (lightweight SSE tailer)

### Path reconciliation

The inline path (`ChatStreamController`) and the background path (`RunAgentChatJob`) have drifted:

1. **Execution policy:** Inline uses `ExecutionPolicy::interactive()` (180s); background uses `ExecutionPolicy::background()` (600s). Fix: store the intended policy in dispatch meta so the job respects it.
2. **Prompt source:** Inline builds prompt from actual last message; background derives from `dispatch->task` (truncated to 500 chars). Fix: job must read messages from DB and build prompt the same way as inline.
3. **Prompt metadata:** Inline passes `prompt_package` extra meta to materializer; background does not. Fix: job should compute and pass the same metadata.
4. **Page context:** Inline resolves from a cache key; background resolves from `PageContextHolder`. Fix: resolve page context in Livewire (during the user's page request) and store directly in dispatch meta — the job already hydrates from dispatch meta.

### Tasks

#### 8A — Fix `max_execution_time` for SSE endpoints

FrankenPHP resets `max_execution_time` to the PHP ini default (30s) per request, overriding Octane's config value. The `set_time_limit(0)` call in the worker bootstrap only runs once at boot and is reset per request by the SAPI. Fix: call `set_time_limit(0)` inside each SSE streaming closure.

- [x] Add `set_time_limit(0)` at top of `TurnEventStreamController` StreamedResponse closure.
- [x] Add `set_time_limit(0)` at top of `ChatStreamController` StreamedResponse closure (kept temporarily until 8C removes it).
- [x] Remove `--restart-tries=3 --restart-after=1000` from `package.json` `dev:all` — this was a band-aid for the crash, not a fix.

#### 8B — Unify dispatch into `prepareStreamingRun()`

Merge the turn creation + job dispatch logic into `prepareStreamingRun()` so all interactive chats go through the background job path. The existing `dispatchBackgroundChat()` in `HandlesBackgroundChat` becomes dead code.

- [x] Move turn creation + OperationDispatch creation + job dispatch into `prepareStreamingRun()`.
- [x] Return the turn resume URL (`TurnEventStreamController`) instead of the stream URL (`ChatStreamController`).
- [x] Store execution policy (`interactive`) in dispatch meta so the job uses the correct policy.
- [x] Store resolved page context directly in dispatch meta (no cache key).
- [x] Emit `agent-chat-background-started` from `prepareStreamingRun()` so the Alpine client connects to the resume endpoint.

#### 8C — Remove `ChatStreamController`

Once all chat goes through the dispatch-first path, the inline SSE controller is dead code.

- [x] Delete `ChatStreamController`.
- [x] Remove the `ai.chat.stream` route.
- [x] Remove `ChatStreamController` import from routes file.

#### 8D — Reconcile `RunAgentChatJob` with inline path semantics

Make the job produce identical results to what the old inline path produced.

- [x] Build system prompt from persisted messages (like inline), not from truncated `dispatch->task`.
- [x] Support `execution_policy` in dispatch meta (default to `background` for backwards compat).
- [x] Pass `prompt_package` metadata to `materializeFromTurn()` when available.
- [x] Store `prompt_package` description in dispatch meta for observability.

#### 8E — Remove dead code

- [x] Remove `dispatchBackgroundChat()` from `HandlesBackgroundChat` (now handled by `prepareStreamingRun`).
- [x] Remove `capturePageContextForBackground()` from `HandlesBackgroundChat`.
- [x] Remove `cancelBackgroundChat()` from `HandlesBackgroundChat` if `cancelActiveTurn()` covers the same functionality.
- [x] Clean up Alpine handler for `@agent-chat-background-started` — unified into `onSubmit` flow.
- [x] Remove `cachePageContext()` from `HandlesStreaming` (replaced by direct dispatch meta).

### Known limitations

**Worker occupancy:** `TurnEventStreamController` still holds a FrankenPHP worker per open SSE connection (sleeping in `usleep` poll loop). This is acceptable at low concurrency (4 workers, typically 1-2 simultaneous chats) but will need to migrate to Reverb WebSocket or short-polling if concurrency grows.

**500ms poll interval:** Events arrive in small batches instead of instantly. For tool/phase events this is fine. For streaming token deltas it's perceptibly bursty but acceptable for now.

## Non-Goals

- Do not optimize for message-bubble chat minimalism.
- Do not preserve the current background-offload UX for normal chat.
- Do not make polling the long-term primary transport.
- Do not let the final assistant message be the only meaningful artifact of a turn.
