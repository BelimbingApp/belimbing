# AI Tool Payload Optimization

**Agent:** Amp
**Status:** Phase 1 Complete, Phase 2 Near-Complete
**Last Updated:** 2025-04-20
**Sources:** `storage/app/ai/wire-logs/run_9A4JM8k82gI7.jsonl`, `app/Modules/Core/AI/Services/AgentToolRegistry.php`, `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Modules/Core/AI/Services/LaraTaskExecutionProfileRegistry.php`, `app/Modules/Core/AI/Services/ChatToolProfileRegistry.php`, `app/Modules/Core/AI/Resources/lara/system_prompt.md`

## Problem Essence

Every LLM request sends all 25 tool definitions (~23.8K chars) and a system prompt (~8.2K chars) regardless of whether the user's message needs them. Tool schemas account for 72% of the payload; the system prompt adds another 25%, of which ~29% (~2.4K chars) restates what tool definitions already describe. A simple "go to Users" turn carries the full schemas for `browser`, `bash`, `document_analysis`, `schedule_task`, etc. plus prose re-explaining how `navigate` and `artisan` work — wasting tokens, increasing latency, and diluting the model's attention.

## Desired Outcome

The tool set and system prompt sent per request are right-sized for the interaction context. Simple navigational or conversational turns carry only the tools they might plausibly need and a prompt free of tool-description duplication; the full set is still reachable when needed. Token spend drops by 50–70% for the majority of turns without sacrificing capability.

## Top-Level Components

- **Tool Profile Registry** — declares named tool subsets (profiles) for different interaction contexts.
- **Intent Classifier** — lightweight mechanism that maps an incoming message to a profile before the main LLM call.
- **Lazy Tool Injector** — meta-tool the model can call to request additional tools mid-conversation when the initial set is insufficient.
- **Composite Tool Consolidator** — merges related tools behind a single schema with an `action` discriminator.
- **System Prompt Tightener** — removes tool-description duplication and dead-code sections from the system prompt.
- **Stored Tool Definitions** — provider-side caching of schemas so they aren't resent on every request.

## Design Decisions

### 1. Tool Profiles for Interactive Chat (lowest effort, highest immediate impact)

The `allowedToolNames` plumbing already exists end-to-end (`AgenticRuntime → AgentToolRegistry → LaraTaskExecutionProfileRegistry`), but interactive chat passes `null` (all tools). Define a small number of named profiles for the chat path — not just background tasks — and select one before calling the runtime.

Recommended starter profiles:

| Profile | Tools (~count) | When |
|---|---|---|
| `chat-core` | `navigate`, `visible_nav_menu`, `guide`, `system_info`, `active_page_snapshot`, `write_js`, `memory_get`, `memory_search` | Default for conversational / navigational turns |
| `chat-data` | `chat-core` + `query_data`, `edit_data`, `artisan` | Data questions, lookups, mutations |
| `chat-action` | `chat-data` + `notification`, `message`, `ticket_update`, `delegate_task`, `agent_list`, `schedule_task` | Action-oriented requests |
| `chat-full` | all 25 | Explicit request or escalation fallback |

Profiles compose additively. The registry should support inheritance (a profile can extend another) to avoid duplication.

### 2. System Prompt Tightening (lowest effort, immediate savings)

The system prompt in `system_prompt.md` (~8.2K chars rendered) has three categories of waste:

**Tool-description duplication (~1,230 chars).** The "Tool calling" section (lines 20–31) restates individual tool purposes (`artisan`, `visible_nav_menu`, `navigate`) that are already fully described in each tool's `description` field. The model already sees those descriptions via function definitions — repeating them in prose is pure duplication. Keep only behavioral guidance the tool descriptions *can't* convey: "prefer action over explanation", "side effects go through tool calls, not raw PHP blocks", "tools are authz-gated".

**Dead fallback section (~820 chars).** The "Browser actions (fallback for non-tool-calling)" section explains `<lara-action>` output blocks for models that can't do tool calling. Lara always runs with tool calling enabled; this section is dead code. Remove entirely.

**Verbose examples (~310 chars).** The "Proactive assistance" section spells out an example workflow ("How do I add an employee?" → artisan → navigate) that duplicates tool descriptions. Collapse to one behavioral line.

**Runtime context bloat (~2,260 chars).** The `knowledge` block in the runtime context JSON contains `default_references` and `query_references` — static documentation indexes that rarely change. These could move into the workspace `tools.md` slot (loaded once) or be served via the `guide` tool on demand, rather than serialized into every request.

Net savings: ~2,360 chars of prompt duplication removed (~29% of system prompt, ~7% of total payload). Combined with runtime context trimming, total prompt savings approach 4,600 chars (~56% of current prompt).

### 3. Intent Classification (two-pass routing)

Before the main LLM call, classify the user message to select a profile. Three implementation tiers, in order of preference:

- **Keyword/heuristic classifier** — regex or keyword map (e.g., "go to" / "navigate" → `chat-core`; "how many" / "show me" → `chat-data`; "send" / "notify" / "schedule" → `chat-action`). Zero latency, zero cost. Good enough for 80% of turns.
- **Small-model classifier** — a single cheap LLM call (e.g., GPT-4.1-mini or local model) with a one-shot prompt: "classify this user message into one of: core, data, action, full". Adds ~200ms and ~100 tokens. Handles ambiguous cases.
- **Hybrid** — heuristic first, small-model only when confidence is low.

The classifier returns a profile name; the runtime resolves it to `allowedToolNames`. Misclassification is safe because the lazy injector (approach 3) provides an escape hatch.

### 4. Lazy Tool Injection (meta-tool escalation)

Register a lightweight meta-tool `request_tools` in the core set. When the model realizes it needs a tool not in its current set, it calls `request_tools(category: "action")` and receives the additional tool definitions injected into the next turn. The runtime re-invokes the LLM with the expanded tool set for the remainder of that conversation turn.

This makes aggressive profile trimming safe — the model can always self-escalate. The `request_tools` schema itself is tiny (~200 chars) so the overhead of including it in every request is negligible.

Implementation: `request_tools` is a special tool that doesn't execute side effects — it returns a signal to the tool-calling loop in `AgenticRuntime.runToolCallingLoop()` to merge the requested profile's tools and re-invoke the LLM.

### 5. Composite Tool Consolidation (schema reduction)

Several tools share a domain and could merge behind an `action` parameter:

| Composite | Merges | Schema savings |
|---|---|---|
| `memory` | `memory_get`, `memory_search`, `memory_status` | 3 → 1 (~600 chars saved) |
| `data` | `query_data`, `edit_data` | 2 → 1 (~400 chars saved) |
| `delegation` | `delegate_task`, `delegation_status`, `agent_list` | 3 → 1 (~800 chars saved) |

Tradeoff: composite tools increase per-schema complexity and may slightly reduce model accuracy on parameter selection. Recommended only for the `memory` group (clear win) and `delegation` group. Keep `query_data` / `edit_data` separate — the read/write distinction is an important authz boundary.

### 6. Provider-Side Stored Tool Definitions (OpenAI Responses API)

The Responses API supports server-side tool storage, avoiding re-transmission of schemas. This is provider-specific and couples the tool surface to OpenAI's lifecycle. Investigate feasibility but treat as a bonus optimization, not a foundation — the architecture must also work for Anthropic and other providers.

Worth pursuing only after profiles (approach 1) and lazy injection (approach 3) are in place, since those reduce the number of tools regardless of provider.

## Phases

### Phase 1 — Chat Tool Profiles

Goal: reduce interactive chat tool payloads from 25 to ~8 tools for typical turns.

- [x] Design the profile data structure in a new `ChatToolProfileRegistry` with `ChatToolProfile` DTO supporting inheritance via `extends`
- [x] Define the four starter profiles (`chat-core` 8 tools, `chat-data` extends core +3, `chat-action` extends data +6, `chat-full` = all)
- [x] Wire `ChatTurnRunner` to resolve a profile via `ChatToolProfileRegistry` and forward `allowedToolNames` to `AgenticRuntime.runStream()`
- [x] Default to `chat-core` when no `tool_profile` is set in `runtime_meta`
- [x] Add `profile.selected` wire-log event with `profile_tools`, `effective_tools`, and `tool_count` in both streaming and non-streaming paths
- [x] Verify existing background task profiles (`research`, `coding`) are unaffected — `LaraTaskExecutionProfileRegistry` untouched

### Phase 2 — System Prompt Tightening

Goal: eliminate duplication between system prompt prose and tool descriptions; remove dead sections.

- [x] Remove per-tool descriptions from the "Tool calling" section (lines 26–28 of `system_prompt.md`) — keep only behavioral rules the tool schemas can't express
- [x] Delete the entire "Browser actions (fallback for non-tool-calling)" section — Lara always uses tool calling; also used wrong tag name (`<lara-action>` vs actual `<agent-action>`)
- [x] Collapse "Proactive assistance" into the Tool calling section as a single directive
- [x] Remove `knowledge.default_references` from runtime context JSON — the `guide` tool already serves these on-demand; `query_references` kept but only included when non-empty
- [x] Verify prompt renders correctly via `LaraPromptFactory::buildForCurrentUser()` in a test
- [ ] Compare wire-log payload sizes before and after

### Phase 3 — Intent Classifier

Goal: automatically select the right profile per message without manual annotation.

- [ ] Implement keyword/heuristic classifier as a service (`ChatIntentClassifier`)
- [ ] Map classifier output to profile names
- [ ] Integrate into `ChatTurnRunner` before runtime invocation
- [ ] Measure classification accuracy against wire-log history (spot-check recent runs)
- [ ] Optional: add small-model classifier tier for low-confidence cases

### Phase 4 — Lazy Tool Injection

Goal: make aggressive trimming safe by letting the model self-escalate.

- [ ] Create `RequestToolsTool` (meta-tool) with minimal schema
- [ ] Include `request_tools` in every profile's tool set
- [ ] Handle the escalation signal in `AgenticRuntime.runToolCallingLoop()` — merge requested profile, re-invoke LLM
- [ ] Cap escalation depth (max 1 escalation per turn to prevent loops)
- [ ] Add wire-log event for escalation occurrences

### Phase 5 — Composite Tool Consolidation

Goal: reduce total schema size for tools that naturally group.

- [ ] Merge `memory_get`, `memory_search`, `memory_status` into a single `memory` tool with `action` discriminator
- [ ] Merge `delegate_task`, `delegation_status`, `agent_list` into `delegation` tool
- [ ] Update system prompt references to use new tool names
- [ ] Verify model accuracy on composite tools with representative prompts

### Phase 6 — Provider-Side Stored Definitions

Goal: eliminate re-transmission of tool schemas for providers that support it.

- [ ] Investigate OpenAI Responses API stored tool support and lifecycle
- [ ] Prototype stored tool registration and reference-by-ID in `OpenAiResponsesRequestMapper`
- [ ] Measure payload size reduction vs. provider lock-in cost
- [ ] Decide go/no-go based on multi-provider strategy
