# Lara Real-Time Console

## Problem Essence

Lara's chat UI should feel like a coding-agent CLI — the user sees what Lara is doing in real time: thinking, tool calls, streaming output; including errors and crashes.

## Status

Goal 1 complete. Goal 2 in progress — see `docs/plans/thinking-content-streaming.md`. Goal 3 inherited from the transport design, not separately validated.

## Goal

1. **Live activity feed:** ✅ The user sees Lara's work as it happens — thinking phases, tool calls with live stdout, assistant output streaming progressively.
2. **Thinking rendered in-place:** 🔧 Plan: `docs/plans/thinking-content-streaming.md`
3. **Multi-user concurrency:** Multiple browsers/users can chat with Lara simultaneously without blocking each other or crashing the server.

## Transport Design

Lara uses direct HTTP streaming for fresh turns plus persisted-event replay for resume and gap-fill. Reverb is not part of the Lara runtime path.

The real-time transport is decoupled from runtime and UI event semantics so it can be swapped without redesigning core chat behavior.

### Runtime Path

- Fresh turns run inside the streaming response and emit NDJSON chunks directly to the browser.
- Each turn event is also persisted to `ai_chat_turn_events` as the durable source of truth.
- The chat UI renders the streamed events immediately without any brokered transport.

### Recovery Path

- Page reloads and disconnects recover through `after_seq` replay from the durable event log.
- Active resumed turns use replay plus short-interval polling for gap fill.
- Ordering remains anchored to the persisted `(turn_id, seq)` stream, not the transport.

### Why This Direction

- One response, one event envelope, no broker.
- No payload-size constraints or TLS/channel-auth failure modes.
- Deterministic recovery because the DB event log is authoritative.
- Consumes one long-lived application response per active fresh turn — acceptable for current concurrency targets. Transport can be swapped later without changing the event model.
