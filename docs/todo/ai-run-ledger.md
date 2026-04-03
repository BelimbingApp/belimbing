# AI Run Ledger & Production Readiness

**Problem:** Run data is scattered across session `meta.json`, JSONL transcripts, `ai.log`, and `OperationDispatch` — no single source of truth for "what happened in run X?"

**Scope:** Agent-agnostic — applies to Lara, Kodi, and any future agent.

---

## Phase 0 — `ai_runs` Table (Canonical Run Ledger)

Introduce a database table as the single authoritative store for every LLM run. All other stores either link to it or stop duplicating its data.

### 0.1 Why a DB Table?

The current run data lives in four places, each with a different access pattern:

| Store | What it holds today | Problem |
|---|---|---|
| `meta.json` `runs` map | Provider, model, tokens, latency, error, retry/fallback | File-scoped, not queryable, not routable, requires session context to find |
| `transcript.jsonl` | Duplicates run meta inline on assistant messages | Redundant — meta already in session file; bloats transcript |
| `ai.log` | Same error/retry/fallback data as structured log lines | Grep-only, no programmatic lookup, rotated after 30 days |
| `OperationDispatch` | `run_id`, `meta`, `error_message` for async jobs | Only covers async runs; mixes queue lifecycle with LLM runtime facts |

A DB table solves all of these: globally queryable, routable, authz-gatable, indexed, and survives log rotation.

### 0.2 Schema: `ai_runs`

```
id                  string PK       — the run_id (e.g. "run_aBcDeFgHiJkL")
employee_id         FK employees    — agent that executed the run
session_id          string nullable  — chat session ID (null for headless/cron runs)
acting_for_user_id  FK users null    — user on whose behalf (null for system-initiated)
dispatch_id         string nullable  — linked OperationDispatch ID (null for interactive)
source              string           — origin: 'chat', 'stream', 'delegate_task', 'orchestration', 'cron'
execution_mode      string           — 'interactive' or 'background'
status              string           — 'running', 'succeeded', 'failed', 'cancelled', 'timed_out'
provider_name       string nullable
model               string nullable
timeout_seconds     int nullable     — configured timeout for this run
latency_ms          int nullable     — actual elapsed time
prompt_tokens       int nullable
completion_tokens   int nullable
retry_attempts      json nullable    — [{provider, model, error, error_type, latency_ms}]
fallback_attempts   json nullable    — same shape
tool_actions        json nullable    — [{tool, result_length}]
error_type          string nullable  — AiErrorType value on failure
error_message       string nullable  — user-safe error message
meta                json nullable    — sanitized extras (hooks, orchestration context — never secrets/prompts)
started_at          timestamp null
finished_at         timestamp null
created_at          timestamp
updated_at          timestamp
```

**Indexes:** `session_id`, `dispatch_id`, `employee_id`, `acting_for_user_id`, `status`, `created_at`.

**Never stored:** API keys, full prompts, full response bodies, user content, PII.

### 0.3 `AiRun` Model + `RunRecorder` Service

- [x] Create `AiRun` Eloquent model at `app/Modules/Core/AI/Models/AiRun.php`
- [x] Create `RunRecorder` service at `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php`
  - `start(string $runId, int $employeeId, ...)` — inserts row with status `running`
  - `complete(string $runId, array $meta)` — updates to `succeeded` with latency/tokens/tools
  - `fail(string $runId, AiRuntimeError $error)` — updates to `failed` with error details
  - `attachDispatch(string $runId, string $dispatchId)` — links async dispatch after the fact
  - `find(string $runId): ?AiRun`
- [x] Create migration `create_ai_runs_table`

#### State Machine & Idempotency

Valid status transitions (enforced in `RunRecorder`):

```
running → succeeded
running → failed
running → cancelled
running → timed_out
```

**Invariants:**
- `start()` is **insert-only** — if `run_id` already exists, it is a no-op (idempotent). Protects against double-start from retry paths.
- `complete()` and `fail()` only transition from `running` — calls on a terminal row are no-ops. Prevents a late retry callback from overwriting an already-recorded outcome.
- `attachDispatch()` is a nullable FK write — safe to call multiple times (last write wins, always the same dispatch).
- All terminal transitions set `finished_at` atomically with `status`.
- `RunRecorder` methods never throw on idempotent no-ops — they return silently. The caller should not need to check current status before calling.

#### `ai_runs` ↔ `OperationDispatch` Cardinality

```
OperationDispatch 1 ←──── 0..* AiRun
```

- One dispatch may trigger **multiple runs** (e.g., a queued task that retries with a different provider produces a new `run_id` per attempt).
- One run belongs to **at most one** dispatch (interactive chat runs have `dispatch_id = null`).
- The `dispatch_id` FK is set by `RunRecorder::attachDispatch()` or at `start()` time if known.
- `OperationDispatch` does not store run-level details anymore — it links to `ai_runs` for that.

#### Orphaned Run Reaper

If a process crashes mid-run, the `ai_runs` row stays in `running` forever. A scheduled reaper marks these as failed.

- [x] Add `blb:ai:runs:reap-orphans` command
- [x] Logic: `WHERE status = 'running' AND started_at < NOW() - INTERVAL ? SECONDS` → mark `failed` with `error_type = 'orphaned'`, `error_message = 'Run did not complete — process may have crashed'`
- [x] Threshold: `2× max timeout` (default: 2×180 = 360s for heavy foreground; 2×600 = 1200s for background)
- [x] Register in Laravel scheduler: run every 5 minutes
- [x] The reaper is **safe to run concurrently** — uses `WHERE status = 'running'` with `updated_at` guard to avoid racing with a legitimate completion

### 0.4 Runtime Integration

- [x] `AgenticRuntime::run()` and `runStream()` call `RunRecorder::start()` at run begin
- [x] On success: `RunRecorder::complete()` with provider, model, latency, tokens, tool actions
- [x] On failure: `RunRecorder::fail()` with the `AiRuntimeError`
- [x] `RunAgentTaskJob` calls `RunRecorder::attachDispatch()` for background runs

### 0.5 Remove Duplicated Storage

- [x] Remove `Session::$runs` property
- [x] Remove `SessionManager::storeRunMeta()` and `SessionManager::runMetadata()`
- [x] Remove run metadata from `Session::toMeta()` / `Session::fromMeta()`
- [x] `MessageManager::appendAssistantMessage()` — stop calling `storeRunMeta()`
- [x] Keep JSONL assistant messages with `run_id` only (no inline meta duplication)

### 0.6 Remove `AiRuntimeLogger` & Dedicated `ai` Log Channel

With `ai_runs` as the canonical store, the dedicated `ai.log` is redundant:

| Logger method | Disposition |
|---|---|
| `runFailed()` | Covered by `ai_runs` error columns |
| `retryAttempted()` | Covered by `ai_runs.retry_attempts` JSON |
| `streamFailed()` | Covered by `ai_runs` error columns |
| `providerPayloadInvalid()` | Covered by `ai_runs` error + meta |
| `unhandledException()` | Move to standard `laravel.log` (app crash, not AI-specific) |
| `providerTestCompleted()` | Move to standard `laravel.log` (admin one-off, not a run) |

- [x] Remove `AiRuntimeLogger` service
- [x] Remove `ai` channel from `config/logging.php`
- [x] `AgenticRuntime` — replace all `$this->runtimeLogger->*()` calls with `RunRecorder` writes
- [x] `Chat.php` catch block — replace `AiRuntimeLogger::unhandledException()` with `report()` (standard Laravel)
- [x] Provider test service — log via default channel instead of `AiRuntimeLogger::providerTestCompleted()`
- [x] Store raw diagnostic strings (cURL errors, HTTP excerpts) in `ai_runs.meta.diagnostic` for admin inspection

### 0.7 Read Path Migration

- [x] `MessageManager::read()` — collect assistant `run_id`s, batch-query `ai_runs`, hydrate message meta from DB
- [x] Remove `MessageManager::enrichMessageMetadata()` session-file enrichment
- [x] `RunInspectionService` — rewrite to query `ai_runs` directly instead of scanning session files
  - `inspectRun(string $runId)` — single DB lookup
  - `inspectSession(string $sessionId)` — `where session_id = ?`
  - `inspectDispatchRun(string $dispatchId)` — `where dispatch_id = ?`

### 0.8 Transcript Schema Versioning

The JSONL transcript format changes with the activity stream (new entry types). Define a versioning contract so future schema changes don't silently corrupt the read path.

**Schema version:** Embed in session `meta.json` as `"transcript_version": 2`.

| Version | Format |
|---|---|
| 1 (current) | `{role, content, timestamp, run_id?, meta?}` — messages only |
| 2 (new) | Adds `type` field: `message` (default), `tool_call`, `tool_result`, `thinking` |

**Read path contract:**
- `MessageManager::read()` checks `transcript_version` from session meta
- Unknown/missing version → treat as v1 (backward compatible: all lines are messages)
- v2 reader parses `type` field and constructs appropriate display entries
- Reader never crashes on unrecognized `type` — skip unknown types gracefully

**Redaction rules:**
- Tool call `args` are persisted but may contain user-provided values (search queries, file paths). These are operational context, not secrets — acceptable to persist.
- Tool `result` content is **not** persisted in JSONL — only `result_length` and a truncated preview (≤200 chars). Full results live only in runtime memory during the run.
- `ai_runs.meta.diagnostic` may contain provider error strings — never user content or credentials.

- [x] Add `transcript_version` field to `Session` DTO and `meta.json`
- [x] New sessions created with `transcript_version: 2`
- [x] `MessageManager::read()` — version-aware parser with v1 fallback
- [x] Document redaction boundaries in code comments

### 0.9 Session File Cleanup

Existing v1 session files lack `transcript_version` and contain `runs` maps that no longer exist. Archive before wiping.

- [x] Archive existing sessions: `mv storage/app/workspace/*/sessions/ storage/app/workspace/*/sessions-archived-v1/`
- [x] Log the archive location in migration output — skipped (manual archive, not a migration)
- [x] New sessions created with v2 schema going forward
- [x] Archived sessions can be deleted manually after verification (no automated purge)

### 0.10 Streaming Path Integration

The streaming path (`ChatStreamController` → `AgenticRuntime::runStream()`) also persists run metadata. It must use `RunRecorder` instead of the removed session meta path.

- [x] `ChatStreamController` — call `RunRecorder::start()` before streaming begins
- [x] On `done` event — call `RunRecorder::complete()` with captured meta
- [x] On `error` event — call `RunRecorder::fail()` with error data
- [x] `ChatStreamController` — extend SSE events to emit tool call details for the activity stream (tool name, args summary, result preview)

### 0.11 Remove `AiRuntimeLogger` Consumers

`AiRuntimeLogger` is injected in 7 places. All must be migrated to `RunRecorder` or standard `report()`.

| Consumer | Replacement |
|---|---|
| `AgenticRuntime` (constructor DI) | `RunRecorder` |
| `RuntimeResponseFactory` (constructor DI) | `RunRecorder` |
| `ProviderTestService` (constructor DI) | Default log channel |
| `Chat.php` (`unhandledException`) | `report($e)` |
| `RunAgentTaskJob` (`unhandledException`) | `report($e)` |
| `SpawnAgentSessionJob` (`unhandledException`) | `report($e)` |
| `Base/AI/ServiceProvider` (singleton registration) | Remove |

- [x] Migrate each consumer per table above
- [x] Delete `AiRuntimeLogger.php`
- [x] Remove `ai` channel from `config/logging.php`
- [x] Remove singleton registration from `Base/AI/ServiceProvider`

---

## Phase 1 — Transparent Agent Activity Stream

Replace the bubble-based chat with an activity stream that shows every step. All users get full visibility — nothing is hidden behind authorization.

### 1.1 Activity Stream UX (Replace Chat Bubbles)

Replace the current bubble-based chat with an **agent activity stream** that shows every step in real-time — thinking, tool calls, results, and final response — like modern CLI coding agents.

**Why:** The bubble UX hides the agent's work behind a loading spinner. The activity stream makes every step visible, which:
- Eliminates the need for an inspector panel (the conversation IS the inspection)
- Makes timeouts understandable (you see which step failed)
- Gives real-time progress for long-running tasks (no "is it stuck?" anxiety)

**Stream entry types:**
- 💭 **Thinking** — reasoning/planning phase indicator
- 🔧 **Tool call** — tool name + arguments visible, result collapsible
- ✅ **Result** — final assistant text response
- ❌ **Error** — failure with error type and user-safe message

**Rendering rules:**
- User messages remain visually distinct (right-aligned or prompt-style)
- Tool call blocks: show tool name + key args inline, result collapsed by default (expand on click)
- Thinking indicators: subtle, timestamped
- Final response: full-width prose (no bubble)
- Errors: warning-styled block with error type

**Streaming integration:**
- Already have SSE events: `status` (thinking, tool_started, tool_finished) and `delta` (text chunks)
- Extend `status` events to include tool name and arguments
- Render each event as a persistent entry instead of an ephemeral indicator
- On `done`: finalize the activity stream and persist structured transcript

**Transcript persistence:**
- JSONL entries gain a `type` field: `'message'`, `'tool_call'`, `'tool_result'`, `'thinking'`
- Tool entries: `{type: 'tool_call', tool: 'web_search', args: {...}, result_length: 2340, run_id: '...'}`
- Final response remains `{role: 'assistant', content: '...', run_id: '...'}`

- [x] Define transcript entry types and JSONL schema extension
- [x] Extend SSE `status` events to include tool name, args summary, and result preview
- [x] Replace bubble rendering with activity stream layout in `chat.blade.php`
- [x] Persist tool call / thinking entries to JSONL transcript
- [x] Alpine.js streaming handler: render each event as a persistent DOM entry
- [x] Collapsible tool result blocks (expand on click)

### 1.2 Run Metadata Popover

Lightweight popover on the run ID for metadata the activity stream doesn't show inline. Visible to all users — no authz gating.

**Popover contents:**
- Token usage (prompt / completion)
- Retry + fallback attempt history
- Raw diagnostic string (on failure)
- Timeout budget vs. actual latency

- [x] Replace current run ID tooltip with a rich popover (click to open, not hover)
- [x] Popover data sourced from `ai_runs` (batch-loaded with messages)

### 1.3 Standalone Run Route (Deep-linking)

For sharing runs via alerts, audit trails, or cross-referencing. Access controlled by session ownership (Lara: per-user isolation; supervised agents: supervisor check) — not a separate capability.

- [x] Route: `GET /admin/ai/runs/{runId}` → name `admin.ai.runs.show`
- [x] Lightweight page showing full run metadata + activity timeline
- [x] Access: session ownership check only (existing `assertCanAccessAgent` pattern)

---

## Phase 2 — Execution Policy (Long-Running Tasks)

Replace blind 60s timeout + retry with a policy that matches execution strategy to task complexity.

### 2.1 Problem

The 60s default timeout is a **budget failure** for tasks like "draft and save a document" — the task genuinely needs more time. Retrying the same request with the same 60s budget will fail identically.

### 2.2 ✅ Remove Iteration Cap (Done)

**Problem:** `MAX_ITERATIONS = 10` was hardcoded in `AgenticRuntime`. Coding tasks requiring multiple file reads, edits, and verifications routinely hit this cap, producing `max_iterations` errors.

**Decision: Remove entirely — no iteration cap.**

The tool-calling loop should run until the model produces a final text response, an error occurs, or the context window is exhausted. This matches how production coding agents (Amp, Claude Code, Codex) work. An iteration cap can only cause false negatives — killing legitimate work that was progressing fine.

**The user decides when to stop — not the framework.** For interactive runs, the user presses Esc to cancel. The agent works until it's done or the user says stop. No artificial limits.

**Natural bounds that already exist:**
- **User cancellation (Esc)** — the primary stop mechanism for interactive runs. The user is in control.
- **Per-LLM-call timeout** — catches a single API call hanging (`ai.llm.timeout`). Stays as-is.
- **Context window** — when messages overflow, the provider returns an error. This is the real iteration bound.
- **Queue job timeout** — for background runs, Laravel's job timeout is the bound.

**Why not a high cap (e.g., 50)?** Any fixed number is arbitrary and will eventually be wrong. The model naturally terminates by producing a text response. Pathological loops hit the context window or per-call timeout anyway. Cost concerns (if needed) belong at a different layer — per-run cost budget, not iteration count.

- [x] Remove `MAX_ITERATIONS` constant from `AgenticRuntime`
- [x] Remove `AiErrorType::MaxIterations` (dead code — loop never hits a cap)
- [x] Both sync and streaming tool-calling loops run unbounded (`while (true)`)
- [x] Remove `maxIterationsResult()` helper
- [x] Remove `ai.llm.max_iterations` config key

### 2.3 Three-Tier Execution Policy

| Tier | Mode | Timeout (per LLM call) | When |
|---|---|---|---|
| **Interactive** | Streaming | 60–90s | Q&A, short edits, simple tool calls |
| **Heavy foreground** | Streaming | 120–180s | Medium drafting, multi-tool analysis, image analysis |
| **Background** | OperationDispatch + queue | 5–10 min | Doc drafting/saving, complex coding, multi-file edits |

### 2.4 Implementation

- [x] Create `ExecutionPolicy` DTO: `mode` (interactive/background), `timeout_seconds`, `allow_retry` — `app/Modules/Core/AI/DTO/ExecutionPolicy.php`
- [x] `AgenticRuntime` accepts optional `ExecutionPolicy` parameter (overrides config defaults) — 5th param on `run()` and `runStream()`
- [x] Config: `ai.llm.timeout_tiers` with interactive/heavy/background defaults — `ExecutionPolicy::forMode()` reads from config with sensible fallbacks
- [x] On timeout of an interactive run: auto-offload to background with notice message — `Chat::sendMessage()` detects timeout in result meta, calls `handleTimeoutWithBackgroundOffer()` which creates `OperationDispatch` and dispatches `RunAgentChatJob`

### 2.5 Background Chat Execution

- [x] Create `RunAgentChatJob` — chat-specific queued job via `OperationDispatch` lifecycle — `app/Modules/Core/AI/Jobs/RunAgentChatJob.php`
- [x] Job flow: load dispatch → `markRunning()` → `AgenticRuntime::run()` → persist assistant message → `markSucceeded()` (or `markFailed()`)
- [x] Chat UI shows progress for background runs — `HandlesBackgroundChat` trait with `pollBackgroundChat()`, Alpine polling widget, cancel button
  - "Queued…" → "Running in background…" → "Completed" with auto-refresh
- [x] Progress tracked via `OperationDispatch` status transitions (queued → running → succeeded/failed/cancelled)

### 2.6 Timeout Retry Policy Fix

- [x] When `AiErrorType::Timeout` occurs on a run that already used the full budget, do **not** retry with the same timeout — in `chatWithRetry()` at latency >= 50% budget
- [x] Only retry timeout if the failure was clearly transient (e.g., < 50% of budget elapsed)

---

## Phase 3 — Future Enhancements (Post-Launch)

Deferred until the core ledger + activity stream prove stable in daily use. Each item is independent.

### Run Query API

Helpers for operators and automated tooling to query runs:
- `AiRun::forSession(string $sessionId)` — scope
- `AiRun::forEmployee(int $employeeId)` — scope
- `AiRun::failed()` — scope
- `AiRun::recentlyFailed(int $minutes = 60)` — scope
- Admin page: filterable run list with status/provider/model/date filters

### Tool Action Timing & Status

Extend `tool_actions` JSON from `[{tool, result_length}]` to include per-tool timing:
`[{tool, args_summary, result_length, started_at, finished_at, duration_ms, status}]`

Enables: "which tool is slow?", "which tool failed?", per-tool latency dashboards.

### Cost Attribution

Add `estimated_cost_usd` column to `ai_runs`. Computed from token counts × model pricing from the catalog. Enables per-agent, per-user, per-session cost reporting.

### Parent/Child Run Causality

Add `parent_run_id` nullable FK to `ai_runs`. When a delegated agent spawns a sub-run, the child links back to the parent. Enables: tracing a multi-agent chain, understanding "why did this run happen?"

### Background Progress Contract

Formalize progress updates for background chat runs:
```
ai_runs.meta.progress = {
    phase: 'running_tools',      // enum: queued, booting, thinking, running_tools, finalizing, completed, failed
    label: 'Drafting document…', // human-readable
    step: 3,                     // current step
    total_steps: 5,              // estimated total (nullable)
    updated_at: '2026-04-02T08:32:00Z'
}
```

**Phase enum:** `queued` → `booting` → `thinking` → `running_tools` → `finalizing` → `completed` (or `failed` from any phase).

Chat UI polls or receives push updates to render live progress for queued runs.

### Anomaly Detection

Flag runs that deviate from normal patterns:
- Latency > 3× median for the same model
- Token usage > 3× median for the same agent
- Repeated failures on the same provider within a window
- Stored as `ai_runs.meta.anomalies` array, surfaced in admin views

### Retention & Export

Prevent unbounded `ai_runs` growth in production:
- Configurable retention period (e.g., `ai.runs.retention_days = 90`)
- `blb:ai:runs:prune` command — deletes rows older than retention, keeps failed/anomalous runs longer
- Export to compressed JSONL before pruning for long-term analytics: `blb:ai:runs:export --before=2026-01-01`
- Session transcript archival follows the same lifecycle — old JSONL files compressed alongside their run exports

### Run Inspection API

JSON endpoint for external tooling (tickets, dashboards, Slack bots):
- `GET /api/ai/runs/{runId}` — returns `RunInspection` DTO as JSON
- `GET /api/ai/runs?session_id=...&status=failed&after=...` — filtered list
- Auth: API token or session cookie, scoped by same ownership rules as the web UI
- No prompts/content in API responses — same redaction rules as the DB

### Replay & Diff Views

Compare two runs side-by-side for debugging regressions or provider differences:
- Select two runs (same session or cross-session) → side-by-side tool action timeline
- Highlight differences: different tools called, different tool order, latency delta, token delta
- Useful for: "why did the same question work yesterday but fail today?"

---

## Design Decisions

1. **`ai_runs` is not `OperationDispatch`:** OperationDispatch is an async job envelope (queued → running → done). `ai_runs` is an LLM call record. Not all runs are async; not all dispatches are LLM calls. They link by `dispatch_id` but serve different purposes.
2. **JSONL stays as content, not metadata:** Transcripts are the chat history — what was said. Run facts (provider, latency, tokens) belong in the run ledger, not duplicated per message line.
3. **`ai.log` removed — YAGNI:** With `ai_runs` as canonical store, the dedicated log channel duplicates 90% of what the DB already holds. Unhandled exceptions go to `laravel.log` (where all app crashes go). Raw cURL diagnostics live in `ai_runs.meta.diagnostic`.
4. **Execution policy over global timeout increase:** Raising timeout globally hurts responsiveness for normal chat and still doesn't solve progress visibility or durability for truly long tasks.
5. **No prompts/responses in `ai_runs`:** Sanitized metadata only, never user content or secrets.
6. **Transparent by default:** All users see the full activity stream, tool calls, errors, and run metadata. No authz gating on visibility — the agent has nothing to hide.
7. **Supersedes `ai-runtime-robustness.md` Phase 1.2 & logging:** The `AiRuntimeFailure` model deferred there is replaced by `ai_runs`. The `AiRuntimeLogger` and `ai` channel introduced there are removed as YAGNI once `ai_runs` exists.
8. **No iteration cap — user decides when to stop:** The model naturally terminates by producing a final text response. For interactive runs, the user presses Esc to cancel — they are always in control. Artificial iteration limits (the old `MAX_ITERATIONS = 10`) only cause false negatives — killing legitimate work mid-progress. Natural bounds handle the rest: per-LLM-call timeout, context window exhaustion, and queue job timeout. Cost budgeting (if needed) belongs at a higher layer, not as an iteration counter.
9. **Transcript is the canonical execution history:** The v2 JSONL transcript stores the full runtime timeline (messages, tool calls, tool results, thinking entries, usage-bearing assistant turns). `ai_runs` is an indexed projection for querying, routing, run detail pages, and correlation with `OperationDispatch`. If they disagree, the transcript wins.

---

## Runtime Parity Appendix

Gaps identified by `docs/todo/clawcode-parity/00-runtime-parity-gap-audit.md` that go beyond the core ledger/activity-stream work. These are documented for awareness and future planning — not blocking Phase 0–2 delivery.

1. **Permission escalation:** BLB uses binary capability-gated tool registration (`AgentToolRegistry` + `AuthorizationService`). Claw-code uses runtime permission escalation with interactive prompts (`PermissionPolicy` + `PermissionPrompter`). Future: `PreToolUse` hook stage that can deny a tool call before execution, with an SSE event for approval prompts.
2. **Sandbox observability:** Claw-code reports sandbox request vs. active status per bash execution (`sandboxStatus` in `BashCommandOutput`). BLB tools declare `riskClass()` but do not report execution isolation status at runtime. Future: tool execution isolation report in activity stream entries.
3. **Hook outcome visibility:** Hook actions (tool denial, registry modification) should appear as first-class activity stream entries, not just run-level metadata. Current `RuntimeHookCoordinator` stages store outcomes in `$hookMetadata` but the transcript and activity stream do not render them.
4. **Usage reconstruction from transcript:** Claw-code's `UsageTracker::from_session()` rebuilds cumulative usage from session messages. BLB should persist `meta.tokens` on assistant transcript entries so usage can be reconstructed from the transcript alone, with `ai_runs` as the query-optimized path.
