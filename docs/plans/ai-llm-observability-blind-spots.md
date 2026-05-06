# ai-llm-observability-blind-spots

**Status:** In Progress
**Last Updated:** 2026-05-05
**Sources:** `app/Base/AI/DTO/ChatRequest.php`, `app/Base/AI/Contracts/LlmTransportTap.php`, `app/Base/Database/Livewire/Queries/Show.php`, `app/Modules/Core/AI/Livewire/Concerns/ManagesChatSessions.php`, `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Modules/Core/AI/Services/AgentRuntime.php`, `app/Modules/Core/AI/Services/TaskModelRecommendationService.php`, `app/Modules/Core/AI/Services/ProviderTestService.php`, `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php`, `app/Modules/Core/AI/Services/ControlPlane/WireLogger.php`, `app/Modules/Core/AI/Services/ControlPlane/WireLoggingTransportTap.php`, `app/Modules/Core/AI/Jobs/RunLaraTaskProfileJob.php`, `docs/plans/integration-external-system-observability.md`, `docs/plans/ai-lara-collapse-into-provider-priority.md`, `docs/plans/lara-task-models.md`, `docs/architecture/ai/lara.md`
**Agents:** Cursor/GPT-5.2, Amp/gpt-5.5-medium

## Problem Essence

BLB can still make operator-visible LLM calls through low-level `LlmClient::chat(...)` paths that bypass both Core AI run recording and the AI wire logging tap. These become “ghost” requests: the UI or admin workflow visibly uses AI, but the request cannot be found in the Control Plane or wire-log evidence.

This is distinct from Integration outbound-exchange observability: the Integration ledger intentionally excludes LLM runtime calls and focuses on non-LLM external systems.

## Desired Outcome

Every LLM request initiated by BLB is traceable through the strongest inspection surface that fits its ownership boundary:

- **Core AI work** appears in the Control Plane as a run (`run_id`) with source, task key when applicable, provider/model metadata, status, token/cost metadata when available, and wire-log evidence when wire logging is enabled.
- **Core AI diagnostics and Base-module utility calls** at least attach the AI wire logging tap when wire logging is enabled, using a stable correlation id and source metadata that make the file meaningful to operators.

No feature or diagnostic workflow should construct an ad-hoc `LlmClient::chat(...)` request without either going through the Core runtime/run ledger or explicitly obtaining a trace context for the call.

## Top-Level Components

### Core AI runtime path

`AgenticRuntime` is the canonical runtime entrypoint for LLM calls that represent Core AI work: Lara chat turns, Lara task profiles, one-shot Lara utilities such as titling, and admin AI assistance that is naturally attributable to Lara or another employee. It already records runs and attaches `WireLoggingTransportTap`; the missing piece is a clean invocation context so non-chat uses can label their `source` and task key without adding more ambiguous public parameters.

Runtime classes should live in a coherent Core AI runtime namespace after this work. The AI module is still early enough that fixing layout now is cheaper than teaching future callers around stale names such as the legacy `AgentRuntime` adapter.

### Core AI trace context for direct diagnostics

Some Core AI workflows intentionally test a provider/model selection rather than execute Lara work. Provider connectivity tests are the clearest example. They may stay direct `LlmClient` calls, but only through a trace-context helper that supplies a correlation id and `WireLoggingTransportTap` when enabled. These calls should not be forced into `ai_runs` unless the run ledger can truthfully represent them without inventing fake employee ownership.

### Base AI tracing seam

Base AI owns protocol and transport mechanics and already exposes `LlmTransportTap` through `ChatRequest`. It should also own a tiny, stateless tracing seam that returns “no trace” by default and can be implemented by Core AI to provide wire logging. This keeps Base services and Base modules from importing Core Control Plane classes while still allowing Core to attach the operator evidence surface.

Base AI may also be reorganized while this seam is added, but only around its own responsibilities: transport protocols, provider mapping, request DTOs, transport taps, and trace-context contracts. It must remain stateless and must not learn about companies, employees, sessions, runs, or the Core Control Plane.

### Wire logging surface

AI wire logs are the evidence surface for raw LLM transport. They remain separate from the Integration exchange ledger and use file-backed JSONL under `storage/app/ai/wire-logs/`. Wire logs should include source/task/correlation metadata early in each file so operators can understand why the call exists even when there is no `ai_runs` row.

## Design Decisions

### Introduce invocation context before moving call sites

Do not route titling or simple tasks through `AgenticRuntime` by adding one more loosely named optional string parameter. The runtime already has many optional knobs; observability should add a small value object for invocation context instead. That context should carry at least:

- source (`chat`, `stream`, `lara_task`, `simple_task`, `task_model_recommendation`, etc.),
- task/profile key when applicable,
- session and turn correlation when available,
- acting user when available, and
- execution mode / timeout policy already represented by `ExecutionPolicy`.

The run ledger can keep its current columns, but the runtime should use this context to write truthful `source` values and preserve task metadata in `meta`.

### Prefer run-level trace for Core AI work

If a call site lives in Core AI and represents “AI work”, it should execute through `AgenticRuntime` so it:

- creates a run record, and
- uses the existing wire logging tap.

This includes one-shot/simple requests such as session titling and task-model recommendation. “Simple” means no tools and tight execution controls; it should not mean “bypass the runtime”.

### Add a simple-task executor instead of duplicating prompt plumbing

Lara simple tasks need one standard executor that resolves the task model, builds messages, calls `AgenticRuntime` with no tools, and returns the text result. `ManagesChatSessions::generateSessionTitle()` should become a consumer of that executor. Future simple tasks then inherit run and wire observability by construction.

### Define a minimal trace context for diagnostics and Base-module LLM calls

For calls that cannot honestly be represented as Core AI runs, require at least:

- a stable, local “pseudo run id” for correlation within wire logs, and
- attaching `WireLoggingTransportTap` when wire logging is enabled.

This should be exposed through a Base AI contract or factory, not by making `app/Base/*` import `app/Modules/Core/AI/Services/ControlPlane/*`. The Core AI service provider can bind the real implementation; Base AI can provide a null implementation when Core is absent.

### Remove or quarantine misleading runtime adapters

Legacy runtime adapters that encourage direct `LlmClient::chat(...)` usage without taps (e.g. Stage-0 runtimes) should be removed or refactored so they cannot reintroduce blind spots.

### Do the focused structural refactor now

Because Core AI is still a new module, the implementation should not preserve confusing layout just to minimize namespace churn. Do the structural cleanup that directly supports the observability contract in the same change set, so future contributors see the intended path by looking at the directories and class names.

Recommended shape:

- `app/Modules/Core/AI/Services/Runtime/` for run-recorded execution: `AgenticRuntime`, invocation context, simple-task executor, stream reader/bridge helpers, message builder, response factory, hook coordinator, runtime session context, credential/config execution helpers that are runtime-specific.
- `app/Modules/Core/AI/Services/ControlPlane/` remains the operator surface: run recorder, run inspection/diagnostics, wire logger, wire tap implementation, telemetry/control services.
- `app/Modules/Core/AI/Services/Tasks/` or a similarly explicit subtree may hold Lara task definitions/registries/executors if keeping all task orchestration beside runtime would make `Runtime/` too broad. The boundary is that task selection/composition belongs here; low-level LLM transport does not.
- `app/Base/AI/Services/Transport/` for `LlmClient` and protocol client mechanics if moved.
- `app/Base/AI/Services/Tracing/` or `app/Base/AI/Contracts/Tracing/` for the stateless trace-context contract, value object, and null implementation.
- Existing provider mapping/protocol namespaces can stay where they are if moving them would not clarify the tracing contract.

The module boundary remains the primary design constraint:

- `app/Base/AI` owns stateless transport/protocol mechanics.
- `app/Modules/Core/AI` owns orchestration, run recording, and operator inspection surfaces.

This is intentionally **not** a whole-AI rewrite. Do not move UI Livewire components, models, migrations, governance CRUD, provider auth flows, menu wiring, or session persistence just to make the tree look symmetrical. Do not create an “AI Integration” submodule that duplicates `Base/Integration` or blurs ownership.

## Blind Spots (Current State)

- ~~**Lara chat session titling**: `ManagesChatSessions::generateSessionTitle()` calls `LlmClient::chat(...)` directly with no run record and no wire tap.~~ **Fixed** (Phase 2): now routes through `SimpleTaskExecutor` → `AgenticRuntime`; source=`simple_task`, taskKey=`titling`.
- **Task model recommendations**: `TaskModelRecommendationService::recommend()` calls `LlmClient::chat(...)` directly from Core AI admin workflow with no run record and no wire tap.
- **Provider connectivity tests**: `ProviderTestService::executeTestCall()` calls `LlmClient::chat(...)` directly. This may reasonably stay out of `ai_runs`, but it still needs wire-log evidence when wire logging is enabled.
- **Future Lara “Simple” task profiles**: without a standard simple-task executor, these are likely to follow the same direct-call pattern and repeat the problem.
- **Base Database query generator**: `Base/Database/Livewire/Queries/Show::generateSql()` calls `LlmClient::chat(...)` directly with no run record and no wire tap.
- **Legacy `AgentRuntime` adapter**: exists and is bound as a singleton; it calls `LlmClient::chat(...)` without taps, and should not be used as a model for new work.

Provider testing and Base Database query generation are deliberately listed separately from Lara work because they may need wire-level trace without a run row. That distinction keeps the plan honest instead of forcing everything into `ai_runs` with misleading ownership.

## Public Contract

- Core AI features that call LLMs as product work MUST go through `AgenticRuntime` or another runtime path that records a run and attaches `WireLoggingTransportTap`.
- Core AI diagnostic calls that intentionally stay direct MUST obtain a trace context before constructing `ChatRequest`.
- Base modules that call LLMs MUST depend only on Base AI contracts and MUST attach the returned transport tap when one is available.
- New `LlmClient::chat(...)` call sites are allowed only in low-level AI transport/protocol services, runtime/executor implementations, or explicitly traced diagnostics/utilities.
- Run `source` and trace source labels are stable operator-facing values. Prefer adding a new truthful label over overloading `chat` or `stream` for unrelated work.

## Phases

### Phase 1 — Reshape runtime/transport seams before moving call sites

Goal: make the right path obvious by class placement and public contracts before fixing callers.

- [ ] Move run-recorded execution classes into a coherent Core AI runtime subtree and update namespaces/imports in one pass.
- [ ] Delete or rename the legacy `AgentRuntime` adapter during the move unless an active caller forces a short-lived compatibility shim; do not leave it as the advertised runtime path.
- [x] Add a Core runtime invocation context value object and thread it through `AgenticRuntime::run()` / `runStream()` without breaking existing chat callers. {Copilot/claude-sonnet-4-6}
- [x] Use invocation context to write truthful run `source` values and task/profile metadata while preserving existing session/turn correlation. {Copilot/claude-sonnet-4-6}
- [x] Add a Base AI trace-context contract/factory/value object that returns a correlation id and optional `LlmTransportTap` for direct `ChatRequest` callers. {Amp/gpt-5.5-medium}
- [x] Bind a Core AI implementation of that trace-context contract that creates `WireLoggingTransportTap` when wire logging is enabled and a null/no-op implementation otherwise. {Amp/gpt-5.5-medium}
- [x] Place the new Base AI tracing seam under a clear tracing/transport namespace; move `LlmClient`/protocol mechanics only if doing so clarifies the same seam. {Amp/gpt-5.5-medium}
- [x] Add source metadata to wire-log entries so pseudo-run files are understandable without opening application code. {Amp/gpt-5.5-medium}
- [ ] Sweep docs, `AGENTS.md`, and imports that still describe the old runtime layout before starting call-site conversions.

### Phase 2 — Make Lara simple work observable

Goal: eliminate the most visible “ghost LLM call”.

- [x] Define one Lara simple-task executor that resolves `resolveTask()` config overrides, applies tight execution controls, passes an empty tool allowlist, and calls `AgenticRuntime` with source/task metadata. {Copilot/claude-sonnet-4-6}
- [x] Route chat session titling through the simple-task executor so it produces a `run_id` and wire log entries when enabled. {Copilot/claude-sonnet-4-6}
- [x] Ensure titling is labeled distinctly from chat turns in run source/meta so operators can filter it. Source value is `simple_task`; task key is `titling`. {Copilot/claude-sonnet-4-6}
- [x] Update `docs/plans/lara-task-models.md` and `docs/architecture/ai/lara.md` references that currently describe simple tasks as direct `LlmClient::chat()` calls. {Copilot/claude-sonnet-4-6}

### Phase 3 — Cover Core AI direct-call utilities

Goal: either move Core AI utilities onto run-level tracing or explicitly mark them as traced diagnostics.

- [x] Route `TaskModelRecommendationService` through a run-recorded Core AI path, preserving its strict JSON contract and fallback recommendation behavior. {Copilot/GPT-5.3-Codex}
- [x] Add wire-trace context to `ProviderTestService` so provider tests leave request/response evidence when wire logging is enabled without pretending to be Lara chat runs. {Amp/gpt-5.5-medium}
- [ ] Decide the fate of any remaining Core AI direct `LlmClient::chat(...)` calls case-by-case: runtime/executor internals are allowed; feature and admin flows must be traced.

### Phase 4 — Add wire-tap coverage for Base-module LLM calls

Goal: Base features still produce evidence when wire logging is enabled.

- [ ] Update `Base/Database` SQL generation to request a Base AI trace context and attach its tap to `ChatRequest`.
- [ ] Use a source label such as `base_database_query_generator` and include the query/user-facing action context needed for operator diagnosis without leaking secrets.
- [ ] Keep Base AI and Base Database free of direct Core Control Plane imports.

### Phase 5 — Remove legacy adapter footguns

Goal: remove attractive but unsafe paths.

- [ ] Delete `AgentRuntime` if it is unused, or refactor it to attach wire taps at minimum and clearly document its intended usage boundary.
- [ ] Remove the `AgentRuntime` singleton binding if no production caller remains.
- [ ] Update module documentation and `AGENTS.md` references that still present `AgentRuntime` as the Core execution path.

### Phase 6 — Regression guardrails

Goal: make the rule hard to regress.

- [ ] Add focused tests proving titling records an `ai_runs` row and writes wire logs when wire logging is enabled.
- [x] Add focused tests proving provider tests attach a trace tap when the trace factory returns one. {Amp/gpt-5.5-medium}
- [ ] Add focused tests proving Base Database attaches a trace tap when the trace factory returns one.
- [ ] Add or document a lightweight static check for `LlmClient::chat(` call sites so future direct calls are reviewed against this contract.
- [x] Run the targeted Core AI provider-test slice and record evidence here before handing off. Evidence: `php artisan test tests/Unit/Modules/Core/AI/Services/ProviderTestServiceTest.php tests/Unit/Modules/Core/AI/Services/ProviderTestServiceCodexTest.php` passed 9 tests / 70 assertions; `php artisan test tests/Feature/Modules/Core/AI/OpenAiCodexSetupTest.php` passed 11 tests / 59 assertions with 1 pre-existing skipped case. {Amp/gpt-5.5-medium}
- [ ] Run the targeted Base Database test slice once Base Database tracing is implemented.
