# Setup Maintenance State and History

## Goal
Add persistent maintenance setup state for reruns while preserving current temporary run-state behavior and authoritative live detection.

## TODO
- [ ] Keep current temporary run-state behavior for `setup.env` unchanged.
- [ ] Confirm `setup.env` path ownership in `scripts/shared/config.sh` and keep cleanup in `scripts/setup.sh` after successful full setup.
- [ ] Add a persistent maintenance-state file in `storage/app/.devops/` to store latest known subsystem status across runs.
- [ ] Add read/write helper functions in `scripts/shared/config.sh` for maintenance state keys.
- [ ] Add an append-only maintenance history log file in `storage/app/.devops/` for audit/history events.
- [ ] Reuse directory helpers from `scripts/shared/runtime.sh` when creating and writing maintenance state/history files.
- [ ] Document and enforce trust model in helpers: persistent state is cache/telemetry only; live detection in each setup step remains authoritative.
- [ ] Incremental rollout phase 1: wire shared helper primitives only (no behavior changes in setup steps).
- [ ] Incremental rollout phase 2: integrate with `scripts/setup-steps/10-git.sh`, `scripts/setup-steps/30-js.sh`, `scripts/setup-steps/20-php.sh`, `scripts/setup-steps/40-database.sh`, and `scripts/setup-steps/70-caddy.sh`.
- [ ] Define exact maintenance-state key schema and helper function signatures before step integrations.
