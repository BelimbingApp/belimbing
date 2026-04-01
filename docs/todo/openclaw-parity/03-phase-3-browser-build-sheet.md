# Phase 3 - Browser Operator Build Sheet

**Parent:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`
**Scope:** Turn BLB's browser feature from per-command automation into a persistent, inspectable browser operator subsystem
**Status:** Done
**Phase Owner:** Core AI / Base AI
**Last Updated:** 2026-04-02

---

## 1. Problem Essence

Phase 3 should not be implemented as "make `BrowserTool` support more actions"; it should be implemented as a **browser operator subsystem** that gives BLB agents persistent browser sessions, stable interaction state, inspectable artifacts, and explicit lifecycle control.

---

## 2. Why the Current Phase 3 Description Is Too Thin

The current Phase 3 in `00-capability-gap-audit.md` says:

1. persistent browser sessions
2. stateful tab/action flows
3. browser status introspection

That is directionally correct, but still too close to the tool surface.

If implemented too literally, the likely outcome is:

- `BrowserTool` grows more branches
- `PlaywrightRunner` starts retaining ad hoc state
- tabs and session IDs are stitched onto current per-command behavior
- status introspection is bolted on after the fact

That would solve symptoms, but not the design problem.

BLB should instead build a deep browser module with clear boundaries:

- browser session ownership and lifecycle
- stateful page/tab context
- artifact capture and retrieval
- policy and isolation controls
- operator visibility

---

## 3. Current Code Snapshot

The existing code is a strong prototype, but not yet a browser subsystem.

### What exists now

- `BrowserTool` exposes a rich action surface: navigate, snapshot, screenshot, act, tabs, open, close, evaluate, pdf, cookies, wait — `app/Modules/Core/AI/Tools/BrowserTool.php`
- `PlaywrightRunner` executes Node/Playwright actions and supports synchronous headless or detached headful execution — `app/Modules/Core/AI/Services/Browser/PlaywrightRunner.php`
- `BrowserPoolManager` tracks active contexts in memory with company concurrency limits — `app/Modules/Core/AI/Services/Browser/BrowserPoolManager.php`
- `BrowserContextFactory` resolves Playwright availability and produces context IDs — `app/Modules/Core/AI/Services/Browser/BrowserContextFactory.php`

### Structural problems in the current shape

1. **The real unit of behavior is a browser session, but the code is organized around one-off actions.**
2. **Context tracking is in-memory only, so it is not reliable across processes or queue workers.**
3. **`PlaywrightRunner` still launches fresh Chromium work per command, which breaks continuity.**
4. **Tab management and interactive actions are blocked because state has nowhere durable to live.**
5. **Artifacts and page state are not modeled as first-class outputs.**

---

## 4. From-Scratch Design: What BLB Should Build Instead

### 4.1 Public interface first

Phase 3 should expose these stable operations:

1. `openBrowserSession(agentId, options)` — create or resume a browser session
2. `getBrowserSession(sessionId)` — inspect lifecycle and state
3. `navigateBrowserSession(sessionId, target)` — navigate current tab or chosen tab
4. `actInBrowserSession(sessionId, action)` — perform stateful interaction against known page state
5. `captureBrowserArtifact(sessionId, artifactType)` — store snapshot/screenshot/pdf outputs
6. `closeBrowserSession(sessionId)` — explicitly end the session

Tools should wrap these operations. They should not be the place where lifecycle is invented.

### 4.2 Architectural decomposition

#### A. Browser Session Manager

Responsibility:

- owns session creation, lookup, expiration, and closure
- binds session to agent/company/runtime context
- enforces concurrency and isolation policy

Key invariant:

- the browser session is the canonical runtime unit, not the Playwright command

#### B. Browser Runtime Adapter

Responsibility:

- bridges BLB PHP services to Playwright execution
- manages process/channel communication
- handles reconnect/reuse semantics for live sessions

Key invariant:

- runtime transport details stay hidden behind a stable BLB interface

#### C. Page State Model

Responsibility:

- represents tabs, active page, known element refs, last navigation, and last snapshot
- provides the state needed for follow-up actions

Key invariant:

- follow-up actions must depend on explicit captured state, not hidden assumptions

#### D. Artifact Store

Responsibility:

- persists screenshots, snapshots, PDFs, and related metadata
- gives operators and follow-up tools something durable to inspect

Key invariant:

- browser outputs are stored as durable artifacts, not only inline strings

#### E. Browser Operations Surface

Responsibility:

- exposes browser lifecycle to tools, UI, jobs, and diagnostics
- centralizes authz and high-trust policy boundaries

---

## 5. Core Design Decisions

### 5.1 Model browser state explicitly

Do not infer state from whichever action ran last.

The subsystem should track:

- browser session ID
- owning agent and company
- active tab ID
- open tabs
- last navigated URL
- last structured snapshot
- last artifact IDs
- headful/headless mode
- created/last-seen timestamps

### 5.2 Separate lifecycle from interaction

Lifecycle concerns:

- open
- reuse
- expire
- close
- recover

Interaction concerns:

- navigate
- snapshot
- click/type/fill
- screenshot/pdf
- wait/evaluate/cookies

Mixing both into one tool class is what makes stateful evolution awkward.

### 5.3 Make artifacts first-class

From scratch, every meaningful browser output should be a durable artifact with metadata:

- snapshot text
- screenshot image
- PDF export
- evaluated result trace

That supports:

- UI inspection
- debugging
- auditability
- downstream tool reuse

### 5.4 Make headful collaboration explicit

Headful mode is not just "headless=false".

BLB should treat headful operation as a collaboration mode with:

- session status
- operator-visible browser session ownership
- timeout/expiry policy
- safe interruption/closure

### 5.5 Keep SSRF and evaluate trust boundaries separate

Navigation policy and JS evaluation policy are different risks.

The subsystem should keep explicit boundaries for:

- allowed navigation targets
- private-network access policy
- JS evaluation capability
- file download/upload policy later if added

### 5.6 Persist session metadata outside PHP memory

`BrowserPoolManager` currently tracks contexts in memory only. That is not sufficient for a long-lived agent/browser capability.

From scratch, Phase 3 should persist browser session state so that:

- queue workers and web requests share the same truth
- stale sessions can be cleaned up
- status introspection works even after worker restart

---

## 6. Proposed Module Shape

Recommended service set:

- `BrowserSessionManager`
- `BrowserSessionRepository`
- `BrowserRuntimeAdapter`
- `BrowserPageStateStore`
- `BrowserArtifactStore`
- `BrowserPolicyService`
- `BrowserHealthService`

Recommended job/command set:

- `blb:ai:browser:sweep`
- `blb:ai:browser:status {session}`
- `ExpireBrowserSessionsJob`
- `CaptureBrowserArtifactJob` if asynchronous capture is useful

Recommended tool evolution:

- `BrowserTool` becomes a thin action router over browser services
- optional later `BrowserStatusTool`
- optional later `BrowserArtifactTool`

---

## 7. Storage and Persistence Model

### 7.1 Session records

Phase 3 should persist browser session state, likely in the database rather than transient PHP memory.

Suggested record shape:

- session ID
- employee ID
- company ID
- mode (`headful` / `headless`)
- status (`opening`, `ready`, `busy`, `expired`, `failed`, `closed`)
- active tab
- metadata about runner/runtime binding
- created at / last heartbeat / expires at

### 7.2 Artifact records

Artifact records should store:

- artifact ID
- browser session ID
- artifact type
- storage path
- MIME type
- related URL/tab
- created at

### 7.3 State snapshots

Do not try to persist the full Playwright process object model.

Persist BLB-facing state only:

- tabs
- URLs
- snapshot references
- known element reference namespace

That keeps the module deep and the interface simple.

---

## 8. Interaction Model

### 8.1 Stable element references

Phase 3 needs a clear contract for how `snapshot` and `act` relate.

From scratch:

1. snapshot produces stable, session-scoped element refs
2. refs expire when page state changes materially
3. interaction actions validate ref freshness before acting

This avoids phantom actions on stale pages.

### 8.2 Tab semantics

Tab operations should become real lifecycle operations:

- open new tab
- list tabs
- switch active tab
- close tab

Current "session_required" behavior is a symptom of missing session architecture, not a tool limitation.

### 8.3 Wait and evaluate semantics

`wait` and `evaluate` should operate only inside explicit session state:

- wait against known tab/page context
- evaluate against known page context
- record the result as a traceable event or artifact when needed

---

## 9. Operator and UI Requirements

Phase 3 should not ship as backend-only.

Required visibility:

1. active browser sessions
2. session owner (agent/company)
3. mode (`headful` / `headless`)
4. current URL and active tab
5. last activity time
6. available artifacts
7. failure reason if session is unhealthy

This can start in the tool workspace or AI admin surfaces before a dedicated browser console exists.

---

## 10. Build Plan

## Phase Status

| Area | Status | Notes |
|---|---|---|
| Public contracts | done | 2 enums + 3 DTOs — 22 tests |
| Persistent session model | done | 2 migrations, 2 models, BrowserSessionRepository — 20 tests |
| Runtime adapter | done | BrowserRuntimeAdapter + BrowserSessionManager — 29 tests |
| Page state model | done | Tab/URL/snapshot state tracked; ref freshness validation + invalidation |
| Artifact persistence | done | BrowserArtifactStore (disk + DB) — 10 tests |
| Tool refactor | done | BrowserTool is thin wrapper — 43 tests |
| Operator visibility | done | `blb:ai:browser:sweep` + `blb:ai:browser:status` — 8 tests |

### 10.1 Step 1 — Define browser session contracts

Status: done

Implemented:

- `BrowserSessionStatus` enum — 6 lifecycle states (Opening, Ready, Busy, Expired, Failed, Closed) with isTerminal(), isActionable(), label(), color()
- `BrowserArtifactType` enum — 4 types (Snapshot, Screenshot, Pdf, EvaluateResult) with label(), mimeType()
- `BrowserTabState` DTO — tabId, url, title, isActive with fromArray()/toArray()
- `BrowserSessionState` DTO — operator-visible session snapshot
- `BrowserArtifactMeta` DTO — artifact metadata
- Tests: 22 (6 + 4 + 5 + 4 + 3)

### 10.2 Step 2 — Add persistent browser session repository

Status: done

Implemented:

- Migration `0200_02_01_000003_create_ai_browser_sessions_table` — FK to employees/companies
- Migration `0200_02_01_000004_create_ai_browser_artifacts_table` — FK cascade to sessions
- `BrowserSession` model — non-incrementing string PK, status casts, scopes (active, stale), lifecycle methods
- `BrowserArtifact` model
- `BrowserSessionRepository` — create, find, state transitions (markReady/Busy/Idle/Failed/Closed/Expired), updatePageState, touchActivity, findStaleSessions
- Tests: 20 (integration with real DB)

### 10.3 Step 3 — Build browser runtime adapter

Status: done

Implemented:

- `BrowserRuntimeAdapter` — wraps PlaywrightRunner with session awareness, Busy→Ready transitions, page state extraction from results
- `BrowserSessionManager` — lifecycle orchestrator: open/reuse/close/sweep/executeAction/getSessionState/getActiveSessionsForCompany
- `BrowserSessionException` — domain exception class
- Tests: 29 (9 adapter + 20 manager, unit tests with mocks)

### 10.4 Step 4 — Build page state and ref freshness model

Status: done

Implemented:

- Tab and active-page state persisted in `page_state` JSON column on `ai_browser_sessions`
- `BrowserRuntimeAdapter` extracts page state (URL, tabs, snapshot refs) from runner results
- `BrowserTabState` DTO models individual tab state
- `validateRefFreshness()` gate on `act` actions — rejects when: no refs, URL mismatch, or staleness beyond `ref_stale_seconds`
- `invalidateRefs()` clears element refs after successful `navigate` — forces fresh snapshot before next `act`
- Config key `ai.tools.browser.ref_stale_seconds` (default 300s)
- Tests: 7 new ref freshness/invalidation tests in BrowserRuntimeAdapterTest

### 10.5 Step 5 — Add artifact persistence

Status: done

Implemented:

- `BrowserArtifactStore` — disk + DB persistence, store/find/list/readContent/deleteForSession
- Supports Snapshot, Screenshot, Pdf, EvaluateResult artifact types
- Artifacts linked to sessions via FK cascade
- Tests: 10 (integration with real DB and disk)

### 10.6 Step 6 — Refactor `BrowserTool` into a thin wrapper

Status: done

Implemented:

- `BrowserTool` refactored to thin wrapper over `BrowserSessionManager` + `BrowserArtifactStore`
- Lifecycle delegated: open/close/navigate/act/snapshot/screenshot/pdf/evaluate/tabs/wait/cookies
- SSRF and evaluate capability checks preserved
- Tests: 43 (rewritten from scratch)

### 10.7 Step 7 — Add operator visibility and cleanup

Status: done

Implemented:

- `BrowserSweepCommand` — `blb:ai:browser:sweep` expires stale sessions via `$manager->sweepStaleSessions()`
- `BrowserStatusCommand` — `blb:ai:browser:status --session=<id> --company=<id>` shows detail or lists
- Commands registered in `app/Modules/Core/AI/ServiceProvider.php` boot()
- Tests: 8 (2 sweep + 6 status)

---

## 11. Scope-Sharpening Notes

These should be updated as implementation begins.

### Open questions to resolve in code, not philosophy

1. Should the persistent browser session live in DB + external runner process, or DB + resumable local process coordination?
2. How much artifact retention should BLB keep by default?
3. Should headful mode be restricted to local/dev first, or designed production-ready from the beginning?
4. Should downloads/uploads be explicitly out of scope for first Phase 3 ship?

### Current best judgment

1. Persist session truth in BLB, while treating the external runtime as an attached executor.
2. Keep artifact retention bounded and sweepable.
3. Design headful mode as production-safe, even if initial rollout is narrower.
4. Keep downloads/uploads out of first ship unless required by a concrete browser workflow.

---

## 12. Exit Criteria

Phase 3 is complete when:

1. browser sessions persist across multiple actions and turns
2. tab operations are real, not blocked placeholders
3. interactive actions operate against explicit session state
4. browser artifacts are durable and inspectable
5. session status is operator-visible
6. browser state survives beyond one PHP request/process boundary
7. `BrowserTool` is a thin wrapper over subsystem services

---

## 13. What to Avoid

1. Do not keep adding lifecycle behavior directly into `BrowserTool`.
2. Do not rely on PHP in-memory arrays as the authoritative browser session store.
3. Do not treat headful mode as just a debug flag.
4. Do not hide artifacts inside transient tool output only.
5. Do not solve stateful interaction by keeping more implicit state in the runner without BLB-side visibility.

---

## 14. File Inventory

### Source files (13 new + 3 modified)

| File | Role |
|---|---|
| `app/Modules/Core/AI/Enums/BrowserSessionStatus.php` | 6-state lifecycle enum |
| `app/Modules/Core/AI/Enums/BrowserArtifactType.php` | 4-type artifact enum |
| `app/Modules/Core/AI/DTO/BrowserTabState.php` | Tab state DTO |
| `app/Modules/Core/AI/DTO/BrowserSessionState.php` | Operator-visible session DTO |
| `app/Modules/Core/AI/DTO/BrowserArtifactMeta.php` | Artifact metadata DTO |
| `app/Modules/Core/AI/Database/Migrations/0200_02_01_000003_create_ai_browser_sessions_table.php` | Session table |
| `app/Modules/Core/AI/Database/Migrations/0200_02_01_000004_create_ai_browser_artifacts_table.php` | Artifact table |
| `app/Modules/Core/AI/Models/BrowserSession.php` | Session model |
| `app/Modules/Core/AI/Models/BrowserArtifact.php` | Artifact model |
| `app/Modules/Core/AI/Services/Browser/BrowserSessionRepository.php` | Persistence layer |
| `app/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapter.php` | Playwright bridge |
| `app/Modules/Core/AI/Services/Browser/BrowserSessionManager.php` | Lifecycle orchestrator |
| `app/Modules/Core/AI/Services/Browser/BrowserSessionException.php` | Domain exception |
| `app/Modules/Core/AI/Services/Browser/BrowserArtifactStore.php` | Artifact persistence |
| `app/Modules/Core/AI/Console/Commands/BrowserSweepCommand.php` | `blb:ai:browser:sweep` |
| `app/Modules/Core/AI/Console/Commands/BrowserStatusCommand.php` | `blb:ai:browser:status` |
| `app/Modules/Core/AI/Tools/BrowserTool.php` | Refactored thin wrapper (modified) |
| `app/Modules/Core/AI/ServiceProvider.php` | Singletons + command registration (modified) |

### Test files (12)

| File | Tests | Assertions |
|---|---|---|
| `tests/Unit/Modules/Core/AI/Tools/BrowserToolTest.php` | 43 | 100 |
| `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionRepositoryTest.php` | 20 | ~60 |
| `tests/Unit/Modules/Core/AI/Services/Browser/BrowserSessionManagerTest.php` | 20 | ~50 |
| `tests/Unit/Modules/Core/AI/Services/Browser/BrowserRuntimeAdapterTest.php` | 17 | ~38 |
| `tests/Unit/Modules/Core/AI/Services/Browser/BrowserArtifactStoreTest.php` | 10 | ~30 |
| `tests/Unit/Modules/Core/AI/Enums/BrowserSessionStatusTest.php` | 6 | ~20 |
| `tests/Unit/Modules/Core/AI/Enums/BrowserArtifactTypeTest.php` | 4 | ~10 |
| `tests/Unit/Modules/Core/AI/DTO/BrowserTabStateTest.php` | 5 | ~15 |
| `tests/Unit/Modules/Core/AI/DTO/BrowserArtifactMetaTest.php` | 4 | ~10 |
| `tests/Unit/Modules/Core/AI/DTO/BrowserSessionStateTest.php` | 3 | ~10 |
| `tests/Unit/Modules/Core/AI/Console/Commands/BrowserSweepCommandTest.php` | 2 | 4 |
| `tests/Unit/Modules/Core/AI/Console/Commands/BrowserStatusCommandTest.php` | 6 | 16 |
| **Total** | **140** | **~383** |

### Test baseline

- Browser subsystem: 140 new tests + 43 rewritten BrowserTool tests = 183 total (but 3 existing snapshot ref tests overlap, so 180 unique)
- Full suite: 1,067 tests, 2,852 assertions
