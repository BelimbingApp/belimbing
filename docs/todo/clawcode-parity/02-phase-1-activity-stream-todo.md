# Phase 1 — Activity Stream: Detailed Implementation TODO

**Parent:** `docs/todo/ai-run-ledger.md` §Phase 1
**Research:** `docs/todo/clawcode-parity/01-phase-1-activity-stream-ux-research.md`
**Prerequisite:** Phase 0 (`ai_runs` table, `RunRecorder`, transcript v2 schema) must land first — Phase 1 reads from those structures.

---

## Policy Decision (Resolve Before Coding)

The original `ai-run-ledger.md` §1.1 contains a contradiction: it opens with "all users get full visibility" but later says non-operator users should have tool/thinking entries hidden.

**Decision: Transparent by default.** One transcript, one timeline, same experience for all authorized viewers. No authz gating on activity stream visibility. This matches design decision §6 in `ai-run-ledger.md` and the research recommendation.

- [x] Remove the contradicting line from `ai-run-ledger.md` §1.1 — Already removed in prior work; design decision §6 "Transparent by default" is the authoritative statement
- [x] Confirm the single-timeline model in code: no role-based filtering of transcript entry types — Verified: no `isOperator`/`hideTools` filtering exists in AI module code

---

## 1. Transcript Entry Types & JSONL Schema (Backend Foundation) ✅

Extend the `Message` DTO and JSONL format to support typed activity entries. This is the data layer that everything else reads from.

### 1.1 Extend `Message` DTO ✅

**File:** `app/Modules/Core/AI/DTO/Message.php`

- [x] Add `type` property: `'message'` (default), `'tool_call'`, `'tool_result'`, `'thinking'`
- [x] `fromJsonLine()` reads `type` field, defaults to `'message'` for v1 backward compatibility
- [x] `toJsonLine()` writes `type` field when not `'message'` (keep v1 messages unchanged)
- [x] Keep `role`, `content`, `timestamp`, `runId`, `meta` unchanged — `type` is additive

### 1.2 Add `MessageManager` append methods for activity entries ✅

**File:** `app/Modules/Core/AI/Services/MessageManager.php`

- [x] `appendToolCall(employeeId, sessionId, runId, toolName, argsSummary, toolCallIndex)` — persists `{type: 'tool_call', role: 'assistant', content: '', meta: {tool, args_summary, tool_call_index}, run_id, timestamp}`
- [x] `appendToolResult(employeeId, sessionId, runId, toolName, resultPreview, resultLength, status, durationMs, errorPayload)` — persists `{type: 'tool_result', role: 'assistant', content: '', meta: {tool, result_preview, result_length, status, duration_ms, error_payload?}, run_id, timestamp}`
- [x] `appendThinking(employeeId, sessionId, runId)` — persists `{type: 'thinking', role: 'assistant', content: '', run_id, timestamp}`

**Redaction rules (from Phase 0 §0.8):**
- Tool call `args` are operational context — acceptable to persist as a summary (first 200 chars or key params)
- Tool `result` content is **not** persisted in full — only `result_length` and a truncated preview (≤200 chars)
- Never persist secrets, credentials, or full user content in tool entries

### 1.3 Version-aware read path ✅

**File:** `app/Modules/Core/AI/Services/MessageManager.php`

- [x] `read()` uses `KNOWN_ENTRY_TYPES` constant to validate types
- [x] v1 lines (no `type` field) → treated as `type: 'message'` (backward compatible)
- [x] v2 lines with `type` field → construct `Message` with correct type
- [x] Unknown `type` values → skip gracefully, never crash

---

## 2. Enrich SSE Events (Runtime → Stream Bridge) ✅

The runtime already emits `status` events with `phase` and `tool` name. Extend them to carry the data the activity stream needs to render persistent entries.

### 2.1 Extend `tool_started` event ✅

**File:** `app/Modules/Core/AI/Services/AgenticRuntime.php` — `runStreamingToolLoop()`

- [x] Add `args_summary` — first 200 chars of JSON-encoded arguments
- [x] Add `started_at` — ISO 8601 timestamp of tool execution start
- [x] Add `tool_call_index` — sequential index within this run (0, 1, 2...)

### 2.2 Extend `tool_finished` event ✅

- [x] Add `result_preview` — first 200 chars of tool result
- [x] Add `result_length` — full result string length
- [x] Add `duration_ms` — elapsed time since `tool_started`
- [x] Add `status` — `'success'` or `'error'` based on error_payload presence
- [x] Add `error_payload` (when status is error) — code, message, hint from the tool error

### 2.3 Track tool timing in the streaming loop ✅

- [x] Capture `$toolStartTime = hrtime(true)` before `executeToolCall()`
- [x] Compute `$durationMs = (int) ((hrtime(true) - $toolStartTime) / 1_000_000)` after execution
- [x] Maintain `$toolIndex` counter, increment per tool call in the loop

---

## 3. Persist Activity Entries During Streaming ✅

The `ChatStreamController` now persists thinking/tool_call/tool_result entries as they stream.

### 3.1 Persist thinking entry ✅

- [x] When `status.phase === 'thinking'`, call `$messageManager->appendThinking()`
- [x] Only persist once per run — tracked via `$thinkingPersisted` boolean

### 3.2 Persist tool call + tool result entries ✅

- [x] When `status.phase === 'tool_started'`, call `$messageManager->appendToolCall(...)` with enriched event data
- [x] When `status.phase === 'tool_finished'`, call `$messageManager->appendToolResult(...)` with enriched event data
- [x] Pass `$data['run_id']` from the event to link entries to the run

### 3.3 Extract `run_id` early for persistence ✅

- [x] The `run_id` is captured from the first status event and available for all subsequent persistence calls via `persistActivityEntry()`

---

## 4. Alpine.js State Machine (Frontend Foundation) ✅

Replaced the 3-variable stream state (`pendingMessage`, `streamingContent`, `streamingStatus`) with a typed entry array.

### 4.1 Replace stream state variables ✅

- [x] Replaced with `streamEntries[]`, `_currentRunId`, `followTail` boolean

### 4.2 Define entry shapes ✅

Entry types: `thinking`, `tool_call`, `assistant_streaming`, `error`

### 4.3 Rewrite SSE event handlers ✅

- [x] `status.thinking` → append thinking entry (once), set `active: true`
- [x] `status.tool_started` → append tool_call entry with `status: 'running'`
- [x] `status.tool_finished` → patch matching tool entry with result/duration/status
- [x] `delta` → find or create `assistant_streaming` entry, append text
- [x] `done` → finalize, trigger `$wire.finalizeStreamingRun()`
- [x] `error` → append error entry, finalize

### 4.4 Clear stream entries on response ready ✅

- [x] On `agent-chat-response-ready`: `streamEntries = []; pendingMessage = null; _currentRunId = null;`

---

## 5. Activity Stream Timeline Renderer (Blade Components) ✅

### 5.1 New Blade components ✅

**Directory:** `resources/core/views/components/ai/activity/`

- [x] **`entry.blade.php`** — Shell wrapper with icon and spacing
- [x] **`user-message.blade.php`** — Right-aligned user prompt display
- [x] **`thinking.blade.php`** — Low-emphasis row with pulse while active
- [x] **`tool-call.blade.php`** — Collapsible card: icon · tool name · args · duration/status badge · expand/collapse detail
- [x] **`assistant-result.blade.php`** — Full-width prose block with markdown + enriched message-meta
- [x] **`error.blade.php`** — Warning-styled card with error type and safe message

### 5.2 Replace persisted message rendering ✅

- [x] Branch on `$message->type` for thinking/tool_call/tool_result, then `$message->role` for user/assistant
- [x] Action messages (orchestration) kept as inline card
- [x] `@empty` state unchanged

### 5.3 Replace live stream rendering ✅

- [x] Single `<template x-for="entry in streamEntries">` loop renders type-appropriate UI
- [x] Optimistic user message kept as separate `<template x-if="pendingMessage">`
- [x] Loading dots shown only when `streamEntries.length === 0`

### 5.4 Visual hierarchy ✅

Implemented as specified: user bubble → thinking → tool cards → assistant prose → error cards

---

## 6. Follow-Tail Scroll Model ✅

### 6.1 Implement follow-tail logic ✅

- [x] Added `followTail` boolean state, default `true`
- [x] Scroll event listener on `agentScroll` container (throttled 100ms)
- [x] If user scrolls more than 50px above bottom → `followTail = false`
- [x] Conditional auto-scroll: only scroll when `followTail === true` via `x-effect`
- [x] `scrollToBottom()` respects `followTail` flag

### 6.2 "Jump to latest" floating control ✅

- [x] Floating button with down-arrow icon shown when `followTail === false`
- [x] Click → `followTail = true`, scroll to bottom
- [x] Auto-hide when `followTail === true`
- [x] Position: sticky at bottom of scroll container, centered
- [x] Accessible: `aria-label="{{ __('Jump to latest') }}"`

---

## 7. Run Metadata Popover (Replace Hover Tooltip) ✅

### 7.1 Replace tooltip with click popover ✅

- [x] Replaced `@mouseenter`/`@mouseleave` with `@click` toggle
- [x] Click-outside to dismiss: `@click.outside="popoverOpen = false"`
- [x] Keyboard accessible: `@keydown.escape`, `aria-expanded`
- [x] Run ID truncated to 8 chars, full ID shown in popover

### 7.2 Popover contents ✅

- [x] Token usage: prompt → completion tokens
- [x] Latency vs. timeout budget (e.g., "2.3s / 60s")
- [x] Retry attempts count
- [x] Fallback attempts count
- [x] Error type / message (when present, separated by border)
- [x] Run status display

### 7.3 Data source ✅

- [x] `buildMetaFromAiRun()` now includes `timeout_seconds` and `status` fields
- [x] `message-meta` component accepts new props: `tokens`, `latencyMs`, `timeoutSeconds`, `retryAttempts`, `fallbackAttempts`, `errorType`, `errorMessage`, `runStatus`
- [x] `assistant-result.blade.php` forwards all enriched props to `message-meta`
- [x] Chat.blade.php extracts and passes all meta fields from hydrated messages

### 7.4 Reuse patterns ✅

- [x] Compact popover format with label/value rows
- [x] Consistent with `run-detail.blade.php` data presentation
- [x] Semantic token colors for surfaces and borders

---

## 8. Streaming Markdown Quality ✅

### 8.1 Approach decision ✅

Chose **Option B: Visual framing as "drafting"**
- No JS markdown library available in the project (no marked/showdown/etc.)
- Option B is pragmatic: streaming text shown with "Writing…" indicator and slight opacity
- On `done`, Livewire re-render replaces with server-rendered markdown seamlessly — no flash

### 8.2 Implementation ✅

- [x] Streaming `assistant_streaming` entry shows text with `opacity-90`
- [x] Pulsing dot + "Writing…" label below streaming text
- [x] `x-text` for raw text display (safe, no XSS risk)
- [x] Server-rendered markdown replaces on finalize

---

## 9. Collapsible Tool Result Blocks ✅

### 9.1 Tool call card behavior ✅

- [x] **Header always visible:** icon · tool name · args summary (truncated 60 chars) · duration badge · status badge
- [x] **Detail collapsed by default:** expand on click, toggle `expanded` state
- [x] **Result preview in detail panel:** text + total char count when >200
- [x] **Error payload styled separately:** code, message display in red
- [x] **Keyboard accessible:** `<button>` for expand/collapse, `aria-expanded`

### 9.2 Live stream vs. persisted behavior ✅

- [x] During streaming: tool card starts in `status: 'running'` (pulsing dot), transitions to finished with result preview
- [x] Persisted: rendered from JSONL `tool_call` + `tool_result` entries, collapsed by default
- [x] Both server-side (`tool-call.blade.php`) and client-side (`x-for` template) implementations

---

## 10. Standalone Run Route (Deep-linking) ✅

### 10.1 Route and controller ✅

- [x] Route: `GET /admin/ai/runs/{runId}` → name `admin.ai.runs.show`
- [x] Access: employee ownership check (`AiRun.employee_id` must match current user's employee)
- [x] Returns 404 for non-existent runs or unauthorized access

### 10.2 Livewire page ✅

- [x] `RunDetail` Livewire component loads `RunInspection` DTO
- [x] Reuses `run-detail.blade.php` partial for full metadata display
- [x] Activity transcript: loads session messages filtered to run-related entries
- [x] Link back to Control Plane in page header

---

## File Change Summary

| File | Change |
|------|--------|
| `app/Modules/Core/AI/DTO/Message.php` | Add `type` property |
| `app/Modules/Core/AI/Services/MessageManager.php` | Add `appendToolCall()`, `appendToolResult()`, `appendThinking()`; version-aware `read()`; `buildMetaFromAiRun()` adds `timeout_seconds` + `status` |
| `app/Modules/Core/AI/Services/AgenticRuntime.php` | Enrich `tool_started`/`tool_finished` SSE events with args, timing, preview |
| `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php` | Persist thinking/tool_call/tool_result entries during streaming |
| `resources/core/views/livewire/ai/chat.blade.php` | Replace stream state with `streamEntries[]`; type-branching timeline; follow-tail scroll; "Jump to latest" button; drafting frame for streaming text |
| `resources/core/views/components/ai/message-meta.blade.php` | Replace hover tooltip with click popover; new props for tokens/latency/retries/errors |
| `resources/core/views/components/ai/activity/entry.blade.php` | New — timeline entry shell |
| `resources/core/views/components/ai/activity/user-message.blade.php` | New — user prompt display |
| `resources/core/views/components/ai/activity/thinking.blade.php` | New — thinking indicator |
| `resources/core/views/components/ai/activity/tool-call.blade.php` | New — collapsible tool call card |
| `resources/core/views/components/ai/activity/assistant-result.blade.php` | New — full-width prose result with enriched meta forwarding |
| `resources/core/views/components/ai/activity/error.blade.php` | New — error display card |
| `docs/todo/ai-run-ledger.md` | Remove visibility contradiction in §1.1 |

## Implementation Order

```text
1. Policy fix (§0) — remove contradiction in ai-run-ledger.md
2. DTO + persistence (§1) — Message.type, append methods, version-aware read
3. SSE enrichment (§2) — tool_started/tool_finished event payloads + timing
4. Stream persistence (§3) — ChatStreamController persists activity entries
5. Alpine state machine (§4) — replace 3-var state with typed entries array
6. Blade components (§5.1) — create activity/ component set
7. Timeline renderer (§5.2–5.3) — replace bubble loop with timeline, wire live stream
8. Follow-tail scroll (§6) — scroll model + jump-to-latest control
9. Run metadata popover (§7) — replace hover tooltip with click popover
10. Streaming markdown (§8) — close the x-text → rendered gap
11. Standalone run route (§10) — deep-link page for run inspection
```

Steps 2–4 are backend-only and can be tested independently. Steps 5–9 are frontend and should land together as the visual switchover. Step 10 is independent.
