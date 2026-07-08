# Base Scheduling Observability (Central Cron Management)

**Status:** Landed (Phases 1-4)
**Last Updated:** 2026-07-08
**Origin:** extensions/kiat investment Agents page (incubator); owner asked whether cron management should be central.

## Problem Essence

Scheduled work in BLB now lives in three unrelated systems with three different observability stories:

1. **Laravel scheduler entries** registered by module ServiceProviders (e.g. investment's price-history, radar-scan, draft-research-notes, agent-dispatch). Laravel gives `schedule:list` but no run history, durations, output, or failures anywhere a user can see.
2. **Core AI ScheduleDefinitions** (`SchedulesTickCommand` → `SchedulePlanner` → `OperationDispatch` ledger) for in-app agent tasks — good run recording, but only for AI-agent work.
3. **Module-owned task ledgers** — the kiat investment extension just built `kiat_investment_agent_tasks/agent_runs/command_runs` plus an Agents page (tabs: Tasks / Run Ledger with upcoming fire times, statuses, durations, output excerpts) because nothing central existed.

The third system proves the need and the UI shape (Aoe_Scheduler-style: upcoming + history in one place), but it is module-local. Every future module with scheduled work would rebuild it — classic entropy. A user asking "what ran last night, what failed, what runs next?" has no single answer.

## Desired Outcome

One framework-owned answer to "what is scheduled, what ran, what's next":

- Every Laravel scheduler event (all modules, not just investment) records start/finish/duration/output/status into a **Base-owned schedule-run ledger** automatically — modules opt out, not in.
- A **Base Scheduling page** (`admin/system/scheduling` alongside existing system pages) lists: upcoming runs (all sources, soonest first), run history with status/duration/output excerpt, and per-entry pause where the source supports it.
- Core AI ScheduleDefinitions and module task systems (kiat agent tasks) appear on the same page through a small **contributor contract** (a provider interface returning upcoming items + recent runs), instead of being rebuilt centrally.
- Module pages (like investment's Agents page) keep their domain view but consume the same ledger — no duplicate recording.

## Design Decisions

**Option A — status quo:** each module wires its own before/onSuccess hooks and tables. Rejected: N ledgers, N UIs, drift guaranteed.

**Option B — adopt Core AI's OperationDispatch as the universal ledger.** Attractive (exists, has UI widget), but it is agent-shaped (employee_id, task text) and company-scoped for AI governance; forcing artisan command runs into it muddies both domains. Rejected for now; revisit if AI module generalizes dispatches.

**Option C (recommended) — Base\Scheduling module:** a `schedule_runs` table + a listener on Laravel's `ScheduledTaskStarting/Finished/Failed` events (framework-wide, zero per-module wiring), a `SchedulingContributor` contract for non-scheduler sources (AI ScheduleDefinitions, kiat agent tasks), and one admin page. Wins on entropy (one recorder, one page), deep modules (modules contribute via a small interface), and honesty (the page shows what actually ran, from the same rows the system wrote).

Migration path: kiat's `CommandRunRecorder` + `kiat_investment_command_runs` are the incubator; once Base\Scheduling lands, the extension drops its command ledger and registers contributors for its agent tasks. The Agents page keeps only the investment-specific task editing.

## Phases

- [x] Phase 1 — Base\Scheduling module: `base_schedule_runs` migration, scheduler event listeners (auto-record all scheduled commands), 90-day retention pruning. anthropic/claude-fable-5
- [x] Phase 2 — Admin Scheduling page: upcoming (from `Schedule::events()` + contributors) and history tables; menu under System with `admin.system.scheduling.view`. Dashboard widget deferred — the page suffices until someone asks. anthropic/claude-fable-5
- [x] Phase 3 — `SchedulingContributor` contract + adapters: Core AI ScheduleDefinitions, kiat investment agent tasks. anthropic/claude-fable-5
- [x] Phase 4 — De-dup: `kiat_investment_command_runs` dropped, kiat recorder deleted; the extension contributes agent tasks and links to the central page. anthropic/claude-fable-5

## Open Questions

- Retention policy for `schedule_runs` (proposal: 90 days, pruned by the scheduler itself).
- Whether pause/resume of Laravel scheduler entries is worth persisting (needs a suppression table the ServiceProviders consult) or Phase-2 scope creep.
