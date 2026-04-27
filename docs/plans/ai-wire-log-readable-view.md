Agent: Codex, Claude
Status: Identified
Last Updated: 2026-04-27
Sources: `docs/plans/ai-control-plane-debuggability.md`; `resources/core/views/livewire/admin/ai/control-plane/partials/wire-log.blade.php`; `app/Modules/Core/AI/Services/ControlPlane/WireLogger.php`; `tests/Feature/Modules/Core/AI/ControlPlaneInspectorTest.php`

## Problem Essence

The control-plane Wire Log is faithful to the raw transport capture, but it asks humans to inspect one low-level JSON/SSE entry at a time. `llm.stream_line` is especially noisy because many entries contain only a small semantic fragment, an empty delta, a partial tool argument, or a finish marker.

## Desired Outcome

Operators should see a readable default view that makes an AI run's transport behavior scannable in seconds, while preserving exact drill-down to every retained raw wire-log entry. The readable view is an interpretation layer only; the JSONL wire log remains the source of truth.

The acceptance heuristic is **triage in 5 seconds**: open a random failed run and the operator should be able to identify the failure mode and locate the responsible entry within 5 seconds without expanding anything. If a design choice fails this test, it is not compact or honest enough. This rule is the acceptance gate for overview content, chip discipline, and the sticky triage strip.

## Top-Level Components

**Readable Wire View**

The Wire Log panel should default to a human-oriented presentation. It should summarize transport shape, group adjacent stream chunks, and render meaningful fragments as compact visual tokens.

**Raw Entry View**

The existing paginated raw-entry accordion should remain available as the fidelity fallback. It should continue to support large-entry omission and "Open Raw" links.

**Semantic Stream Formatter**

Server-side formatting should derive display fragments from retained wire entries without changing the stored JSONL format. Each display fragment should retain its source `entry_number`, timestamp, semantic type, extracted text/value, raw line, and decoded payload when available.

**Reassembled Artifacts**

The formatter should also produce stitched artifacts that answer "what did the model actually say?" without forcing the operator to read fragments: the concatenated assistant content, a separate reasoning-content block, and one assembled-arguments JSON per tool call. Each artifact must retain back-links to its constituent `entry_number`s and must declare whether the assembled tool-args parse as valid JSON; an invalid assembly is one of the most common silent failures and must be surfaced as such.

## Design Decisions

### Default To Meaning, Not Raw Chronology

The first screen should answer "what happened?" before "what exact bytes were received?" A compact transport overview should show the request/status/first-byte/stream/complete shape, total retained entries, stream chunk count, file footprint, finish reason, error count, and timing markers: time-to-first-byte, time-to-first-content, time-to-first-reasoning, and time-to-first-tool-call. Rate stats (chunks/sec and approximate tokens/sec) belong here too, clearly labelled "for the loaded window only" so the operator never mistakes window-scoped numbers for full-run truth.

The overview should also render as a **sticky triage strip** that remains visible while the operator scrolls the readable view, so status, finish reason, and the headline counts never leave the viewport during drill-down.

Raw chronology still matters, but it belongs one click away in the Raw Entries tab or in per-fragment drill-down.

### Group Adjacent Stream Lines

Adjacent `llm.stream_line` entries should collapse into a single stream block instead of rendering hundreds of independent accordions. Non-stream entries such as `llm.request`, `llm.response_status`, `llm.response_body`, `llm.error`, and `llm.complete` should appear as separate transport events around those grouped stream blocks.

This keeps the view faithful to order while removing the repeated visual cost of one card per SSE line.

### Render Semantic Chips

Each parsed stream fragment should render as a small chip. Chip color should communicate semantic role, not provider implementation detail:

- `content`: normal assistant-visible output.
- `reasoning_content`: muted warm or violet accent, rendered smaller and italic so the eye lands on real assistant text first when scanning.
- `tool_call`: blue command chip.
- `tool_args`: teal/cyan code-like chip, preserving exact partial fragments.
- `finish_reason`: neutral status chip pinned to the end of each grouped stream block, with shape and color cue (`stop` neutral, `tool_calls` blue, `length` warning, `content_filter` and `error` danger).
- `empty`: collapsed into a single muted "× N keep-alives" rule when consecutive empty deltas appear, instead of emitting one tick per heartbeat. A run that is mostly heartbeats followed by an error becomes immediately legible.
- `done`: neutral terminal marker.
- `decode_error`: warning chip.

Reserve **danger** color for genuinely broken signals — decode errors, non-2xx HTTP, `finish=error`, `finish=content_filter` — never for routine `tool_args`. This keeps "red means look here" honest.

Prose fragments may be visually compacted for readability, but tool arguments must preserve exact chunk text because partial JSON is operationally meaningful.

### Anomaly Signals

Chip color tells the operator what a fragment is. Anomaly signals tell them what is wrong. The readable view should render derived signals on top of the chip layer:

- **Unknown delta keys** — any key in `choice.delta` that the formatter does not recognize gets a warning chip "unknown key: foo." This delivers the parent debuggability plan's promise that unknown provider keys are never silently dropped.
- **Decode errors and truncated entries** — count, banner inside the readable view, anchored back to the offending `entry_number`s.
- **HTTP status anomalies** — non-2xx `llm.response_status` is badged at the top of the readable view in danger color.
- **Finish reason ≠ stop** — `length`, `tool_calls`, `content_filter`, `error` get prominent placement in the overview, not buried mid-stream.

These signals are derived presentation; the JSONL is unchanged.

### Timing Signals

The plain timestamp column does not make stalls visible. Add timing affordances:

- **Inter-fragment gap badges** — any gap above a configurable threshold between adjacent stream chunks renders inline as "stalled 4.2s." This reuses the visual idiom Phase 2 of the parent debuggability plan already established for turn-event gaps so operator muscle memory transfers.
- **Mini timeline strip** above each grouped stream block — a thin horizontal bar with markers for `request`, first byte, each chunk as a tick, tool calls, and `complete`/`error`. Long stalls appear as visible whitespace; the operator does not need to do arithmetic on timestamps.
- **Window-scoped rate stats** in the overview, with explicit "for the loaded window only" labelling so partial-window scope is never mistaken for full-run truth.

### Multi-Attempt Segmentation

A single run can contain multiple `llm.request` / `llm.response_*` cycles when retries or provider fallbacks fire. The readable view should segment the panel by attempt and render a one-line summary per attempt, for example "Attempt 1 — openai / gpt-4o, HTTP 503 at 1.4s, retried" followed by "Attempt 2 — anthropic / claude-3.5-sonnet, streamed 47s, finish=stop."

`RunDetail` already shows fallback attempts from `ai_runs` in normalized form; the wire-log view uniquely sees the actual response bodies and SSE lines per attempt and is therefore where attempt-level forensic inspection earns its keep.

### Drill Down Without Fidelity Loss

Clicking or expanding a chip should reveal:

- source entry number;
- timestamp;
- semantic classification;
- extracted value exactly as parsed;
- raw SSE line;
- decoded JSON payload when available;
- existing "Open Raw" link for the full retained entry;
- a "Jump to turn event" link that aligns by timestamp to the corresponding `ai_chat_turn_events` row, so operators can pivot between the wire-log lens and the turn-timeline lens they already use.

The readable view should never replace or mutate raw payloads.

### Operator Affordances

Wire logs exist so operators can reproduce, compare, and share. Make the common moves one click:

- **Copy as replay** — the captured outbound `ChatRequest` becomes a cURL invocation with `$API_KEY` placeholder, ready to paste into a terminal. This is the single highest-leverage button on the panel.
- **Copy reassembled assistant content** and **copy reassembled tool-args JSON** per tool call — these are the artifacts an operator most often needs to share or paste into a ticket.
- **Fragment permalinks** — each chip is anchorable by `entry_number` so a teammate can be pointed at "look at #347" without ambiguity.

### Keep Pagination Honest

The first prototype may derive the readable view from the currently loaded window of entries. The UI must make that scope visceral so operators do not mistake a paginated slice for the full run:

- The "Showing entries N–M of T" banner appears inside the readable view itself, not only in the Raw Entries tab.
- Aggregate numbers derived from a partial window render in a muted style with a tooltip "computed from this window only."
- A "Load full run for derivation (≈Z KB)" button lets operators opt in to whole-run scope with the cost shown up front.

A later phase can add whole-run semantic indexing if needed.

## Public Contract

The Wire Log panel should expose two operator modes in v1:

- **Readable**: default, grouped, semantic display for scan-and-drill workflows.
- **Raw Entries**: current paginated wire-entry view for exact inspection.

Readable fragments must always be traceable back to retained raw entries by `entry_number`.

A future **Transcript** mode is worth considering once chips exist: one fragment per line, `[ts] type: value…`, like a `tail -f`, optimized for fast vertical scanning of very long runs. A separate **Reassembled** mode that hides individual fragments and shows only the stitched artifacts is also a candidate. These are deferred to Phase 2 so the v1 surface stays minimal.

## Phases

### Phase 1

Goal: create a reviewable first version that proves the presentation model in the browser.

- [ ] Add a server-side semantic formatter for the currently loaded wire-log window.
- [ ] Add a Readable/Raw Entries mode switch inside the Wire Log panel.
- [ ] Render grouped stream blocks with semantic chips for `llm.stream_line`.
- [ ] Produce reassembled artifacts (assistant content, reasoning block, per-tool-call assembled args with JSON parse indicator) at the top of each grouped stream block, with back-links to constituent `entry_number`s.
- [ ] Render the transport overview as a sticky triage strip (status, finish reason, error count, headline counts, TTFB / TT-first-content / TT-first-reasoning / TT-first-tool-call, window-scoped rate stats).
- [ ] Surface anomaly signals: unknown delta keys, decode errors, truncated entries, non-2xx response status, finish reason ≠ stop.
- [ ] Segment the readable view by attempt when multiple `llm.request` / `llm.response_*` cycles are present, with a one-line summary per attempt.
- [ ] Add operator affordances: copy-as-cURL replay for the outbound `ChatRequest`, copy reassembled assistant content, copy reassembled tool-args JSON, fragment permalinks by `entry_number`.
- [ ] Show the "Showing entries N–M of T" banner inside the readable view and grey out window-scoped aggregates with explicit tooltips.
- [ ] Preserve the existing raw-entry accordion as the Raw Entries mode.
- [ ] Add focused feature coverage for readable stream fragments, reassembled artifacts, anomaly chips, and raw drill-down anchors.
- [ ] Verify the control-plane inspector page manually with a retained run containing stream lines, fallback attempts, and tool calls.

### Phase 2

Goal: refine operator usefulness after seeing the first browser version.

- [ ] Tune chip labels, colors, spacing, and empty-cascade collapse from real run data.
- [ ] Add the mini timeline strip above each grouped stream block and inter-fragment gap badges using a configurable threshold.
- [ ] Add the "Jump to turn event" link from each transport event to the corresponding `ai_chat_turn_events` row by timestamp.
- [ ] Add a "Load full run for derivation" affordance with the cost shown up front, and decide whether readable grouping should keep window-only scope or always derive from the full run.
- [ ] Add Transcript and Reassembled modes if Phase 1 shows the chip mode alone is too dense or too coarse for some triage tasks.
- [ ] Update this plan with the chosen follow-up direction before implementation continues.
