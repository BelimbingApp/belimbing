# AI Agent Execution Convergence

**Status:** Proposed
**Last Updated:** 2026-07-09
**Origin:** kiat investment agent tasks vs Core AI AgenticRuntime — two execution paths for AI work.

## Problem Essence

BLB now executes AI work through two unrelated stacks:

1. **Core AI `AgenticRuntime`** — in-app runs with run recording, provider governance (`ai_providers`, task-model config), tool registry, cost telemetry, and the `OperationDispatch` ledger. Its toolset is chat/browse/page-shaped; it cannot safely edit repo files, run artisan+git, or drive long scraping sessions.
2. **kiat `AgentTaskRunner`** — headless CLI agents (Claude Code, Codex CLI) doing exactly that repo-shaped work, with its own task table, run ledger (`kiat_investment_agent_runs`), CLI templates (`investment/Config/agents.php`), and attribution convention. It borrows only model *selection* from Core AI (`ConfigResolver::resolveTask(research)`).

Both record runs, both resolve providers/models, both carry attribution — in different schemas. Every future module wanting repo-capable scheduled agents would clone the kiat stack. The central Scheduling page already *displays* both, but display-level unification hides a real duplication underneath.

## Desired Outcome

One execution contract with two engines behind it: modules declare *what* an agent task is (prompt/skill pointer, schedule, execution profile); the platform decides *how* it runs — in-process `AgenticRuntime` for tool-safe work, headless CLI runner for repo-capable work — and both record into the same run ledger with the same provider/model attribution and cost fields.

## Design Decisions

**Option A — teach AgenticRuntime repo tools.** Gives one engine, but grants the in-app runtime (reachable from chat) filesystem/git/shell powers — a large security surface the bash-tool production gate was built to avoid. Rejected while the app is user-facing.

**Option B — status quo, converge only observability.** Cheap, already done (SchedulingContributor), but leaves duplicate task schemas and provider plumbing to drift. Acceptable short-term only.

**Option C (recommended) — promote the CLI runner to Core AI as a second executor.** Move the generic parts of kiat's runner (CLI templates, process executor, attribution preamble, run recording) into Core AI as `HeadlessCliExecutor` beside `AgenticRuntime`; runs record as `OperationDispatch` rows (new `operation_type: HeadlessTask`), reusing existing status/telemetry UI. Schedule definitions gain an `executor` field. Kiat keeps only its domain task rows and prompts, pointing at repo skills. Wins on entropy (one ledger, one provider config), honesty (one attribution pipeline), and keeps the security boundary: headless CLI runs stay opt-in, machine-local, and never reachable from chat input.

## Phases

- [ ] Phase 1 — Extract `HeadlessCliExecutor` + CLI template config into Core AI; record runs as OperationDispatch (`HeadlessTask`); port kiat's attribution preamble.
- [ ] Phase 2 — ScheduleDefinition `executor` field + planner dispatch to either engine; admin UI shows executor per schedule.
- [ ] Phase 3 — Migrate kiat agent tasks onto ScheduleDefinitions; drop `kiat_investment_agent_tasks/agent_runs`; Agents page becomes a filtered view over platform schedules.
- [ ] Phase 4 — Cost/usage telemetry parity: parse CLI JSON usage into the same pricing pipeline as runtime runs.

## Open Questions

- Whether headless runs should be schedulable per-company (Core AI schedules are company-scoped; kiat is single-owner).
- Windows service vs FrankenPHP worker for long CLI runs (today: scheduler `runInBackground`).
