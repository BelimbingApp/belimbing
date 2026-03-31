# AI Runtime Robustness

**Problem:** Agent runtime errors are invisible to admins, leak raw internals to users, and sometimes produce blank messages.

**Scope:** Agent-agnostic — applies to Lara, Kodi, and any future agent.

---

## Phase 0 — Error Spine & Guards ✅

Build the structural foundation: normalize errors, log them, and prevent blank/crashed states.

### 0.1 Dedicated `ai` Log Channel
- [x] Add `ai` channel to `config/logging.php` (daily, `storage/logs/ai.log`)
- [x] Create `AiRuntimeLogger` service — structured logging with safe context (no secrets/prompts)

### 0.2 Error Type Enum
- [x] Create `AiErrorType` enum with stable machine-readable error classifications
- [x] Add `retryable()` method to each case
- [x] Add `userMessage()` method — safe, actionable text per error type

### 0.3 Normalized Error DTO
- [x] Create `AiRuntimeError` value object: `errorType`, `userMessage`, `diagnostic`, `hint`, `httpStatus`, `latencyMs`, `retryable`
- [x] `LlmClient` returns `AiRuntimeError` in result arrays instead of raw strings
- [x] `RuntimeCredentialResolver` returns `AiRuntimeError` on failure
- [x] `RuntimeResponseFactory` consumes `AiRuntimeError` — persists safe text, logs diagnostic

### 0.4 Blank-Response Guard
- [x] `AgenticRuntime::successResult()` — convert empty content to `empty_response` error
- [x] `ChatStreamController` — persist fallback error message instead of blank content
- [x] Streaming path: emit `error` event if stream ends with no content

### 0.5 Exception Boundary in Chat.php
- [x] Wrap `sendMessage()` in try/catch/finally
- [x] `catch`: log `ai.unhandled_exception`, create fallback error response, persist as assistant message
- [x] `finally`: always reset `$this->isLoading = false`

### 0.6 Error Message Tagging
- [x] Tag persisted error messages with `'message_type' => 'error'` in meta
- [x] Render error messages with distinct visual treatment in chat UI (warning styling)

### 0.7 Chat Error Context Visibility
- [x] Preserve provider/model metadata when streaming errors are persisted to the transcript
- [x] Show assistant provider/model next to the chat timestamp when runtime metadata is available
- [x] Show the full datetime in a tooltip when the timestamp is hovered or focused

### 0.8 RunAgentTaskJob Guard
- [x] Log runtime errors via `AiRuntimeLogger` (job already has try/catch but no structured logging)

---

## Phase 1 — Admin Diagnostics 🔲

Thin diagnostics surface for admins to understand what's failing and why.

- [ ] Add "Test provider" action to setup pages: resolve config → credentials → tiny API call → show diagnosis
- [ ] Surface provider health summary in setup pages (last success/failure from log file or small DB table)
- [ ] Optionally: `AiRuntimeFailure` model + migration for in-app failure browsing (defer until log-based approach proves insufficient)

---

## Phase 2 — Resilience 🔲

Targeted retry and fallback for transient failures, only after observability exists.

- [ ] Single retry for retryable errors (`timeout`, `connection_error`, `rate_limit`, `server_error`)
- [ ] No retry on config/auth/payload errors
- [ ] Provider fallback only before tool-calling loop commits
- [ ] Log all retry/fallback attempts in metadata
- [ ] Expose retry/fallback info in run metadata for admin visibility

---

## Design Decisions

1. **Agent-agnostic:** All error handling lives in `AgenticRuntime`, `LlmClient`, and shared services — not in Lara/Kodi-specific code.
2. **No raw provider text to users:** `AiErrorType::userMessage()` maps every error to safe, actionable text. Diagnostic detail goes only to logs.
3. **Error DTO over exception:** `AiRuntimeError` is a value object, not an exception — runtime errors are expected operational states, not exceptional conditions.
4. **Dedicated log channel:** `ai` channel keeps runtime diagnostics separate from application logs, easy to tail/ship.
5. **`message_type` tag:** Enables distinct UI rendering without changing the Message DTO structure.
6. **`shouldFallback` derived from enum:** `AgentRuntime` uses `AiErrorType::retryable()` instead of hardcoded string lists, keeping fallback policy in one place.
