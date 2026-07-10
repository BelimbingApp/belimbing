# Base Schedule Observability (Central Cron Management)

**Status:** Landed; canonicalized to one `Base\Schedule` module and one append ledger
**Last Updated:** 2026-07-10
**Origin:** extensions/kiat investment Agents page (incubator); owner asked whether cron management should be central.
**Agents:** anthropic/claude-fable-5, OpenAI/GPT-5 Codex

## Problem Essence

Scheduled work in BLB now lives in three unrelated systems with three different observability stories:

1. **Laravel scheduler entries** registered by module ServiceProviders (e.g. investment's price-history, radar-scan, draft-research-notes, agent-dispatch). Laravel gives `schedule:list` but no run history, durations, output, or failures anywhere a user can see.
2. **Core AI ScheduleDefinitions** (`SchedulesTickCommand` → `SchedulePlanner` → `OperationDispatch` ledger) for in-app agent tasks — good run recording, but only for AI-agent work.
3. **Module-owned task ledgers** — the kiat investment extension just built `kiat_investment_agent_tasks/agent_runs/command_runs` plus an Agents page (tabs: Tasks / Run Ledger with upcoming fire times, statuses, durations, output excerpts) because nothing central existed.

The third system proves the need and the UI shape (Aoe_Scheduler-style: upcoming + history in one place), but it is module-local. Every future module with scheduled work would rebuild it — classic entropy. A user asking "what ran last night, what failed, what runs next?" has no single answer.

## Desired Outcome

One framework-owned answer to "what is scheduled, what ran, what's next":

- Every Laravel scheduler event (all modules, not just investment) records start/finish/duration/output/status into a **Base-owned schedule-run ledger** automatically — modules opt out, not in.
- A **Base Schedule module** owns the **Schedule page** (`admin/system/schedule` alongside existing system pages), with Tasks / History / Settings tabs. Tasks lists all sources, next run, status, last run, result, and supported actions; History lists recent attempts; Settings owns retention.
- Core AI ScheduleDefinitions and module task systems (kiat agent tasks) appear on the same page through a small **contributor contract** (a provider interface returning upcoming items + recent runs), instead of being rebuilt centrally.
- Module pages (like investment's Agents page) keep their domain view but consume the same ledger — no duplicate recording.

## Design Decisions

**Option A — status quo:** each module wires its own before/onSuccess hooks and tables. Rejected: N ledgers, N UIs, drift guaranteed.

**Option B — adopt Core AI's OperationDispatch as the universal ledger.** Attractive (exists, has UI widget), but it is agent-shaped (employee_id, task text) and company-scoped for AI governance; forcing artisan command runs into it muddies both domains. Rejected for now; revisit if AI module generalizes dispatches.

**Option C (recommended) — one Base Schedule module:** a `base_schedule_runs` append ledger + listeners on Laravel's scheduler events (framework-wide, zero per-module wiring), a `ScheduleContributor` contract for non-scheduler sources (AI ScheduleDefinitions, kiat agent tasks), and one admin page. The module path is `app/Base/Schedule`; the user-facing route/menu/capability use "Schedule" for the same concept. Wins on entropy (one recorder, one page, one provider-discovered module), deep modules (modules contribute via a small interface), and honesty (the page shows what actually ran, from the same rows the system wrote). Do not add a second history table: the ledger is already one row per attempt, with `source + key` as stable identity and `name` as display text.

Migration path: kiat's `CommandRunRecorder` + `kiat_investment_command_runs` are the incubator; once Base Schedule lands, the extension drops its command ledger and registers contributors for its agent tasks. The Agents page keeps only the investment-specific task editing.

## Phases

- [x] Phase 1 — Base Schedule module: `base_schedule_runs` migration, scheduler event listeners (auto-record all scheduled commands), 90-day retention pruning. anthropic/claude-fable-5
- [x] Phase 2 — Admin Schedule page: upcoming (from `Schedule::events()` + contributors) and history tables; menu under System with `admin.system.schedule.view`. Dashboard widget deferred — the page suffices until someone asks. anthropic/claude-fable-5
- [x] Phase 3 — `ScheduleContributor` contract + adapters: Core AI ScheduleDefinitions, kiat investment agent tasks. anthropic/claude-fable-5
- [x] Phase 4 — De-dup: `kiat_investment_command_runs` dropped, kiat recorder deleted; the extension contributes agent tasks and links to the central page. anthropic/claude-fable-5
- [x] Phase 5 — Remove the duplicate `Base\Scheduling`/`Base\Schedule` split: keep `app/Base/Schedule` as the only provider-discovered module, keep `/admin/system/schedule` as the public surface, remove the old scheduling/scheduled-tasks routes, and keep Schedule schema source-clean under `IncubatingSchema`. OpenAI/GPT-5 Codex
- [x] Phase 6 — Lower table/UI entropy: replace "Upcoming" model names with `ScheduleTask`, rename contributor projection to `tasks()`, key scheduler rows by `source + key`, batch-load latest runs, and make the Tasks tab show Status, Last run, and Result. OpenAI/GPT-5 Codex
- [x] Phase 7 — Operator controls: add History and Settings tabs, queue run-now through a registered scheduler-event job, gate execute/manage operations with `admin.system.schedule.execute/manage`, retain history via `schedule.history.keep_days` in `base_settings`, and rebuild the current SQLite Schedule tables directly because the migrations remain incubating. OpenAI/GPT-5 Codex

## Resolved Decisions

- Retention: resolved — `schedule.history.keep_days` in `base_settings`, default 90 days, pruned opportunistically by the recorder.
- Pause/resume: resolved and built — `base_schedule_suppressions`
  keyed by `source + key`; a `CommandStarting` hook on
  `schedule:run/work/test` attaches skip filters after all providers boot, so
  no ServiceProvider changes are needed anywhere. Page gains Pause/Resume for
  scheduler-source rows; contributor sources keep their own toggles.
- Run now: resolved — scheduler-source rows get a play icon when the actor has
  `admin.system.schedule.execute`. The queued job finds an already registered
  event by the recorder key and dispatches Laravel scheduler lifecycle events
  so the ledger remains the source of truth.
- Related follow-up plan: `ai-agent-execution-convergence.md` (one execution
  contract over AgenticRuntime + headless CLI runner).
