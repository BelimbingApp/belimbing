# ai-control-plane-unified-timeline

**Status:** Phase 3 Complete (with documented deviations) — Phase 4 under consideration
**Last Updated:** 2026-05-08
**Sources:** `backup/pre-unified-entity` branch (snapshot before Phase 1); DB backup `01kr2w72bssreaxjxy2gss0gqp`; wire log backup `storage/app/ai/wire-logs-backup-pre-unified/` (26 files, 75 MB — delete after Phase 2 is confirmed stable)
**Agents:** claude/sonnet-4-6, amp/opus-4-7, codex/gpt-5.5-medium, amp/gpt-5.5-medium

## Problem Essence

The control plane's Run Inspector and Turn Inspector tabs force operators to navigate between two surfaces and build a mental picture manually to answer a single question: what happened with this prompt?

## Desired Outcome

A unified Prompt Timeline view that interleaves run meta-events (DB) and wire log entries (disk) onto one chronological axis, so an operator can diagnose any prompt end-to-end without switching tabs. Every AI execution — interactive chat, background job, orchestration session — flows through a single execution envelope, so the same timeline surface diagnoses chat and non-chat work without per-source plumbing.

## Design Decisions

### Mental model

`AiRun` is the universal execution envelope for any LLM call in the system. It carries the full lifecycle (queue → boot → execute → terminal), all telemetry (tokens, cost, retries, tools, error), and is the FK target for events, wire logs, and per-call usage rows. The source column distinguishes who minted it. `session_id` is optional execution/session correlation: chat commonly sets it, and background work may set it when it participates in a multi-turn or transcript-backed workflow.

```
                       ╭───────────────────────────────╮
                       │  AiRun (universal envelope)   │
                       │  ULID id  •  source: chat /   │
                       │             background /      │
                       │             orchestration     │
                       ╰─┬───────────┬──────────┬──────╯
                         │           │          │
                  AiRunEvent ×N   wire log    AiRunCall ×N
                  (DB, ordered)   (JSONL)     (per LLM call)

  Chat path:        ChatTurnRunner ─────────▶ AiRun (source=chat, session_id set)
  Background path:  RunAgentTaskJob   ─┐
                    RunLaraTaskProf…  ─┤───▶ AiRun (source=background, dispatch_id set)
                    SpawnAgentSession ─┤
                    SimpleTaskExecutor ┘
```

The envelope is minted by the caller before execution begins. The runtime receives the ULID and updates the existing row as execution proceeds. Chat lifecycle fields (`current_phase`, `current_label`, `last_event_seq`) are nullable and populated only when the caller emits lifecycle events. Background paths can leave those fields null and use `dispatch_id` and/or `session_id` for correlation. Lifecycle events are an opt-in concern — only the chat path emits them today.

### Run event taxonomy

After unification the `turn.*` and `run.*` event prefixes collapse: there is one envelope, so events are namespaced under `run.*`. The old `run.started` / `run.failed` markers (which signalled the LLM execution starting *inside* a turn) drop out — the envelope's own status transitions cover them.

**Meta-only** (envelope lifecycle and out-of-band signals, kept in the unified timeline):
- `run.started`, `run.phase_changed`, `run.completed`, `run.failed`, `run.cancelled`, `run.ready_for_input`
- `heartbeat`
- `tool.denied` (policy rejection before tool ran)

**Already captured on the wire** (shown via wire log entries, not repeated as meta markers):
- `assistant.thinking_delta`, `assistant.thinking_started`, `assistant.iteration_completed`
- `assistant.output_delta`, `assistant.output_block_committed`
- `tool.started`, `tool.finished` (wire log `tool_use` / `tool_result` blocks)
- `tool.stdout_delta` (tool result content in transcript)
- `usage.updated` (wire log final chunk)

Usage accounting is DB-first, not event-first. Provider usage and cost are persisted in `ai_run_calls` per LLM call and aggregated onto `ai_runs` for billing, reporting, and session totals. Timeline filtering can hide or omit `usage.updated` markers, but Phase 2 must not drop the DB usage columns, raw provider usage payload, per-call rows, or aggregate refresh behavior.

### Recovery events — removed (YAGNI)

`recovery.attempted`, `recovery.succeeded`, `recovery.failed` were designed for a multi-run retry model (provider fallback, worker crash re-dispatch) that was never implemented. The actual in-run retry (`chatWithRetry`) is silent and produces no turn events. Decision: errors surface directly to the user or calling program; programmatic callers handle retry themselves. The `chatWithRetry` silent retry within a run (transient HTTP errors) is unaffected — it is a provider-level reliability measure with no user-visible events.

### Unified timeline layout

Run meta-events (DB) and wire log entries (disk) are interleaved by timestamp. They do not bracket each other cleanly — meta-events fire during wire activity at arbitrary points. Both sources are normalised to `{timestamp, source, type, payload}` and sorted chronologically.

```
[Prompt — AiRun 01HKQ7…] ─────────────────────────────────────────
  ↓ [META] run.started         queued              +0ms
  ↓ [META] run.phase_changed   booting             +120ms
  ═ [WIRE] → REQUEST           messages[12]        +380ms
  ↓ [META] run.phase_changed   awaiting LLM        +385ms
  ═ [WIRE] ← chunk             thinking delta      +410ms
  ═ [WIRE] ← chunk             thinking delta      +450ms
  ↓ [META] heartbeat           elapsed 500ms       +500ms
  ═ [WIRE] ← chunk             tool_use: bash      +900ms
  ═ [WIRE] → tool_result       stdout preview      +1200ms
  ↓ [META] run.phase_changed   streaming answer    +1210ms
  ═ [WIRE] ← chunk             output delta        +1250ms
  ═ [WIRE] ← RESPONSE complete 512 tok / 88ms      +1300ms
  ↓ [META] run.completed                           +1320ms
  ↓ [META] run.ready_for_input                     +1325ms
───────────────────────────────────────────────────────────────────
```

`[META]` entries render with a distinct visual treatment (left-rail marker, muted background) to remain separable from `[WIRE]` entries without breaking the chronological flow.

### Two-source merge

Wire log entries live on disk (files via `WireLogger`, keyed by run ULID). Run events live in DB (`ai_run_events`). `buildPromptTimelineView(string $runId)` fetches both, normalises to a common shape, and merges by timestamp.

Some meta events reliably fall outside the wire log's time range — `run.started`, `run.phase_changed` (queued/booting) precede the first HTTP request; `run.completed` and `run.ready_for_input` follow the last response — forming a natural prologue and epilogue. All other meta-events interleave within the wire segment. Background runs typically have no meta events at all, so their timeline is just the wire segment.

### Gap diagnostics

`gap_ms` between events and stuck-turn detection are preserved on meta-event markers only (e.g. a long gap between `run.started` and the first wire entry signals a slow worker boot). Not applied to wire log entries.

### Filtering

Delta events (`assistant.thinking_delta`, `assistant.output_delta`, `tool.stdout_delta`) are high-volume. A toggle collapses them to show only structural wire entries (requests, responses, tool blocks) alongside meta markers — the common diagnostic view.

### Tabs after refactor

> **Note (post-implementation):** Phase 3 originally intended to add Prompt Timeline as a 5th tab next to an unchanged Turn Inspector. The shipped change collapsed that early — Turn Inspector was removed and Prompt Timeline took its tab slot — so the surface is now 4 tabs. The table reflects what shipped.

| Tab | Before | After (Phase 3 — shipped) | Phase 4 (optional) |
|---|---|---|---|
| Run Inspector | Drill by run ID, wire log + transcript | Retained as deep-dive surface; embeds Prompt Timeline inline and keeps the Wire Log card for readable/raw transport drill-down | Unchanged |
| Turn Inspector | Drill by turn ID, full event timeline | **Removed** — collapsed early into Prompt Timeline | (removed) |
| Prompt Timeline | Does not exist | **New** — replaces Turn Inspector tab; chat + non-chat run IDs accepted (no source filter) | TBD pending Phase 4 memory-safety decision |
| Health & Presence | Unchanged | Unchanged | Unchanged |
| Lifecycle Controls | Unchanged | Unchanged | Unchanged |

## Phases

### Phase 1 — Remove recovery logic (YAGNI)

- [x] Remove `RunEventType` cases `RecoveryAttempted`, `RecoverySucceeded`, `RecoveryFailed` and their `severity()` / `label()` arms — claude-sonnet-4-6
- [x] Delete `PublishesRecoveryEvents` trait — claude-sonnet-4-6
- [x] Remove `use PublishesRecoveryEvents` from `RunEventPublisher` — claude-sonnet-4-6
- [x] Remove `onRecoveryAttempted()`, `onRecoverySucceeded()`, and dispatch cases from `RunStreamBridge` — claude-sonnet-4-6
- [x] Remove recovery tests from `RunEventPublisherTest`, `RunStreamBridgeTest`, `TurnContractEnumsTest`; update case count 22 → 19 — claude-sonnet-4-6

### Phase 2 — Unify execution envelope

Promote `ai_runs` to the universal AI execution envelope. Drop `ai_chat_turns` and absorb its lifecycle fields onto `ai_runs`. Every LLM call — interactive chat, background job, orchestration session — flows through one entity with one ID format (ULID), one status enum, and one wire-log naming scheme. This is the storage prerequisite for the Prompt Timeline (Phase 3) treating chat and non-chat work uniformly.

**Target shape:**

`ai_runs` keeps every column it has today (`dispatch_id`, `source`, `execution_mode`, `provider_name`, `model`, all token / cost / pricing columns, `call_count`, `retry_attempts`, `tool_actions`, `error_*`, `meta`, `started_at`, `finished_at`) and gains lifecycle columns lifted from `ChatTurn`:

- `session_id` — already present, stays nullable; populated by chat and by background work that belongs to a session or transcript-backed workflow
- `current_phase`, `current_label` — nullable, populated by lifecycle-event emitters, currently the chat path
- `last_event_seq` — nullable, populated only when events are emitted
- `cancel_requested_at`, `runtime_meta` — nullable, populated only by chat path
- `acting_for_user_id` — already present

`ai_run_calls` remains the accounting ledger for per-call usage: prompt/completion/cached/reasoning/total tokens, raw provider usage payloads, rate-limit metadata, pricing source/version, and cost columns. `ai_runs` keeps the aggregate columns refreshed from those rows. Usage may appear in the wire log and transcript for diagnostics, but DB rows are the billing/reporting source of truth.

`status` becomes a superset enum: `queued → booting → running → succeeded / failed / cancelled / timed_out`. Background paths skip `queued`/`booting` and start at `running` (no fake states until a non-chat surface needs them). `current_run_id` on `ChatTurn` disappears — the run *is* the envelope.

**ID and naming:**

- Primary key is ULID for every row. The `run_<random12>` format is dropped.
- The caller (chat runner / background job / executor) mints the ULID before invoking the runtime.
- `WireLogger.path()` accepts the run ULID directly: `storage/app/ai/wire-logs/{ulid}.jsonl`.
- `AiRunCall.run_id` is a ULID FK to `ai_runs.id`.
- `OperationDispatch.run_id` becomes a ULID FK to `ai_runs.id`.

**Code rename:**

- `ChatTurn` model → drop. References that meant "the chat-side view of a run" become `AiRun` with chat-specific accessors when needed.
- `AiRunEvent` model + `ai_run_events` table → `AiRunEvent` + `ai_run_events`, FK retargets to `ai_runs.id`.
- `ChatTurnRunner` keeps its name (it's still the chat-side runner) but now creates `AiRun` rows directly with `source='chat'` and threads the ULID into the runtime.
- `TurnStatus`, `RunPhase`, `RunEventType` → `RunStatus`, `RunPhase`, `RunEventType` (or merged into existing `AiRunStatus` where the enum supersets cleanly).
- `RunEventPublisher`, `RunStreamBridge`, `RunStreamController`, `RunEventStreamController` rename their *Turn* prefix to *Run* in symbol names; SSE channel names follow.
- `RunRecorder.start()` → `beginExecution(string $ulid, …)` — updates the existing envelope row instead of inserting.

**Background-path changes:**

- `RunAgentTaskJob`, `RunLaraTaskProfileJob`, `SpawnAgentSessionJob`, `SimpleTaskExecutor`, `TaskModelRecommendationService` mint the run ULID at job/dispatch construction (or at the executor entry point) and pass it to `AgenticRuntime::run(..., runId: $ulid)`.
- `AgenticRuntime::run()` and `runStream()` lose their internal `run_<random12>` generation; both take the ULID as a required parameter.
- `OperationDispatch` linkage stays via `dispatch_id` on `ai_runs`, written at envelope creation by the dispatching job (no separate `attachDispatch()` round-trip needed).

**Destructive evolution:**

Per the BLB destructive-evolution principle, no migration path for existing rows. All `ai_runs`, `ai_run_calls`, `ai_chat_turns`, `ai_run_events`, `operation_dispatches.run_id`, and existing wire-log files (`storage/app/ai/wire-logs/run_*.jsonl`) are dropped/recreated. The pre-Phase-1 backups (DB `01kr2w72bssreaxjxy2gss0gqp`, `wire-logs-backup-pre-unified/`) are no longer useful for restore after this phase — delete them when Phase 2 lands.

**Scope:**

- [x] Migration: extend `ai_runs` with lifecycle columns (`session_id` already there, add `current_phase`, `current_label`, `last_event_seq`, `cancel_requested_at`, `runtime_meta`); change `id` to ULID; drop `ai_chat_turns` and `ai_chat_turn_events` migrations; create `ai_run_events` migration with FK to `ai_runs.id`; update `ai_run_calls.run_id` to ULID FK while preserving every usage / raw usage / pricing / cost column; update `operation_dispatches.run_id` to ULID FK after `ai_runs` exists
- [x] Status enum: define unified `AiRunStatus` covering `queued → booting → running → succeeded / failed / cancelled / timed_out` with the same transition rules `TurnStatus` enforces today; drop `TurnStatus`
- [x] Models: drop `ChatTurn`; rename `ChatTurnEvent` → `AiRunEvent`; expand `AiRun` with chat-side helpers (`nextSeq`, `isCancelRequested`, `requestCancel`, `transitionTo`, `updatePhase`, `finalize`, `eventsAfter`); keep `current_run_id` only as a compatibility key in existing inspector/chat view-models, backed by `AiRun.id`
- [x] Enums: rename `TurnPhase` → `RunPhase`, `TurnEventType` → `RunEventType`; collapse `turn.*` event prefixes to `run.*` per *Run event taxonomy*
- [x] Services: rename `TurnEventPublisher` → `RunEventPublisher`; rename `TurnStreamBridge` → `RunStreamBridge`; update `RunRecorder` to `beginExecution(ulid)` semantics; update `ChatRunPersister` to operate on `AiRun`; update the run inspector/control-plane diagnostic surfaces and stale-run sweeper to read the unified envelope while preserving the existing readable chunk parser
- [x] Runtime: `AgenticRuntime::run()` and `runStream()` accept `runId: $ulid` as a required parameter; remove internal `Str::random` ID generation; thread ULID through to `WireLogger` and `RunRecorder`
- [x] Caller mint sites: `ChatTurnRunner` (already creates the envelope), `RunAgentTaskJob`, `RunLaraTaskProfileJob`, `SpawnAgentSessionJob`, `SimpleTaskExecutor`, `TaskModelRecommendationService` — each mints the ULID upfront and inserts the envelope row before invoking the runtime
- [x] Controllers + Livewire: rename `ChatTurnStreamController` / `TurnEventStreamController` to `RunStreamController` / `RunEventStreamController`; update `Chat`, `ControlPlane`, and any Livewire components/concerns that reference `ChatTurn` or turn-prefixed symbols; route names remain backwards-compatible for the existing UI
- [x] Wire log: `WireLogger.path()` takes ULID; remove runtime `run_` prefix generation; readable formatter and entry controller URLs use the `AiRun.id` ULID
- [x] Tests: update fixtures, factories, and assertions across the focused AI control-plane suite — `tests/AGENTS.md` quality bar applies; delete tests asserting the two-table split
- [ ] Cleanup: remove `backup/pre-unified-entity` branch, DB backup `01kr2w72bssreaxjxy2gss0gqp`, and `storage/app/ai/wire-logs-backup-pre-unified/` once Phase 2 verifies green

### Phase 3 — Build the Prompt Timeline

With the envelope unified in Phase 2, the timeline view collapses to a straight composition: load events for a run ULID, read its wire log, merge by timestamp.

**Scope:**

- [x] Build `buildPromptTimelineView(string $runId, bool $collapseDelta = false): ?array` — loads `AiRun` + ordered `AiRunEvent`s, reads the wire log via `WireLogger::read()`, normalises both sources to the unified entry shape `{timestamp, source, type, label, summary, severity, is_delta, gap_ms, has_gap_warning, is_stuck, payload, seq, entry_number}`, returns the chronologically merged stream — claude-sonnet-4-6
- [x] Apply `gap_ms` and stuck-run detection to meta-event markers only (per *Gap diagnostics*) — claude-sonnet-4-6
- [x] Add a delta-collapse toggle: hides `RunEventType::isDelta()` meta events and `llm.stream_line` wire entries; wired to `$timelineCollapseDelta` / `toggleTimelineDelta()` on `ControlPlane` — claude-sonnet-4-6
- [x] Land a Prompt Timeline tab in `ControlPlane` (`heroicon-o-queue-list`); `[META]` and `[WIRE]` entries render with distinct semantic-token badges, gap/stuck warnings on meta only, collapsible payloads available in the timeline (`partials/prompt-timeline.blade.php`) — claude-sonnet-4-6, amp/gpt-5.5-medium — **Deviation:** shipped as a 4-tab layout that **replaces** Turn Inspector instead of adding a 5th tab. Run Inspector now embeds the Prompt Timeline inline while retaining the Wire Log card and `wireLogDiskUsageBytes` indicator for readable/raw transport drill-down.
- [x] Do not restrict the tab's run picker to `source='chat'`; the unified envelope makes all run IDs valid, while Phase 4 remains responsible for a richer non-chat picker UX — claude-sonnet-4-6, amp/gpt-5.5-medium
- [x] Tests: 10 isolated unit tests for `buildPromptTimelineView` covering null-for-unknown-run, empty timeline, meta-only, chronological merge with wire entries, `llm.request` summary, delta collapse, collapsed-gap semantics, mixed-offset timestamp sorting, error severity, 1-based entry numbering (`RunDiagnosticServicePromptTimelineTest.php`) — claude-sonnet-4-6, amp/gpt-5.5-medium
- [x] Livewire feature coverage for the Prompt Timeline query-parameter entry point, plus retained Run Inspector wire-log coverage for readable/raw drill-down and pagination — amp/gpt-5.5-medium

**Phase 3 follow-ups (surfaced during post-implementation review):**

- [x] Restore Wire Log card in Run Inspector so the readable transcript view, per-attempt anomaly summary, `wireLogStartEntry` / `wireLogLimit` paging, and per-entry deep-link route remain reachable — amp/gpt-5.5-medium
- [x] Fix `gap_ms` reset across collapsed deltas in `buildPromptTimelineView` so the next visible meta event measures from the last visible meta event, not a hidden delta — amp/gpt-5.5-medium
- [x] Replace `strcmp($a['timestamp'], $b['timestamp'])` sort with a parsed-instant comparison so mixed `Z` / offset timestamps sort chronologically — amp/gpt-5.5-medium
- [x] De-duplicate `buildPromptTimelineView` calls per render by caching per-run results inside `ControlPlane::render()` — amp/gpt-5.5-medium
- [x] Fix `tab=timeline&runId=...` mounting so chat/control-plane links load the Prompt Timeline tab instead of being forced back to Run Inspector — amp/gpt-5.5-medium
- [x] Remove dead code: `RunDiagnosticService::buildTurnView()`, `RunDiagnosticService::recentTurns()`, and `partials/turn-timeline.blade.php`. The `partials/wire-log-readable/` directory is not removed because it contains active readable-wire-log subpartials — amp/gpt-5.5-medium

### ID Standardization — `turnId` → `runId`

After Phase 3 shipped, a naming inconsistency was discovered: PHP returned `turnId` keys in `prepareStreamingRun()` and `formatActiveTurnPayload()` while Alpine JS used `selectedTurnId`, `currentTurnId`, and `turnRegistry` on the client side. This caused a regression where the stream URL was never opened (Alpine read `result.turnId` which was undefined once PHP was renamed in Phase 2).

**Scope:**

- [x] `HandlesStreaming::prepareStreamingRun()` return key: `turnId` → `runId` — claude-sonnet-4-6
- [x] `HandlesStreaming::formatActiveTurnPayload()` return key: `turnId` → `runId` — claude-sonnet-4-6
- [x] PHPDoc shapes in `HandlesStreaming` and `Chat`: `turnId: string` → `runId: string` — claude-sonnet-4-6
- [x] Alpine `agentChatStream` data: `selectedTurnId` → `selectedRunId`, `currentTurnId` → `currentRunId`, `turnRegistry` → `runRegistry` — claude-sonnet-4-6
- [x] All JS methods and references inside `chat.blade.php` updated to use `runId`-based naming throughout — claude-sonnet-4-6

### Phase 4 — Timeline memory safety and Wire Log consolidation

**Status:** Under consideration — problem documented, solution not yet chosen.

#### Problem

`buildPromptTimelineView()` calls `WireLogger::read()`, which slurps the entire JSONL file into a PHP array before merging with meta events. For a long-running agent a single run can produce tens of thousands of `llm.stream_line` entries. The Wire Log card faced this same crash when it rendered all entries at once; it solved it by introducing `WireLogger::preview()` — a file-offset window that decodes only N lines per request. The Timeline has no equivalent guard and will eventually crash PHP on a sufficiently large run.

Meta events are not the problem. Even the most active run produces at most hundreds of `AiRunEvent` rows — loading them fully is always safe. The danger is exclusively in the wire JSONL.

#### Constraints

1. **Two heterogeneous sources.** Wire entries are indexed by JSONL file line position; meta events are indexed by DB `seq` / `created_at`. A merged chronological view cannot be paginated by file offset alone — to show "page 3" (wire lines 200–300) you would need to know which meta events fall in that time window, but their timestamps are unknown until you read those lines.
2. **Stream deltas dominate volume.** `llm.stream_line` entries make up the bulk of any large JSONL file. With `collapseDelta=true` they are currently skipped via `continue`, but `WireLogger::read()` still materialises every line before the caller can skip anything.
3. **True chronological order requires the full merge.** Any windowing scheme that splits the merged stream into pages breaks global ordering — a meta event at time T may fall in wire page 5's time range but is invisible until the user navigates there.

#### Options

**Option A — Load meta fully, paginate wire by file offset (approximate order)**

Continue reading meta events in full (safe). Feed wire entries through the existing `preview()` window. Overlay the meta events that fall within the current wire window's timestamp range. Events outside the window are shown as a prologue/epilogue.

- Reuses existing pagination infrastructure with minimal change.
- Order is approximate: a meta event whose timestamp falls between two wire pages is shown at the boundary, not precisely in-sequence.
- Relatively low implementation risk.

**Option B — Streaming JSONL iterator, skip deltas, stop after N visible entries**

Replace `WireLogger::read()` with a line-by-line generator. The generator skips `llm.stream_line` entries when `collapseDelta=true` and stops after emitting N non-delta entries. The working set stays small regardless of file size. Meta events are loaded fully and merged against the streamed wire entries.

- Gives true chronological order for the visible window.
- Does not support random-access pagination (no "jump to entry 500") without a separate file offset index.
- Generator replaces the array contract of `read()` — callers need updating.
- Higher implementation cost; good long-term foundation.

**Option C — Eliminate the Timeline card; extend Wire Log to include meta events**

Remove `buildPromptTimelineView()` and the Prompt Timeline card entirely. Extend the Wire Log card (which already has safe pagination) to interleave meta events alongside wire entries. The Wire Log's readable mode (via `StreamAssembler`) is already a higher-level view immune to the line-count problem; meta event milestones could be injected as structural anchors.

- One card instead of two — removes the dual-surface confusion.
- The readable mode (`StreamAssembler`) is already the best view for most diagnostic needs; adding meta milestones there is lower-risk than a new merged raw view.
- The raw mode loses strict chronological ordering between meta and wire entries (same page-boundary problem as Option A).
- Eliminates Timeline-specific code (`buildPromptTimelineView`, `prompt-timeline.blade.php`, related Livewire state).

**Option D — Dual-panel layout in one card (meta rail + paginated wire)**

Show meta events as a fixed left-rail timeline (always loaded, always visible). Show wire entries in a paginated right panel. The two panels share a time axis visually but are not interleaved in a single list.

- Avoids the merge-pagination problem entirely by keeping the sources visually adjacent but separate.
- May require significant new layout work.
- Operators lose single-scroll chronological reading but gain stable navigation.

#### Decision criteria

- If **strict chronological order** across both sources matters for diagnosis: Option B is the only correct solution, at the cost of implementation work.
- If **approximate order** is acceptable and speed of delivery matters: Option A or C.
- If the **Wire Log readable mode** already answers 80 % of diagnostic questions and the Timeline is mostly used for the raw merged view: Option C (consolidate) is the most pragmatic.

#### Open questions

1. Does the Readable mode need meta event milestones to be diagnostically complete, or is the raw merged chronological view essential?
2. Is random-access pagination (jump to entry N) a requirement for long-run diagnosis, or is sequential streaming sufficient?
3. Should the JSONL file grow without bound, or should `WireLogger` rotate / cap file size at write time as a complementary safety measure?

---

### Phase 5 — Operator surface for non-chat runs (optional)

Once Phase 3 ships and the timeline is the primary diagnostic surface, lift the chat-only restriction and rename the legacy tab.

**Scope:**

- [ ] Drop the `source='chat'` filter on the Prompt Timeline run picker; surface background and orchestration runs alongside chat runs (note: today no source filter is applied — this is now about an explicit picker UX rather than lifting a guard)
- [ ] Decide whether a Session Inspector surface is still needed for session-level navigation (lists run envelopes per session, links into the Prompt Timeline) — the old Turn Inspector is already gone, so this is a green-field decision rather than a rename
- [ ] Decide whether background paths should emit minimal lifecycle events (`run.started`, `run.completed`, `run.failed`) for symmetric timeline rendering, or whether their wire-log-only timeline is acceptable
- [ ] Update `Tabs after refactor` to reflect the final state
