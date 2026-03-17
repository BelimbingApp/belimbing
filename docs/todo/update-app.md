# TODO: update-app.sh and In-App Update

**Status**: Proposed  
**Priority**: Medium  
**Target**: Script and optional admin-panel flow for upgrading dependencies and applying project updates

## Overview

Provide a clear, repeatable way to update the BLB project: PHP/Composer and Node/Bun dependencies, migrations, caches, and (optionally) an in-admin “Check for updates” or “Apply updates” flow.

Today `scripts/start-app.sh` only verifies that composer and node/bun are present; it does not install or upgrade dependencies. This todo covers adding an explicit update path.

## Goals

1. **Script: `scripts/update-app.sh`**
   - Run project-level updates in a single command:
     - `composer install` or `composer update` (policy TBD: default to safe install, optional flag for update).
     - Node/Bun: `npm ci` / `npm install` or `bun install` (and optionally `npm update` / `bun update` with a flag).
     - Laravel: `php artisan migrate` (and optionally `php artisan migrate:fresh` with a flag or confirmation).
     - Cache/config optimizations as needed: `config:clear`, `cache:clear`, `view:clear`, etc.
   - Document when to use it (e.g. after pull, before start-app, after changing composer.json/package.json).
   - Align with existing scripts (e.g. `scripts/setup.sh`, `scripts/start-app.sh`) and BLB conventions (`blb:` commands if any are added).

2. **Optional: Admin-panel update**
   - Consider an “Update application” or “Check for updates” area in the admin panel (e.g. under System or Settings):
     - Trigger or mirror the same steps (composer install, npm/bun install, migrate, clear caches), or
     - Show current versions and link to instructions / run script via docs.
   - Clarify security and permissions: who can run updates, and whether it should be “run script on server” vs “show status and instructions”.

## Out of Scope (for this todo)

- Upgrading the PHP or Node/Bun runtime versions (that remains in setup/setup-steps).
- Automatic background updates or auto-update on every start-app (keep start-app fast and predictable).

## Open Questions

- Default behavior: `composer install` + `npm ci`/`bun install` (lockfile-friendly) vs `composer update` / `npm update` (refresh versions)?
- Should `update-app.sh` run migrations by default or require a flag?
- Should admin-panel update run commands on the server (e.g. via Artisan or a guarded Livewire action) or only show status and link to running `./scripts/update-app.sh`?

## References

- `scripts/start-app.sh` — does not upgrade dependencies; only checks presence of composer, node/bun, caddy.
- `scripts/setup.sh` / `scripts/setup-steps/` — install runtimes and initial deps; update-app would complement for ongoing updates.
