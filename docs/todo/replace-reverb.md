# Replace Reverb

## Problem Essence

BLB's AI chat no longer uses Reverb, and the remaining Reverb footprint is now a small set of isolated features plus infrastructure overhead. Keeping the full Reverb and Echo stack for those leftovers adds runtime processes, frontend dependencies, environment variables, and architectural noise that no longer earns its keep.

## Status

Proposed

## Desired Outcome

Remove Reverb, Echo, and `pusher-js` from the product and development stack without losing any user-visible capability that still matters. Replace the remaining live-update surfaces with simpler HTTP- and Livewire-native patterns, then delete the unused broadcasting infrastructure, scripts, and docs.

## Public Contract

After this work:

- Lara chat continues to use direct streaming for fresh turns and persisted replay for recovery.
- Geonames postcode import still shows progress, but through polling or Livewire refresh instead of WebSocket push.
- The system-level Reverb test page is removed rather than preserved under a different transport.
- Frontend boot no longer initializes `window.Echo`.
- Local development no longer starts a Reverb process.
- Reverb-specific config, channels, env vars, docs, and JS dependencies are removed unless another non-negotiable feature still depends on Laravel broadcasting.

## Top-Level Components

### Remaining Runtime Consumers

The current known Reverb consumers are narrow:

- `resources/core/views/livewire/admin/geonames/postcodes/index.blade.php` listens on `postcode-import`
- `app/Modules/Core/Geonames/Events/PostcodeImportProgress.php` broadcasts import progress
- `resources/core/views/livewire/admin/system/test-reverb/index.blade.php` is an infrastructure test surface for Reverb itself
- `app/Base/System/Events/ReverbTestMessageOccurred.php` exists only to drive that test page
- `resources/core/js/echo.js` bootstraps Echo and `pusher-js`
- `routes/channels.php` still defines broadcast channels, including the now-unused AI turn channel

### Infrastructure Surface

The stack is also wired into:

- `package.json` dev scripts (`php artisan reverb:start`)
- `config/broadcasting.php` and `config/reverb.php`
- setup/runtime scripts that provision `REVERB_*` values and ports
- architecture and setup docs that still describe Reverb as a first-class subsystem

### Edge Contract: Notification Broadcasting

`NotificationTool` still supports a `broadcast` channel at the tool contract level. Before removing broadcasting globally, BLB should either:

- narrow that tool to the channels that remain supported in product UX, or
- explicitly keep Laravel broadcasting for notifications only

The recommended direction is to remove `broadcast` from the tool contract unless there is a real browser consumer that still needs it.

## Design Decisions

### Recommendation: Remove Reverb App-Wide, Not Just From AI

The codebase has already paid the cost of migrating the hardest realtime surface, Lara chat, to direct streaming. That changes the economics: Reverb is no longer a strategic subsystem, it is now mostly leftover infrastructure for one import progress surface and one test harness.

A hybrid state is worse than either clear alternative:

- keeping Reverb means continuing to carry `laravel-echo`, `pusher-js`, Reverb server startup, env management, reverse-proxy rules, and websocket-specific docs
- removing it means rewriting only a small number of features using simpler mechanisms BLB already trusts

BLB should take the simpler system.

### Recommendation: Replace Progress Push With Polling, Not Another Generic Transport Layer

The remaining live progress use case is Geonames import status. That is not a good reason to preserve a general-purpose websocket stack.

The replacement should be task-specific:

- persist or expose import progress through the existing job/import state
- poll from the UI on a short interval while import is active
- keep the current immediate fallback message so UX still responds instantly

This gives up nothing material for that workflow and removes a lot of platform complexity.

### Recommendation: Delete the Reverb Test Surface

`TestReverb` verifies infrastructure BLB is trying to remove. It should not be migrated to another transport unless there is an actual product need for a generic realtime diagnostics page.

The right replacement is deletion, not transport substitution.

## Phases

### Phase 1 — Final Consumer Audit

Goal: confirm the true removal boundary so the implementation does not leave hidden broadcast dependencies behind.

- [ ] Confirm whether any browser-visible feature besides Geonames import and `TestReverb` still consumes `window.Echo`
- [ ] Confirm whether `routes/channels.php` has any remaining required channels after AI turn streaming removal
- [ ] Decide whether `NotificationTool` keeps `broadcast` as a supported channel or is narrowed to non-broadcast delivery modes
- [ ] Update this document with the final removal boundary once the audit is complete

### Phase 2 — Replace Product Behavior

Goal: replace the remaining user-visible Reverb behavior with simpler product-local behavior.

- [ ] Replace Geonames postcode import WebSocket progress with polling or Livewire-native refresh
- [ ] Remove `PostcodeImportProgress` broadcasting once the replacement path is in place
- [ ] Remove the `TestReverb` system page, route, menu entry, and event class
- [ ] Remove any now-unused broadcast channels from `routes/channels.php`

### Phase 3 — Remove Frontend and Runtime Infrastructure

Goal: delete the platform-level Reverb stack after product callers are gone.

- [ ] Remove `resources/core/js/echo.js` and any frontend bootstrapping that expects `window.Echo`
- [ ] Remove `laravel-echo` and `pusher-js` from `package.json`
- [ ] Remove `php artisan reverb:start` from development scripts
- [ ] Remove or simplify Reverb-specific setup/runtime shell logic and env variable handling
- [ ] Remove `config/reverb.php` and simplify `config/broadcasting.php` if no broadcasting driver remains necessary

### Phase 4 — Documentation and Verification

Goal: leave no stale operational or architectural guidance behind.

- [ ] Update architecture docs that currently describe Reverb as a first-class subsystem
- [ ] Update setup and troubleshooting docs that mention Reverb ports or startup steps
- [ ] Verify local development starts cleanly without a Reverb process
- [ ] Verify Geonames import still provides usable progress feedback after the transport change
- [ ] Verify Lara chat and other AI surfaces still work with no `window.Echo` bootstrap present
