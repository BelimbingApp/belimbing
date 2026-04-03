# Phase 1 Activity Stream UX Research

## Executive Summary

`docs/todo/ai-run-ledger.md` is correct that the current chat UX hides too much of the runtime behind a loading bubble, but the implementation gap is larger than "replace bubbles with a stream". The live UI currently shows only three transient states—optimistic user echo, a plain-text streaming assistant bubble, and a single-line status chip—while the durable transcript persists only the final assistant/error message, not the runtime steps that produced it.[^1][^2][^3] That means users cannot inspect tool sequences, understand where a run stalled, or keep reading earlier content without being forced back to the bottom of the conversation.[^1]

The biggest UX wins are therefore structural: move from message bubbles to a **timeline-first view model**, persist the same typed entries that the UI renders, replace hover-only run metadata with click-based inline popovers, and stop treating tool/thinking events as ephemeral text strings.[^4][^5] Phase 1 should also resolve its own policy contradiction: the section opens by saying "all users get full visibility" but later says non-operator users should have tool/thinking entries hidden.[^4]

## 1. Current UX audit

### 1.1 The chat surface is still bubble-first, not runtime-first

The main chat view renders user and assistant messages as classic left/right bubbles with separate branches for assistant errors and orchestration actions.[^1] While streaming, the UI does **not** append persistent runtime entries; it shows a single transient status string (`Thinking...` or `🔧 tool`) and, once text starts arriving, swaps into a plain-text assistant bubble.[^1] This directly conflicts with Phase 1's stated goal that the conversation itself should become the inspection surface.[^4]

In practice, the user sees:

1. an optimistic user bubble,
2. maybe a tiny status chip,
3. then a plain streaming response bubble,
4. then the final persisted message after Livewire refresh.[^1]

That flow hides the tool-call timeline almost completely, which is why timeouts and long-running tasks still feel like a spinner problem rather than an inspectable process.[^1][^4]

### 1.2 The live stream loses fidelity compared to the final rendered message

Persisted assistant messages are rendered through the markdown renderer, but the live streaming bubble uses `x-text`, so the user sees raw plain text while the run is live and only gets rich formatting after the message is saved and re-rendered.[^1] This creates a jarring before/after transition: code blocks, lists, and emphasis are invisible while the answer is streaming even though they appear moments later in the final transcript.[^1]

For a coding-agent-style experience, this is a major UX gap. The stream should feel like the final answer gradually materializing, not like a temporary degraded preview.[^1][^4]

### 1.3 Auto-scroll currently overrides user intent

The chat scroll container uses `x-effect` to force `scrollTop = scrollHeight` after every reactive change.[^1] That is acceptable for a simple chat, but it is hostile to a timeline/inspection UX because the moment a user scrolls up to inspect earlier tool results, the next delta or status event snaps them back to the bottom.[^1]

Once Phase 1 introduces more runtime entries, this will become more disruptive, not less. A transparent activity stream needs a **follow-tail mode** that pauses auto-scroll when the user scrolls away from the bottom and offers an explicit "jump to latest" affordance instead.[^1]

### 1.4 Runtime metadata is technically present, but discoverability is weak

The inline `message-meta` component currently exposes timestamp, provider/model, and run ID, but the run ID only opens a hover tooltip that says "Run ID"; it does not expose tokens, latency, retries, fallback attempts, or diagnostics.[^5] The richer metadata surfaces already exist elsewhere—there is a debug panel in the AI playground and a manual run-inspection panel in the operator control plane—but those are separate destinations, not part of the day-to-day chat UX.[^6][^7]

That split produces a poor operator flow:

1. the normal chat hides most runtime facts,
2. the playground shows metadata but is not the real end-user surface,
3. the control plane is powerful but requires manual lookup by IDs.[^6][^7]

Phase 1's run popover and standalone run route are therefore not optional polish; they are the missing bridge between chat and inspection.[^4]

### 1.5 The current stream state is too coarse to explain long tasks

The streaming client only reacts to `thinking`, `tool_started`, `tool_finished`, `delta`, `done`, and `error` events.[^2][^3] Tool status currently shows just the tool name, without arguments, duration, preview, or result summary, and `ChatStreamController` persists only the final assistant message on `done` or a single structured error message on `error`.[^2][^3]

This means the UI loses exactly the information the user needs during a long task:

1. what arguments the tool was called with,
2. whether a tool returned useful output or an error payload,
3. how many tools have already run,
4. where the run spent time.[^2][^3][^4]

### 1.6 Phase 1 currently contains a visibility-policy contradiction

At the start of Phase 1, the doc says the activity stream should replace bubbles and that **all users get full visibility**.[^4] Later in the same section, transcript persistence says non-operator users should have tool/thinking entries hidden.[^4] Those two rules cannot both be true.

This is not just a documentation nit. It changes the shape of the transcript, the read path, the UI branching, and the mental model of the product:

1. **Transparent-by-default policy:** one transcript, one timeline, same experience for all authorized viewers.
2. **Operator-only internals policy:** transcript stores everything, but normal viewers receive a filtered projection.[^4]

Phase 1 implementation should choose one model before coding begins. From a UX-coherence standpoint, the first model is cleaner because it aligns the timeline, persistence, and inspection model into one surface.[^4]

## 2. UX principles that should shape the implementation

The repository's UI guidance already points in the right direction: use existing `x-ui.*` primitives, prefer skeletons over spinners, maintain keyboard accessibility, avoid hover-only critical interactions, and keep dense information readable and responsive.[^8] Phase 1 should be implemented as a **deep component system**, not as a large conditional blob inside `chat.blade.php`, because the activity stream introduces repeated patterns that will otherwise sprawl quickly.[^8]

The key principles should be:

1. **Timeline-first, not bubble-first** — entries represent runtime events, not just chat roles.[^4]
2. **Durable parity between live state and persisted state** — if the user saw it live, they should be able to inspect it later.[^2][^3][^4]
3. **Progressive disclosure** — show tool name/summary inline, details on expand, full diagnostics in popover or run page.[^4][^5]
4. **Scroll respect** — follow the tail by default, but never steal scroll when the user is inspecting history.[^1]
5. **Keyboard-first inspection** — expand/collapse, popovers, and run links must all work without hover.[^5][^8]

## 3. Proposed UI/UX implementation blueprint

## 3.1 Replace the bubble renderer with a typed entry timeline

The current `@forelse($messages as $message)` loop should evolve into a timeline renderer that branches on entry type instead of only on chat role.[^1][^4] The Phase 1 transcript contract already suggests the right entry types: `message`, `tool_call`, `tool_result`, `thinking`, and error/result states.[^4]

Recommended component split:

1. `x-ai.activity-entry` — shell wrapper for timestamp, icon, alignment, spacing, and tone.
2. `x-ai.activity-user-message` — current right-aligned prompt bubble, slightly simplified.
3. `x-ai.activity-thinking` — low-emphasis, timestamped progress row.
4. `x-ai.activity-tool-call` — card with tool name, args summary, duration/status badge, disclosure toggle.
5. `x-ai.activity-tool-result` — collapsible details panel, syntax-safe plain text or markdown preview depending on tool.
6. `x-ai.activity-assistant-result` — final answer block with markdown rendering.
7. `x-ai.run-popover` — metadata surface anchored from the run pill.[^4][^8]

That keeps `chat.blade.php` focused on layout and state wiring while moving the visual semantics into reusable deep components, which matches the repository's component-first guidance.[^8]

### Suggested visual hierarchy

```text
User prompt
└─ right-aligned bubble / prompt card

Thinking
└─ subtle row, timestamp, low-emphasis pulse only while active

Tool call
└─ compact card header: icon · tool name · args summary · duration/status
   └─ disclosure: preview/result/error payload

Assistant result
└─ full-width prose block with markdown, run pill, metadata popover

Error
└─ warning-styled card with error type, safe message, retry/fallback summary
```

## 3.2 Replace ephemeral stream strings with a local entry state machine

The Alpine state in `chat.blade.php` currently tracks only `pendingMessage`, `streamingContent`, and `streamingStatus`.[^1] That is too small for an activity stream. Replace it with a local `entries` array plus a small state machine:

```text
idle
 -> optimistic_user
 -> thinking
 -> tool_call_started
 -> tool_call_finished / tool_call_failed
 -> assistant_streaming
 -> done | error
```

Each SSE event should append or update a typed entry instead of rewriting a single status string.[^2][^3][^4] Concretely:

1. `thinking` => append `thinking` entry if not already active.
2. `tool_started` => append `tool_call` entry with tool name, args summary, start time.
3. `tool_finished` => patch the active tool entry with finish time, preview, status, result length.
4. `delta` => append to the current `assistant_streaming` entry.
5. `done` => finalize the streaming assistant entry and hand off to persisted transcript state.
6. `error` => finalize a typed error entry with message, error type, and any retry/fallback context.[^2][^3]

This will also make it trivial to support background/progress phases later, because the UI will already be event-driven instead of bubble-driven.[^4]

## 3.3 Persist what the user saw

Today `ChatStreamController` only persists the final assistant message or a single error message.[^3] That is the core persistence gap. The UX-safe rule should be:

> Every runtime entry rendered in the timeline must have a durable transcript representation or a clearly explained reason why it is intentionally ephemeral.

For Phase 1, that means persisting:

1. `thinking` start/stop entries,
2. `tool_call` with tool name and redacted args summary,
3. `tool_result` with preview, status, and length,
4. final assistant result,
5. typed error entries.[^4]

The persistence format can still remain JSONL, but the UI should stop depending on side-channel state to reconstruct the visible run history.[^4]

## 3.4 Upgrade the run metadata affordance from hover tooltip to click popover

The current run-id affordance is a hover tooltip that only labels the pill as "Run ID".[^5] Phase 1 should turn that into a click-triggered, keyboard-accessible popover with:

1. prompt/completion tokens,
2. latency vs timeout budget,
3. retry attempts,
4. fallback attempts,
5. error type / diagnostic summary,
6. link to the standalone run page.[^4]

Two implementation notes follow directly from the current codebase:

1. Use **click**, not hover, because this is critical inspection content, not decorative hint text.[^5][^8]
2. Reuse presentation ideas from the playground's existing fallback-attempt disclosure and the control-plane's run-detail partial so metadata formatting stays consistent across surfaces.[^6][^7]

## 3.5 Reuse existing run-detail work, but invert the information architecture

The control-plane run detail already has a reasonable metadata breakdown: outcome, provider/model, latency, tokens, retries, fallback attempts, tool actions, and error details.[^7] The UX problem is not the absence of data structure; it is that the structure lives in the wrong place for routine chat use.[^7]

Recommended reuse pattern:

1. **Chat popover:** compact slice of run details.
2. **Standalone run page:** full metadata + full activity timeline.
3. **Control plane:** cross-session/operator aggregation, not the primary place to understand a single normal run.[^4][^7]

That keeps the operator tooling but removes the need for users to leave the chat just to understand what happened.[^4][^7]

## 3.6 Preserve markdown quality while streaming

Because the live stream currently uses `x-text`, the streamed answer is visually worse than the final saved answer.[^1] Phase 1 should close that gap with one of two approaches:

1. **Preferred:** incremental markdown rendering in a dedicated assistant-result component.
2. **Acceptable first step:** keep plain text while streaming, but visually frame it as a "drafting" state and swap seamlessly to rendered markdown without reflow shock.[^1]

If the preferred option is chosen, scope it carefully:

1. render only the active streaming assistant block,
2. debounce rendering to avoid heavy reflows,
3. preserve code block stability and scroll position.[^8]

## 3.7 Add scroll-follow discipline

The current unconditional auto-scroll should be replaced with:

1. a `followTail` boolean that starts `true`,
2. automatic switch to `false` when the user scrolls above a threshold,
3. a floating "Jump to latest" control while `followTail === false`,
4. automatic re-enable when the user clicks that control or reaches the bottom again.[^1]

This matters more in an activity stream than in a bubble chat because users will routinely inspect earlier tool cards while the run continues.[^1][^4]

## 3.8 Surface tool results as collapsible cards, not full inline dumps

Phase 1 already says tool result blocks should be collapsible.[^4] That is the right default because raw tool outputs can be long, repetitive, or noisy. The UX target should be:

1. **Header always visible:** tool, short args summary, status, duration.
2. **Preview visible by default:** first line / short preview / result length.
3. **Full content collapsed:** expand on demand.
4. **Error payload styled separately:** code, message, hint, setup action if present.[^2][^4]

Belimbing already records `tool_actions` with previews and error payloads at runtime; the UI should capitalize on that instead of flattening tool execution to a single transient label.[^2]

## 4. Priority recommendations

| Priority | UX change | Why it matters |
|---|---|---|
| P0 | Resolve transparency policy contradiction in Phase 1 | Avoid building the wrong transcript/read-path model.[^4] |
| P0 | Replace `streamingStatus` / `streamingContent` with typed `entries[]` state | Necessary foundation for real activity stream UX.[^1][^2] |
| P0 | Persist `thinking`, `tool_call`, and `tool_result` entries | Without this, the stream remains non-durable.[^3][^4] |
| P1 | Replace hover run tooltip with click popover | Current metadata affordance is too weak for inspection.[^5] |
| P1 | Add follow-tail scroll model | Prevent timeline UX from fighting the user.[^1] |
| P1 | Stream markdown-quality assistant rendering | Remove the "raw while live, rich after refresh" mismatch.[^1] |
| P2 | Ship standalone run page reusing run-detail data | Best deep-link/share surface after inline popover.[^4][^7] |

## 5. Recommended file-level implementation plan

1. **`resources/core/views/livewire/ai/chat.blade.php`**
   - replace transient stream state with typed activity entries,
   - remove unconditional auto-scroll,
   - replace bubble-centric branching with entry-type rendering.[^1]
2. **`resources/core/views/components/ai/message-meta.blade.php`**
   - convert run-id tooltip into click popover,
   - keep timestamp tooltip secondary, not primary.[^5]
3. **`app/Modules/Core/AI/Http/Controllers/ChatStreamController.php`**
   - persist streamed runtime entries, not just final assistant/error messages.[^3]
4. **`app/Modules/Core/AI/Services/AgenticRuntime.php`**
   - enrich SSE `status` events with args summary, preview, and timing where available.[^2][^3][^4]
5. **New Blade components under `resources/core/views/components/ai/`**
   - extract timeline entry primitives to avoid `chat.blade.php` becoming an unreadable conditional tree.[^8]

## Confidence Assessment

**Certain**

- The current chat UX is still bubble-driven, uses plain-text live streaming, forces bottom scroll, and exposes only minimal inline run metadata.[^1][^5]
- The streaming backend currently provides only coarse status phases and persists only final assistant/error messages.[^2][^3]
- The TODO's Phase 1 visibility rules are internally inconsistent and need resolution before implementation.[^4]

**Strong inference**

- Reusing the playground/control-plane metadata patterns will reduce implementation risk because those surfaces already normalize retries, fallback attempts, latency, tokens, and tool-action summaries in the same codebase.[^6][^7]
- A typed-entry Alpine state model is the cleanest path because the present `pendingMessage` / `streamingStatus` / `streamingContent` model is too limited to support a durable activity timeline.[^1][^2]

## Footnotes

[^1]: `/home/kiat/repo/laravel/blb/resources/core/views/livewire/ai/chat.blade.php:350-477, 523-637`.
[^2]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Services/AgenticRuntime.php:587-704, 722-804`.
[^3]: `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Http/Controllers/ChatStreamController.php:67-86, 99-131, 184-219`.
[^4]: `/home/kiat/repo/laravel/blb/docs/todo/ai-run-ledger.md:219-283`.
[^5]: `/home/kiat/repo/laravel/blb/resources/core/views/components/ai/message-meta.blade.php:14-95`.
[^6]: `/home/kiat/repo/laravel/blb/resources/core/views/livewire/admin/ai/playground.blade.php:163-217`; `/home/kiat/repo/laravel/blb/app/Modules/Core/AI/Livewire/Playground.php:126-141`.
[^7]: `/home/kiat/repo/laravel/blb/resources/core/views/livewire/admin/ai/control-plane.blade.php:13-20, 45-107`; `/home/kiat/repo/laravel/blb/resources/core/views/livewire/admin/ai/control-plane/partials/run-detail.blade.php:10-115`.
[^8]: `/home/kiat/repo/laravel/blb/resources/core/views/AGENTS.md:25-35, 104-130, 138-155`.
