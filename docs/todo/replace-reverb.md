# Replace Reverb

## Problem Essence

BLB's AI chat no longer uses Reverb, and the remaining Reverb footprint is now a small set of isolated features plus infrastructure overhead. Keeping the full Reverb and Echo stack for those leftovers adds runtime processes, frontend dependencies, environment variables, and architectural noise that no longer earns its keep.

## Status

Complete

## Desired Outcome

Remove Reverb, Echo, and `pusher-js` from the product and development stack without losing any user-visible capability that still matters. Replace the remaining live-update surfaces with simpler HTTP- and Livewire-native patterns, then delete the unused broadcasting infrastructure, scripts, and docs.

## Public Contract

After this work:

- Lara chat continues to use direct streaming for fresh turns and persisted replay for recovery.
- Geonames postcode import still shows progress through Livewire's `wire:loading` during synchronous operations.
- The system-level Reverb test page is removed rather than preserved under a different transport.
- Frontend boot no longer initializes `window.Echo`.
- Local development no longer starts a Reverb process.
- Reverb-specific config, channels, env vars, docs, and JS dependencies are removed.

## Phases

### Phase 1 — Final Consumer Audit

- [x] Confirm whether any browser-visible feature besides Geonames import and `TestReverb` still consumes `window.Echo` — none found
- [x] Confirm whether `routes/channels.php` has any remaining required channels after AI turn streaming removal — none; both channels were dead code
- [x] Decide whether `NotificationTool` keeps `broadcast` as a supported channel — narrowed to `database` only
- [x] Update this document with the final removal boundary once the audit is complete

### Phase 2 — Replace Product Behavior

- [x] Replace Geonames postcode import WebSocket progress with Livewire `wire:loading` (already present as fallback)
- [x] Remove `PostcodeImportProgress` broadcast event
- [x] Remove the `TestReverb` system page, route, menu entry, and event class
- [x] Remove `TestReverbDispatchController`
- [x] Remove now-unused broadcast channels from `routes/channels.php`
- [x] Narrow `NotificationTool` to `database` channel only

### Phase 3 — Remove Frontend and Runtime Infrastructure

- [x] Remove `resources/core/js/echo.js` and its import from `app.js`
- [x] Remove `laravel-echo` and `pusher-js` from `package.json`
- [x] Remove `php artisan reverb:start` from `dev:all` / `dev:all:watch` scripts
- [x] Remove Reverb-specific setup/runtime shell logic and env variable handling
- [x] Remove `config/reverb.php` and Reverb connection from `config/broadcasting.php`
- [x] Remove Reverb proxy blocks from `Caddyfile`
- [x] Remove `REVERB_SERVER_PORT` from `config/octane.php`
- [x] Remove `laravel/reverb` Composer dependency
- [x] Clean `.env.example` of all `REVERB_*` and `VITE_REVERB_*` variables

### Phase 4 — Documentation and Verification

- [x] Replace `docs/architecture/broadcasting.md` with current-state architecture
- [x] Update `docs/architecture/caddy-frankenphp-topology.md`
- [x] Update setup and troubleshooting docs
- [x] Update plan docs and module docs
- [x] Verify `TransportTestUiTest` passes (3 tests, 20 assertions)
- [x] Verify no stale Reverb/Echo references in app code, config, or routes
