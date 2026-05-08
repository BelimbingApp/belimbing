# ai-control-plane-unified-timeline

**Status:** Phase 1 Complete — Phase 2 Designing
**Last Updated:** 2026-05-08
**Sources:** `backup/pre-unified-entity` branch (snapshot before Phase 1); DB backup `01kr2w72bssreaxjxy2gss0gqp`; wire log backup `storage/app/ai/wire-logs-backup-pre-unified/` (26 files, 75 MB — delete after Phase 2 is confirmed stable)
**Agents:** claude/sonnet-4-6

## Problem Essence

The control plane's Run Inspector and Turn Inspector tabs force operators to navigate between two surfaces and build a mental picture manually to answer a single question: what happened with this prompt?

## Desired Outcome

A unified Prompt Timeline view that interleaves turn meta-events (DB) and wire log entries (disk) onto one chronological axis, so an operator can diagnose any prompt end-to-end without switching tabs. The Turn Inspector tab becomes a Session Inspector, giving session-scoped entry into individual prompt timelines.

## Design Decisions

### Mental model

```
Session
└── Prompt  →  Turn (meta-run)           ← queue, cancel, phase, SSE stream
               └── Run (LLM execution)   ← wire log, transcript, token usage
                    └── AiRunCall ×N     ← individual LLM API call iterations
```

Turn is the lifecycle envelope — it exists before the run starts (queued/booting), narrates run sub-events, and closes after the run closes. Run is the execution record, created only when a worker begins the LLM call. In the normal path a prompt maps 1:1:1.

### Turn event taxonomy

**Meta-only** (not on the wire, not in the run — kept in the unified timeline):
- `turn.started`, `turn.phase_changed`, `turn.completed`, `turn.failed`, `turn.cancelled`, `turn.ready_for_input`
- `run.started`, `run.failed`
- `heartbeat`
- `tool.denied` (policy rejection before tool ran)

**Already captured by the run** (wire log or transcript — shown via wire log entries, not repeated as meta markers):
- `assistant.thinking_delta`, `assistant.thinking_started`, `assistant.iteration_completed`
- `assistant.output_delta`, `assistant.output_block_committed`
- `tool.started`, `tool.finished` (wire log `tool_use` / `tool_result` blocks)
- `tool.stdout_delta` (tool result content in transcript)
- `usage.updated` (wire log final chunk)

### Recovery events — removed (YAGNI)

`recovery.attempted`, `recovery.succeeded`, `recovery.failed` were designed for a multi-run retry model (provider fallback, worker crash re-dispatch) that was never implemented. The actual in-run retry (`chatWithRetry`) is silent and produces no turn events. Decision: errors surface directly to the user or calling program; programmatic callers handle retry themselves. The `chatWithRetry` silent retry within a run (transient HTTP errors) is unaffected — it is a provider-level reliability measure with no user-visible events.

### Unified timeline layout

Turn meta-events (DB) and wire log entries (disk) are interleaved by timestamp. They do not bracket each other cleanly — meta-events fire during wire activity at arbitrary points. Both sources are normalised to `{timestamp, source, type, payload}` and sorted chronologically.

```
[Prompt] ──────────────────────────────────────────────────────────
  ↓ [META] turn.started        queued              +0ms
  ↓ [META] turn.phase_changed  booting             +120ms
  ↓ [META] run.started         run_abc123          +340ms  → run detail
  ═ [WIRE] → REQUEST           messages[12]        +380ms
  ↓ [META] turn.phase_changed  awaiting LLM        +385ms
  ═ [WIRE] ← chunk             thinking delta      +410ms
  ═ [WIRE] ← chunk             thinking delta      +450ms
  ↓ [META] heartbeat           elapsed 500ms       +500ms
  ═ [WIRE] ← chunk             tool_use: bash      +900ms
  ↓ [META] tool.started        bash / ls -la       +902ms
  ═ [WIRE] → tool_result       stdout preview      +1200ms
  ↓ [META] tool.finished       bash / ok           +1202ms
  ↓ [META] turn.phase_changed  streaming answer    +1210ms
  ═ [WIRE] ← chunk             output delta        +1250ms
  ↓ [META] usage.updated       prompt 312 / comp 88 +1290ms
  ═ [WIRE] ← RESPONSE complete 512 tok / 88ms      +1300ms
  ↓ [META] turn.completed                          +1320ms
  ↓ [META] turn.ready_for_input                    +1325ms
───────────────────────────────────────────────────────────────────
```

`[META]` entries render with a distinct visual treatment (left-rail marker, muted background) to remain separable from `[WIRE]` entries without breaking the chronological flow.

### Two-source merge

Wire log entries live on disk (files via `WireLogger`). Turn events live in DB (`ai_chat_turn_events`). `buildRunView` must be extended (or a new `buildPromptTimelineView` introduced) to fetch both, normalise to a common shape, and merge by timestamp.

Some turn events reliably fall outside the wire log's time range — `turn.started`, `turn.phase_changed` (queued/booting), `run.started` precede the first HTTP request; `turn.completed` and `turn.ready_for_input` follow the last response — forming a natural prologue and epilogue. All other meta-events interleave within the wire segment.

### Gap diagnostics

`gap_ms` between events and stuck-turn detection are preserved on meta-event markers only (e.g. a long gap between `run.started` and the first wire entry signals a slow worker boot). Not applied to wire log entries.

### Filtering

Delta events (`assistant.thinking_delta`, `assistant.output_delta`, `tool.stdout_delta`) are high-volume. A toggle collapses them to show only structural wire entries (requests, responses, tool blocks) alongside meta markers — the common diagnostic view.

### Tabs after refactor

| Tab | Before | After |
|---|---|---|
| Run Inspector | Drill by run ID, wire log + transcript | Retained as deep-dive surface, reached via Prompt Timeline |
| Turn Inspector | Drill by turn ID, full event timeline | Becomes Session Inspector |
| Prompt Timeline | Does not exist | New: unified meta + wire log view |
| Health & Presence | Unchanged | Unchanged |
| Lifecycle Controls | Unchanged | Unchanged |

## Phases

### Phase 1 — Remove recovery logic (YAGNI)

- [x] Remove `TurnEventType` cases `RecoveryAttempted`, `RecoverySucceeded`, `RecoveryFailed` and their `severity()` / `label()` arms — claude-sonnet-4-6
- [x] Delete `PublishesRecoveryEvents` trait — claude-sonnet-4-6
- [x] Remove `use PublishesRecoveryEvents` from `TurnEventPublisher` — claude-sonnet-4-6
- [x] Remove `onRecoveryAttempted()`, `onRecoverySucceeded()`, and dispatch cases from `TurnStreamBridge` — claude-sonnet-4-6
- [x] Remove recovery tests from `TurnEventPublisherTest`, `TurnStreamBridgeTest`, `TurnContractEnumsTest`; update case count 22 → 19 — claude-sonnet-4-6

### Phase 2 — Merge Turn and Run into one unified entity

Since one prompt always produces one turn and one run (1:1:1 invariant, recovery removed in Phase 1), the two-table design adds indirection without benefit. With destructive migration evolution we can redesign from scratch as though they were always one thing.

**Design:**

One entity carries the full prompt lifecycle — pre-execution state (queued/booting), runtime execution state (running), and terminal state (completed/failed/cancelled), plus all run-specific data (provider, model, tokens, latency, error). The entity is created at prompt submission with a ULID; the runtime receives that ULID rather than generating its own `run_<random12>` ID.

```
ai_chat_turns (unified)
  id              ULID — primary key, used by wire log, turn events, run calls
  employee_id
  session_id
  acting_for_user_id
  status          queued → booting → running → completed / failed / cancelled
  current_phase   fine-grained phase label for UI busy signal
  current_label
  last_event_seq  SSE resume pointer
  cancel_requested_at
  runtime_meta    model override, page context, execution mode
  source          chat / background / …
  execution_mode
  timeout_seconds
  error_type
  error_message
  latency_ms
  meta            provider, model, token counts, tool actions (populated on completion)
  started_at
  finished_at
  created_at / updated_at
```

`current_run_id` is removed — it was a self-pointer.

Wire log files: `storage/app/ai/wire-logs/{ulid}.jsonl` — `WireLogger.path()` takes the entity ULID directly.

**What collapses:**
- `ai_runs` table and `AiRun` model — absorbed into `ai_chat_turns` / `ChatTurn`
- `run_<random12>` ID generation in `AgenticRuntime` — replaced by the entity ULID threaded in from `ChatTurnRunner`
- `RunRecorder.start()` insert — becomes an update on the existing entity row when execution begins
- `current_run_id` column
- The `turn_id → current_run_id` lookup needed by Phase 3 (Prompt Timeline)

**What is unchanged:**
- `ChatTurnEvent` and SSE stream — same FK, same contracts
- `AiRunCall` — `run_id` column renamed to reference unified entity ULID
- Lifecycle states and phase tracking
- `chatWithRetry` silent in-run retry
- **Activity Transcript UI** — `activity-transcript-card.blade.php`, transcript reading, message rendering; the data source is the transcript file (keyed by session), not the run row
- **Wire Log UI** — entries view, readable view (`WireLogReadableFormatter`), raw entry streaming (`WireLogEntryController`), anomaly detection, stream-block rendering; wire log files change only their naming key (ULID instead of `run_<random12>`), all parsing and rendering logic is untouched

**Scope:**
- [ ] Merge `ai_runs` columns into `ai_chat_turns` migration; remove `create_ai_runs_table` migration
- [ ] Merge `AiRun` model into `ChatTurn`; remove `AiRun` class
- [ ] `AgenticRuntime.runStream()` — accept `turnId` parameter; use it as the run ID instead of generating one
- [ ] `ChatTurnRunner` — thread entity ULID through to `AgenticRuntime`
- [ ] `RunRecorder.start()` — update existing entity row rather than insert; collapse into `ChatTurn` methods or keep as updater
- [ ] `WireLogger.path()` — accepts entity ULID (no format change needed, just the source of the ID)
- [ ] Update `ai_run_calls` migration — `run_id` references unified entity
- [ ] Update all service/query references that join or look up `ai_runs` separately — `RunDiagnosticService`, `ChatRunPersister`, `HealthAndPresenceService`, `MessageManager`, `ReapOrphanRunsCommand`
- [ ] Update tests
