# framework-modernization

**Status:** In Progress (Phase 1)
**Last Updated:** 2026-06-02
**Sources:** `composer.lock` (Laravel 13.12.0, Livewire 4.3.0, Octane 2.17.4, PHP 8.5); Livewire 4 feature set under `vendor/livewire/livewire/src/Features/`; `config/octane.php`; `AGENTS.md` (§2 Development Philosophy, dependency policy "latest minor/patch within each major"); usage audit performed in session; related plan `performance-page-rendering.md`
**Agents:** claude/opus-4.8

## Problem Essence

BLB runs Laravel 13.12, Livewire 4.3, and PHP 8.5, but leaves several first-class, bug-preventing framework features unused. Left unaddressed this becomes **debt by omission** — idioms that should have been adopted from the start, where the cost of retrofitting grows as more code is written against the older pattern. A session audit found, concretely: strict model mode is off; Livewire's cached computed properties, form objects, and reactive props are unused; the islands/persistence features are unused (covered separately by the page-rendering plan); cached reads use plain `remember`/`forever` rather than stale-while-revalidate; and 25 singleton bindings plus a static-state class have not been audited for Octane request-safety. Dependency currency should also be re-verified against the project's own "latest minor/patch" policy.

## Desired Outcome

A deliberate, prioritized adoption of the high-value framework features — **correctness guardrails first** — with optional or speculative additions explicitly decided rather than drifted past. The codebase reflects current Laravel/Livewire idioms, surfaces a class of bugs at development time instead of shipping them silently, and stays current with its dependency policy. Scope is "adopt what earns its place," not "rewrite everything" — the philosophy's caution against forced abstractions and speculative carry still applies.

## Top-Level Components

1. **Correctness guardrails** — strict model mode (`Model::shouldBeStrict()` / `preventLazyLoading`) in a non-production service provider. Unused today (0). Converts silent N+1 lazy loads, access to missing attributes, and silently-discarded mass-assignment into loud development-time errors. Highest value, lowest cost — the direct antidote to "ignorance debt."
2. **Livewire ergonomics** — `#[Computed]` cached properties (0 uses) to memoize expensive derived data within a request; Form objects (0) to extract form state and validation from large components; `#[Reactive]` for parent→child data flow in nested components.
3. **Rendering architecture** — Islands / `@persist` / `wire:current` / lazy loading. Owned by `performance-page-rendering.md`; referenced here so the modernization picture is complete, not duplicated.
4. **Caching modernization** — `Cache::flexible()` (stale-while-revalidate, 0 uses) for the menu and other derived reads currently on `remember`/`forever`; serves cached data instantly while refreshing in the background, removing the periodic slow request when a cache entry expires.
5. **Octane state hygiene** — under the FrankenPHP worker, singletons or static state that hold per-request data leak across requests (and across users). 25 files bind singletons and one class holds static state; each must be audited for request-scoped data, and the Octane flush listeners confirmed to cover custom singletons. This is a correctness audit, not a feature.
6. **Deferred work** — `defer()` (0 uses) to push non-critical work (audit writes, side-effects, notifications) after the HTTP response, shortening perceived latency.
7. **Dependency currency & optional tooling** — confirm dependencies against the "latest minor/patch" policy (`composer outdated`) and refresh any that trail. Separately, evaluate — not mandate — Pulse (performance monitoring, directly useful given the ongoing perf work), Precognition (live form validation UX), and Pennant (feature flags) against real need.

## Design Decisions

**Lead with guardrails.** Enabling strict model mode outside production is the single highest-leverage change here: it makes a whole class of performance and correctness mistakes fail loudly during development rather than degrade silently in production. It is cheap to turn on and pays for itself the first time it catches an N+1. Adopt it first, then fix what it surfaces.

**Adopt ergonomics where they pay, not as a sweep.** `#[Computed]` and Form objects are reach-for-when-it-helps tools — apply them to components with repeated derived queries or heavy form state (the page-weight triage list is a natural starting set), not as a blanket rewrite. Churning working components for idiom alone violates the project's caution against forced abstractions.

**Octane hygiene is non-negotiable but bounded.** The cost of getting cross-request state wrong (data bleeding between users) is severe; the audit itself is small and finite. Do the audit; add resets only where a real leak exists.

**Caching gets the stale-while-revalidate treatment where consistency allows.** The menu and similar derived reads are read constantly and change rarely — exactly the `Cache::flexible()` sweet spot. Keep `remember` where immediate consistency is required.

**Cross-link, don't duplicate.** The islands/persistence work has its own plan; this document references it and avoids restating it.

**Optional packages are a decision, not a default.** Pulse is the most justified addition given the active performance effort and would replace ad-hoc trace analysis with continuous data. The rest (Precognition, Pennant, Volt, Concurrency) are evaluated against concrete need and explicitly deferred if not yet warranted, with the reason recorded.

## Public Contract

Conventions once adopted:

- Strict model mode is enabled outside production; new code must not trigger lazy-loading or missing-attribute violations.
- New Livewire components use `#[Computed]` for repeated derived data and Form objects for non-trivial form state and validation.
- Cached derived reads use `Cache::flexible()` unless immediate consistency is required.
- Any new singleton or static state is reviewed for Octane request-safety before merge.
- Dependencies are kept on the latest minor/patch within each major, per `AGENTS.md`.

## Phases

### Phase 1 — Correctness guardrails (in progress)

Goal: make silent model bugs loud in development.

Adopted in **stages**: enabling full `shouldBeStrict()` at once surfaced 133 failing tests, so each protection is turned on and its violations cleared before the next. First-run breakdown: ~12 lazy-loading (N+1), ~40 mass-assignment, ~196 missing-attribute — the last concentrated in `User.employee_id`/`company_id` read by the datetime/timezone path on factory-hydrated users (a test-factory faithfulness gap, not a production path, since the session guard loads the full user).

- [x] Enable `preventLazyLoading` outside production in `AppServiceProvider::configureModels()` — claude/opus-4.8
- [x] Fix the 3 N+1s it surfaced: `AiRun.calls` (`RunInspectionService`), `ClaimEntitlementUsageEntry.employee` (`ClaimUtilizationReportBuilder`), `AttributeValue.attribute` (`ManagesItemFitments`) — claude/opus-4.8
- [x] `AiRun.created_at` mass-assignment — tests backdated it via direct `create()`; switched those sites to `forceCreate` (the `ChatStopStaleTurn` helper, `ChatConcurrentSessionLifecycle` ×6, `ChatConcurrentRunPolicy`). Timestamps stay out of `$fillable`. — claude/opus-4.8
- [x] `Department.name` mass-assignment in `AttendancePolicyOperationsTest` — removed; `departments` has no `name` column, so the value was always silently dropped (dead, misleading code). — claude/opus-4.8
- [x] `User` factory faithfulness — added nullable `employee_id`/`company_id` defaults so factory-hydrated users carry the attributes the datetime/timezone path reads; clears the ~196 `User` missing-attribute violations. — claude/opus-4.8

- [x] `Department.name` accessor — `Department` has no `name` column (the name lives on its `DepartmentType`, relation `type()`); added a `name` accessor delegating to `type?->name`. All read-sites already eager-load `type`, so no N+1. — claude/opus-4.8
- [x] Enable `preventAccessingMissingAttributes` outside production — suite green (2105 passed). — claude/opus-4.8

**DEFERRED — `preventSilentlyDiscardingAttributes` is blocked on a real cross-module bug it surfaced.** `payroll_pay_item_code` is still mass-assigned to `AttendanceAllowanceRule` and `ClaimType` (e.g. `ClaimAccountingExportBuilder`, `AttendancePolicyOperationsTest`), but the **Payroll module intentionally dropped that column** from both tables (migrations `0320_03_01_000008` / `_000012`, per Payroll Plan 12 Phase 2 / Plan 17) after moving the mapping into dedicated tables (`people_payroll_attendance_rule_pay_items`, `people_payroll_claim_type_pay_items`). Those assignments are therefore **stale and silently lost** — a genuine data-loss bug, not a missing column (re-adding it to `$fillable` only turns the silent drop into a SQL insert error, confirming the column is gone). Resolution (a focused Payroll-domain task) is split out into its own plan: **`payroll-pay-item-code-reconciliation.md`**. Once that lands, enable `preventSilentlyDiscardingAttributes` here.

Test-infra corrections made alongside (low-entropy cleanup surfaced by this work):

- [x] Cleared leftover dev route/config/view caches — a staleness footgun, and the route cache caused a 128 MB OOM during test bootstrap — claude/opus-4.8
- [x] Set `memory_limit=512M` in `phpunit.xml` (the default 128 MB OOMs the suite bootstrap) — claude/opus-4.8

Evidence: full suite after the change shows **0 `LazyLoadingViolationException`** and 2070 passing. The remaining failures were pre-existing/environmental and are being triaged separately:

- [x] **Database-backup cluster (~22)** — root cause: `ext-sodium` not enabled (the app-key encryption requires it, and the backup feature was therefore broken at runtime too). `setup.ps1` and the project `php.ini` enabled curl/openssl/etc. but not sodium; added it (the DLL already ships in the FrankenPHP ext dir). Verified: the Backup slice now passes 32/32 when PHP loads the project ini (the documented native-Windows config via `PHPRC`). — claude/opus-4.8
- [x] **AI `MemoryGetTool` (5)** — real Windows bug: the scope-containment check compared `realpath()` output against a hard-coded `'/'`, so on Windows (backslash paths) it rejected every path as traversal, breaking memory file-reading entirely. Use `DIRECTORY_SEPARATOR`. — claude/opus-4.8
- [x] **OpenAI Codex / model-catalog (3)** — completing/disconnecting the Codex OAuth flow triggered a `ModelCatalogService` sync to `https://models.dev/api.json` that the tests didn't fake → real call → `HTTP 0`. Fixed with a file-level `beforeEach` faking `models.dev` with a valid catalog payload (Laravel's `Http::fake` accumulates, so it coexists with the per-test endpoint fakes). — claude/opus-4.8

**Result: full suite is green — 2105 passed, 0 failed, 2 (pre-existing) skips** — with `preventLazyLoading` enabled and the project ini (`PHPRC`, for sodium). The deeper strict protections remain deferred to the two domain decisions above.

### Phase 2 — Octane state-hygiene audit

Goal: no per-request state leaks across the worker.

- [x] Audited ~140 singleton bindings + all mutable `static` properties across `app/`. Surface is mostly clean: stateless services, or correctly `scoped` / in the `flush` list / explicitly `clear()`ed (e.g. `AgentExecutionContext` is cleared in `RunAgentTaskJob`'s `finally`). Static-state surface clean (3 props, all request-independent). — claude/opus-4.8
- [x] **P2 fixed — audit cross-user leak.** `RequestContext` (`Base\Audit`) is a `singleton` built once via `fromRequest()` (actor/IP/URL/company) and injected into `AuditRequestMiddleware`, but was **not** in `config/octane.php`'s `flush` list (its sibling `AuditBuffer` was) — so under the worker every later request's audit records would be stamped with the first request's actor/company. Added `RequestContext::class` to the flush list. — claude/opus-4.8
- [ ] **P1 (deferred) — locale staleness leak.** `ApplicationLocaleContext` is a `singleton` that memoizes the resolved locale write-once, so the worker freezes the locale for its ~500-request life (an admin changing `ui.locale` busts the settings cache but not the in-memory memo; an early resolve can pin the config default). Just scoping `LocaleContext` is insufficient — `NumberDisplayService` / `CurrencyDisplayService` (Locale provider) and `DateTimeDisplayService` (DateTime provider) inject it as constructor deps in their own `singleton`s and would pin the stale instance. Fix: bind `LocaleContext` + those three display services `scoped` (or reset the memo via an `OperationTerminated` listener), then run `vendor/bin/pest tests/Unit/Base/Locale` + the DateTime display tests. Cross-cutting across two providers — left for a focused pass.

### Phase 3 — Livewire ergonomics

Goal: less redundant work, less bespoke form code.

- [ ] Adopt `#[Computed]` in components with repeated derived queries (start from the page-weight triage list)
- [ ] Introduce Form objects for components with non-trivial form state/validation
- [ ] Apply `#[Reactive]` where nested components currently re-fetch parent data

### Phase 4 — Caching modernization

Goal: remove the periodic cache-expiry slow request.

- [x] Menu tree (`MenuBuilder::buildAndCache`) moved from `Cache::remember` (hourly, blocking rebuild on expiry) to `Cache::flexible([3600, 21600])` — serves stale + refreshes after the response. `MenuRegistry` keeps `Cache::forever` (explicitly invalidated, no expiry to smooth). — claude/opus-4.8
- [ ] Sweep for other eligible derived `Cache::remember` reads (none material found beyond the menu; the only other is a trivial `app_version` warm)

### Phase 5 — Deferred work & dependency currency

Goal: trim perceived latency and close the version gap.

- [ ] Use `defer()` for non-critical after-response work where it shortens response time
- [ ] `composer outdated --direct` (run 2026-06-02): only **`livewire/livewire` 4.3.0 → 4.3.1** is in-policy (a patch within major 4) — apply it when the tree is clean (it republishes assets, so not mid-multi-agent-session). `phpunit` 12 → 13 is a major bump (out of the "within each major" policy; leave on 12.x). Nothing else direct is trailing.
- [x] Decided — **defer all three** (none yet warranted, per the Strategic-Programming "don't carry speculative features" principle): **Pulse** (perf monitoring) is the most justified given the perf focus, but adds a DB-backed ingest + dashboard to carry — adopt if/when continuous perf monitoring becomes a priority (the `PULSE_ENABLED=false` env is already in `phpunit.xml`, so wiring is anticipated). **Precognition** — adopt when a form needs live server-side validation UX. **Pennant** — adopt when feature flags are actually needed. — claude/opus-4.8
