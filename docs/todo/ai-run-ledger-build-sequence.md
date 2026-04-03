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

- [ ] **§0.3 orphan reaper: `blb:ai:runs:reap-orphans`** — `ai-run-ledger.md` §0.3
  - Command: mark `running` rows older than `2× max timeout` as `failed` with `error_type = 'orphaned'`
  - Register in Laravel scheduler (every 5 min)

- [ ] **§0.9: Session file cleanup** — `ai-run-ledger.md` §0.9
  - Archive existing v1 session files
  - Only needed if dev workspace has pre-migration sessions

- [x] **§0.10: Streaming path `RunRecorder` in `ChatStreamController`** — `ai-run-ledger.md` §0.10
  - `ChatStreamController` already receives events from `AgenticRuntime` which now calls `RunRecorder`.
  - Verify no double-writes. If `ChatStreamController` has its own metadata persistence, remove it.
  - This may already be satisfied by the §0.4 work — verify.

- [ ] **§9.1: `RunInspection` DTO enrichment** — `03-runtime-parity-todo.md` §9
  - Add `source`, `executionMode`, `status`, `timeoutSeconds`, `startedAt`, `finishedAt`, `actingForUserId`
  - Update `fromAiRun()` to map the new fields
  - Update `run-detail.blade.php` to display new fields

### Tier 2: Phase 1 Backend (sequential — each step feeds the next)

> **Prerequisite:** Tier 1 complete (especially `AiRuntimeLogger` removal, since `AgenticRuntime` constructor changes).

- [ ] **Step 1: Transcript entry persistence** — `02-phase-1-activity-stream-todo.md` §1.2
  - Add `MessageManager::appendToolCall()`, `appendToolResult()`, `appendThinking()`
  - Version-aware `read()` (§1.3) — skip unknown types gracefully

- [ ] **Step 2: SSE event enrichment** — `02-phase-1-activity-stream-todo.md` §2
  - Extend `tool_started` event: `args_summary`, `started_at`, `tool_call_index`
  - Extend `tool_finished` event: `result_preview`, `result_length`, `duration_ms`, `status`, `error_payload`
  - Add `hrtime()` timing in streaming tool loop

- [ ] **Step 3: Stream persistence** — `02-phase-1-activity-stream-todo.md` §3
  - `ChatStreamController` persists thinking/tool_call/tool_result entries as they stream
  - Extract `run_id` from first status event for all subsequent persistence calls

### Tier 3: Phase 1 Frontend (sequential — build bottom-up)

> **Prerequisite:** Tier 2 Step 3 complete (backend emits enriched events and persists them).

- [ ] **Step 4: Alpine state machine** — `02-phase-1-activity-stream-todo.md` §4
  - Replace `pendingMessage`/`streamingContent`/`streamingStatus` with `streamEntries[]`
  - Rewrite SSE event handlers to push typed entries

- [ ] **Step 5: Blade activity components** — `02-phase-1-activity-stream-todo.md` §5.1
  - Create `resources/core/views/components/ai/activity/` component set
  - `entry.blade.php`, `user-message.blade.php`, `thinking.blade.php`, `tool-call.blade.php`, `assistant-result.blade.php`, `error.blade.php`

- [ ] **Step 6: Timeline renderer** — `02-phase-1-activity-stream-todo.md` §5.2–5.3
  - Replace bubble `@forelse` with type-branching timeline
  - Wire live `streamEntries` rendering with `x-for`

- [ ] **Step 7: Follow-tail scroll** — `02-phase-1-activity-stream-todo.md` §6
  - `followTail` boolean, scroll listener, "Jump to latest" floating button

- [ ] **Step 8: Run metadata popover** — `02-phase-1-activity-stream-todo.md` §7
  - Replace hover tooltip with click popover
  - Data sourced from `ai_runs` (already batch-loaded)

- [ ] **Step 9: Streaming markdown** — `02-phase-1-activity-stream-todo.md` §8
  - Choose Option A (incremental) or B (drafting frame)
  - Debounced rendering for live assistant text

- [ ] **Step 10: Collapsible tool result blocks** — `02-phase-1-activity-stream-todo.md` §9
  - Progressive-disclosure cards with expand/collapse
  - Live: spinner → finished; persisted: collapsed by default

### Tier 4: Phase 1 Standalone (independent of Tier 3)

- [ ] **Standalone run route** — `02-phase-1-activity-stream-todo.md` §10
  - `GET /admin/ai/runs/{runId}` → Livewire page with full run metadata + activity timeline
  - Access: session ownership check

### Tier 5: Phase 0/1 Complement (after Tiers 1–3 land)

- [ ] **§2.1–2.4: Source-of-truth hierarchy** — `03-runtime-parity-todo.md` §2
  - `RunRecorder::reconstructFromTranscript()` repair tool
  - Usage data (`meta.tokens`) in transcript assistant entries
  - `RunInspectionService` transcript fallback for pre-migration data

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
