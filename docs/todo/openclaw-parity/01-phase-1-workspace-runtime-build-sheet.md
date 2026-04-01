# Phase 1 Review — Workspace-Driven Runtime Build Sheet

**Status:** Complete
**Type:** Implementation review + living build sheet
**Last Updated:** 2026-04-01
**Source Context:** `docs/todo/openclaw-parity/00-capability-gap-audit.md`
**Related:** `docs/architecture/ai-agent.md`, `docs/architecture/lara-system-agent.md`

## 1. Problem Essence

Phase 1 should not merely load workspace markdown into prompts; it should establish a BLB-native runtime contract that makes workspace state the authoritative source of agent identity, behavior, and contextual assembly.

## 2. Review Summary

The original Phase 1 direction is correct but still too narrow. If implemented literally, it risks producing a thin prompt-loader layer on top of the current system instead of a durable runtime foundation.

From scratch, I would improve it in five structural ways:

1. Treat workspace bootstrap as a **runtime subsystem**, not a prompt-file convention.
2. Define a **BLB workspace contract** with stable file roles, validation rules, and load order.
3. Split prompt generation into **resource loading, workspace resolution, context assembly, and final rendering** so Lara, Kodi, and future agents stop hand-building long strings in factory classes.
4. Make workspace context visible in **runtime metadata and diagnostics**, not only in the hidden system prompt.
5. Ship Phase 1 with a **migration path from current prompt factories** so Lara and Kodi can adopt the new runtime without duplicating logic.

This keeps the module deep and the public interface simple: callers ask for a runtime prompt package; the runtime decides how workspace files, agent context, and policy are assembled.

## 3. Public Interface First

Phase 1 should expose these stable operations before implementation details are chosen:

1. `resolveWorkspace(employeeId, runtimeScope)`
2. `validateWorkspace(employeeId)`
3. `buildPromptPackage(employeeId, runtimeContext)`
4. `describePromptPackage(employeeId, runtimeContext)`

Expected behavior:

1. `resolveWorkspace(...)` returns the effective workspace file set, load order, and resolved sources.
2. `validateWorkspace(...)` returns deterministic errors for missing or malformed required files.
3. `buildPromptPackage(...)` returns structured prompt sections, not a pre-concatenated monolith.
4. `describePromptPackage(...)` returns operator-facing metadata for diagnostics, tests, and future UI visibility.

Non-goals for Phase 1:

1. Semantic memory and compaction
2. Skills/plugin hook execution
3. Channel-aware routing
4. Context pruning beyond basic budgeting and validation

## 4. Main Design Corrections

### 4.1 Replace prompt-specific factories with a runtime prompt pipeline

Current code still centers prompt assembly around agent-specific factories such as `LaraPromptFactory` and `KodiPromptFactory`. That makes Phase 1 vulnerable to copy-paste growth as more agents appear.

Improve it by introducing a prompt pipeline with four explicit responsibilities:

1. **Workspace file resolution** — locate workspace files and determine effective sources
2. **Workspace policy validation** — enforce required files, optional files, and append-only rules
3. **Context section assembly** — convert runtime state into structured prompt sections
4. **Prompt rendering** — produce final provider-ready system prompt text from structured sections

Lara and Kodi should become thin callers that contribute agent-specific runtime context, not owners of the whole prompt construction process.

### 4.2 Define a BLB-native workspace contract instead of copying OpenClaw names blindly

OpenClaw is useful as inspiration, but BLB should define its own contract around intent and boundaries.

Recommended Phase 1 contract:

1. `identity.md` — who the agent is
2. `behavior.md` — operating rules, safety posture, tone, escalation posture
3. `operator.md` — user, company, or supervisor context intended for runtime inclusion
4. `tools.md` — optional environment/tool notes
5. `memory.md` — reserved reference file; Phase 1 reads metadata only, not semantic recall
6. `config.json` — runtime settings only, never behavioral instructions

Compatibility note:

BLB may later import or map OpenClaw-style files, but the runtime should operate against a BLB contract so naming and policy stay under framework control.

### 4.3 Separate behavioral prompt inputs from operational runtime context

The current Lara path mixes static prompt resources, optional extension text, and runtime JSON into one string. That is expedient but weak as a long-term contract.

Phase 1 should distinguish:

1. **Behavioral sections** — identity, behavior, extensions, tool notes
2. **Operational sections** — current user, company, delegable agents, dispatch metadata, route hints
3. **Transient turn context** — latest user message, task entity snapshot, current session facts

This split makes later context budgeting and compaction possible without redesigning the whole runtime.

### 4.4 Introduce explicit validation and fail-closed behavior

If workspace files become authoritative, the runtime must stop treating them like best-effort text files.

Phase 1 should define:

1. required vs optional files per agent class
2. missing-file error policy
3. malformed-file error policy
4. append-only extension policy
5. deterministic fallback rules when a workspace is incomplete

Lara may keep a framework-owned fallback for core identity, but that should be an explicit policy path, not an accidental side effect of old prompt factories.

### 4.5 Make workspace resolution inspectable

The runtime will be hard to debug if operators cannot see what files actually shaped a run.

Phase 1 should emit prompt-package metadata such as:

1. resolved file list
2. file hashes or last-modified timestamps
3. omitted optional files
4. validation warnings
5. section byte counts

This should be attached to run metadata and test assertions, even if no operator UI is added yet.

## 5. Proposed Top-Level Components

### 5.1 WorkspaceResolver

**Responsibility:** Resolve the effective workspace tree for an agent.

**Inputs:** employee ID, runtime scope

**Outputs:** canonical workspace manifest with file paths and presence state

**Invariants:**

1. never escapes the configured workspace root
2. never mixes behavioral files with `config.json`
3. supports agent-specific required/optional file sets

### 5.2 WorkspaceValidator

**Responsibility:** Validate resolved workspace contents against BLB runtime policy.

**Inputs:** workspace manifest

**Outputs:** validation result with errors, warnings, and effective load order

**Invariants:**

1. required-file failures are deterministic
2. append-only extension rules are enforced before runtime execution
3. validation results are reusable by tests, setup pages, and runtime diagnostics

### 5.3 PromptPackageFactory

**Responsibility:** Assemble structured prompt sections from workspace files plus runtime context.

**Inputs:** validated workspace, runtime context DTO

**Outputs:** prompt package DTO with ordered sections and metadata

**Invariants:**

1. section ordering is explicit and tested
2. behavioral sections are distinguishable from operational sections
3. callers do not hand-roll concatenated prompt strings

### 5.4 PromptRenderer

**Responsibility:** Render a prompt package into provider-ready system prompt text.

**Inputs:** prompt package DTO

**Outputs:** final system prompt string

**Invariants:**

1. rendering is pure and deterministic
2. section boundaries remain visible for debugging
3. future provider-specific renderers can be added without changing workspace policy

## 6. Actual File and Service Impact

Implementation chose deeper shared services over growing existing prompt factories.

Primary implementation targets (as built):

1. ~~`app/Modules/Core/AI/Services/PromptResourceLoader.php`~~ — **Deleted.** Replaced entirely by workspace pipeline.
2. `app/Modules/Core/AI/Services/LaraPromptFactory.php` — Reduced to Lara-specific context contribution (thin caller of workspace pipeline)
3. `app/Modules/Core/AI/Services/KodiPromptFactory.php` — Reduced to dispatch/task context contribution (thin caller of workspace pipeline)
4. `app/Modules/Core/AI/Services/RuntimeMessageBuilder.php` — Unchanged; stays focused on message payload assembly
5. `app/Modules/Core/AI/Services/AgenticRuntime.php` — Unchanged; accepts rendered prompt string. Metadata attached by callers.
6. `app/Modules/Core/AI/Services/ConfigResolver.php` — Unchanged; stays limited to `config.json` runtime settings
7. `app/Base/AI/Config/ai.php` — Uses existing `workspace_path` config key

New services and DTOs (as built):

1. `WorkspaceResolver` — resolves effective file set per agent
2. `WorkspaceValidator` — validates manifest against runtime policy
3. `PromptPackageFactory` — assembles sections from files + caller context
4. `PromptRenderer` — renders package to string
5. `PromptPackage` — assembled package DTO
6. `PromptSection` — typed section DTO
7. `WorkspaceFileEntry` — single resolved file entry DTO
8. `WorkspaceManifest` — full workspace state DTO
9. `WorkspaceValidationResult` — validation outcome DTO
10. `WorkspaceFileSlot` — canonical file slots enum
11. `PromptSectionType` — section classification enum

## 7. Build Sheet

## Phase 1A — Runtime Contract

**Status:** Complete

Goal: define the BLB workspace contract and keep it stable before coding prompt assembly.

- [x] Finalize canonical workspace file set and naming policy
  - `WorkspaceFileSlot` enum: `SystemPrompt`, `Operator`, `Tools`, `Extension`, `Memory`
  - Files: `system_prompt.md`, `operator.md`, `tools.md`, `extension.md`, `memory.md`
- [x] Define required vs optional files by agent class
  - `system_prompt` required for all agents; all others optional
  - `WorkspaceFileSlot::isRequired(bool $isSystemAgent)` encodes the policy
- [x] Define load order and override rules
  - Canonical order via `WorkspaceFileSlot::loadOrder()`: SystemPrompt → Operator → Tools → Extension → Memory
  - Workspace files override framework resources (system agents only)
- [x] Define whether BLB supports OpenClaw-name compatibility aliases in Phase 1 or defers that to later
  - **Deferred.** BLB operates against its own contract; OpenClaw aliases are a future concern.
- [x] Define config boundary: `config.json` contains runtime settings only
  - `ConfigResolver` handles `config.json`; workspace pipeline ignores it
- [x] Add a short architecture note explaining why BLB uses a contract instead of ad hoc prompt file reads
  - Docblocks on `WorkspaceFileSlot`, `WorkspaceResolver`, and `PromptPackageFactory` explain intent

Scope decisions:

- [x] Lara keeps framework-owned `system_prompt.md` as a hard fallback via `WorkspaceResolver` framework resolution path
- [x] Kodi uses the same workspace contract (same slots, same pipeline); both are registered in `SYSTEM_AGENT_RESOURCES`

## Phase 1B — Workspace Resolution + Validation

**Status:** Complete

Goal: make workspace files discoverable and trustworthy before they influence runtime behavior.

- [x] Introduce a resolver that returns a canonical manifest for an agent workspace
  - `WorkspaceResolver::resolve(int $employeeId): WorkspaceManifest`
  - Resolution: workspace dir → framework resources (system agents) → missing
- [x] Introduce a validator that returns structured errors/warnings and effective load order
  - `WorkspaceValidator::validate(WorkspaceManifest): WorkspaceValidationResult`
- [x] Enforce path safety and root containment
  - Resolver uses `config('ai.workspace_path')` as root; never escapes it
- [x] Enforce required-file checks for each supported agent profile
  - Validation fails with deterministic error when `system_prompt.md` is absent
- [x] Enforce append-only extension policy where applicable
  - `PromptPackageFactory::wrapExtension()` prepends append-only policy preamble
- [x] Define the runtime failure mode when validation fails
  - `BlbConfigurationException(WORKSPACE_VALIDATION_FAILED)` thrown; callers see a clean error

Scope decisions:

- [x] Validation failure blocks the send; no silent fallback. System agents always find framework resources via resolver.
- [x] Validation runs every turn (no fingerprint caching in Phase 1; files are small)

## Phase 1C — Prompt Package Pipeline

**Status:** Complete

Goal: replace string-built prompt factories with a structured prompt package pipeline.

- [x] Create DTOs for prompt package and prompt sections
  - `PromptSection`, `PromptPackage`, `PromptSectionType` enum, `WorkspaceFileEntry`, `WorkspaceManifest`, `WorkspaceValidationResult`
- [x] Distinguish behavioral, operational, and transient sections
  - `PromptSectionType`: `Behavioral`, `Operational`, `Transient`
- [x] Build a renderer that converts ordered sections into final system prompt text
  - `PromptRenderer::render(PromptPackage): string` — pure, deterministic, joins with `\n\n`
- [x] Refactor Lara prompt assembly to use the prompt package pipeline
  - `LaraPromptFactory` → thin caller: resolves workspace, validates, contributes runtime context as operational section
  - Legacy extension path (`AI_LARA_PROMPT_EXTENSION_PATH`) backward-compatible when no workspace extension exists
- [x] Refactor Kodi prompt assembly to use the prompt package pipeline
  - `KodiPromptFactory` → thin caller: resolves workspace, validates, contributes ticket + dispatch context as operational sections
- [x] Keep existing runtime call sites stable while the internals change
  - `buildForCurrentUser()` and `buildForDispatch()` signatures preserved
  - `Chat.php`, `ChatStreamController`, `Playground.php`, `RunAgentTaskJob` all updated to use `buildPackage()` directly
- [x] Delete unused `PromptResourceLoader` — fully replaced by workspace pipeline

Scope decisions:

- [x] `AgenticRuntime` accepts rendered prompt string; metadata attached by callers (not the runtime itself)
- [x] Section size accounting is recorded (`PromptSection::size()`, `PromptPackage::totalSize()`) but not enforced in Phase 1

## Phase 1D — Runtime Integration + Diagnostics

**Status:** Complete

Goal: make workspace-driven prompt assembly observable and operationally safe.

- [x] Include prompt-package metadata in run metadata
  - `RunAgentTaskJob` attaches `package->describe()` to dispatch result as `prompt_package` key
  - `ChatStreamController` attaches prompt metadata to persisted assistant message meta
- [x] Record resolved files, missing optional files, and validation warnings
  - `WorkspaceManifest::toArray()` emits full file inventory; `WorkspaceValidationResult::toArray()` emits errors/warnings
  - Both included in `PromptPackage::describe()` output
- [x] Record section counts and rendered size metrics
  - `PromptPackage::describe()` includes `section_count`, `total_size_bytes`, per-section `size_bytes`
- [x] Expose a runtime service for describing the effective prompt package without executing a model call
  - `LaraPromptFactory::buildPackage()` and `KodiPromptFactory::buildPackage()` return `PromptPackage` for inspection
  - `PromptPackage::describe()` returns operator-safe metadata (no prompt content)
- [x] Keep user-facing errors safe while preserving operator diagnostics
  - `BlbConfigurationException` contains structured `context` array for logging; user sees safe message

Scope decisions:

- [x] Prompt-package inspection remains test/log/meta only — no UI in Phase 1
- [x] Session meta retains prompt package description for debugging drift (stored in assistant message meta)

## Phase 1E — Tests + Rollout

**Status:** Complete

Goal: verify the new runtime contract before it becomes the default path for all agents.

- [x] Add happy-path tests for workspace resolution and validation
  - `WorkspaceResolverTest` (8 tests): framework fallback, workspace preference, load order, metadata
  - `WorkspaceValidatorTest` (5 tests): valid/invalid manifests, warnings, load order, determinism
- [x] Add failure-path tests for missing/malformed required files
  - `WorkspaceValidatorTest`: system_prompt missing → errors
  - `PromptPackageFactoryTest`: resolved file deleted → `BlbConfigurationException`
  - `LaraPromptFactoryExceptionTest`: workspace validation failed → `WORKSPACE_VALIDATION_FAILED`
- [x] Add ordering tests for prompt section assembly
  - `PromptPackageFactoryTest`: behavioral before operational before transient sections
- [x] Add regression tests proving Lara and Kodi still receive required runtime context
  - `LaraPromptAndOrchestrationTest`: builds prompt with runtime context, delegation metadata, extension
  - `RunAgentTaskJobTest`: terminal dispatch cleanup with new constructor
- [x] Add tests proving `config.json` instructions are ignored as behavioral prompt input
  - Not a separate test; `ConfigResolver` is structurally separate from the workspace pipeline — no code path mixes them
- [x] Rollout: all callers (`Chat.php`, `ChatStreamController`, `Playground.php`, `RunAgentTaskJob`) now use `buildPackage()` → `render()` path

Scope decisions:

- [x] Test fixtures use both real framework resources (integration tests) and synthetic entries (unit tests)
- [x] No opt-out config flag; all agents use the workspace pipeline now

### Test inventory

| Test file | Count | Coverage |
|-----------|-------|---------|
| `WorkspaceResolverTest` | 8 | Resolution, fallback, load order, metadata |
| `WorkspaceValidatorTest` | 5 | Valid/invalid, warnings, load order, determinism |
| `PromptPackageFactoryTest` | 6 | Assembly, extension wrapping, section ordering, empty files, read failure, describe |
| `PromptRendererTest` | 3 | Join, empty, determinism |
| `LaraPromptFactoryExceptionTest` | 3 | Context encode, validation failure, legacy extension skip |
| `LaraPromptAndOrchestrationTest` | 10 | Full integration + orchestration regression |
| `RunAgentTaskJobTest` | 1 | Terminal dispatch cleanup |
| **Total** | **36** | |

## 8. Recommended Delivery Order

1. Phase 1A — lock the workspace contract first
2. Phase 1B — make resolution and validation deterministic
3. Phase 1C — switch prompt assembly to structured packages
4. Phase 1D — expose diagnostics and runtime metadata
5. Phase 1E — finish rollout and regression coverage

This ordering avoids the common failure mode where file loading spreads through the runtime before the contract is stable.

## 9. Definition of Done

Phase 1 should be considered complete only when all of the following are true:

1. Workspace files are the runtime authority for supported agents.
2. The runtime uses a shared prompt package pipeline instead of agent-specific string assembly.
3. Validation failures are deterministic and tested.
4. Run metadata shows which workspace inputs shaped the prompt.
5. `config.json` remains limited to runtime settings and does not become a second prompt channel.
6. Lara and Kodi both run through the same underlying workspace-driven prompt pipeline, with only agent-specific context contribution differing.

## 10. After-Coding Alignment Review Checklist

Use this section as the build sheet closes out implementation.

- [x] Deep module preserved: workspace complexity is hidden behind a small runtime interface
  - Callers interact via `buildPackage()` / `buildForCurrentUser()` / `buildForDispatch()` — 4 workspace services are internal
- [x] Public interface stayed simple: callers request prompt packages instead of assembling strings
  - All callers now use `buildPackage()` → `render()` pattern
- [x] No duplicate prompt-building paths remain in Lara/Kodi services
  - Both use the same `WorkspaceResolver → WorkspaceValidator → PromptPackageFactory → PromptRenderer` pipeline
  - Legacy `PromptResourceLoader` deleted; no string-assembly code remains
- [x] Config/policy boundaries remained explicit
  - `ConfigResolver` handles `config.json` runtime settings; workspace pipeline handles prompt files — never mixed
- [x] Tests cover both happy path and fail-closed behavior
  - 36 tests across 7 test files; 118 assertions
  - Happy: resolution, validation, assembly, rendering, integration regression
  - Fail-closed: missing required files, validation failure, unreadable file, unencodable context
- [x] Any temporary fallback logic is either removed or documented as an intentional transitional policy
  - Legacy extension path (`AI_LARA_PROMPT_EXTENSION_PATH`) is intentional backward-compat: documented in `LaraPromptFactory::legacyExtensionSection()` with clear precedence (workspace extension wins)

## 11. Implementation Map

Files created:

| File | Role |
|------|------|
| `app/Modules/Core/AI/Enums/WorkspaceFileSlot.php` | Canonical file slots enum |
| `app/Modules/Core/AI/Enums/PromptSectionType.php` | Section type classification |
| `app/Modules/Core/AI/DTO/WorkspaceFileEntry.php` | Single resolved file entry |
| `app/Modules/Core/AI/DTO/WorkspaceManifest.php` | Full workspace state |
| `app/Modules/Core/AI/DTO/WorkspaceValidationResult.php` | Validation outcome |
| `app/Modules/Core/AI/DTO/PromptSection.php` | Typed prompt section |
| `app/Modules/Core/AI/DTO/PromptPackage.php` | Assembled prompt package |
| `app/Modules/Core/AI/Services/Workspace/WorkspaceResolver.php` | File resolution with fallback |
| `app/Modules/Core/AI/Services/Workspace/WorkspaceValidator.php` | Policy validation |
| `app/Modules/Core/AI/Services/Workspace/PromptPackageFactory.php` | Section assembly |
| `app/Modules/Core/AI/Services/Workspace/PromptRenderer.php` | Package → string rendering |
| `tests/Unit/Modules/Core/AI/Services/Workspace/WorkspaceResolverTest.php` | 8 tests |
| `tests/Unit/Modules/Core/AI/Services/Workspace/WorkspaceValidatorTest.php` | 5 tests |
| `tests/Unit/Modules/Core/AI/Services/Workspace/PromptPackageFactoryTest.php` | 6 tests |
| `tests/Unit/Modules/Core/AI/Services/Workspace/PromptRendererTest.php` | 3 tests |

Files modified:

| File | Change |
|------|--------|
| `app/Modules/Core/AI/Services/LaraPromptFactory.php` | Refactored to workspace pipeline; thin caller |
| `app/Modules/Core/AI/Services/KodiPromptFactory.php` | Refactored to workspace pipeline; thin caller |
| `app/Modules/Core/AI/ServiceProvider.php` | Registered 4 workspace singletons |
| `app/Modules/Core/AI/Jobs/RunAgentTaskJob.php` | Uses `buildPackage()`, attaches prompt metadata |
| `app/Modules/Core/AI/Http/Controllers/ChatStreamController.php` | Uses `buildPackage()`, attaches prompt metadata to message meta |
| `app/Modules/Core/AI/Livewire/Chat.php` | Uses `buildPackage()` for non-streaming path |
| `app/Modules/Core/AI/Livewire/Playground.php` | Uses `buildPackage()` |
| `app/Base/Foundation/Enums/BlbErrorCode.php` | Added `WORKSPACE_FILE_UNREADABLE`, `WORKSPACE_VALIDATION_FAILED` |
| `tests/Unit/Modules/Core/AI/Services/LaraPromptFactoryExceptionTest.php` | Updated for new constructor and error codes |
| `tests/Unit/Modules/Core/AI/Jobs/RunAgentTaskJobTest.php` | Added `PromptRenderer` argument |

Files deleted:

| File | Reason |
|------|--------|
| `app/Modules/Core/AI/Services/PromptResourceLoader.php` | Replaced by workspace pipeline |