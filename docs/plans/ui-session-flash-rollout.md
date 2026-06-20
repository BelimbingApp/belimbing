# docs/plans/ui-session-flash-rollout.md

Status: success/error lane complete (all Core views on `x-ui.session-flash`); `status` lane partly open — auth status stays on its own component, but 3 admin views still render `session('status')` as raw success alerts (Phase 2)
Last Updated: 2026-06-21
Sources: `resources/core/views/components/ui/session-flash.blade.php`, `resources/core/views/AGENTS.md` (Component Inventory, Principle 1), `DESIGN.md` (no raw repeated controls in Blade)
Agents: claude/claude-opus-4-8

## Problem Essence

The `success`/`error` session-flash rendering was hand-written as a repeated `@if (session(...)) <x-ui.alert> @endif` block across ~30 Core views. The shape is identical everywhere, so it is raw repeated control markup that `DESIGN.md` and `resources/core/views/AGENTS.md` Principle 1 forbid.

## Desired Outcome

Every Core view that surfaces `success`/`error` session flash uses the shared `x-ui.session-flash` component. No view hand-writes the `@if (session('success'))` / `@if (session('error'))` alert block. A reader sees one canonical flash primitive, and flash styling/markup changes happen in one file.

## Design Decisions

- `x-ui.session-flash` (already created) renders the `success` then `error` flash keys as the matching `x-ui.alert` variant, and forwards attributes (e.g. `class="mb-4"`) to each alert. Truthy check on `session($key)` preserves prior behavior.
- The component intentionally does **not** handle the auth `session('status')` key (used by `auth/login`, `auth/forgot-password`, `auth/reset-password`, `auth/verify-email`, `auth/confirm-password`). Those carry a distinct message contract and stay as-is unless a follow-up extends the component with an explicit `status` slot. Decide that separately; do not force them into this rollout.
- Per-file the existing block may differ slightly (only-success, custom `class`, different indentation). Swap only when the block is semantically the standard success/error pair; preserve any wrapper class by passing it through (`<x-ui.session-flash class="..." />`).
- The flash work that motivated this rollout replaced the profile/password `<x-action-message on="...">` blocks, which left `Profile@updateProfileInformation` and `Password@updatePassword` dispatching `profile-updated` / `password-updated` with no consumer. Those dispatches were removed. This does **not** weaken the audit trail: audit is driven by the global Eloquent mutation listeners (`eloquent.updated: *` in `app/Base/Audit/ServiceProvider.php`), not the Livewire dispatch bus — `User` is not in `audit.exclude_models`, and `password` is in `audit.redact`, so both mutations are recorded independently.

## Phases

### Phase 0 — Component + flash-touched views (done)

Goal: shared `x-ui.session-flash` exists and every view changed in the flash feature renders through it.
Evidence: `resources/core/views/components/ui/session-flash.blade.php`; inventory row in `resources/core/views/AGENTS.md`; `PasswordUpdateTest` / `ProfileUpdateTest` green (the password test's `assertSee('Password updated successfully.')` renders through the component).

- [x] Create `x-ui.session-flash` (renders `success`/`error`, forwards attributes) — claude/claude-opus-4-8
- [x] Migrate the 13 flash-touched views (addresses/show, roles/index, database-queries/index+show, logs/index+show, workflows/show, profile/profile, profile/password, companies/show, employees/show, users/show, ai/providers ×3) — claude/claude-opus-4-8
- [x] Remove now-unconsumed `profile-updated` / `password-updated` dispatches (audit verified independent) — claude/claude-opus-4-8
- [x] Document component in `resources/core/views/AGENTS.md` Component Inventory — claude/claude-opus-4-8

### Phase 1 — Migrate remaining Core views

Goal: each listed view renders flash via `<x-ui.session-flash />` (with `class` passthrough where the original alert carried one); no raw `session('success')`/`session('error')` alert block remains.
Validation: `grep -rln "session('success')\|session('error')" resources/core/views --include=*.blade.php` returns nothing (the component reads `session($key)`, so it is not matched). Confirmed; `php artisan view:cache` compiled all views with no errors.
Notes on variants folded in during the sweep: `employee-types/index` and `domain-manager` had no blank line between the two `@if`s; `roles/show` and `domain-manager` rendered the error as `variant="danger"` (identical output to `error` in `x-ui.alert`, so no visual change); `cache/index`, `database-residue/index`, `integration-parameters/index`, `sessions/index`, `github-access/index` were success-only (the component degrades gracefully — it only renders keys that are set); `plugin-manager` used a bespoke `<div>` instead of `x-ui.alert` and now matches the standard alert.

- [x] `livewire/admin/addresses/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/companies/department-types.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/companies/departments.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/companies/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/companies/legal-entity-types.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/companies/relationships.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/employee-types/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/employees/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/geonames/admin1/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/geonames/countries/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/geonames/postcodes/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/roles/show.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/system/cache/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/system/database-residue/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/system/integration-parameters/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/system/sessions/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/system/update/github-access/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/admin/users/index.blade.php` — claude/claude-opus-4-8
- [x] `livewire/base/foundation/domain-manager.blade.php` — claude/claude-opus-4-8
- [x] `livewire/base/foundation/plugin-manager.blade.php` — claude/claude-opus-4-8
- [x] `livewire/settings/form.blade.php` — claude/claude-opus-4-8

### Phase 2 — `status` lane

The `session('status')` key splits into two cases that must be judged separately — the earlier "auth-only, no entropy" framing was wrong:

**Auth status (leave as-is).** `auth/login`, `auth/forgot-password`, `auth/reset-password`, `auth/confirm-password` flow through the dedicated `<x-auth-session-status>` component (centered, icon-less contract); `auth/verify-email` and `profile/profile` check the specific `verification-link-sent` value for a flow-specific inline message. Already componentized — nothing to fix.

**Admin status (entropy — open).** Three non-auth admin views render `session('status')` through the *same* raw `@if (session('status')) <x-ui.alert variant="success">` block the sweep set out to remove — these were missed because the sweep only grepped `success`/`error`. They are post-action success confirmations mis-keyed as `status`. Resolve by either (a) re-keying those controller flashes to `success` and rendering via `<x-ui.session-flash>`, or (b) routing them through the notifications lane in `docs/plans/ui-feedback-notifications.md` if they are same-page. Decide alongside the feedback-lane direction; do not rush a migration that the notifications work may supersede.

- [x] Confirm auth status already uses `x-auth-session-status`; leave untouched — claude/claude-opus-4-8
- [ ] `livewire/admin/system/database-incubation/index.blade.php` — raw `session('status')` success alert
- [ ] `livewire/admin/system/database-tables/index.blade.php` — raw `session('status')` success alert
- [ ] `livewire/admin/system/update/deployment/index.blade.php` — raw `session('status')` success alert
