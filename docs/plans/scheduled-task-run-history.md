# docs/plans/scheduled-task-run-history.md

**Status:** Complete  
**Last Updated:** 2026-07-09  
**Sources:** Scheduled Tasks admin page (`admin.system.scheduled-tasks`), `base_schedule_runs`, Base Settings (`base_settings`)  
**Agents:** Cursor/Grok 4.5

## Problem Essence

Operators can see only the **last** attempt per command. Intermittent failures, “was #41 also bad?”, and post-mortems across days have no durable trail. Retention without a prune policy will grow unbounded.

## Desired Outcome

Attempt history operators can browse (filter by command / status; search covers command/output), with retention in `base_settings` and a scheduled prune. Tasks = registry + last status; History = prior attempts.

## Top-Level Components

- **Last-run mirror** — `base_schedule_runs` (one row per `command_key`).
- **History log** — `base_schedule_run_history` (one row per attempt; running row mutated to terminal).
- **Recorder** — dual-write; durable `attempt_key` correlates start/finish across events.
- **Prune** — `blb:schedule:history:prune`; never deletes last-run rows.
- **UI tabs** — Tasks | History | Settings.

## Design Decisions

### Two tables (last-run + history)

**Chosen.** Different grain and lifecycle. Same columns are two read models, not entropy.

### Retention

**Chosen:** `schedule.history.keep_days` / `keep_count` in `base_settings`, Settings tab, `admin.system.scheduled-task.manage`.

### Authz

- `list` — route middleware + component mount/render (page is sensitive: outputs/history).
- `execute` — Run now only (not on System Viewer).
- `manage` — retention settings.

### Concurrency / correlation

**Chosen:** UUID `attempt_key` bound on the Event at Starting and stored on history (+ last-run while running). Finish/fail/skip complete by `attempt_key`, not “latest running for command_key.”

**BLB overlap policy:** Run now is refused while that `command_key` is running. Prefer `withoutOverlapping()` on long jobs. Background `schedule:finish` adopts `attempt_key` from the last-run mirror when still `running` (same-command overlapping backgrounds remain unsafe — avoid them).

### Laravel event sequences (invariants)

- **Skipped** can occur without Starting (filters/pause) → insert standalone skipped row.
- **Overlap skip** after Starting may surface as Skipped (manual job) or Finished with `skippedBecauseOverlapping` → complete the running row as skipped (never succeeded).
- **Non-zero foreground** emits Finished then Failed → one history row; Failed enriches output on the same attempt.
- **Background finish** is a separate process → restore deterministic output path before `readOutput`.

## Public Contract

- History fields: `command_key`, `command`, `expression`, `attempt_key`, `status`, `exit_code`, `runtime_ms`, `output`, `started_at`, `finished_at`.
- Statuses: `running` | `succeeded` | `failed` | `skipped` (constants on `ScheduleRunStatuses`).
- Tasks `#N` = last-run id; History `#N` = history id.
- History is **one row per attempt** (not a strict append-only audit log): insert on start (or skip-without-start), mutate to terminal.
- Defaults: keep_days 30, keep_count 500; 0 disables that axis.

## Phases

### Phase 1 — Schema + recorder dual-write

- [x] `base_schedule_run_history` + `attempt_key` on both tables. {Cursor/Grok 4.5}
- [x] Recorder dual-write with attempt correlation. {Cursor/Grok 4.5}
- [x] Tests: history grows; last-run unique. {Cursor/Grok 4.5}

### Phase 2 — History + Settings UI

- [x] Tabs: Tasks | History | Settings. {Cursor/Grok 4.5}
- [x] Retention via SettingsService; Schedule `Config/settings.php`. {Cursor/Grok 4.5}
- [x] Livewire tests. {Cursor/Grok 4.5}

### Phase 3 — Scheduled prune

- [x] `blb:schedule:history:prune` daily 03:15 UTC. {Cursor/Grok 4.5}
- [x] Prune tests; never touches last-run. {Cursor/Grok 4.5}

### Phase 4 — Amp review hardening

- [x] Authz: list on route/component; Viewer loses execute; manage for retention; reseed. {Cursor/Grok 4.5}
- [x] Finished+Failed idempotency; overlap/filter skip shapes; attempt_key concurrency; background output path; run-now overlap deny + filtersPass. {Cursor/Grok 4.5}
- [x] Regression tests for real event sequences. {Cursor/Grok 4.5}

## Out of scope

- Renaming Diagnostics menu group.
- Streaming live output; per-command retention; time-range History filters (search/status only for now).
- Full schedule:run parity for Run now (`onOneServer` ownership, etc.).
