# docs/plans/scheduled-task-run-history.md

**Status:** Superseded
**Last Updated:** 2026-07-10
**Sources:** `docs/plans/base-schedule-observability.md` (canonical), collision between duplicate Base scheduling modules
**Agents:** Cursor/Grok 4.5, OpenAI/GPT-5 Codex

## Problem Essence (Historical)

Operators needed more than a single last-run snapshot for scheduled work. The first implementation answered that with a separate Scheduled Tasks surface, a last-run mirror, and a second history table, but that created a second Base scheduling module and collided with the existing central Schedule design.

## Supersession Decision

Do not revive the two-table `base_schedule_runs` + `base_schedule_run_history` design, the `ScheduledTasks` UI, the `Base\Scheduling` namespace, `attempt_key`, `keep_count`, or `blb:schedule:history:prune`. The canonical design is now `docs/plans/base-schedule-observability.md`:

- one provider-discovered module at `app/Base/Schedule`
- one public surface at `/admin/system/schedule`
- one append-style `base_schedule_runs` ledger, one row per attempt
- stable task identity as `source + key`; `name` is display text
- Tasks / History / Settings tabs on the Schedule page
- `schedule.history.keep_days` stored in `base_settings`
- run-now actions queued through registered scheduler events
- no compatibility redirects for old scheduling/scheduled-tasks URLs

## Closed Work

- [x] Superseded the duplicate Scheduled Tasks implementation and removed the old routes/views/models/jobs/commands that depended on a separate history table. OpenAI/GPT-5 Codex
- [x] Repaired the current SQLite database directly: dropped obsolete schedule migration rows, rebuilt the canonical Schedule tables from incubating source, and aligned incubating source hashes so `php artisan migrate --force` reports no drift. OpenAI/GPT-5 Codex
- [x] Folded the useful parts of the discussion into `base-schedule-observability.md`: History and Settings tabs, retention in `base_settings`, run-now, Status/Last run/Result columns, and stable task keys. OpenAI/GPT-5 Codex
