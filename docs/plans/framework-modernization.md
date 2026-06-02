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
- [~] Enable `preventSilentlyDiscardingAttributes` — measured ~10 failing tests from 4 root causes; re-enable once the two below are resolved:
  - [x] `payroll_pay_item_code` missing from `$fillable` on `AttendanceAllowanceRule` and `ClaimType` (the column exists; it was being silently dropped on mass-assign — a real bug) — claude/opus-4.8
  - [ ] `AiRun.created_at` — tests backdate it via direct `create()`; switch those ~4 test sites to `forceCreate` (timestamps should not enter `$fillable`)
  - [ ] `Department.name` — `Companies\Departments.php` mass-assigns `name`, but the `departments` table has no `name` column (it lives on `DepartmentType`). Real caller bug; needs a domain decision (drop it, or set it on the type), not a rote fillable add
- [ ] Make test factories faithful to the schema (start with `User`), then enable `preventAccessingMissingAttributes`; fix the ~196 violations

Test-infra corrections made alongside (low-entropy cleanup surfaced by this work):

- [x] Cleared leftover dev route/config/view caches — a staleness footgun, and the route cache caused a 128 MB OOM during test bootstrap — claude/opus-4.8
- [x] Set `memory_limit=512M` in `phpunit.xml` (the default 128 MB OOMs the suite bootstrap) — claude/opus-4.8

Evidence: full suite after the change shows **0 `LazyLoadingViolationException`** and 2070 passing. The remaining failures were pre-existing/environmental and are being triaged separately:

- [x] **Database-backup cluster (~22)** — root cause: `ext-sodium` not enabled (the app-key encryption requires it, and the backup feature was therefore broken at runtime too). `setup.ps1` and the project `php.ini` enabled curl/openssl/etc. but not sodium; added it (the DLL already ships in the FrankenPHP ext dir). Verified: the Backup slice now passes 32/32 when PHP loads the project ini (the documented native-Windows config via `PHPRC`). — claude/opus-4.8
- [x] **AI `MemoryGetTool` (5)** — real Windows bug: the scope-containment check compared `realpath()` output against a hard-coded `'/'`, so on Windows (backslash paths) it rejected every path as traversal, breaking memory file-reading entirely. Use `DIRECTORY_SEPARATOR`. — claude/opus-4.8
- [ ] **OpenAI Codex / model-catalog (3)** — completing/disconnecting the Codex OAuth flow triggers a `ModelCatalogService` sync to `https://models.dev/api.json` that the tests don't fake → real call → `HTTP 0`. Fix needs either a valid faked catalog payload in those 3 tests (or a file-level `beforeEach`), or a review of why disconnect triggers a synchronous external sync. Not yet done.

### Phase 2 — Octane state-hygiene audit

Goal: no per-request state leaks across the worker.

- [ ] Review the 25 singleton/bind sites and the static-state class for data held across requests
- [ ] Confirm the Octane flush listeners cover custom singletons; add explicit resets where a leak is found

### Phase 3 — Livewire ergonomics

Goal: less redundant work, less bespoke form code.

- [ ] Adopt `#[Computed]` in components with repeated derived queries (start from the page-weight triage list)
- [ ] Introduce Form objects for components with non-trivial form state/validation
- [ ] Apply `#[Reactive]` where nested components currently re-fetch parent data

### Phase 4 — Caching modernization

Goal: remove the periodic cache-expiry slow request.

- [ ] Move the menu and other eligible derived cached reads to `Cache::flexible()` (stale-while-revalidate)

### Phase 5 — Deferred work & dependency currency

Goal: trim perceived latency and close the version gap.

- [ ] Use `defer()` for non-critical after-response work where it shortens response time
- [ ] Run `composer outdated`; update anything trailing to latest minor/patch per policy
- [ ] Decide on Pulse / Precognition / Pennant — adopt or explicitly defer each with a recorded reason
