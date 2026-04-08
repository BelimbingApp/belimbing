# Lara Real-Time Console [WIP]

## Problem Essence

Lara's chat UI should feel like a coding-agent CLI — the user sees what Lara is doing in real time: thinking, tool calls, streaming output; including errors and crashes (e.g. Go-level SIGSEGV after ~63s, runtime error, etc.).

## Goal

1. **Live activity feed:** The user sees Lara's work as it happens — thinking phases, tool calls with live stdout, assistant output streaming progressively.
2. **Thinking rendered in-place:** Reasoning/thinking content appears at the moment it occurs in the tool-calling loop.
3. **Multi-user concurrency:** Multiple browsers/users can chat with Lara simultaneously without blocking each other or crashing the server.

## Top-Level Requirement

The real-time transport must remain decoupled from Lara runtime and UI event semantics so the active transport can be swapped in the future without redesigning the core chat behavior.

## Decision To Make

Choose the primary real-time transport model for Lara chat: brokered WebSocket pub/sub (Reverb), long-lived HTTP streaming (SSE / persistent fetch with chunked transfer), Livewire's streaming, or build our own transport model.

### Connection Model

Decision point: should live updates use socket sessions or long-lived HTTP responses?

- **Reverb (WebSocket):** Stateful channel connection managed out-of-process by Reverb.
- **SSE / Persistent fetch:** Long-lived HTTP response per client stream.
- **Livewire `wire:stream`:** Component/request-scoped streaming response.
- **Custom transport:** Team defines and owns connection protocol and lifecycle.

### Delivery Semantics

Decision point: do we standardize on push + replay, or stream-only delivery?

- **Reverb (WebSocket):** Push on private channel, replay via sequence cursor (`after_seq`) from durable event log.
- **SSE / Persistent fetch:** Ordered stream bytes; replay and idempotency must be explicitly implemented.
- **Livewire `wire:stream`:** Streamed HTML/content updates in a component lifecycle, not a generalized event bus.
- **Custom transport:** Can support any semantic model, but protocol correctness is fully in-house.

### Scalability Pattern

Decision point: what concurrency shape must the transport support by default?

- **Reverb (WebSocket):** Better fan-out for many simultaneous listeners across active turns.
- **SSE / Persistent fetch:** Scales with one long-lived request per client.
- **Livewire `wire:stream`:** Best suited to narrow component streams, not high fan-out chat event distribution.
- **Custom transport:** Potentially optimal, but capacity behavior is unknown until built and load-tested.

### Reliability Model

Decision point: where do ordering, gap-fill, and reconnect guarantees live?

- **Reverb (WebSocket):** Durable turn-event log + live channel + replay cursor gives deterministic recovery.
- **SSE / Persistent fetch:** Recovery quality depends on keepalive, buffering behavior, and replay cursor discipline.
- **Livewire `wire:stream`:** Reliability is tied to request lifecycle; reconnect/gap-fill must be layered separately for chat-grade guarantees.
- **Custom transport:** Full control over guarantees, full responsibility for correctness.

### Operational Risk Profile

Decision point: prefer fewer moving parts or lower app-worker residency risk?

- **Reverb (WebSocket):** More components (broker, channel auth, payload limits), but fewer long-lived app-worker streams.
- **SSE / Persistent fetch:** Fewer components, but long-lived HTTP streams stay coupled to app worker capacity and proxy behavior.
- **Livewire `wire:stream`:** Simpler developer ergonomics for UI streaming, still HTTP-stream residency at runtime.
- **Custom transport:** Highest design/maintenance burden and largest unknown-failure surface.

### UX

Decision point: which model best preserves "coding-agent console" feel under real-world failures?

- **Reverb (WebSocket):** Strong live feel with low-latency updates plus replay backfill during attach races/reconnects.
- **SSE / Persistent fetch:** Good live output for single-stream flows; resilience under reconnect churn depends on custom recovery.
- **Livewire `wire:stream`:** Smooth for component-local progressive rendering, weaker fit for cross-session event timelines.
- **Custom transport:** Could be tailored precisely, but UX quality depends entirely on implementation maturity.
