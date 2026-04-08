# Lara Real-Time Console

## Problem Essence

Lara's chat UI should feel like a coding-agent CLI — the user sees what Lara is doing in real time: thinking, tool calls, streaming output; including errors and crashes.

## Status

Goals 1 and 2 are complete. Goal 3 (multi-user concurrency) is inherited from the transport design and has not been separately validated.

## Goal

1. **Live activity feed:** ✅ The user sees Lara's work as it happens — thinking phases, tool calls with live stdout, assistant output streaming progressively.
2. **Thinking rendered in-place:** ✅ Reasoning/thinking content streams into the chat at the moment it occurs in the tool-calling loop.
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

## Thinking Content Streaming

Reasoning summary text from the LLM streams into the chat UI in real time, rendered inline under the "Thinking…" label. The full content is persisted in the session transcript so it survives page reloads.

### Data Flow

```
OpenAI Responses API
  → response.reasoning_summary_text.delta (SSE)
  → LlmResponsesDecoder yields thinking_delta
  → AgenticFinalResponseStreamer emits status phase:thinking_delta
  → TurnStreamBridge → TurnEventPublisher.thinkingDelta()
  → persisted to ai_chat_turn_events (assistant.thinking_delta)
  → NDJSON to browser → Alpine appends to thinkingContent buffer
  → rendered as scrollable text block in the thinking entry
```

### Request Configuration

`AgenticFinalResponseStreamer` passes `reasoning.summary: 'auto'` in the `ChatRequest` when the API type is `OpenAiResponses`. This tells OpenAI to emit reasoning summary deltas during streaming.

### Persistence

`ChatRunPersister` accumulates `assistant.thinking_delta` payloads during transcript materialization and stores the full thinking content via `MessageManager.appendThinking()`. The thinking Blade component renders this content on page reload.

### Scope

- Works with the Responses API (`OpenAiResponses`) only. Chat Completions API does not expose reasoning content.
- Only reasoning models (o-series, gpt-5 with reasoning effort) emit summaries. Non-reasoning models skip this path silently — no errors, no empty entries.

### Touched Files

- `app/Base/AI/DTO/ChatRequest.php` — added `reasoningSummary` parameter
- `app/Base/AI/Services/LlmClient.php` — `reasoning` key in Responses payload
- `app/Base/AI/Services/LlmResponsesDecoder.php` — handles `response.reasoning_summary_text.*` events
- `app/Modules/Core/AI/Enums/TurnEventType.php` — `AssistantThinkingDelta` case
- `app/Modules/Core/AI/Services/TurnEventPublisher.php` — `thinkingDelta()` method
- `app/Modules/Core/AI/Services/TurnStreamBridge.php` — `thinking_delta` phase mapping
- `app/Modules/Core/AI/Services/AgenticFinalResponseStreamer.php` — forwards thinking deltas, sets `reasoningSummary`
- `app/Modules/Core/AI/Services/ChatRunPersister.php` — accumulates deltas, deferred flush
- `app/Modules/Core/AI/Services/MessageManager.php` — `appendThinking()` accepts content
- `resources/core/views/components/ai/activity/thinking.blade.php` — renders persisted thinking content
- `resources/core/views/livewire/ai/chat.blade.php` — `onThinkingDelta` handler, thinking content rendering in stream entries
