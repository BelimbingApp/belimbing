# docs/plans/ui-feedback-notifications.md

Status: Complete — notification lane built (severity-tiered, two-thirds width), same-page feedback rolled out across Core admin + Commerce, variant styling unified behind StatusVariant, UI Reference demo fires the real event. Minor follow-ups noted.
Last Updated: 2026-06-21
Sources: `docs/plans/ui-session-flash-rollout.md`, `resources/core/views/components/ui/{notification-hub,flash,flash-stack}.blade.php`, `app/Base/Foundation/Livewire/Concerns/InteractsWithNotifications.php`, `DESIGN.md`
Agents: claude/claude-opus-4-8

## Problem Essence

Most BLB feedback is a same-page confirmation: the user toggles/saves/edits a control mid-page and stays there. ~356 `success`/`error` flashes are set across the app; only ~19 files pair a flash with a `redirect()`. Rendering those as inline `x-ui.alert`/`x-ui.session-flash` prepends a banner to the page top (often scrolled out of view) and shifts layout right where the user is looking. The `x-ui.flash` / `x-ui.flash-stack` notification components were built and demoed in the UI Reference but never wired to real feedback.

## Desired Outcome

Three clear feedback lanes, each on its right component:

- **Same-page feedback** (stay on page, result obvious) → top-right notification via `InteractsWithNotifications` + the global `x-ui.notification-hub`.
- **Persistent context** (validation summaries, standing warnings like "Lara inactive", licensee notice) → inline `x-ui.alert`.
- **Post-redirect landing banner** (delete-then-return, save-then-navigate) → `success`/`error` session flash via `x-ui.session-flash` (the lane standardized in the sibling plan).

A reader can tell which lane an action uses from the call (`$this->notify(...)` vs `session()->flash(...)`), and the notification styling lives in one place.

## Top-Level Components

- `x-ui.notification-hub` — global outlet, mounted once in `layouts/app.blade.php` (persisted across `wire:navigate`). Listens for the `notify` window event and renders stacked notifications via `x-ui.flash-stack`. Owns the severity → persistence policy.
- `InteractsWithNotifications` trait — `notify($message, $variant='success', $duration=null)` (+ `notifySuccess`/`notifyError`/`notifyWarning`), dispatching `notify`. Components opt in by using the trait.

## Design Decisions

- **Severity-tiered persistence (decided 2026-06-21).** `error`/`warning` stay until the user dismisses them — they must not be missed; `success`/`info` auto-dismiss after ~4.7s. A close button is always present.
- **High-signal width.** The notification stack renders at `width="wide"` (two-thirds of the viewport, via `x-ui.flash-stack`) so action feedback is noticeable regardless of where on the page it was triggered. The narrow `sm` default remains for other `flash-stack` uses (e.g. the UI Reference demo). The policy lives in one place: the hub's `sticky` list. Rationale: a missed *error* is harmful, but making every "Saved." a manual dismiss creates pile-up — the exact friction operational users dislike, and the action's visible result already confirms success. An explicit `duration` (ms) overrides the default for any variant.
- **Honest naming.** Because messages can persist, the lane is "notifications," not "toasts"/"flash" (which mean transient by definition). Hence `notification-hub` / `InteractsWithNotifications` / `notify()` / event `notify`. The lower-level `x-ui.flash` (presentational item) and `x-ui.flash-stack` (positioning) keep their names — they remain accurate primitives; `flash-stack` is lane-agnostic positioning.
- **Dispatch bus, not session.** Notifications ride `$this->dispatch('notify', ...)` so they deliver immediately on the current Livewire render without a page reload, and do **not** survive a redirect — which matches the same-page contract. Redirect cases deliberately stay on session flash.
- **Above modals.** The notification layer is `z-[60]`, above modals and the Lara overlays (z-50), so a notification fired from an open modal (e.g. execution-controls reset, `saveModel` errors) is never buried.
- **Accessibility by severity.** Sticky `error`/`warning` render `role="alert"` `aria-live="assertive"`; auto-dismiss `success`/`info` render `role="status"` `aria-live="polite"`.
- **Known duplication to retire.** The hub carries a variant→`{bg,border,text,icon-path}` map that mirrors `x-ui.flash` and `x-ui.alert` token-for-token. Unify behind one `StatusVariant` source (see Phase 3). Until then a variant style change must touch all three.

## Public Contract

- `InteractsWithNotifications::notify(string $message, string $variant = 'success', ?int $duration = null)` — `$variant` ∈ `success|error|warning|info`; unknown variant falls back to `success`. `$duration` in ms forces a timer; null → severity default (sticky for error/warning, ~4700ms for success/info). Helpers: `notifySuccess`, `notifyError`, `notifyWarning`.
- Browser event: `notify` with `detail = { message, variant, duration }`, caught only by `x-ui.notification-hub`.

## Phases

### Phase 1 — Outlet + trigger + prototype (done)

Goal: a real page emits notifications through the global outlet, with severity-tiered persistence.
Affected pages: `/admin/ai/providers` (model rows — toggle active, set default, cost override, save model, reset execution controls).
Evidence: `notification-hub.blade.php`, `InteractsWithNotifications.php`, `layouts/app.blade.php` (persisted mount), `ManagesModels.php` (7 sites → `$this->notify(...)`/`notifyError(...)`), `tests/Feature/AI/ModelNotificationFeedbackTest.php` (asserts `notify` dispatched, no session flash).

- [x] Build `x-ui.notification-hub` (listens `notify`, stacks via `x-ui.flash-stack`, severity-tiered persistence + close, severity-based aria) — claude/claude-opus-4-8
- [x] Raise the notification layer to `z-[60]` so notifications from an open modal are not buried under the z-50 modal/overlays — claude/claude-opus-4-8
- [x] Add `InteractsWithNotifications` trait and mount the hub once in `layouts/app.blade.php` — claude/claude-opus-4-8
- [x] Convert AI model actions (`ManagesModels`) to `$this->notify(...)`; opt the `Providers` component into the trait — claude/claude-opus-4-8
- [x] Document `x-ui.notification-hub` in `resources/core/views/AGENTS.md` — claude/claude-opus-4-8

### Phase 2 — Roll out to same-page feedback (done)

Goal: same-page mutation feedback uses notifications; only persistent context and post-redirect banners stay inline. Converted `session()->flash(...)`/`Session::flash(...)` to `$this->notify(...)`/`notifyError(...)`/`notifyWarning(...)` wherever the action does not redirect.
Validation: Pint clean (also removed now-unused `Session` imports); `php artisan view:cache` compiled; targeted Pest suites green — AI (58), Authz/User (50), Company/Employee/Geonames/Company-timezone (51).
Redirect exceptions deliberately kept on session flash (must survive navigation): `Roles\Show::deleteRole`, `Logs\Show::deleteFile`, `EmployeeTypes\Create`, `EmployeeTypes\Edit`.
Trait wiring: components already using `SavesValidatedFields` get `notify()` transitively (the trait now `use`s `InteractsWithNotifications`); `Geonames\Admin1\Index`, `Geonames\Countries\Index`, `EmployeeTypes\Index`, and `Logs\Show` opt in directly. Latent gap fixed: `ProviderSetup` hosts `ManagesModels` (converted in Phase 1) but lacked the trait — added.

- [x] Commerce Inventory `Items\Show` + `items/show.blade.php` — all same-page actions → `$this->notify(...)`; bespoke `x-ui.flash-stack` surface replaced with `<x-ui.session-flash>` (post-redirect only). Policy reconciled: adopted the global rule (`warning` sticky), superseding Commerce's 7s auto-dismiss — claude/claude-opus-4-8
- [x] AI providers (`ManagesProviders`): save/update/delete provider, priority move — claude/claude-opus-4-8
- [x] Inline edit-in-place saves via `SavesValidatedFields` ("Saved.") — trait now notifies; covers all 9 hosts (also Addresses\Show, Workflows\Show) — claude/claude-opus-4-8
- [x] Geonames row renames (`Admin1\Index`, `Countries\Index`) — claude/claude-opus-4-8
- [x] Company show: status, parent, activities, metadata, address pivots/kinds, attach address — claude/claude-opus-4-8
- [x] Employee show: status, type, department, supervisor, user link, subordinates, address pivots/kinds — claude/claude-opus-4-8
- [x] User show: company, roles, capabilities, employee link/unlink — claude/claude-opus-4-8
- [x] Role show: scope, capability assign/remove, user assign/remove (deleteRole stays inline — redirects) — claude/claude-opus-4-8
- [x] Address show: country, location, verification status, timezone accept — claude/claude-opus-4-8
- [x] Type toggles/CRUD: department & legal-entity types (inline, same-page); employee-types `Index::delete` (Create/Edit redirect → inline) — claude/claude-opus-4-8
- [x] Logs: delete lines from top → notify; delete-file stays inline (redirects) — claude/claude-opus-4-8
- [ ] Admin `session('status')` success alerts (database-incubation, database-tables, deployment) — still open; same-page? route here; otherwise re-key to `success` + `x-ui.session-flash` (tracked in `ui-session-flash-rollout.md` Phase 2)

Follow-up (minor, deferred): show/detail pages whose every flash became a notification now carry a vestigial `<x-ui.session-flash>` that only fires if a redirect lands there. Harmless; prune where a page is provably not a redirect destination.

### Phase 3 — Unify the variant style map (done)

Goal: one source of truth for variant → `{bg,border,text,glyph}`, consumed by `x-ui.alert`, `x-ui.flash`, and `x-ui.notification-hub`; remove the duplicated `match()`/JS maps.
Evidence: `app/Base/Foundation/Enums/StatusVariant.php`; `alert`/`flash` consume `classes()` + `icon()`, the hub consumes `StatusVariant::jsMap()` via `@js`. Pint clean, all Blade compiles, 15 alert-rendering tests green (identical class output).

- [x] Introduce `StatusVariant` enum exposing `classes()` + `icon()` (heroicon name) + `iconPath()` (for JS) + `jsMap()`, with the `danger`==`error` alias in `fromLabel()` — claude/claude-opus-4-8
- [x] Refactor `alert`, `flash`, `notification-hub` to consume it (hub via `@js(StatusVariant::jsMap())`) — claude/claude-opus-4-8

### Phase 4 — Make the UI Reference demo real (done)

Goal: the Feedback section demos the production trigger, not bespoke Alpine.

- [x] Replaced the `timer()`/`stack()` demo in `ui-reference/partials/feedback.blade.php` with buttons that `$dispatch('notify', …)` through the global hub — sticky error + warning and an auto-dismiss success, plus a stack-three — claude/claude-opus-4-8
- [x] Rewrote the copy: dropped "proposed standard"; describes the adopted severity-tiered pattern and notification-vs-inline-alert guidance — claude/claude-opus-4-8

### Phase 5 — App-wide rollout (done, with one deferral)

Extended the same-page→`notify` conversion beyond the original Core-admin scope to the rest of the app, classifying each flash site by whether its method redirects (broad audit including `redirectRoute`/`redirectIntended`/`->redirect`). Committed per repo (Core/Base + nested Commerce, Operation, People).

- [x] Core/Base remainder: cache, database residue/queries/schema-incubation, settings form, integration parameters, GitHub access, plugin manager, AI advanced-settings + Lara, company departments/relationships/index, address index, employee/user index, geonames postcodes — claude/claude-opus-4-8
- [x] Commerce: Catalog (categories/templates/attributes), Inventory item concerns, Marketplace eBay (index + settings) — claude/claude-opus-4-8
- [x] Operation: IT ticket, Quality NCR + SCAR show pages — claude/claude-opus-4-8
- [x] People (non-Attendance): Claim, Leave, Payroll, Employees, Settings — claude/claude-opus-4-8
- [ ] **People Attendance — deferred.** Policy groups, allowance rules, rosters, shift templates are heavily covered by `AttendancePolicyOperationsTest`, which asserts flash text via `assertSee`. Converting requires migrating those assertions to `assertDispatched('notify', ...)` first. Reverted to keep the suite green; do this as a focused pass.

Post-redirect flashes left on the session lane throughout (all `*Create`/`*Edit`, domain install/disable/enable/uninstall, query create/delete, db-table reconcile redirect, eBay OAuth). Auth `status`, OAuth callback, and custom keys (`command-log`, `locale-status`) untouched. Two latent redirect-misclassifications were caught and reverted during the pass (`DomainManager`, `DatabaseTables\Show` — both use `redirectRoute`, which the first-pass `redirect(` grep missed).

Note: People has 4 pre-existing `*DoesNotImportPayroll`/`PayrollIntakeBoundary` test failures unrelated to this work (confirmed failing on a clean tree).

### Follow-ups (open)

- `x-ui.flash` is now orphaned — the demo no longer uses it and the hub renders its own markup (a Blade component can't be used per dynamic `x-for` item). It still compiles and consumes `StatusVariant`. Left in place rather than deleted (pre-existing public primitive); decide whether to remove it and its inventory row.
- The 3 admin `session('status')` success alerts (database-incubation, database-tables, deployment) — still on the raw inline block (tracked here and in `ui-session-flash-rollout.md`).
- Vestigial `<x-ui.session-flash>` on show pages whose every flash became a notification — harmless; prune where a page is provably not a redirect destination.
