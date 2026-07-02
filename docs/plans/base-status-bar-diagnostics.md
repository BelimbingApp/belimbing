# base-status-bar-diagnostics

**Status:** Phase 1 implemented; additional diagnostic sources planned
**Last Updated:** 2026-07-02
**Sources:** `resources/core/views/components/layouts/status-bar.blade.php`; `docs/architecture/ui-layout.md`; `app/Base/Menu/Services/VisibleNavMenuItemsFlat.php`; user discussion on surfacing menu route diagnostics
**Agents:** Codex/GPT-5

## Problem Essence

Some framework diagnostics are currently only visible in logs even though they explain observable UI behavior. The recent menu hardening prevents stale menu routes from crashing the app shell, but an operator who sees a missing sidebar item has no in-app clue unless they inspect `storage/logs/laravel.log`.

## Desired Outcome

The persisted status bar should surface concise, actionable diagnostic warnings for operators without interrupting normal work. Clicking the warning should reveal enough detail to understand what needs attention and where to fix it, while logs continue to carry full server-side context.

## Top-Level Components

- Status bar diagnostics provider: a small read model that gathers current warnings for the authenticated user and request context.
- Diagnostic message contract: stable fields for severity, source, summary, details, target URL, and optional metadata.
- Status bar indicator: a compact warning/count affordance in the existing status bar left side.
- Detail surface: a lightweight popover or drawer listing current diagnostics with links to existing admin pages such as Menu Inspector.
- Producers: menu link validation first, then module dependency health, update/reload warnings, queue/scheduler health, and other operator-facing checks.

## Design Decisions

### Logging only

Logs are still required for server-side context, stack traces, and automated incident review. They fail the UX test for operator-visible configuration drift: the user can see the symptom in the browser but not the reason.

Recommendation: keep logging as the durable diagnostic trail, but do not treat logs as the only user-facing feedback.

### Inline page banners

Page banners are appropriate when the current page cannot complete its own workflow. Menu route drift is shell-level configuration health, not a page-specific failure, and banners would be intrusive and duplicated across pages.

Recommendation: avoid page banners for global diagnostics unless a specific page owns the remediation workflow.

### Status bar diagnostics

The status bar already owns environment/debug/licensee/locale/Lara state, is persisted across navigation, and is visually designed for compact operational signals. It is the right place for low-intrusion but observable system health messages.

Recommendation: add a shared status bar diagnostic surface. Show only compact severity/count in the bar, then reveal details on click. This keeps normal users unblocked while giving operators a reliable place to look.

## Public Contract

Diagnostic entries should be plain data, not view fragments:

- `id`: stable unique key for de-duplication, such as `menu.missing-route.investment.holdings`.
- `severity`: `danger`, `warning`, or `info`.
- `source`: short source label such as `Menu`, `Modules`, or `Updates`.
- `summary`: one-line user-facing text.
- `detail`: optional longer explanation for the click surface.
- `target`: optional internal URL for remediation or inspection.
- `metadata`: optional structured context for logs/tests only; never secrets.

Visibility should be authorization-aware. Diagnostics that expose system internals should only appear for users who can access the relevant admin/diagnostic area. The base status bar can show a generic count only when the user has no detail permission.

Repeated diagnostics must be fingerprinted and throttled at the producer boundary before writing to `laravel.log`. Global Monolog deduplication is too blunt because repeated failures can be useful while debugging; known persistent diagnostics should choose their own fingerprint and time window.

Diagnostics are live state, not durable tickets. A warning is resolved when its provider no longer emits it. For example, a fixed menu route should disappear from the status bar on the next shell render or explicit refresh, and logging should simply stop. Acknowledgement/snooze, if added later, may reduce noise but must not mark an unhealthy check as fixed.

## Phases

### Phase 1: Menu Diagnostics In The Status Bar

Goal: A broken menu item no longer crashes the app and is visible as an operator warning.

Affected pages: Any authenticated app page with the status bar; `/admin/system/menu-inspector`.

- [x] Introduce a Base diagnostic message DTO/read model for status bar use. {Codex/GPT-5}
- [x] Convert menu link resolution failures into diagnostic entries while preserving existing log warnings. {Codex/GPT-5}
- [x] Fingerprint-throttle repeated menu link warning logs so a persistent broken item does not flood `laravel.log`. {Codex/GPT-5}
- [x] Add a compact status bar warning indicator with count and highest severity. {Codex/GPT-5}
- [x] Add click-to-view details using an accessible lightweight popover or existing drawer pattern. {Codex/GPT-5}
- [x] Make warning resolution live-computed: fixed diagnostics disappear automatically, with an explicit refresh/recheck affordance if cached shell state could lag. {Codex/GPT-5}
- [x] Link menu diagnostics to Menu Inspector when the user has permission. {Codex/GPT-5}
- [x] Extend tests around `VisibleNavMenuItemsFlat`, `StatusBarTest`, and Menu Inspector. {Codex/GPT-5}

Validation: A synthetic missing menu route is hidden from the sidebar, logged once with source context, and shown as a status bar warning for an authorized admin.

### Phase 2: Shared Diagnostic Providers

Goal: The status bar can aggregate multiple operator-facing health signals without coupling the shell to each subsystem.

- [x] Add provider registration for Base/Core diagnostic contributors. {Codex/GPT-5}
- [x] Add de-duplication and severity ordering rules. {Codex/GPT-5}
- [x] Add per-provider authorization checks. {Codex/GPT-5}
- [x] Document the provider contract in `docs/architecture/ui-layout.md`. {Codex/GPT-5}
- [ ] Add UI Reference coverage for status bar diagnostics.

Validation: Menu diagnostics and a synthetic tagged provider can be displayed together with stable ordering and correct visibility. UI Reference coverage remains open.

### Phase 3: Codebase Diagnostic Opportunity Audit

Goal: Identify existing warnings, health checks, and admin-only status signals that belong in the shared status bar diagnostic surface.

- [ ] Sweep existing `Log::warning`, `logger()->warning`, `session()->flash('warning')`, health snapshot, dependency health, and diagnostic services.
- [ ] Classify each candidate as status-bar diagnostic, page-local feedback, log-only, notification, or no-op/noise.
- [ ] Require each status-bar candidate to have an owner, severity, remediation path, authorization rule, and live-resolution check.
- [ ] Add accepted candidates to Phase 4 with source paths and proof expectations.
- [ ] Document rejected categories so future agents do not promote noisy or page-specific warnings.

Validation: The audit produces a short candidate table in this plan, not code changes, and every accepted candidate has a clear user action or remediation link.

### Phase 4: Operational Health Sources

Goal: Promote existing health checks into the shared surface only where they are actionable from the shell.

- [ ] Evaluate module dependency health as a status bar contributor.
- [ ] Evaluate deployment/update warning state as a status bar contributor.
- [ ] Evaluate queue/scheduler/browser/AI health contributors and reject any that create noisy permanent warnings.
- [ ] Add suppression or acknowledgement only if repeated diagnostics become noisy in real use.

Validation: Each added source has a clear remediation link and does not duplicate a page-specific banner.
