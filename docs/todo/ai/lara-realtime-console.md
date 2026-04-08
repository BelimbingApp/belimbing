# Lara Real-Time Console

## Problem Essence

Lara's chat UI should feel like a coding-agent CLI — the user sees what Lara is doing in real time: thinking, tool calls, streaming output; including errors and crashes (e.g. Go-level SIGSEGV after ~63s, runtime error, etc.).

## Goal

1. **Live activity feed:** The user sees Lara's work as it happens — thinking phases, tool calls with live stdout, assistant output streaming progressively.
2. **Thinking rendered in-place:** Reasoning/thinking content appears at the moment it occurs in the tool-calling loop.
3. **Multi-user concurrency:** Multiple browsers/users can chat with Lara simultaneously without blocking each other or crashing the server.

## Top-Level Requirement

The real-time transport must remain decoupled from Lara runtime and UI event semantics so the active transport can be swapped in the future without redesigning the core chat behavior.

## Decision

Lara now uses direct HTTP streaming for fresh turns plus persisted-event replay for resume and gap-fill. Reverb is no longer part of the Lara runtime path.

### Runtime Path

- Fresh turns run inside the streaming response and emit NDJSON chunks directly to the browser.
- Each turn event is also persisted to `ai_chat_turn_events` as the durable source of truth.
- The chat UI renders the streamed events immediately without any brokered transport.

### Recovery Path

- Page reloads and disconnects recover through `after_seq` replay from the durable event log.
- Active resumed turns use replay plus short-interval polling for gap fill.
- Ordering remains anchored to the persisted `(turn_id, seq)` stream, not the transport.

### Why This Direction

- It keeps the live path simple: one response, one event envelope, no broker.
- It removes payload-size constraints and TLS/channel-auth failure modes from Lara chat.
- It preserves deterministic recovery because the DB event log remains authoritative.

### Operational Profile

- This design consumes one long-lived application response per active fresh turn.
- That is acceptable for the current initialization-phase concurrency target.
- If concurrency pressure changes later, the transport can still be swapped because the runtime event model and persisted replay contract stay unchanged.

### UX Contract

- The user sees Lara's work as it happens during a fresh turn.
- If the page is reloaded or the connection drops, the UI rebuilds from persisted events and continues tailing via replay polling.
- The console semantics stay transport-agnostic because the client always consumes the same canonical event envelope.
