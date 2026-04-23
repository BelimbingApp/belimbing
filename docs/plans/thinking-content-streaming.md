# Thinking Content Streaming

**Status:** Complete — all four phases implemented
**Last Updated:** 2026-04-12
**Sources:** `docs/todo/ai/lara-realtime-console.md`, [GPT-5.4 guide](https://developers.openai.com/api/docs/guides/latest-model), [Responses API streaming reference](https://platform.openai.com/docs/api-reference/responses-streaming), [Reasoning models guide](https://platform.openai.com/docs/guides/reasoning)

## Problem Essence

Lara's tool-calling loop uses sync LLM calls for intermediate iterations and only streams the final response. The model reasons and emits preambles at every iteration, but all of that content is discarded — the user sees a "Thinking…" placeholder and nothing else until the final answer.

## Desired Outcome

Reasoning content and preambles stream into the chat UI in real time at every iteration of the agentic loop, interleaved with tool calls — like a coding agent console:

```
Thinking (reasoning summary) → Preamble ("I'll search for…") → Tool Call 1
→ Thinking → Preamble → Tool Call 2 → Thinking → Answer
```

The user sees what the AI is thinking as it happens and can steer when reasoning goes off-track.

## Public Contract

- When using a reasoning model via the Responses API, the chat UI renders reasoning summary text and preambles as they stream, at every iteration of the agentic loop.
- Thinking entries appear chronologically in the activity feed, interleaved with tool call entries.
- Thinking content persists in the session transcript and survives page reloads.
- Non-reasoning models and Chat Completions API calls produce no thinking entries — silent no-op.

## Design Decisions

**Stream every iteration, not just the final one.** The current architecture uses sync `LlmClient::chat()` for intermediate tool-calling iterations. The Responses API emits reasoning summaries (`response.reasoning_summary_text.delta`) and preambles (`response.output_text.delta` within a `phase: "commentary"` message) only during streaming. To show thinking at every step, every iteration must use `LlmClient::chatStream()`.

**Three types of thinking content from GPT-5.4.** A single streaming response can emit, in order:
1. **Reasoning summary** — `response.reasoning_summary_text.delta` events (opt-in via `reasoning.summary: 'auto'`). Internal chain-of-thought summary.
2. **Preamble** — a `message` output item with `phase: "commentary"`, streamed as `response.output_text.delta`. The model explains its intent before calling tools ("I'll inspect the logs and then summarize root cause"). GPT-5.4 docs recommend prompting "Before you call a tool, explain why you are calling it."
3. **Final answer** — a `message` with `phase: "final_answer"`, streamed as `response.output_text.delta`.

We should render all three. Reasoning summaries and preambles both go into thinking entries; the final answer goes into the assistant response. However, internally the decoder must discriminate between reasoning summaries and commentary — both yield `thinking_delta` to the UI, but only commentary content belongs in `apiMessages` for subsequent iterations. Feeding reasoning summaries back to the model pollutes the conversation context.

**Track `phase` on output items to distinguish preamble from answer.** The `response.output_item.added` event carries the `phase` field on message items. The decoder must track which message item is currently streaming — if `phase: "commentary"`, text deltas are thinking content; if `phase: "final_answer"` (or no phase), they are assistant output.

**Preserve `phase` in conversation context.** GPT-5.4 docs warn: "dropping `phase` can degrade performance." When building `apiMessages` for subsequent iterations, commentary messages must carry `phase: "commentary"`. This affects `LlmClient::convertToResponsesInputWithInstructions()`.

**Multiple thinking entries in the feed.** Each agentic iteration that emits reasoning or preamble gets its own thinking entry, stacking chronologically between tool call entries.

**Responses API only.** Chat Completions API does not expose reasoning content or preambles.

**Streaming path only.** Phase 3 targets `runStreamingToolLoop()` (used by `runStream()`). The sync `runToolCallingLoop()` (used by `run()`) remains phase-blind. This keeps scope bounded — sync Responses API calls continue to work but don't surface thinking content. If sync phase awareness becomes needed, it can be added independently.

**Eliminate the double-final-call.** Today `runStreamingToolLoop()` runs sync iterations, then makes a *second* LLM call via `AgenticFinalResponseStreamer` to stream the final answer. Once every iteration streams, the iteration that produces no tool calls *is* the final answer — its streamed content goes directly to the UI. `AgenticFinalResponseStreamer` is removed from this path; run completion logic moves into the streaming loop itself.

## Top-Level Components

**`LlmResponsesDecoder`** — must track the `phase` of the currently-streaming message item. Text deltas within a `commentary` message yield `thinking_delta` instead of `content_delta`. Already handles `response.reasoning_summary_text.delta`. Thinking deltas carry a `source` discriminator (`'commentary'` vs `'reasoning_summary'`) so downstream consumers can distinguish them.

**`AgenticRuntime::runStreamingToolLoop()`** — the orchestrator. Must stream every iteration (not just the final one), yielding thinking deltas as they arrive while accumulating tool calls from the same stream. Emits a `thinking` status boundary at the start of each iteration for replay segmentation. Replaces the current sync-loop + `AgenticFinalResponseStreamer` pattern — the final iteration completes inline without a second LLM call.

**`LlmClient`** — `convertToResponsesInputWithInstructions()` must preserve `phase` on assistant messages when building Responses API input.

**`TurnStreamBridge` + `TurnEventPublisher`** — already maps `thinking_delta` to `AssistantThinkingDelta` turn events (Phase 1 work). No changes needed.

**Frontend (`chat.blade.php`)** — must support multiple thinking entries interleaved with tool calls instead of reusing a single entry. Phase label updates and deactivation must target the most recent entry, not the first.

## Phases

### Phase 1 — Streaming plumbing ✅

Infrastructure for thinking deltas through the full event pipeline. Only the final streaming response captures content.

- [x] Canonical execution controls carry reasoning visibility for Responses requests instead of a dedicated `ChatRequest.reasoningSummary` field
- [x] Responses request mapping emits `reasoning.summary` when canonical execution controls request summary visibility
- [x] `LlmResponsesDecoder` handles `response.reasoning_summary_text.delta` SSE events
- [x] `TurnEventType::AssistantThinkingDelta` enum case
- [x] `TurnEventPublisher.thinkingDelta()` method
- [x] `TurnStreamBridge` maps `thinking_delta` phase to publisher call
- [x] `AgenticFinalResponseStreamer` forwards `thinking_delta` events while the request mapper derives `reasoning.summary: 'auto'` from canonical execution controls
- [x] `ChatRunPersister` accumulates deltas, deferred flush
- [x] `MessageManager.appendThinking()` accepts content string
- [x] Thinking Blade component and stream entry template render content
- [x] Tests updated (enum count, persister mock signature)

### Phase 2 — Preamble-aware streaming decoder ✅

Teach `LlmResponsesDecoder` to distinguish preamble text from final-answer text using the `phase` field on message output items.

#### Goal

Text deltas within a `phase: "commentary"` message yield `thinking_delta` events with `source: 'commentary'`. Text deltas within a `phase: "final_answer"` (or no phase) message yield `content_delta` as before. Reasoning summary deltas continue to yield `thinking_delta` with `source: 'reasoning_summary'`. The `source` discriminator is transparent to the UI (both render as thinking) but critical for Phase 3: only commentary content is preserved in `apiMessages`.

- [x] `LlmResponsesDecoder::processSseEvent()` — on `response.output_item.added` with `item.type === 'message'`, capture `item.phase` into decoder state (new `&$currentMessagePhase` ref parameter)
- [x] `LlmResponsesDecoder::processSseEvent()` — on `response.output_text.delta`, check `$currentMessagePhase`: if `'commentary'`, yield `['type' => 'thinking_delta', 'text' => $delta, 'source' => 'commentary']`; otherwise yield `content_delta` as before
- [x] `LlmResponsesDecoder::processSseEvent()` — on `response.output_item.done` with `item.type === 'message'`, reset `$currentMessagePhase`
- [x] `response.reasoning_summary_text.delta` — update existing handler to yield `['type' => 'thinking_delta', 'text' => $delta, 'source' => 'reasoning_summary']`
- [x] Verify reasoning summary deltas still work independently of message phase
- [x] `TurnStreamBridge::onThinkingDelta()` — ignores `source`, forwards delta text only (no change needed if `source` is just extra metadata)

### Phase 3 — Stream every tool-loop iteration ✅

Replace sync `chatWithRetry()` calls with streaming calls in `runStreamingToolLoop()` so reasoning and preambles flow at every step. The sync `runToolCallingLoop()` is out of scope.

#### Goal

Every LLM call in the streaming agentic loop streams its response. Reasoning summary deltas and preamble text yield to the UI in real time. Tool calls are accumulated from the same stream and executed as before. The final iteration completes inline — no second LLM call via `AgenticFinalResponseStreamer`.

- [x] Emit `{'event': 'status', 'data': {'phase': 'thinking', ...}}` at the start of every iteration (not just iteration 0), so each iteration produces a `assistant.thinking_started` boundary for replay segmentation
- [x] Implement a streaming iteration method in `AgenticRuntime` that consumes `chatStream()`, yields `thinking_delta` events (both reasoning summary and commentary) to the outer generator, accumulates `tool_call_delta` and `content_delta` events internally, and returns the accumulated result (commentary, content, tool_calls, usage) when the stream completes
- [x] Replace the `chatWithRetry()` call in the `while (true)` loop with this streaming method
- [x] Ensure Responses API iterations request summary visibility through canonical execution controls at every iteration
- [x] Preserve the `appendAssistantToolCallMessage()` flow — tool calls must still be appended to `apiMessages` for context continuity; add optional `?string $phase` parameter
- [x] Preserve `phase` on assistant messages — when appending preamble content to `apiMessages`, set `phase: 'commentary'`; when appending final answer, set `phase: 'final_answer'`. Only `source: 'commentary'` thinking deltas contribute to the appended content — reasoning summaries are excluded from `apiMessages`
- [x] `LlmClient::convertToResponsesInputWithInstructions()` — preserve `phase` field on assistant messages when converting to Responses API input format
- [x] Remove `AgenticFinalResponseStreamer` from the streaming path — when the streaming iteration produces no tool calls, emit `delta` events for the final content and the `done` event with run metadata directly from `runStreamingToolLoop()`. Move `runRecorder->complete()` logic accordingly

#### Risks

- **Mid-stream failure.** Streaming calls may fail after thinking deltas have already been yielded and persisted. Retry is only safe if no persisted deltas were emitted for the current iteration. If deltas were already published, fail the turn rather than retrying (to avoid duplicate/incoherent thinking content).
- **Double-final-call.** If `AgenticFinalResponseStreamer` is not removed from this path, the model gets called twice for the final answer. The streaming iteration that discovers "no tool calls" must be the terminal one.

### Phase 4 — Multiple thinking entries in the UI ✅

The frontend currently reuses a single thinking entry. Change to stacking entries so they interleave with tool calls.

- [x] `onThinkingStarted()` — always push a new entry (do not reuse existing)
- [x] `onThinkingDelta()` — append to the last thinking entry in `streamEntries`
- [x] `deactivateThinking()` — deactivate only the last (most recent) thinking entry, not `.find()` which returns the first
- [x] `onPhaseChanged()` — when updating a thinking entry's description (e.g., "Analyzing X result"), target the last thinking entry, not the first found via `.find()`
- [x] `removeThinkingEntries()` — on turn completion, remove only empty/active-only entries; keep entries that have `thinkingContent`
- [x] Completion cleanup — all terminal paths (`turn.completed`, `turn.ready_for_input`, `_stream_complete`, normal EOF) must preserve non-empty thinking entries
- [x] Persisted replay — verify multiple thinking phases per turn work (each `ThinkingStarted` creates a new entry; deltas append to the most recent)
