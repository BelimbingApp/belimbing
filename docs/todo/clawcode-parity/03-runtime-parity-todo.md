# Runtime Parity Gaps — Detailed Implementation TODO

**Source:** `docs/todo/clawcode-parity/00-runtime-parity-gap-audit.md`
**Parent:** `docs/todo/ai-run-ledger.md` (Phase 0, Phase 2, Phase 3)
**Scope:** Everything the gap audit flags that is NOT the Phase 1 activity stream UX (covered by `02-phase-1-activity-stream-todo.md`)

---

## 1. Iteration Cap Removal (Verified Code/Doc Mismatch) — P0

The `ai-run-ledger.md` §2.2 marks iteration cap removal as `[x] Done`. The code still enforces it. This is the most straightforward parity bug.

**Gap audit §2.2:** "BLB is still materially behind claw-code on loop termination semantics, despite the TODO saying the opposite."

### 1.1 Remove from `AgenticRuntime` ✅

**File:** `app/Modules/Core/AI/Services/AgenticRuntime.php`

- [x] Remove `DEFAULT_MAX_ITERATIONS = 25` constant
- [x] Remove `maxIterations()` method
- [x] Remove `maxIterationsResult()` method
- [x] **Sync path** `runToolCallingLoop()`: replaced `for` loop with `while (true)`
- [x] **Streaming path** `runStreamingToolLoop()`: replaced `for` loop with `while (true)`

### 1.2 Remove from config ✅

**File:** `app/Base/AI/Config/ai.php`

- [x] Remove `'max_iterations' => 25` from `ai.llm`

### 1.3 Remove from `AiErrorType` ✅

**File:** `app/Base/AI/Enums/AiErrorType.php`

- [x] Remove `case MaxIterations = 'max_iterations'`
- [x] Remove `self::MaxIterations` from `userMessage()` match

### 1.4 Remove from `SpawnEnvelope` ✅

**File:** `app/Modules/Core/AI/DTO/Orchestration/SpawnEnvelope.php`

- [x] Remove `maxIterations` constructor parameter
- [x] Remove `'max_iterations'` from `toArray()`
- [x] Remove `@param  int  $maxIterations` from PHPDoc

### 1.5 Remove from `SpawnAgentSessionJob` ✅

**File:** `app/Modules/Core/AI/Jobs/SpawnAgentSessionJob.php`

- [x] Remove `$maxIterations = $envelope['max_iterations'] ?? 10`
- [x] Remove `maxIterations: $maxIterations` from `$runtime->run()` call (was a latent bug — `run()` did not accept this parameter)

### 1.6 Verify no other consumers ✅

- [x] Grep for `max_iterations`, `MAX_ITERATIONS`, `MaxIterations` across the codebase — all references removed
- [x] Test helper `makeSpawnEnvelope()` in `SessionSpawnManagerTest.php` updated

### Natural bounds (already in place)

These remain the only termination conditions — no new work needed:

| Bound | Where |
|-------|-------|
| User cancellation (Esc / SSE close) | Client closes EventSource; `ChatStreamController` stops yielding |
| Per-LLM-call timeout | `config('ai.llm.timeout')` — stays at 60s default |
| Context window exhaustion | Provider returns error when messages overflow |
| Queue job timeout | `RunAgentTaskJob::$timeout = 600` |

---

## 2. `ai_runs` as Projection, Not Sole Truth (Phase 0 Refinement) — P1

The gap audit's central architectural recommendation: keep `ai_runs` but demote it from "sole source of truth" to "indexed projection over the structured transcript."

**Gap audit §6.1:** "The parity-safe implementation is: (1) transcript = canonical execution history, (2) ai_runs = indexed metadata/routing/analytics, (3) OperationDispatch = async lifecycle envelope only."

### 2.1 Clarify the source-of-truth hierarchy in `ai-run-ledger.md`

- [x] Add a design decision to `ai-run-ledger.md` §Design Decisions — Added as item 9: "Transcript is the canonical execution history."

> **Transcript is the canonical execution history.** The v2 JSONL transcript stores the full runtime timeline (messages, tool calls, tool results, thinking entries, usage-bearing assistant turns). `ai_runs` is an indexed projection for querying, routing, run detail pages, and correlation with `OperationDispatch`. If they disagree, the transcript wins.

### 2.2 Ensure `ai_runs` can be reconstructed from transcript

**File:** `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php` (new, from Phase 0 §0.3)

- [x] Add `reconstructFromTranscript(int $employeeId, string $sessionId, string $runId): void` method
  - Reads v2 transcript entries for the given `runId`
  - Computes tool actions, timing, and outcome from transcript entries
  - Upserts `ai_runs` row
- [x] This is a repair/migration tool, not the hot path — `RunRecorder::start()`/`complete()`/`fail()` remain the normal write path

### 2.3 Usage data in transcript entries

The gap audit notes that claw-code embeds `TokenUsage` inline on assistant messages, allowing usage reconstruction from the session alone.

**File:** `app/Modules/Core/AI/Services/MessageManager.php` (changed from Message.php — persistence happens in `appendAssistantMessage()`)

- [x] When persisting the final assistant `type: 'message'` entry, include usage in meta: `meta.tokens = { prompt: N, completion: N }` — done via `extractTranscriptMeta()` helper in `MessageManager::appendAssistantMessage()`
- [x] This is additive — `ai_runs` still stores the same data for query purposes, but the transcript is now self-sufficient for usage reconstruction

### 2.4 Update `RunInspectionService` to query `ai_runs` first, transcript as fallback

**File:** `app/Modules/Core/AI/Services/ControlPlane/RunInspectionService.php`

Phase 0 §0.7 already plans to rewrite this to query `ai_runs` directly. The refinement:

- [x] `inspectRun()` — query `ai_runs` first; if row missing (pre-migration data), fall back to transcript scan — Note: transcript fallback not added (requires employee/session context not available from runId alone); pre-migration data is not a concern per destructive evolution policy
- [x] `inspectSession()` — `AiRun::where('session_id', $sessionId)` as primary path
- [x] `inspectDispatchRun()` — `AiRun::where('dispatch_id', $dispatchId)` — no more session scanning

---

## 3. Session DTO Cleanup (Phase 0 §0.5 Complement) — P1 ✅

The `Session` DTO still carries the `$runs` map that Phase 0 is removing. The gap audit implicitly flags this by showing how claw-code's session stores structured messages, not a separate runs map.

> **All items completed in prior work.**

### 3.1 Remove `Session::$runs`

**File:** `app/Modules/Core/AI/DTO/Session.php`

- [x] Remove `public array $runs = []` property (line 20)
- [x] Remove `runs` from `fromMeta()` (line 39)
- [x] Remove `runs` from `toMeta()` (lines 60–62)

### 3.2 Add `transcript_version` to `Session`

**File:** `app/Modules/Core/AI/DTO/Session.php` (Phase 0 §0.8)

- [x] Add `public int $transcriptVersion = 1` property
- [x] Read from `$data['transcript_version'] ?? 1` in `fromMeta()`
- [x] Write `'transcript_version' => $this->transcriptVersion` in `toMeta()`
- [x] New sessions created with `transcriptVersion: 2`

### 3.3 Remove `SessionManager::storeRunMeta()` and `SessionManager::runMetadata()`

**File:** `app/Modules/Core/AI/Services/SessionManager.php` (Phase 0 §0.5)

- [x] Remove `storeRunMeta()` method
- [x] Remove `runMetadata()` method
- [x] Remove any callers (already tracked in Phase 0 §0.5 checklist)

---

## 4. `AiRuntimeLogger` Removal (Phase 0 §0.6 + §0.11) — P1 ✅

The gap audit agrees this is correct: with `ai_runs` as canonical store, the dedicated logger is YAGNI.

> **All items completed in prior work.** `AiRuntimeLogger` was removed, `ai` logging channel removed, consumers migrated to `RunRecorder` or standard `report()`.

### 4.1 Migrate consumers

| Consumer | File | Replacement |
|----------|------|-------------|
| `AgenticRuntime` (constructor DI) | `AgenticRuntime.php:44` | `RunRecorder` |
| `RuntimeResponseFactory` (constructor DI) | `RuntimeResponseFactory.php` | `RunRecorder` |
| `ProviderTestService` (constructor DI) | `ProviderTestService.php` | Default `Log` channel |
| `Chat.php` catch block | `Chat.php:173` | `report($e)` (standard Laravel) |
| `RunAgentTaskJob` catch block | `RunAgentTaskJob.php` | `report($e)` |
| `SpawnAgentSessionJob` catch block | `SpawnAgentSessionJob.php:117` | `report($e)` |
| `Base/AI/ServiceProvider` | `ServiceProvider.php` | Remove singleton registration |

- [x] Migrate each consumer per table
- [x] Delete `app/Base/AI/Services/AiRuntimeLogger.php`
- [x] Remove `ai` channel from `config/logging.php`
- [x] Remove singleton registration from `Base/AI/ServiceProvider`

### 4.2 Diagnostic string preservation

- [x] Store raw diagnostic strings (cURL errors, HTTP excerpts) in `ai_runs.meta.diagnostic` for admin inspection — handled via `ai_runs.error_type` + `error_message` columns plus `meta` JSON for diagnostic detail
- [x] These are the only values `AiRuntimeLogger` held that `ai_runs` error columns don't capture

---

## 5. Hook Outcome Visibility in Runtime Timeline — P2

The gap audit §3 notes that claw-code's `HookRunner` can deny a tool (exit code 2) and merges hook feedback back into the conversation. BLB has `RuntimeHookCoordinator` with 5 stages but hook outcomes are metadata-only — not visible in the activity stream or transcript.

### 5.1 Current state

BLB's hook stages: `PreContextBuild`, `PreToolRegistry`, `PreLlmCall`, `PostToolResult`, `PostRun`.
Hook outcomes are stored in `$hookMetadata` (a flat map) and included in run meta.
The activity stream and transcript do not represent hook executions as first-class entries.

### 5.2 Surface hook outcomes in activity stream

- [x] When `PreToolRegistry` removes tools, emit a transcript entry: `{type: 'hook_action', meta: {stage: 'pre_tool_registry', tools_removed: [...]}}` — stored in `hookMetadata['pre_tool_registry_removed']` and emitted as SSE `hook_action` phase event in streaming path
- [x] When `PostToolResult` hook executes, include hook outcome summary in the tool_result transcript entry's meta — already happens via `$hookMetadata` passed to `RuntimeHookCoordinator::postToolResult()`
- [x] When `PostRun` hook executes, include outcome in run-level meta (already happens via `$hookMetadata`)

### 5.3 Add `PreToolUse` hook stage (parity with claw-code)

Claw-code has `PreToolUse` that can **deny** a tool call before execution. BLB's `PostToolResult` runs **after**. This is a parity gap for tool approval workflows.

- [x] Add `HookStage::PreToolUse` case to `app/Modules/Core/AI/Enums/HookStage.php`
- [x] Add `preToolUse(runId, employeeId, toolName, arguments)` to `RuntimeHookCoordinator`
- [x] Hook returns `{ denied: bool, reason: string }` — if denied, skip execution, emit `tool_result` with denial reason
- [x] Call in `executeToolCallsWithHooks()` before `$this->toolRegistry->execute()` — denial generates tool message with denial reason
- [x] Emit transcript entry for denied tools: `{type: 'tool_result', meta: {tool, status: 'denied', reason: '...'}}` — denial action stored in `$toolActions` with `status: 'denied'`

### 5.4 SSE event for hook actions (if streaming)

- [x] Extend `runStreamingToolLoop()` to yield a `status` event with `phase: 'hook_action'` when PreToolRegistry removes tools
- [x] Extend streaming tool loop to yield `phase: 'tool_denied'` when PreToolUse hook denies a tool call
- [x] The activity stream renders this as a subtle annotation on the affected tool card

---

## 6. Approval / Permission Escalation Observability — P2

The gap audit §3 identifies claw-code's `PermissionPolicy` with explicit modes (`read-only`, `workspace-write`, `danger-full-access`, `prompt`, `allow`) and `PermissionPrompter` for escalation as a parity gap. BLB has capability-gated tools via `AgentToolRegistry` but no runtime-visible approval surface.

### 6.1 Current BLB state

- Tools are gated by `AuthorizationService::can(actor, capability)` in `AgentToolRegistry::currentUserCanUse()`
- This is a binary yes/no at registration time — no mid-run escalation
- The user never sees "Tool X needs permission Y" in the chat

### 6.2 Design consideration (defer detailed implementation)

Full permission escalation is a major feature. For parity awareness, document the gap and plan the surface:

- [x] Add a "Runtime Parity Appendix" section to `ai-run-ledger.md` documenting: — Already present in `ai-run-ledger.md` §Runtime Parity Appendix item 1
  - BLB uses capability-gated tool registration (binary, pre-run)
  - Claw-code uses runtime permission escalation (interactive, mid-run)
  - The gap: BLB cannot prompt users for elevated permissions during a run
  - Future work: `PermissionEscalation` DTO, SSE event for approval prompts, client-side approval UI

### 6.3 Immediate observability improvement

Even without full escalation, surface tool authorization decisions in the activity stream:

- [x] When `AgentToolRegistry::execute()` is called for a tool the user lacks permission for, the existing error path already returns a `ToolResult` with `isError = true`. Ensure this is visible as a tool_result entry with `status: 'denied'` in the transcript (ties into §5.2 transcript persistence work) — Already functional: `AgentToolRegistry::execute()` returns `ToolResult::error('...', 'permission_denied')` which flows through `executeToolCall()` → `error_payload.code = 'permission_denied'` in action metadata. Additionally, `PreToolUse` hook can deny tools before they reach the registry.

---

## 7. Sandbox Observability — P2

The gap audit §3 notes claw-code's `BashCommandOutput` includes `sandboxStatus` describing what was requested, supported, and actually active. BLB has no equivalent runtime surface.

### 7.1 Current BLB state

- BLB does not have a sandboxed execution environment for tools
- The browser tool has its own process isolation but no runtime-reported sandbox status
- This is mostly a future concern — BLB's tool risk classification (`riskClass()`) is the closest analog

### 7.2 Design consideration (defer)

- [x] Add to the "Runtime Parity Appendix" in `ai-run-ledger.md` — Already present in `ai-run-ledger.md` §Runtime Parity Appendix item 2
  - Claw-code reports sandbox request vs. active status per bash execution
  - BLB tools declare `riskClass()` but do not report execution isolation status at runtime
  - Future work: tool execution report could include `{isolation: 'none'|'process'|'container', requested: '...', active: '...'}`

---

## 8. Usage Reconstruction from Transcript (Phase 3 Enhancement) — P2

The gap audit §2.3 notes claw-code's `UsageTracker::from_session()` reconstructs cumulative usage from session messages. BLB can only compute usage per-run from `ai_runs` or run metadata.

### 8.1 Enable session-level usage aggregation

**File:** `app/Modules/Core/AI/Services/MessageManager.php`

- [x] Add `sessionUsage(int $employeeId, string $sessionId): array` method
  - Queries `ai_runs` table first (fast path) via `AiRun::where(employee_id, session_id)`
  - Falls back to v2 transcript scanning if no `ai_runs` rows exist
  - Returns `{ total_prompt_tokens: N, total_completion_tokens: N, run_count: N }`

### 8.2 Surface in UI

- [ ] Show cumulative session token usage in the session list or session header (Phase 3 — after `ai_runs` is live, this can also be computed via `AiRun::where('session_id', $sessionId)->sum('prompt_tokens')`) — **deferred to Phase 3 UI**
- [ ] The transcript-based path is the fallback for sessions without `ai_runs` rows — **deferred to Phase 3 UI**

---

## 9. `RunInspection` DTO Enrichment — P1 ✅

When `ai_runs` becomes the data source (Phase 0 §0.7), `RunInspection` should carry richer data already available in the new schema but not currently exposed.

### 9.1 Add fields from `ai_runs` schema ✅

**File:** `app/Modules/Core/AI/DTO/ControlPlane/RunInspection.php`

- [x] Add `source` (chat, stream, delegate_task, orchestration, cron)
- [x] Add `executionMode` (interactive, background)
- [x] Add `status` (running, succeeded, failed, cancelled, timed_out) — replaces the string `outcome`
- [x] Add `timeoutSeconds` — configured timeout for this run
- [x] Add `startedAt`, `finishedAt` — precise timestamps
- [x] Add `actingForUserId` — the human user on whose behalf

### 9.2 Update `fromRunMeta()` → `fromAiRun()` ✅

- [x] Add `static fromAiRun(AiRun $run): self` factory method that maps the Eloquent model directly
- [x] Keep `fromRunMeta()` as a v1/fallback path for pre-migration data
- [x] `RunInspectionService` uses `fromAiRun()` when querying the DB

### 9.3 Update `run-detail.blade.php` ✅

**File:** `resources/core/views/livewire/admin/ai/control-plane/partials/run-detail.blade.php`

- [x] Display the new fields: source badge, execution mode, status (with proper badge variant), timeout budget vs. latency, timestamps, acting-for user
- [x] Reuse in the standalone run route page (Phase 1 §1.3) — `resources/core/views/livewire/admin/ai/run-detail.blade.php` `@include`s the partial

---

## 10. `ai-run-ledger.md` Document Corrections — P0 ✅

Fix the doc/code drift the gap audit identified.

### 10.1 Iteration cap status ✅

- [x] Iteration cap removal implemented (§1) — `ai-run-ledger.md` §2.2 `[x]` checkboxes are now correct

### 10.2 Add Runtime Parity Appendix ✅

- [x] Added "Runtime Parity Appendix" section to `ai-run-ledger.md` covering permission escalation, sandbox observability, hook outcome visibility, and usage reconstruction gaps
- [x] Added design decision §9 ("Transcript is the canonical execution history")

### 10.3 Remove visibility contradiction ✅

- [x] Removed "Non-operator users: tool/thinking entries hidden, only final response shown" from `ai-run-ledger.md` §1.1

---

## File Change Summary

| File | Change | Priority |
|------|--------|----------|
| `app/Modules/Core/AI/Services/AgenticRuntime.php` | Remove iteration cap (const, methods, loop bounds) | P0 |
| `app/Base/AI/Config/ai.php` | Remove `max_iterations` | P0 |
| `app/Base/AI/Enums/AiErrorType.php` | Remove `MaxIterations` case | P0 |
| `app/Modules/Core/AI/DTO/Orchestration/SpawnEnvelope.php` | Remove `maxIterations` | P0 |
| `app/Modules/Core/AI/Jobs/SpawnAgentSessionJob.php` | Remove `maxIterations` usage | P0 |
| `docs/todo/ai-run-ledger.md` | Fix iteration cap status, add parity appendix, remove visibility contradiction | P0 |
| `app/Modules/Core/AI/DTO/Session.php` | Remove `$runs`, add `$transcriptVersion` | P1 |
| `app/Modules/Core/AI/Services/SessionManager.php` | Remove `storeRunMeta()`, `runMetadata()` | P1 |
| `app/Base/AI/Services/AiRuntimeLogger.php` | Delete entirely | P1 |
| `app/Modules/Core/AI/DTO/ControlPlane/RunInspection.php` | Add fields, `fromAiRun()` factory | P1 |
| `app/Modules/Core/AI/Services/ControlPlane/RunInspectionService.php` | Rewrite to query `ai_runs` | P1 |
| `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php` | Add `reconstructFromTranscript()` | P1 |
| `app/Modules/Core/AI/Enums/HookStage.php` | Add `PreToolUse` | P2 |
| `app/Modules/Core/AI/Services/RuntimeHookCoordinator.php` | Add `preToolUse()` | P2 |
| `app/Modules/Core/AI/Services/MessageManager.php` | Add `sessionUsage()` | P2 |

## Implementation Order

```text
1. Iteration cap removal (§1) — P0 bug fix, no dependencies, immediate
2. ai-run-ledger.md corrections (§10) — doc hygiene, do alongside §1
3. Phase 0 landing (§2–4, §9) — ai_runs table, RunRecorder, Session cleanup, logger removal, RunInspection enrichment
4. Hook outcome visibility (§5) — after Phase 1 activity stream delivers the transcript format
5. Approval/sandbox documentation (§6–7) — planning only, defer implementation
6. Usage reconstruction (§8) — after Phase 0 ai_runs + Phase 1 transcript v2 are live
```

§1 is the only item that should be done immediately — it's a verified code/doc mismatch with no dependencies. Everything else sequences naturally after Phase 0 or Phase 1 work from `ai-run-ledger.md`.
