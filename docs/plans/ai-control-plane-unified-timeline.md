# ai-control-plane-unified-timeline

**Status:** Phase 4 Complete (Option C: consolidated on Run Inspector / Wire Log)
**Last Updated:** 2026-05-09
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

> **Note (post-implementation):** Phase 3 originally intended to add Prompt Timeline as a 5th tab next to an unchanged Turn Inspector. The shipped change collapsed that early — Turn Inspector was removed and Prompt Timeline took its tab slot — so the surface was 4 tabs through Phase 3. Phase 4 (Option C) retired the Prompt Timeline tab and consolidated lifecycle milestones into the Run Inspector / Wire Log card, leaving **3 control-plane drill-down tabs** (Run Inspector, Health & Presence, Lifecycle Controls).

| Tab | Before | After (Phase 3 — shipped) | After Phase 4 (planned) |
|---|---|---|---|
| Run Inspector | Drill by run ID, wire log + transcript | Retained as deep-dive surface; embeds Prompt Timeline inline and keeps the Wire Log card for readable/raw transport drill-down | **Single drill-down surface.** Embedded Prompt Timeline removed; Wire Log card is the canonical view, annotated with `AiRunEvent` lifecycle milestones in both readable and raw modes |
| Turn Inspector | Drill by turn ID, full event timeline | **Removed** — collapsed early into Prompt Timeline | (removed) |
| Prompt Timeline | Does not exist | **New** — replaces Turn Inspector tab; chat + non-chat run IDs accepted (no source filter) | **Removed.** Merged-list surface retired; meta milestones move into the Wire Log card. Tab slot freed (or repurposed in Phase 5) |
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

### Phase 4 — Consolidate on Run Inspector / Wire Log (Option C)

**Status:** Complete.

#### Decision

The Prompt Timeline experiment proved the merged raw timeline is the wrong abstraction for this workload. We **retire the Prompt Timeline as a separate surface** and **consolidate diagnostics on Run Inspector / Wire Log**, annotating it with bounded `AiRunEvent` lifecycle milestones. The two-source storage split (DB = bounded lifecycle facts, JSONL = unbounded transport log) stays — the mismatch was at the *surface*, not at storage.

**Why not the alternatives:** Option A (paginate wire by file offset, overlay meta) keeps the same misleading "one merged list" model with fuzzy boundary placement. Option B (streaming JSONL iterator + cursor) is the right primitive *if* exact merged chronology is ever a real product requirement, but right now it solves a memory problem more than a product problem and adds machinery without a clear payoff. Option D (dual-panel layout) preserves the split visually instead of simplifying it. Unifying storage to a single source (DB-only or JSONL-only) was considered and rejected: DB-only ingests tens of thousands of `llm.stream_line` rows per long run and pollutes the operational store; JSONL-only loses cheap control-plane queries (recent runs, stuck-run detection, billing aggregates) and contradicts the DB-first usage accounting rule.

#### Outcomes

- Single drill-down surface — Run Inspector embeds the Wire Log card (readable + raw) and is the canonical answer to "what happened in this run?"
- `AiRunEvent` lifecycle milestones are visible alongside wire content without claiming exact full-log chronological interleave
- No operator path can crash PHP by reading an unbounded JSONL — `WireLogger::read()` is removed from operator code; `preview()` is hard-capped even on long delta bursts
- Tab count drops back to 3 control-plane drill-down surfaces (Run Inspector, Health & Presence, Lifecycle Controls); the freed tab slot is left empty for now and revisited in Phase 5

#### Honesty in copy

The Wire Log card surfaces are labelled honestly:

- **Readable** — reconstructed diagnostic transcript (via `StreamAssembler`) with lifecycle milestones injected as structural anchors between attempts / stream blocks
- **Raw** — paginated transport view with a compact meta rail summarising lifecycle events for the run, plus in-window highlighting of meta events whose timestamps fall inside the current `preview()` window
- We do **not** imply exact full-log merged chronology between meta and wire entries

#### Scope

- [x] Retire the merged-list service: delete `RunDiagnosticService::buildPromptTimelineView()` and its private helpers (`collapsedDeltaEntry`, `timelineTimestampOrder`, `wireEntryLabel`, `wireEntrySummary` — keep the wire entry labelling helpers if needed by readable mode), and drop `RunDiagnosticServicePromptTimelineTest.php` — amp/gpt-5.5-medium
- [x] Remove `partials/prompt-timeline.blade.php` and any `prompt-timeline*` partials it pulls in — amp/gpt-5.5-medium
- [x] Strip Prompt Timeline state and methods from `ControlPlane`: `inspectTimelineRunId`, `timelineCollapseDelta`, `timelineError`, `inspectTimeline`, `toggleTimelineDelta`, `inspectTimelineFromRun`, the `timeline` branch of `mount()`/`updatedActiveTab()`, the `inspectorTimeline` / `timelineView` view-model entries, and the `buildTimelineViews()` helper — amp/gpt-5.5-medium
- [x] Remove the `timeline` tab from `control-plane.blade.php` and any links/buttons that route into it (chat, control-plane row actions); replace those entry points with Run Inspector links keyed by run ULID — amp/gpt-5.5-medium
- [x] Inject lifecycle milestones into Wire Log readable mode: add a service method on `RunDiagnosticService` (or a focused collaborator under `Services/ControlPlane/WireLog/`) that loads ordered `AiRunEvent` rows for a run and annotates the `StreamAssembler` output with structural milestones (`run.started`, `run.phase_changed`, terminal markers, `tool.denied`, heartbeats with `gap_ms` warnings); milestones render as a distinct semantic-token block, not interleaved as fake transport rows — amp/gpt-5.5-medium
- [x] Add a compact meta rail to Wire Log raw mode: render a top-of-card summary of meta events for the run (count by type, terminal status, phase progression) and visually mark wire entries whose surrounding window contains meta events; this rail is always safe because meta event count is bounded — amp/gpt-5.5-medium
- [x] Delete `WireLogger::read()` (operator-facing path); confirm no remaining callers other than tests, then drop the method and its tests. If a small internal helper is needed by the milestone injector, expose only a bounded `eachEntry(callable $visitor): void` generator-style API that decodes one JSONL line at a time without building a full array — amp/gpt-5.5-medium
- [x] Cap `WireLogger::preview()`'s "extend through stream block" behaviour so a pathological consecutive `llm.stream_line` burst cannot grow the working set past a hard ceiling — collapse trailing deltas in the page into a single summarised placeholder when the cap is hit (mirrors the existing `PREVIEW_ENTRY_LIMIT_MAX` intent) — amp/gpt-5.5-medium
- [x] Tests: drop `RunDiagnosticServicePromptTimelineTest`; add coverage for (a) milestone injection into readable output, (b) the meta-rail summary in raw mode, (c) the `preview()` delta-burst cap behaviour, (d) the Run Inspector view-model returning milestones for a run with both events and wire entries; existing Wire Log readable/raw and pagination tests stay — amp/gpt-5.5-medium
- [x] Cleanup: remove dead helpers, dead translation keys (`Run ID is required.` if only the timeline used it), and any blade partials no longer referenced; update the `Tabs after refactor` table preamble note to reflect 3 drill-down tabs — amp/gpt-5.5-medium

#### Risks and guardrails

- **Risk:** removing the Prompt Timeline tab will look like backing out of Phase 3. **Guardrail:** frame in commit/release notes as destructive evolution — the Prompt Timeline experiment validated the merged view's UX cost and discovered a memory hazard; the lessons (meta milestones + wire-first surface) are kept, the unsafe surface is dropped.
- **Risk:** operators who relied on the merged raw stream lose strict chronological reading. **Guardrail:** readable mode already answers most diagnostic questions; the meta rail in raw mode preserves "what lifecycle events occurred" without faking interleave; if exact merged chronology becomes a real recurring need, Option B (cursor-based merged reader with a JSONL byte-offset index) is the correct next step — not storage unification.
- **Risk:** `preview()`'s stream-block extension cap could hide useful deltas. **Guardrail:** the placeholder shows the suppressed range and count, mirroring the existing collapsed-delta UX; readable mode remains the authoritative reconstruction.

---

### Phase 5 — Operator surface for non-chat runs (optional)

After Phase 4, Run Inspector is the single drill-down surface. Phase 5 hardens the experience for non-chat runs and decides whether to repurpose the freed tab slot.

**Scope:**

- [ ] Confirm Run Inspector accepts non-chat run ULIDs cleanly (no `source='chat'` assumption in lookups, view-models, or links from chat / control-plane lists); add a focused test for a `source='background'` run rendering with empty meta rail and full wire content
- [ ] Decide whether a Session Inspector surface is still needed for session-level navigation (lists run envelopes per session, links into Run Inspector); if yes, this is the natural occupant of the freed tab slot from Phase 4
- [ ] Decide whether background paths should emit minimal lifecycle events (`run.started`, `run.completed`, `run.failed`) so the meta rail stays useful for non-chat runs, or whether their wire-log-only diagnostics are acceptable
- [ ] Update `Tabs after refactor` to reflect the final state once Phase 5 lands
