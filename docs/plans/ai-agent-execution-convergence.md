# AI Agent Execution Convergence

**Status:** Implemented
**Last Updated:** 2026-07-10
**Origin:** kiat investment agent tasks vs Core AI AgenticRuntime — two execution paths for AI work.

## Problem Essence

BLB now executes AI work through two unrelated stacks:

1. **Core AI `AgenticRuntime`** — in-app runs with run recording, provider governance (`ai_providers`, task-model config), tool registry, cost telemetry, and the `OperationDispatch` ledger. Its toolset is chat/browse/page-shaped; it cannot safely edit repo files, run artisan+git, or drive long scraping sessions.
2. **kiat investment agents** — headless CLI agents (Claude Code, Codex CLI) doing exactly that repo-shaped work. These now live as Core AI `ScheduleDefinition` rows with `source = investment-agent` and `executor = headless_cli`; the Kiat Agents page is only a filtered editing/observability surface over platform schedules and `OperationDispatch`.

The previous duplication was not just visual: both paths recorded runs, resolved providers/models, and carried attribution in different schemas. The Base Schedule feature is now the shared schedule/observability surface, not a display-only adapter.

## Desired Outcome

One execution contract with two engines behind it: modules declare *what* an agent task is (prompt/skill pointer, schedule, execution profile) through Core AI `ScheduleDefinition`; the platform decides *how* it runs — in-process `AgenticRuntime` for tool-safe work, headless CLI runner for repo-capable work — and both record into the shared `OperationDispatch`/`AiRun` ledgers. Base Schedule projects those definitions and dispatches by `source`.

## Design Decisions

**Option A — teach AgenticRuntime repo tools.** Gives one engine, but grants the in-app runtime (reachable from chat) filesystem/git/shell powers — a large security surface the bash-tool production gate was built to avoid. Rejected while the app is user-facing.

**Option B — status quo, converge only observability.** Cheap, already done (ScheduleContributor), but leaves duplicate task schemas and provider plumbing to drift. Acceptable short-term only.

**Option C (implemented) — promote the CLI runner to Core AI as a second executor.** The generic runner pieces live in Core AI as `HeadlessCliExecutor`, `HeadlessCliProcessExecutor`, and `RunHeadlessCliTaskJob`; runs record as `OperationDispatch` rows (`operation_type = headless_task`) and create `AiRun`/`AiRunCall` telemetry rows when CLI usage is available. `ScheduleDefinition` now owns `source`, `source_key`, `executor`, headless provider/model, and manual run requests. Kiat keeps only a source-specific default schedule catalog and attribution preamble; it does not own task/run tables.

Base Schedule dependency:

- Core AI registers `ScheduleDefinitionContributor` as the single contributor for AI schedules.
- The contributor projects every enabled `ScheduleDefinition` to Base Schedule using its owning `source` (`core-ai`, `investment-agent`, future modules).
- Headless and agentic schedule dispatches both appear in Base Schedule's run ledger from `OperationDispatch`; module-specific contributors for Kiat agent tasks were removed.

## Phases

- [x] Phase 1 — Extract `HeadlessCliExecutor` + CLI template config into Core AI; record runs as OperationDispatch (`HeadlessTask`); port kiat's attribution preamble into the investment schedule catalog.
- [x] Phase 2 — Add ScheduleDefinition `executor` fields and planner dispatch to either engine; Base Schedule shows the shared schedule definitions and dispatches by source.
- [x] Phase 3 — Migrate kiat agent tasks onto ScheduleDefinitions; drop `kiat_investment_agent_tasks/agent_runs`; Agents page is a filtered view over platform schedules.
- [x] Phase 4 — Cost/usage telemetry parity: parse CLI JSON usage into `ai_run_calls` through `RunRecorder`, while preserving raw CLI-reported total cost in dispatch metadata.

## Open Questions

- Windows service vs FrankenPHP worker for long CLI runs (today: scheduler `runInBackground` for Kiat's source-filtered dispatch command; the headless job itself is queue-backed).
- Whether CLI-reported `total_cost_usd` should become a first-class trusted override when provider token pricing is unavailable. For now it is preserved in dispatch metadata and token usage uses the existing pricing resolver.
