# Lara Real-Time Console

## Problem Essence

Lara's chat UI should feel like a coding-agent CLI — the user sees what Lara is doing in real time: thinking, tool calls, streaming output. FrankenPHP crashes (Go-level SIGSEGV after ~63s) when SSE connections hold workers in `while(true) { usleep(); }` poll loops, starving the 4-worker pool.

## Status

In Progress — the Reverb transport path is now working end-to-end for Lara's live feed. Phase 3 uncovered two transport issues that are now fixed: (1) a Reverb/Pusher payload ceiling for large `tool.stdout_delta`, `tool.finished.result_preview`, and `assistant.output_block_committed` events; and (2) a browser attach race / transport miss where the page can replay persisted events before Echo/Reverb is ready, leaving the UI without live updates. The fix keeps the full event durable in `ai_chat_turn_events`, downgrades oversized WebSocket broadcasts to a tiny `replay_required` marker, and keeps a short replay poll running until live delivery is attached. The separate `ERR_CONNECTION_REFUSED` reports traced back to watched-dev-stack crashes (`octane:start --watch` exiting with `std::bad_alloc` / `zend_mm_heap corrupted` during file-change reloads), so the default dev pipeline now avoids `--watch` and keeps watched restarts as an explicit opt-in.

## Goal

1. **Live activity feed:** The user sees Lara's work as it happens — thinking phases, tool calls with live stdout, assistant output streaming progressively.
2. **Thinking rendered in-place:** Reasoning/thinking content appears at the moment it occurs in the tool-calling loop.
3. **Multi-user concurrency:** Multiple browsers/users can chat with Lara simultaneously without blocking each other or crashing the server.

## What Already Works

The hard infrastructure is built (Phases 1–5 of `ai-chat-coding-agent-console.md`):

- **Turn event model:** `ai_chat_turns` + `ai_chat_turn_events` — append-only, per-turn, seq-ordered, durable.
- **TurnEventPublisher:** 21 event types (turn lifecycle, tool activity, assistant deltas, recovery, heartbeat). Now broadcasts each event via Reverb after DB write.
- **TurnStreamBridge:** Maps `AgenticRuntime` generator events → durable turn events. 18 tests passing.
- **RunAgentChatJob:** Background job that runs the agentic loop, publishes turn events via bridge. Cooperative cancellation, transcript materialization. Now has `$tries = 1` / `$backoff = 0` to fail fast.
- **Dispatch-first path:** `prepareStreamingRun()` creates a turn + dispatch + job upfront, returns `turnId` for Echo subscription.
- **Alpine state machine:** `agentChatStream` handles all 20+ event types via Echo private channel, with HTTP replay for page-load resume.
- **Serving stack:** FrankenPHP + Octane with 4 workers, HTTP/2, TLS via mkcert. Reverb handles WebSocket transport out-of-process.

## Design Decision — SSE → Reverb

**Diagnosis (Phase 1 — complete):** FrankenPHP crashes at the Go level (~63s) when an SSE connection holds a worker in a `usleep()` poll loop. `set_time_limit(0)` does not prevent the crash — the Go runtime itself fails (not a PHP timeout). Worker starvation compounds the problem: 4 workers total, one consumed by SSE, leaves 3 for all other traffic.

**Decision:** Replace SSE with Reverb (WebSocket). Broadcasting via `TurnEventOccurred` is fire-and-forget from the queue worker — no long-lived PHP responses, no worker starvation. Reverb runs as a separate process, so WebSocket connections consume zero FrankenPHP workers.

## Phases

### Phase 1 — Diagnose the actual crash ✅

The crash is a Go-level instability in FrankenPHP when workers hold long-lived streamed responses. Evidence: `ERR_CONNECTION_REFUSED` after ~63s, no PHP `Maximum execution time` errors, `set_time_limit(0)` already in place. Additionally, `MaxAttemptsExceededException` on stale jobs from crashed worker sessions.

### Phase 2 — Replace SSE with Reverb ✅

- [x] Create `TurnEventOccurred` broadcast event (`ShouldBroadcastNow`), channel `PrivateChannel("turn.{turnId}")` — `app/Modules/Core/AI/Events/TurnEventOccurred.php`
- [x] Add channel authorization in `routes/channels.php` — user can listen only to turns where `acting_for_user_id` matches
- [x] Broadcast from `TurnEventPublisher::publish()` after DB write — fire-and-forget dispatch
- [x] Replace Alpine EventSource with `Echo.private('turn.' + turnId).listen('.turn-event', handler)` — reuses existing `handleTurnEvent()`
- [x] Page-load replay: HTTP fetch of persisted events via `TurnEventStreamController` (converted from SSE to JSON), then subscribe to Echo channel if turn is still active
- [x] Convert `TurnEventStreamController` from SSE `StreamedResponse` to JSON endpoint — returns `{events, turn_id, status, current_phase, current_label, started_at}`
- [x] Remove SSE-only artifacts: `onMetaEvent()`, `_eventSource`, `closeTurnStream()`, `reconnectToTurnStream()`, `stream_end` meta events
- [x] Fix `RunAgentChatJob`: add `$tries = 1` and `$backoff = 0` to prevent `MaxAttemptsExceededException`
- [x] Remove `resumeUrl` from `prepareStreamingRun()` return — client subscribes to Echo channel using `turnId` only
- [x] Update `ChatTurnEvent` docblocks to reflect WebSocket transport
- [x] All 43 turn-related tests passing (19 publisher + 18 bridge + 6 controller)

### Phase 3 — End-to-end verification

- [x] Diagnose `Pusher error: Payload too large` — live Reverb transport cannot carry arbitrarily large turn-event payloads in one frame.
- [x] Add bounded live payload fallback: oversize broadcasts emit `replay_required`, browser gap-fills via HTTP replay using `after_seq`.
- [x] Add transport backstop: browser keeps replay tailing on a short interval while Echo/Reverb initializes or misses attachment, instead of going dark.
- [x] Send a prompt, see live tool cards and streaming output via Reverb.
- [ ] Thinking phases render in the timeline at the moment they occur.
- [ ] Navigate away and back during an active turn — timer resumes from real elapsed time, events replay via HTTP then Echo picks up.
- [ ] Cancel a turn via the stop button — turn terminates, UI returns to ready state.
- [ ] 3+ concurrent users chatting simultaneously — all receive live events, page loads stay responsive, no FrankenPHP crashes.

## Non-Goals

- Do not change the turn event model (Phases 1–5 infrastructure) — it works.
- Do not change `RunAgentChatJob` or `TurnStreamBridge` internal logic — they work.
- Do not change the serving stack (FrankenPHP stays; Reverb handles WebSocket out-of-process).
