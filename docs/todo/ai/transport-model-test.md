# Transport Model Test

## Problem Essence

The chat console now uses direct streaming for fresh turns, but the remaining work is to remove legacy Reverb assumptions and keep the visible plan aligned with the runtime. The durable event store (`ai_chat_turn_events`) remains the reliability boundary for replay and recovery.

## Status

Complete — all three phases done

## Desired Outcome

A single test page (`admin/system/test-transport`) that replays real `ai_chat_turn_events` rows from a completed turn through each transport model, letting us observe latency, ordering, reconnect behavior, and UX fidelity side-by-side — then make a defensible transport decision backed by evidence.

## Public Contract

The test page should:

1. **Pick a real turn** from the DB (dropdown of recent completed turns with event counts).
2. **Select a transport model** to test.
3. **Replay events** from that turn through the selected transport at realistic or accelerated pace, using the same `toSsePayload()` format the chat console consumes.
4. **Render the event stream** using the same `processTurnEvent` switch logic as `agentChatStream`, so what you see in the test page is what you'd see in the real console.
5. **Show transport-level diagnostics**: delivery latency per event, gap count, reconnect count, total elapsed vs original turn elapsed.

Non-goals: this page does not test the chat UX itself (input, sessions, message history). It tests only whether events arrive correctly and promptly through each transport.

## Transport Models Under Test

### 1. Livewire-Custom (persistent fetch)

Alpine opens a `fetch()` with `ReadableStream` against a streaming controller endpoint. The server reads events from the durable store and pushes them as chunked JSON lines. No new infrastructure — uses the same Livewire component, Alpine event loop, and replay endpoint already in the stack.

**Why test first:** smallest delta from what exists. If persistent fetch against the replay endpoint delivers console-grade latency, SSE and Reverb become unnecessary complexity. Falls back naturally to tight polling if streaming proves unreliable.

### 2. Livewire-Custom (polling)

Same architecture, but Alpine polls the existing `TurnEventStreamController` replay endpoint on a short interval (200–500ms) instead of holding a persistent connection. No streaming, no new endpoints.

**Why test second:** if persistent fetch has edge cases (proxy buffering, FrankenPHP worker pinning), polling is the zero-risk fallback that still uses the existing replay endpoint.

### 3. SSE (EventSource)

Dedicated streaming controller, browser uses native `EventSource`. Server reads from the durable store and pushes events as SSE frames. `Last-Event-ID` maps to `after_seq` for native reconnect.

**Why test third:** cleanest streaming protocol, but adds a new controller endpoint and moves event delivery outside the Livewire component lifecycle.

### 4. Reverb (WebSocket)

Retained only as a transport comparison in the system test page. It is no longer part of the Lara chat runtime path.

**Known constraints:** adds extra infrastructure and previously forced payload-size fallbacks that the direct-streaming path does not need.

## Design Decisions

### Transport: Livewire-Custom Persistent Fetch

Chosen over SSE, Reverb, and full custom. The persistent-fetch model uses `fetch()` with `ReadableStream` against a streaming controller, managed by Alpine inside the existing Livewire component. No new infrastructure, no broker, no payload limits.

Reverb is demoted. Its 6KB Pusher payload limit caused real production failures ("Pusher error: Payload too large") and adds operational complexity for a reliability problem the durable store already solves.

### Delivery: Direct Streaming with Parallel DB Persist

The provider call runs inside the streaming HTTP response, not in a background job. Each provider event triggers two independent actions:

1. **Persist event row** — assign `seq` and write the canonical event envelope to `ai_chat_turn_events`
2. **Write to browser stream + flush** — send that same canonical envelope over the NDJSON response

On browser disconnect, the response detects `connection_aborted()` and stops streaming. The persisted event log still supports replay, resume, and post-mortem inspection. The background job model becomes a fallback, not the primary path.

FrankenPHP handles long-lived streaming responses natively (Go goroutines), so PHP worker pinning is a non-concern.

### Durable Store Role

The `ai_chat_turn_events` table remains the source of truth for replay, history, and audit. It no longer sits in the live delivery path — it's written in parallel, not read from for live updates. The `after_seq` replay contract is preserved for disconnect recovery and page-load catch-up.

### Test Methodology

**Real data, not synthetic.** The existing `CodingAgentTransportSimulator` uses randomized fake payloads that skip event types the real system emits (`assistant.thinking_started`, `run.started`, recovery events, oversized payloads). Replaying actual DB rows exercises the full event taxonomy with realistic timing and payload shapes.

**Replay pacing.** Events are replayed using proportional delays derived from `created_at` timestamps on the original events. A speed multiplier (1×, 2×, 5×, 10×) lets us compress long turns without losing the relative timing pattern. 1× is the default for fidelity testing.

**Shared rendering.** The test page reuses the `processTurnEvent` handler from `agentChatStream`. This ensures transport-level differences aren't masked by different rendering logic.

## Top-Level Components

### Turn Picker (Livewire)

Loads recent completed turns with metadata (session name, event count, duration, created_at). User selects one to replay.

### Replay Controller (PHP)

A controller that reads `ai_chat_turn_events` for a given turn and streams them through the selected transport with paced delays. For persistent-fetch and SSE, this is a `StreamedResponse`. For polling, the existing `TurnEventStreamController` is reused directly. For Reverb, events are dispatched as broadcast events.

### Transport Adapter (Alpine)

A thin JS adapter per transport model. Each implements `subscribe(turnId, onEvent)` and `unsubscribe()`. The test page wires the selected adapter to the shared event processor.

### Event Renderer + Diagnostics (Alpine/Blade)

Renders the event stream (thinking, tool calls, output deltas) using the same visual structure as the chat console. Adds a diagnostics panel showing per-event latency, gap detection, and transport state.

## Phases

### Phase 1 — Livewire-Custom Persistent Fetch

Build the test page with the turn picker and persistent-fetch transport only. This is the primary candidate — if it clears the bar, we stop here.

- [x] Create `TestTransport` Livewire component with turn picker (recent completed turns)
- [x] Create `TestTransportStreamController` that reads events from DB and streams as chunked JSON with paced delays
- [x] Build Alpine persistent-fetch adapter (`fetch` + `ReadableStream` reader)
- [x] Reuse `processTurnEvent` rendering for the event stream display
- [x] Add diagnostics panel (delivery latency, event count, gap detection)
- [x] Route and permission registration

#### Files Created

- `app/Base/System/Livewire/TestTransport/Index.php` — Livewire component with turn picker
- `app/Base/System/Http/Controllers/TestTransportStreamController.php` — NDJSON streaming controller with paced replay
- `resources/core/views/livewire/admin/system/test-transport/index.blade.php` — Blade view with persistent-fetch adapter, console renderer, and diagnostics

#### Test Data Available

18 turns / 3,003 events covering all 15 event types. Notable turns:
- `01knk81qxja5m7epvw3tk8skw8` — 358 events, 94s span, rich completed turn
- `01knk5c9py7cn5g28my77032k2` — 8 events, failed with "Pusher error: Payload too large" (demonstrates Reverb's 6KB limit)
- `01knh47bfhyn8qcaphc8bz4qvc` — 5 events, failed with empty response timeout
- `01knnbq3sr6hvs6x6zg7pefc8s` — 33 events, failed with runtime exception

### Phase 2 — Direct Streaming in Chat Console

Migrate the real chat console from background-job + Reverb to direct streaming with parallel DB persist.

- [x] Add `runtime_meta` and `cancel_requested_at` columns to `ai_chat_turns`
- [x] Extract `ChatTurnRunner` service from `RunAgentChatJob` (shared run logic)
- [x] Create `ChatTurnStreamController` — runs agentic runtime inline, streams NDJSON, parallel DB persist via TurnStreamBridge
- [x] Update `HandlesStreaming` — stores runtime_meta on turn, returns stream URL instead of dispatching job
- [x] Update `cancelActiveTurn` — uses `requestCancel()` instead of terminal-state + dispatch cancellation
- [x] Wire Alpine `startPersistentFetch` into `agentChatStream` replacing Echo subscription for new turns
- [x] Preserve `after_seq` replay for page-load resume with polling-only gap fill

#### Files Created

- `app/Modules/Core/AI/Services/ChatTurnRunner.php` — shared run logic for both job and controller
- `app/Modules/Core/AI/Http/Controllers/ChatTurnStreamController.php` — NDJSON streaming controller
- `app/Modules/Core/AI/Database/Migrations/0200_02_01_000015_add_cancel_and_runtime_meta_to_ai_chat_turns_table.php`

#### Files Modified

- `app/Modules/Core/AI/Models/ChatTurn.php` — added `runtime_meta`, `cancel_requested_at`, `requestCancel()`, `isCancelRequested()`
- `app/Modules/Core/AI/Livewire/Concerns/HandlesStreaming.php` — direct-stream architecture, no more job dispatch
- `app/Modules/Core/AI/Routes/web.php` — added `ai.chat.turn.stream` route
- `resources/core/views/livewire/ai/chat.blade.php` — `startPersistentFetch`, `abortPersistentFetch` in Alpine

### Phase 3 — Cleanup

- [x] Remove `RunAgentChatJob`, `AgentChatStreamingRunRequest`, and `HandlesBackgroundChat` — dead code, never dispatched after direct-streaming migration
- [x] Remove `TurnEventOccurred` broadcast event from the Lara chat runtime path
- [x] Update `lara-realtime-console.md` with the final architecture decision
