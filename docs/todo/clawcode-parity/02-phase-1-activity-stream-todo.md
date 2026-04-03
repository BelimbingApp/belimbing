# Phase 1 — Activity Stream: Detailed Implementation TODO

**Parent:** `docs/todo/ai-run-ledger.md` §Phase 1
**Research:** `docs/todo/clawcode-parity/01-phase-1-activity-stream-ux-research.md`
**Prerequisite:** Phase 0 (`ai_runs` table, `RunRecorder`, transcript v2 schema) must land first — Phase 1 reads from those structures.

---

## Policy Decision (Resolve Before Coding)

The original `ai-run-ledger.md` §1.1 contains a contradiction: it opens with "all users get full visibility" but later says non-operator users should have tool/thinking entries hidden.

**Decision: Transparent by default.** One transcript, one timeline, same experience for all authorized viewers. No authz gating on activity stream visibility. This matches design decision §6 in `ai-run-ledger.md` and the research recommendation.

- [ ] Remove the contradicting line from `ai-run-ledger.md` §1.1 ("Non-operator users: tool/thinking entries hidden, only final response shown")
- [ ] Confirm the single-timeline model in code: no role-based filtering of transcript entry types

---

## 1. Transcript Entry Types & JSONL Schema (Backend Foundation)

Extend the `Message` DTO and JSONL format to support typed activity entries. This is the data layer that everything else reads from.

### 1.1 Extend `Message` DTO

**File:** `app/Modules/Core/AI/DTO/Message.php`

- [ ] Add `type` property: `'message'` (default), `'tool_call'`, `'tool_result'`, `'thinking'`
- [ ] `fromJsonLine()` reads `type` field, defaults to `'message'` for v1 backward compatibility
- [ ] `toJsonLine()` writes `type` field when not `'message'` (keep v1 messages unchanged)
- [ ] Keep `role`, `content`, `timestamp`, `runId`, `meta` unchanged — `type` is additive

```php
public function __construct(
    public string $role,
    public string $content,
    public DateTimeImmutable $timestamp,
    public ?string $runId = null,
    public array $meta = [],
    public string $type = 'message',  // new
) {}
```

### 1.2 Add `MessageManager` append methods for activity entries

**File:** `app/Modules/Core/AI/Services/MessageManager.php`

- [ ] `appendToolCall(employeeId, sessionId, runId, toolName, argsSummary, timestamp)` — persists `{type: 'tool_call', role: 'assistant', content: '', meta: {tool, args_summary}, run_id, timestamp}`
- [ ] `appendToolResult(employeeId, sessionId, runId, toolName, resultPreview, resultLength, status, durationMs, timestamp)` — persists `{type: 'tool_result', role: 'assistant', content: '', meta: {tool, result_preview, result_length, status, duration_ms}, run_id, timestamp}`
- [ ] `appendThinking(employeeId, sessionId, runId, timestamp)` — persists `{type: 'thinking', role: 'assistant', content: '', run_id, timestamp}`

**Redaction rules (from Phase 0 §0.8):**
- Tool call `args` are operational context — acceptable to persist as a summary (first 200 chars or key params)
- Tool `result` content is **not** persisted in full — only `result_length` and a truncated preview (≤200 chars)
- Never persist secrets, credentials, or full user content in tool entries

### 1.3 Version-aware read path

**File:** `app/Modules/Core/AI/Services/MessageManager.php`

- [ ] `read()` checks `transcript_version` from session meta (Phase 0 §0.8)
- [ ] v1 lines (no `type` field) → treated as `type: 'message'` (backward compatible)
- [ ] v2 lines with `type` field → construct `Message` with correct type
- [ ] Unknown `type` values → skip gracefully, never crash

---

## 2. Enrich SSE Events (Runtime → Stream Bridge)

The runtime already emits `status` events with `phase` and `tool` name. Extend them to carry the data the activity stream needs to render persistent entries.

### 2.1 Extend `tool_started` event

**File:** `app/Modules/Core/AI/Services/AgenticRuntime.php` — `runStreamingToolLoop()`

Current event (line ~656):
```php
yield ['event' => 'status', 'data' => [
    'phase' => 'tool_started',
    'tool' => $functionName,
    'run_id' => $runId,
]];
```

- [ ] Add `args_summary` — first 200 chars of JSON-encoded arguments (or key param names for common tools)
- [ ] Add `started_at` — ISO 8601 timestamp of tool execution start
- [ ] Add `tool_call_index` — sequential index within this run (0, 1, 2...)

```php
yield ['event' => 'status', 'data' => [
    'phase' => 'tool_started',
    'tool' => $functionName,
    'args_summary' => Str::limit(json_encode($arguments, JSON_UNESCAPED_SLASHES), 200),
    'tool_call_index' => $toolIndex,
    'started_at' => now()->toISOString(),
    'run_id' => $runId,
]];
```

### 2.2 Extend `tool_finished` event

**File:** `app/Modules/Core/AI/Services/AgenticRuntime.php` — `runStreamingToolLoop()`

Current event (line ~670):
```php
yield ['event' => 'status', 'data' => [
    'phase' => 'tool_finished',
    'tool' => $functionName,
    'run_id' => $runId,
]];
```

- [ ] Add `result_preview` — first 200 chars of tool result (already available as `$toolExecution['action']['result_preview']`)
- [ ] Add `result_length` — full result string length
- [ ] Add `duration_ms` — elapsed time since `tool_started`
- [ ] Add `status` — `'success'` or `'error'` based on `$toolExecution['action']['error_payload']`
- [ ] Add `error_payload` (when status is error) — code, message, hint from the tool error

```php
yield ['event' => 'status', 'data' => [
    'phase' => 'tool_finished',
    'tool' => $functionName,
    'result_preview' => $toolExecution['action']['result_preview'] ?? '',
    'result_length' => mb_strlen($resultString),
    'duration_ms' => $durationMs,
    'status' => isset($toolExecution['action']['error_payload']) ? 'error' : 'success',
    'error_payload' => $toolExecution['action']['error_payload'] ?? null,
    'run_id' => $runId,
]];
```

### 2.3 Track tool timing in the streaming loop

**File:** `app/Modules/Core/AI/Services/AgenticRuntime.php` — `runStreamingToolLoop()`

- [ ] Capture `$toolStartTime = hrtime(true)` before `executeToolCall()`
- [ ] Compute `$durationMs = (int) ((hrtime(true) - $toolStartTime) / 1_000_000)` after execution
- [ ] Maintain `$toolIndex` counter, increment per tool call in the loop

---

## 3. Persist Activity Entries During Streaming

The `ChatStreamController` currently only persists the final assistant message (on `done`) or a single error (on `error`). It must also persist tool call/result/thinking entries as they happen.

### 3.1 Persist thinking entry

**File:** `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php` — `streamRuntimeEvents()`

- [ ] When `status.phase === 'thinking'`, call `$messageManager->appendThinking(employeeId, sessionId, runId, now())`
- [ ] Only persist once per run — track a `$thinkingPersisted` boolean

### 3.2 Persist tool call + tool result entries

**File:** `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php` — `streamRuntimeEvents()`

- [ ] When `status.phase === 'tool_started'`, call `$messageManager->appendToolCall(...)` with data from the enriched event
- [ ] When `status.phase === 'tool_finished'`, call `$messageManager->appendToolResult(...)` with data from the enriched event
- [ ] Pass `$data['run_id']` from the event to link entries to the run

### 3.3 Extract `run_id` early for persistence

- [ ] The `run_id` is emitted with the first `status` event (thinking). Capture it in `streamRuntimeEvents()` so it's available for all subsequent persistence calls.

---

## 4. Alpine.js State Machine (Frontend Foundation)

Replace the current 3-variable stream state (`pendingMessage`, `streamingContent`, `streamingStatus`) with a typed entry array that the timeline renderer consumes.

### 4.1 Replace stream state variables

**File:** `resources/core/views/livewire/ai/chat.blade.php` — chat area `x-data`

Current state (line ~352):
```js
x-data="{
    pendingMessage: null,
    streamingContent: '',
    streamingStatus: null,
    _eventSource: null,
}"
```

- [ ] Replace with:

```js
x-data="{
    pendingMessage: null,
    streamEntries: [],        // typed activity entries during live stream
    _eventSource: null,
    _currentRunId: null,
    _toolStartTimes: {},      // track start for duration display
}"
```

### 4.2 Define entry shapes

Each entry in `streamEntries` is a plain object with a `type` field:

```js
// Thinking
{ type: 'thinking', timestamp: '...', active: true }

// Tool call (in progress)
{ type: 'tool_call', tool: 'web_search', argsSummary: '{"query":"..."}', status: 'running', startedAt: '...', index: 0 }

// Tool call (finished)
{ type: 'tool_call', tool: 'web_search', argsSummary: '...', status: 'success', startedAt: '...', durationMs: 450, resultPreview: '...', resultLength: 2340, expanded: false, index: 0 }

// Tool call (error)
{ type: 'tool_call', tool: 'web_search', argsSummary: '...', status: 'error', startedAt: '...', durationMs: 120, errorPayload: {code: '...', message: '...'}, expanded: false, index: 0 }

// Streaming assistant text
{ type: 'assistant_streaming', content: '...partial text...' }

// Error
{ type: 'error', message: '...', errorType: '...' }
```

### 4.3 Rewrite SSE event handlers

**File:** `resources/core/views/livewire/ai/chat.blade.php` — `agentChatComposer` Alpine data

- [ ] `status.thinking` → append `{ type: 'thinking', timestamp: now, active: true }`; deactivate any prior thinking entry
- [ ] `status.tool_started` → append `{ type: 'tool_call', tool, argsSummary, status: 'running', startedAt, index }`
- [ ] `status.tool_finished` → find the matching running tool entry by index, patch it with `status`, `durationMs`, `resultPreview`, `resultLength`, `errorPayload`
- [ ] `delta` → find or create the `assistant_streaming` entry, append `text` to its `content`
- [ ] `done` → finalize: clear `pendingMessage`, trigger `$wire.finalizeStreamingRun()`
- [ ] `error` → append `{ type: 'error', message }`, finalize

### 4.4 Clear stream entries on response ready

- [ ] On `agent-chat-response-ready` event: `streamEntries = []; pendingMessage = null; _currentRunId = null;`
- [ ] The persisted messages from Livewire re-render replace the live entries seamlessly

---

## 5. Activity Stream Timeline Renderer (Blade Components)

Replace the bubble-centric `@forelse` loop with a timeline that branches on entry type. Extract into deep components per `AGENTS.md` guidance.

### 5.1 New Blade components

**Directory:** `resources/core/views/components/ai/activity/`

- [ ] **`entry.blade.php`** — Shell wrapper: timestamp gutter, icon, spacing, tone. Props: `type`, `timestamp`, `tone`. Delegates to type-specific slot content.
- [ ] **`user-message.blade.php`** — User prompt display (right-aligned or prompt-style). Props: `content`, `timestamp`, `meta`.
- [ ] **`thinking.blade.php`** — Low-emphasis timestamped row with subtle pulse while `active`. Props: `timestamp`, `active`.
- [ ] **`tool-call.blade.php`** — Compact card: icon · tool name · args summary · duration/status badge. Collapsible details panel for result preview or error payload. Props: `tool`, `argsSummary`, `status`, `durationMs`, `resultPreview`, `resultLength`, `errorPayload`, `expanded`.
- [ ] **`assistant-result.blade.php`** — Full-width prose block with markdown rendering. Props: `content`, `markdown`, `timestamp`, `runId`, `provider`, `model`.
- [ ] **`error.blade.php`** — Warning-styled card with error type, safe message. Props: `message`, `errorType`, `runId`, `provider`, `model`.

### 5.2 Replace persisted message rendering

**File:** `resources/core/views/livewire/ai/chat.blade.php`

Current loop (line ~366):
```blade
@forelse($messages as $message)
    {{-- bubble branching on role and meta --}}
@empty
    {{-- empty state --}}
@endforelse
```

- [ ] Branch on `$message->type` instead of (or in addition to) `$message->role`:
  - `type === 'message' && role === 'user'` → `<x-ai.activity.user-message />`
  - `type === 'message' && role === 'assistant'` (with error meta) → `<x-ai.activity.error />`
  - `type === 'message' && role === 'assistant'` (with orchestration meta) → keep action card (existing)
  - `type === 'message' && role === 'assistant'` → `<x-ai.activity.assistant-result />`
  - `type === 'thinking'` → `<x-ai.activity.thinking :active="false" />`
  - `type === 'tool_call'` → `<x-ai.activity.tool-call />` (with data from meta)
  - `type === 'tool_result'` → merged into tool-call display (result is shown as collapsible detail of the preceding tool_call)
- [ ] Keep the `@empty` state unchanged

### 5.3 Replace live stream rendering

- [ ] Remove the three existing live-stream template blocks: optimistic user bubble, streaming content bubble, tool status indicator, and loading dots
- [ ] Replace with a single `<template x-for="entry in streamEntries">` loop that renders type-appropriate UI:
  - `entry.type === 'thinking'` → thinking component (with `active` pulse)
  - `entry.type === 'tool_call'` → tool-call card (running state shows spinner, finished shows result)
  - `entry.type === 'assistant_streaming'` → prose block with `x-text` (or incremental markdown, see §8)
  - `entry.type === 'error'` → error card
- [ ] Keep optimistic user message as a separate `<template x-if="pendingMessage">` before the stream entries

### 5.4 Visual hierarchy

```text
User prompt
└─ right-aligned bubble / prompt card

💭 Thinking
└─ subtle row, timestamp, low-emphasis pulse only while active

🔧 Tool call: web_search
└─ compact card: icon · tool name · args summary · [1.2s ✓]
   └─ ▶ expand: result preview (200 chars) or error detail

🔧 Tool call: create_file
└─ compact card: icon · tool name · args summary · [0.3s ✓]

✅ Assistant result
└─ full-width prose block with markdown, run pill + message-meta

❌ Error
└─ warning-styled card with error type, safe message, run meta
```

---

## 6. Follow-Tail Scroll Model

Replace the unconditional auto-scroll with a scroll-respectful model.

### 6.1 Implement follow-tail logic

**File:** `resources/core/views/livewire/ai/chat.blade.php`

Current auto-scroll (line ~364):
```blade
x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
x-effect="$nextTick(() => $refs.agentScroll.scrollTop = $refs.agentScroll.scrollHeight)"
```

- [ ] Add `followTail` boolean state, default `true`
- [ ] Add scroll event listener on `agentScroll` container:
  - If user scrolls more than ~50px above the bottom → `followTail = false`
  - If user scrolls to within ~20px of bottom → `followTail = true`
- [ ] Conditional auto-scroll: only scroll to bottom when `followTail === true` and new entries arrive
- [ ] Throttle/debounce scroll event handler to avoid performance issues

### 6.2 "Jump to latest" floating control

- [ ] Show a small floating button (e.g., down-arrow) when `followTail === false` and there is new content below
- [ ] Click → `followTail = true`, scroll to bottom
- [ ] Auto-hide when `followTail === true`
- [ ] Position: fixed at the bottom of the scroll container, centered
- [ ] Accessible: `aria-label="{{ __('Jump to latest') }}"`

---

## 7. Run Metadata Popover (Replace Hover Tooltip)

Convert the run ID hover tooltip into a click-triggered, keyboard-accessible popover with rich metadata.

### 7.1 Replace tooltip with click popover

**File:** `resources/core/views/components/ai/message-meta.blade.php`

Current run ID affordance (line ~59–83): hover tooltip that only shows "Run ID".

- [ ] Replace `@mouseenter`/`@mouseleave` with `@click` toggle
- [ ] Use click-outside to dismiss: `@click.outside="popoverOpen = false"`
- [ ] Keep keyboard accessible: `@keydown.escape="popoverOpen = false"`, `@keydown.enter="popoverOpen = !popoverOpen"`
- [ ] Add `aria-expanded` attribute bound to popover state

### 7.2 Popover contents

- [ ] Token usage: prompt tokens / completion tokens
- [ ] Latency vs. timeout budget (e.g., "2.3s / 60s")
- [ ] Retry attempts count + detail (provider, error, latency per attempt)
- [ ] Fallback attempts count + detail
- [ ] Error type / diagnostic summary (on failure)
- [ ] Link to standalone run page (Phase 1 §1.3, if available): `<a href="{{ route('admin.ai.runs.show', $runId) }}">`

### 7.3 Data source

- [ ] Phase 0 delivers `ai_runs` with all metadata. `MessageManager::read()` batch-queries `ai_runs` to hydrate message meta from DB (Phase 0 §0.7)
- [ ] The `message-meta` component receives enriched data as props — no inline DB queries
- [ ] Props to add: `tokens` (array), `latencyMs`, `timeoutSeconds`, `retryAttempts` (array), `fallbackAttempts` (array), `errorType`, `errorMessage`

### 7.4 Reuse patterns from existing surfaces

- [ ] Borrow metadata formatting from `control-plane/partials/run-detail.blade.php` (tokens grid, fallback attempt list, error detail block)
- [ ] Keep the popover compact — it's an inline summary, not the full run-detail page
- [ ] Use `x-ui.badge` for status/outcome within the popover

---

## 8. Streaming Markdown Quality

Close the gap between raw `x-text` during streaming and rendered markdown after save.

### 8.1 Approach decision

Two options (from research §3.6):

**Option A (preferred): Incremental markdown rendering**
- Render streaming assistant text through the markdown renderer in a debounced loop
- Scope: only the active streaming assistant block, not the full page
- Debounce: render at most every 100–150ms to avoid heavy reflows
- Preserve code block stability and scroll position

**Option B (acceptable first step): Visual framing as "drafting"**
- Keep `x-text` for streaming, but visually frame it as a drafting state
- On `done`, swap seamlessly to rendered markdown without reflow shock
- Lower implementation cost, still removes the jarring transition

- [ ] Choose approach (recommend Option A for a coding-agent experience)
- [ ] If Option A: evaluate lightweight JS markdown renderers already available in the project (check `package.json`)
- [ ] If Option A: implement debounced rendering in the `assistant_streaming` entry template
- [ ] If Option B: add visual "drafting" indicator and smooth transition to rendered markdown

### 8.2 Implementation notes for Option A

- [ ] Use a dedicated `<div>` with `x-html` bound to a computed rendered output
- [ ] Debounce the render function (100–150ms) using `setTimeout`/`clearTimeout`
- [ ] Handle incomplete markdown gracefully (unclosed code fences, partial lists)
- [ ] On `done`, the Livewire re-render replaces the stream entry with the server-rendered markdown — ensure no visible flash

---

## 9. Collapsible Tool Result Blocks

Tool results should be progressive-disclosure cards, not full inline dumps.

### 9.1 Tool call card behavior

**File:** `resources/core/views/components/ai/activity/tool-call.blade.php`

- [ ] **Header always visible:** icon · tool name · args summary (truncated) · duration badge · status badge
- [ ] **Preview visible by default:** first line or short preview (≤200 chars) + total result length
- [ ] **Full content collapsed:** expand on click, toggle `expanded` state
- [ ] **Error payload styled separately:** code, message, hint, setup action if present
- [ ] **Keyboard accessible:** `<button>` for expand/collapse, Enter/Space to toggle, `aria-expanded`

### 9.2 Live stream vs. persisted behavior

- [ ] During streaming: tool card starts in `status: 'running'` (subtle spinner), transitions to finished with result preview
- [ ] Persisted: rendered from JSONL `tool_call` + `tool_result` entries, always in finished state, collapsed by default

---

## 10. Standalone Run Route (Deep-linking)

For sharing runs via alerts, audit trails, or cross-referencing.

### 10.1 Route and controller

- [ ] Route: `GET /admin/ai/runs/{runId}` → name `admin.ai.runs.show`
- [ ] Access: session ownership check (Lara: per-user isolation; supervised agents: supervisor check)
- [ ] Not a separate capability — reuse existing `assertCanAccessAgent` pattern

### 10.2 Livewire page

- [ ] Lightweight page showing full run metadata (reuse/adapt `run-detail.blade.php` partial)
- [ ] Full activity timeline for the run: query transcript entries by `run_id`
- [ ] Link back to the parent session/chat

---

## File Change Summary

| File | Change |
|------|--------|
| `app/Modules/Core/AI/DTO/Message.php` | Add `type` property |
| `app/Modules/Core/AI/Services/MessageManager.php` | Add `appendToolCall()`, `appendToolResult()`, `appendThinking()`; version-aware `read()` |
| `app/Modules/Core/AI/Services/AgenticRuntime.php` | Enrich `tool_started`/`tool_finished` SSE events with args, timing, preview |
| `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php` | Persist thinking/tool_call/tool_result entries during streaming |
| `resources/core/views/livewire/ai/chat.blade.php` | Replace stream state with typed entries; replace bubble loop with timeline; follow-tail scroll |
| `resources/core/views/components/ai/message-meta.blade.php` | Replace hover tooltip with click popover |
| `resources/core/views/components/ai/activity/entry.blade.php` | New — timeline entry shell |
| `resources/core/views/components/ai/activity/user-message.blade.php` | New — user prompt display |
| `resources/core/views/components/ai/activity/thinking.blade.php` | New — thinking indicator |
| `resources/core/views/components/ai/activity/tool-call.blade.php` | New — collapsible tool call card |
| `resources/core/views/components/ai/activity/assistant-result.blade.php` | New — full-width prose result |
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
