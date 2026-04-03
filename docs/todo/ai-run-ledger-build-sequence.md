# AI Run Ledger — Build Sequence Checklist

**Purpose:** Ordered implementation checklist for remaining work. Items with dependencies must be done in sequence; independent items are grouped.

**Master plan:** `docs/todo/ai-run-ledger.md`
**Detailed TODOs:** `docs/todo/clawcode-parity/02-phase-1-activity-stream-todo.md`, `docs/todo/clawcode-parity/03-runtime-parity-todo.md`

---

## What's Done

| Block | Status |
|-------|--------|
| Iteration cap removal (Phase 0 P0, `03-runtime-parity-todo.md` §1) | ✅ |
| `ai-run-ledger.md` corrections — visibility contradiction, design decision §9, parity appendix (`03-runtime-parity-todo.md` §10) | ✅ |
| `ai_runs` migration + `AiRun` model + `AiRunStatus` enum (`ai-run-ledger.md` §0.2–0.3) | ✅ |
| `RunRecorder` service — `start()`, `complete()`, `fail()`, `attachDispatch()`, `find()` (`ai-run-ledger.md` §0.3) | ✅ |
| Message DTO `type` field — backward-compatible v1/v2 (`02-phase-1-activity-stream-todo.md` §1.1) | ✅ |
| Session DTO `transcriptVersion` field (`ai-run-ledger.md` §0.8) | ✅ |
| Runtime integration — `RunRecorder` wired into `AgenticRuntime` sync + streaming paths (`ai-run-ledger.md` §0.4) | ✅ |
| Remove duplicated storage — `Session::$runs`, `storeRunMeta()`, `runMetadata()` removed (`ai-run-ledger.md` §0.5) | ✅ |
| Read path migration — `RunInspectionService` queries `ai_runs`, `MessageManager::read()` hydrates from `ai_runs` (`ai-run-ledger.md` §0.7) | ✅ |
| `AiRuntimeLogger` removal — all 7 consumers migrated, class deleted, `ai` channel removed (`ai-run-ledger.md` §0.6 + §0.11) | ✅ |
| Streaming path verification — `ChatStreamController` delegates to `AgenticRuntime` which uses `RunRecorder`; no double-writes (`ai-run-ledger.md` §0.10) | ✅ |
| `RunInspection::fromAiRun()` factory + signature cleanup (`03-runtime-parity-todo.md` §9.2, partial) | ✅ |
| Orphan reaper command `blb:ai:runs:reap-orphans` + scheduler (`ai-run-ledger.md` §0.3) | ✅ |
| Session file cleanup — v1 sessions archived (`ai-run-ledger.md` §0.9) | ✅ |
| `RunInspection` DTO enrichment — 7 new fields + blade (`03-runtime-parity-todo.md` §9.1) | ✅ |
| Transcript entry persistence — `MessageManager` append methods + version-aware read (`02-phase-1-activity-stream-todo.md` §1) | ✅ |
| SSE event enrichment — `tool_started`/`tool_finished` with args, timing, preview (`02-phase-1-activity-stream-todo.md` §2) | ✅ |
| Stream persistence — `ChatStreamController` persists thinking/tool entries during streaming (`02-phase-1-activity-stream-todo.md` §3) | ✅ |
| Alpine state machine — `streamEntries[]` replaces 3-variable stream state (`02-phase-1-activity-stream-todo.md` §4) | ✅ |
| Blade activity components — 6 components in `components/ai/activity/` (`02-phase-1-activity-stream-todo.md` §5.1) | ✅ |
| Timeline renderer — type-branching `@forelse` + live `x-for` rendering (`02-phase-1-activity-stream-todo.md` §5.2–5.3) | ✅ |
| Follow-tail scroll — `followTail` boolean, scroll listener, "Jump to latest" button (`02-phase-1-activity-stream-todo.md` §6) | ✅ |
| Run metadata popover — click-triggered popover with tokens/latency/retries (`02-phase-1-activity-stream-todo.md` §7) | ✅ |
| Streaming markdown — Option B drafting frame with "Writing…" indicator (`02-phase-1-activity-stream-todo.md` §8) | ✅ |
| Collapsible tool result blocks — expand/collapse cards with status badges (`02-phase-1-activity-stream-todo.md` §9) | ✅ |
| Standalone run route — `GET /admin/ai/runs/{runId}` with ownership check (`02-phase-1-activity-stream-todo.md` §10) | ✅ |
| Source-of-truth hierarchy — `reconstructFromTranscript()`, usage in transcript, `ai_runs`-first inspection (`03-runtime-parity-todo.md` §2) | ✅ |
| Session DTO cleanup — `$runs` removed, `transcriptVersion` added, stale methods removed (`03-runtime-parity-todo.md` §3) | ✅ |
| `AiRuntimeLogger` removal — all consumers migrated, class deleted, channel removed (`03-runtime-parity-todo.md` §4) | ✅ |

---

## Remaining Work — Ordered

### Tier 1: Phase 0 Completion (no cross-dependencies, do in any order)

These finish Phase 0. None depend on each other.

- [x] **§0.6 + §0.11: Remove `AiRuntimeLogger`** — `03-runtime-parity-todo.md` §4
  - Migrate 7 consumers per the table in `ai-run-ledger.md` §0.11
  - `AgenticRuntime` + `RuntimeResponseFactory`: replace `$runtimeLogger` with `RunRecorder`
  - `Chat.php`, `RunAgentTaskJob`, `SpawnAgentSessionJob`: replace with `report($e)`
  - `ProviderTestService`: use default log channel
  - Delete `AiRuntimeLogger.php`, remove `ai` channel from `config/logging.php`, remove singleton from `Base/AI/ServiceProvider`
  - Store raw diagnostics in `ai_runs.meta.diagnostic`

- [x] **§0.3 orphan reaper: `blb:ai:runs:reap-orphans`** — `ai-run-ledger.md` §0.3
  - Command: mark `running` rows older than `2× max timeout` as `failed` with `error_type = 'orphaned'`
  - Register in Laravel scheduler (every 5 min)
  - Registered in `ServiceProvider::boot()`, scheduled via `withSchedule()` in `bootstrap/app.php`

- [x] **§0.9: Session file cleanup** — `ai-run-ledger.md` §0.9
  - Archive existing v1 session files
  - Only needed if dev workspace has pre-migration sessions
  - **Implementation notes (✅ done):**
    - 4 v1 meta.json files existed at `storage/app/workspace/1/sessions/6/`
    - Archived to `storage/app/workspace/1/sessions-archived-v1/`

- [x] **§0.10: Streaming path `RunRecorder` in `ChatStreamController`** — `ai-run-ledger.md` §0.10
  - `ChatStreamController` already receives events from `AgenticRuntime` which now calls `RunRecorder`.
  - Verify no double-writes. If `ChatStreamController` has its own metadata persistence, remove it.
  - This may already be satisfied by the §0.4 work — verify.

- [x] **§9.1: `RunInspection` DTO enrichment** — `03-runtime-parity-todo.md` §9
  - Added 7 new fields: `source`, `executionMode`, `status`, `timeoutSeconds`, `startedAt`, `finishedAt`, `actingForUserId`
  - Updated `fromAiRun()` to map all new fields from `AiRun` model
  - Updated `toArray()` with all new fields
  - Updated `run-detail.blade.php`: Status badge via `AiRunStatus::color()`/`label()`, Source badge, Execution Mode, Timeout Budget with used-time comparison, Acting For User, Started/Finished timestamps

### Tier 2: Phase 1 Backend (sequential — each step feeds the next)

> **Prerequisite:** Tier 1 complete (especially `AiRuntimeLogger` removal, since `AgenticRuntime` constructor changes).

- [x] **Step 1: Transcript entry persistence** — `02-phase-1-activity-stream-todo.md` §1.2
  - Add `MessageManager::appendToolCall()`, `appendToolResult()`, `appendThinking()`
  - Version-aware `read()` (§1.3) — skip unknown types gracefully

- [x] **Step 2: SSE event enrichment** — `02-phase-1-activity-stream-todo.md` §2
  - Extend `tool_started` event: `args_summary`, `started_at`, `tool_call_index`
  - Extend `tool_finished` event: `result_preview`, `result_length`, `duration_ms`, `status`, `error_payload`
  - Add `hrtime()` timing in streaming tool loop

- [x] **Step 3: Stream persistence** — `02-phase-1-activity-stream-todo.md` §3
  - `ChatStreamController` persists thinking/tool_call/tool_result entries as they stream
  - Extract `run_id` from first status event for all subsequent persistence calls

### Tier 3: Phase 1 Frontend (sequential — build bottom-up)

> **Prerequisite:** Tier 2 Step 3 complete (backend emits enriched events and persists them).

- [x] **Step 4: Alpine state machine** — `02-phase-1-activity-stream-todo.md` §4
  - Replaced `pendingMessage`/`streamingContent`/`streamingStatus` with `streamEntries[]`
  - Rewrote SSE event handlers to push typed entries (thinking, tool_call, assistant_streaming, error)

- [x] **Step 5: Blade activity components** — `02-phase-1-activity-stream-todo.md` §5.1
  - Created `resources/core/views/components/ai/activity/` component set
  - `entry.blade.php`, `user-message.blade.php`, `thinking.blade.php`, `tool-call.blade.php`, `assistant-result.blade.php`, `error.blade.php`

- [x] **Step 6: Timeline renderer** — `02-phase-1-activity-stream-todo.md` §5.2–5.3
  - Replaced bubble `@forelse` with type-branching timeline (thinking, tool_call, tool_result, user, error, action, assistant)
  - Live `streamEntries` rendered with `x-for` loop

- [x] **Step 7: Follow-tail scroll** — `02-phase-1-activity-stream-todo.md` §6
  - `followTail` boolean state, scroll listener with 50px threshold
  - "Jump to latest" floating button with down-arrow icon
  - Conditional auto-scroll only when following tail

- [x] **Step 8: Run metadata popover** — `02-phase-1-activity-stream-todo.md` §7
  - Replaced hover tooltip with click-triggered popover in `message-meta.blade.php`
  - Popover shows: ID, status, tokens (prompt→completion), latency/timeout, retry/fallback counts, error details
  - Keyboard accessible: click toggle, Escape to close, aria-expanded
  - Run ID display truncated to 8 chars with full ID in popover

- [x] **Step 9: Streaming markdown** — `02-phase-1-activity-stream-todo.md` §8
  - Chose Option B: visual "drafting" frame with "Writing…" indicator
  - Streaming text shown with slight opacity, pulsing dot, "Writing…" label
  - On `done`, Livewire re-render replaces with server-rendered markdown seamlessly

- [x] **Step 10: Collapsible tool result blocks** — `02-phase-1-activity-stream-todo.md` §9
  - Progressive-disclosure cards: header always visible, expand/collapse for details
  - Live: spinner (running) → finished with status badge; Persisted: collapsed by default
  - Error payload styled separately with code/message display

### Tier 4: Phase 1 Standalone (independent of Tier 3)

- [x] **Standalone run route** — `02-phase-1-activity-stream-todo.md` §10
  - `GET /admin/ai/runs/{runId}` → `RunDetail` Livewire page
  - Access: employee ownership check (run's employee_id must match current user)
  - Reuses `run-detail.blade.php` partial for metadata display
  - Activity transcript filtered to run-related entries
  - Route registered as `admin.ai.runs.show`

### Tier 5: Phase 0/1 Complement (after Tiers 1–3 land)

- [x] **§2.1–2.4: Source-of-truth hierarchy** — `03-runtime-parity-todo.md` §2
  - `RunRecorder::reconstructFromTranscript()` repair tool
  - Usage data (`meta.tokens`) in transcript assistant entries via `MessageManager::extractTranscriptMeta()`
  - `RunInspectionService` queries `ai_runs` as primary path; transcript fallback skipped per destructive evolution policy

### Tier 6: Phase 2 (independent — after Phase 0+1 stable)

- [ ] **Execution policy** — `ai-run-ledger.md` §2.3–2.6
  - `ExecutionPolicy` DTO, three-tier timeout config
  - `RunAgentChatJob` for background chat execution
  - Timeout retry policy fix

### Tier 7: P2 Parity Items (deferred — after Phase 2)

- [ ] **Hook outcome visibility** — `03-runtime-parity-todo.md` §5
- [ ] **Approval/permission escalation observability** — `03-runtime-parity-todo.md` §6
- [ ] **Sandbox observability** — `03-runtime-parity-todo.md` §7
- [ ] **Usage reconstruction** — `03-runtime-parity-todo.md` §8

---

## Dependency Graph

```
Tier 1 (parallel)
├── AiRuntimeLogger removal
├── Orphan reaper
├── Session file cleanup
├── Streaming path verification
└── RunInspection enrichment
        │
        ▼
Tier 2 (sequential)
  Step 1: Transcript entry types
  Step 2: SSE enrichment
  Step 3: Stream persistence
        │
        ▼
Tier 3 (sequential)          Tier 4 (independent)
  Step 4: Alpine state        Standalone run route
  Step 5: Blade components
  Step 6: Timeline renderer
  Step 7: Follow-tail scroll
  Step 8: Run popover
  Step 9: Streaming markdown
  Step 10: Collapsible tools
        │
        ▼
Tier 5: Source-of-truth complement
        │
        ▼
Tier 6: Phase 2 execution policy
        │
        ▼
Tier 7: P2 parity items
```
