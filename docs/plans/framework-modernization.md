# framework-modernization

**Status:** Complete (catalog-led foundational pass delivered; measured page-specific follow-ups stay in owning plans)
**Last Updated:** 2026-06-04
**Sources:** `composer.lock` (Laravel 13.13.0, Livewire 4.3.1, Octane 2.17.4, PHP 8.5); Laravel 13 docs for cache, concurrency, helpers, queues, relationships, and Octane; Livewire 4 docs for computed properties, lazy loading, islands, navigate, forms, async, and JSON actions; PHP 8.5 release notes and OPcache docs; FrankenPHP configuration docs; local probes of `config/octane.php`, `config/cache.php`, `config/queue.php`, `Caddyfile`, `.php.d/perf.ini`, `scripts/start-app.sh`, `scripts/start-app.ps1`, `composer outdated --direct`, `php -i`, and usage greps; related plans `performance-page-rendering.md` and `payroll-pay-item-code-reconciliation.md`; Oracle advisory in the 2026-06-04 session
**Agents:** claude/opus-4.8; amp/unknown-model; GPT-5.5/gpt-5.5; oracle/gpt-5.5-advisory

## Problem Essence

BLB runs modern platform versions — Laravel 13.13, Livewire 4.3.1, PHP 8.5, and the FrankenPHP/Octane worker model — but modernization had been driven by remembered features rather than a system-wide capability map. Left unaddressed this becomes **debt by omission**: performance, worker-safety, and correctness features are available now, but future code can keep accumulating around older idioms because nobody explicitly cataloged what should be audited.

## Desired Outcome

A curated modernization catalog becomes the front door for foundational framework work: each Laravel, Livewire, PHP, or Octane capability worth considering is tied to a BLB relevance statement, an audit signal, an adoption rule, and a priority. Audits then proceed by lane — performance, worker safety, rendering, request lifecycle, runtime configuration, and dependency currency — so BLB adopts current framework idioms deliberately, avoids speculative churn, and keeps completed findings traceable in this plan or the owning companion plan.

## Top-Level Components

1. **Modernization catalog and audit map** — the curated inventory of framework/runtime capabilities worth checking against BLB. It is a decision surface, not a release-note archive: include a feature only when it implies a concrete audit question or adoption convention.
2. **Correctness guardrails** — strict model mode and related Laravel protections that convert silent N+1s, missing attributes, and discarded assignments into development-time failures. This work started first because it prevents bugs and exposes performance mistakes early.
3. **Octane / FrankenPHP worker safety** — singleton bindings, mutable static state, scoped services, and flush behavior that determine whether request, actor, company, locale, timezone, or memory state can leak across worker requests.
4. **Data/query/cache performance** — stale-while-revalidate cache, repeated derived reads, eager-loading discipline, pagination/chunking/cursor patterns, report builders, and other backend hot-path opportunities surfaced by the catalog.
5. **Livewire rendering and payload performance** — `#[Computed]`, Form objects, `#[Reactive]`, lazy components, islands, persistence, and payload/prop-churn controls. The page-rendering plan owns page-weight evidence; this plan owns the modernization conventions and audit map.
6. **Request lifecycle and after-response work** — `defer()`, queues, after-commit behavior, non-critical side effects, outbound calls, and other work that can move out of the user-visible response path without changing business semantics.
7. **Runtime/configuration and dependency currency** — PHP runtime assumptions, OPcache/autoload/config-cache policy, Octane worker lifecycle tuning, extension requirements, latest minor/patch compliance, and optional packages evaluated only against concrete need.

## Design Decisions

**Catalog first, but keep it bounded.** The catalog should prevent forgotten modernization, not turn into release-note archaeology. A feature belongs only when it gives BLB a useful audit signal, convention, or adoption decision. Cosmetic syntax changes and optional packages stay out unless they remove real complexity or answer a current operational need.

**Prioritize performance by lane, not by memory.** Performance work should be grouped by the system surface it affects: data/query/cache, Livewire rendering/payload, request lifecycle, worker memory/state, and runtime configuration. This keeps audits comparable and prevents whichever feature was noticed first from defining the backlog.

**Lead with guardrails where they prevent future waste.** Enabling strict model protections outside production remains one of the highest-leverage changes: it makes a whole class of performance and correctness mistakes fail loudly during development rather than degrade silently in production. Adopt these protections in stages and fix what each stage surfaces.

**Adopt ergonomics where they pay, not as a sweep.** `#[Computed]`, Form objects, `#[Reactive]`, lazy Livewire boundaries, and persistence are reach-for-when-it-helps tools — apply them to components with measured weight, repeated derived queries, or heavy form state, not as a blanket rewrite. Churning working components for idiom alone violates the project's caution against forced abstractions.

**Octane hygiene is non-negotiable but bounded.** The cost of getting cross-request state wrong (data bleeding between users) is severe; the audit itself is small and finite. Do the audit; add resets only where a real leak exists.

**Caching and request deferral are consistency decisions.** Stale-while-revalidate belongs on derived reads that are read constantly and change rarely; `defer()` belongs on non-critical work that can safely happen after the response. Keep blocking cache rebuilds, synchronous work, or queues where business consistency requires them.

**Cross-link, don't duplicate.** The islands/persistence work has its own plan; this document references it and avoids restating it.

**Optional packages are a decision, not a default.** Pulse, Precognition, Pennant, Volt, Concurrency, or any future package must be evaluated against catalog evidence and a concrete owner. Modernization is allowed to say "defer" when carrying the package would add more surface area than the current problem justifies.

## Public Contract

Conventions for modernization work:

- New modernization work starts from a catalog entry or adds one: feature, source/version family, category, BLB relevance, audit signal, adoption rule, risk, priority, and owning plan.
- High-priority entries graduate into phase checklists only after an audit signal exists; "defer" is an acceptable outcome when the feature is speculative or the carrying cost is higher than the current value.
- Full Eloquent strict mode is enabled outside production; new code must not trigger lazy-loading, missing-attribute, or silently-discarded-attribute violations.
- New and touched Livewire components use `#[Computed]` for repeated derived data, Form objects for non-trivial form state, and `#[Reactive]` for parent→child data flow only when the data contract requires it.
- Cached derived reads use `Cache::flexible()` unless immediate consistency is required.
- Any new singleton or static state is reviewed for Octane request-safety before merge.
- Dependencies are kept on the latest minor/patch within each major, per `AGENTS.md`.

## Modernization Catalog

The catalog is the decision surface for current Laravel, Livewire, PHP, and Octane/FrankenPHP capabilities that may materially improve BLB. It is broad enough to prevent forgotten foundational features, but narrow enough that every entry creates a concrete audit question.

Each catalog entry should record:

- **Feature / capability** — the framework or runtime feature being considered.
- **Source / version family** — Laravel, Livewire, PHP, Octane, or FrankenPHP version context.
- **Category** — performance, correctness, worker safety, rendering, request lifecycle, runtime/configuration, dependency currency, or operational tooling.
- **BLB relevance** — why this capability might matter in this codebase.
- **Audit signal** — what to search, inspect, measure, or trace before adopting.
- **Adoption rule** — adopt by default, adopt for heavy paths, adopt when touching nearby code, or defer.
- **Risk / caveat** — consistency, worker lifetime, semantics, test cost, or maintenance surface.
- **Priority** — Now, targeted, opportunistic, or defer.
- **Owning plan** — this plan or a companion plan such as `performance-page-rendering.md`.

Initial performance-heavy lanes:

| Audit lane | Catalog focus | Example audit signal |
| --- | --- | --- |
| Data/query/cache performance | strict loading feedback, eager-loading discipline, stale-while-revalidate cache, repeated derived reads, pagination/chunking/cursor patterns, heavy report builders | lazy-loading violations, repeated queries, `Cache::remember` on hot derived reads, unbounded collections |
| Livewire rendering and payload performance | `#[Computed]`, Form objects, `#[Reactive]`, lazy components, islands, persistence, payload and prop churn controls | page-weight triage, duplicated derived state, large component public state, parent components rerendering children unnecessarily |
| Request lifecycle latency | `defer()`, queues, after-commit behavior, synchronous side effects, outbound calls | audit writes, notifications, exports, AI/model calls, or cache rebuilds that do not need to block the response |
| Octane / FrankenPHP worker safety | singleton scope, scoped services, flush list, mutable statics, worker memory lifecycle | request/user/company/locale/timezone/actor/IP/URL state inside long-lived services |
| Runtime/configuration performance | PHP extensions, OPcache/autoload/config-cache policy, worker limits, preload/JIT only if evidence warrants | slow bootstrap, memory growth, missing extension incidents, production config mismatch |
| Dependency and optional tooling | latest minor/patch compliance, obsolete workarounds, Pulse/Precognition/Pennant-style packages | `composer outdated`, current pain requiring continuous monitoring, live validation, or flags |

First-pass catalog entries:

| Feature / source | Category / priority | BLB relevance | Audit signal | Adoption rule / caveat | Owner |
| --- | --- | --- | --- | --- | --- |
| Laravel strict model protections (`preventLazyLoading`, `preventAccessingMissingAttributes`, `preventSilentlyDiscardingAttributes`) | Correctness + data performance / Complete | Prevents N+1s, stale model assumptions, and silently discarded data from becoming normalized development behavior. | Strict-mode violations; stale assignment of removed columns; factory-hydrated models missing real attributes. | Full `Model::shouldBeStrict(! app()->isProduction())` is active. The Payroll mapping blocker was resolved in `payroll-pay-item-code-reconciliation.md`; keep the guardrail on. | This plan; `payroll-pay-item-code-reconciliation.md` |
| Eloquent inverse hydration (`chaperone`) | Data/query performance / Targeted | Covers the N+1 shape where parents eager-load children but child loops read the parent again. | `hasMany` / `morphMany` eager loads followed by child→parent relation access; lazy-loading violations on inverse relations. Local use: 0. | Use only on relationships or eager-load sites that actually need child→parent access; avoid broad hydration where it only increases memory. | Phase 5 |
| Pagination, cursor pagination, chunks, lazy collections | Data/query + memory performance / Now for request paths | BLB has large admin lists, imports, reports, and grids where unbounded collections can inflate HTML, memory, and query time. | Request-path `all()` / `get()` on variable-size tables; local broad signal: many `all()` references, 0 `cursorPaginate`, 0 `lazyById`, 0 `chunkById` outside vendor. | Paginate user-facing lists by default; use chunk/lazy/cursor patterns for bulk jobs and exports. Preserve deterministic ordering before cursor pagination. | Phase 5; `performance-page-rendering.md` |
| `Cache::flexible()` stale-while-revalidate | Data/cache performance / Complete for first pass | Removes the periodic slow request when derived cache entries expire. | Hot `Cache::remember` reads whose value can be briefly stale. Local material uses moved: menu tree and workflow statuses; remaining known `remember` is trivial `app_version`. | Prefer for read-heavy derived data; keep normal cache APIs for immediately consistent reads. | Phase 5 |
| `Cache::memo()` | Data/cache performance / Targeted | Avoids repeated cache-store hits for the same key during one request or job, useful under database/Redis cache stores. | Same cache key read repeatedly in a request/job; services that call cache-backed preferences/menu/authz state multiple times. Local use: 0. | Use as a request-local optimization, not a replacement for durable cache. Mutations already clear the memoized value. | Phase 5 |
| Cache locks, `withoutOverlapping`, and `funnel` | Throughput + duplicate-work control / Opportunistic | Prevents duplicate expensive rebuilds, exports, syncs, or external calls across workers. | Manual lock logic, overlapping scheduled/queued work, repeated expensive rebuilds under concurrent requests. | Use where duplicate execution is harmful or wasteful; requires a lock-capable shared cache store. | Phase 5 / Phase 6 |
| `defer()`, deferred/background queue connections, `dispatchAfterResponse`, and HTTP `afterResponse` callbacks | Request lifecycle latency / Complete for first pass | Moves non-critical work out of the user-visible response without inventing job classes for tiny side effects. | Synchronous audit writes, notifications, metrics, cache rebuilds, external calls, or jobs that do not need to block. First-pass material use: Audit and Authz buffered writes now use named `defer()->always()` callbacks. | Use for simple after-response side effects; use durable queues for retries or long work; pair DB-dependent jobs with `afterCommit` where needed. | Phase 6 |
| Laravel `Concurrency` facade | Request/command latency / Targeted | Can reduce wall-clock time for independent slow tasks such as unrelated aggregates or external calls. | Multiple independent slow operations executed serially in the same request/command. Local use: 0. | Use only after evidence: process driver has serialization/process overhead; fork driver is CLI-only and requires `spatie/fork`. | Phase 6 |
| Queue `after_commit`, failover, deferred, and background drivers | Correctness + latency / Targeted | Keeps response-path and transaction-sensitive jobs honest while avoiding unnecessary queue-worker dependency for tiny after-response jobs. | Jobs dispatched inside DB transactions; side effects that can run after response; queue outage paths. | Prefer explicit `afterCommit` / `beforeCommit` at sensitive call sites; add deferred/background connections only if audits find useful call sites. | Phase 6 |
| Livewire `#[Computed]` request memoization, persisted computed values, and shared computed cache | Livewire data/rendering performance / Targeted | Avoids duplicate derived queries during one Livewire request and can cache expensive component data across requests when invalidation is clear. | Repeated method calls/properties in `render()`, `stats()`, `dashboard()`, or views; page-weight triage and query counts. Local use: 0. | Use for repeated derived values; use `persist` / `cache` only with explicit invalidation via `unset()` or stable cache keys. | Phase 4; `performance-page-rendering.md` |
| Livewire lazy/deferred components and bundled lazy requests | Initial render + payload performance / Targeted | Keeps below-the-fold or secondary panels from blocking first paint and helps keep initial HTML under the ~150 KB guideline. | Page-weight offenders, below-fold catalogs/lists, expensive secondary tabs. Local app use: one `#[Lazy]` component; docs already record the pattern. | Lazy/defer measured heavy sections; bundle only many similar lazy components, not slow + fast mixed components. Tests may need `Livewire::withoutLazyLoading()`. | Phase 4; `performance-page-rendering.md` |
| Livewire islands | Partial-render performance / Targeted | Lets independent regions in a large component update without rerendering the whole component or extracting child components. | Large Livewire components with independent panes, counters, feeds, or refresh buttons; local use: 0. | Use for measured bottlenecks and independent UI regions; avoid for tightly coupled state or simple fast components. | Phase 4; `performance-page-rendering.md` |
| `wire:navigate`, `@persist`, and `wire:current` / `data-current` | Navigation performance / Mostly adopted; audit caveats | Provides SPA-like navigation and persistent layout chrome without full browser reloads. | Persisted layout elements, stale active-link logic, document-level JS listeners, asset version reload behavior. Local use: `wire:navigate` 199, `@persist` 21, `wire:current` 8. | Continue convention; prefer `data-current` / `wire:current` for persisted nav state; audit JS hooks for `livewire:navigated` leaks. | `performance-page-rendering.md` |
| Livewire `#[Async]`, `.async`, `#[Json]`, and renderless actions | Interaction latency + render avoidance / Targeted | Fire-and-forget UI actions and JavaScript-consumed data can avoid Livewire's per-component request queue and skip unnecessary rerenders. | Analytics/logging/notification triggers, autocomplete/search data for Alpine, chart/map data, actions whose return is consumed only by JS. Local use: 0. | Use only for pure side effects or JS-only data; never mutate component state reflected in the view from async actions. | Phase 4 / Phase 6 |
| Livewire Form objects | Component depth + validation maintainability / Opportunistic | Reduces component bloat and repeated validation/state code in complex forms, which can indirectly reduce fragile payload/state handling. | Components with many public form fields, duplicated create/update validation, or validation logic obscuring rendering/query logic. Local use: 0. | Use on new/touched non-trivial forms; do not extract tiny forms for idiom alone. | Phase 4 |
| Livewire `#[Reactive]`, `#[Locked]`, and child data-flow attributes | Rendering contract + safety / Opportunistic | Makes parent→child updates explicit and protects identity-like props from client mutation. | Nested components with stale child props, over-broad parent rerenders, or public IDs treated as trusted. Local app `#[Reactive]` use: 0. | Use when the data-flow or trust boundary requires it; do not mark props reactive by default. | Phase 4 |
| Octane scoped bindings, flush list, singleton audit, and mutable static audit | Worker safety + memory stability / Complete for first pass | FrankenPHP keeps the app in memory; stale request/user/company/locale/timezone state can leak across users and grow memory. | `singleton()` services injecting request-scoped collaborators; mutable statics; services absent from `config/octane.php` flush. | New singletons/static state require worker-safety review; prefer `scoped` for request-derived collaborators. First-pass leaks fixed: Audit `RequestContext`, locale/date display services, and AI `RunDiagnosticService`. | Phase 3 |
| FrankenPHP worker/thread configuration, compression, request recycling, and watch behavior | Runtime performance + stability / Targeted | Worker count, Caddy compression, request limits, and watch paths define real request throughput and memory recovery. | `Caddyfile`, `config/octane.php`, production launch path, memory growth, max-wait/request symptoms. Local config already enables `zstd gzip`; worker directives are env-driven. | Tune from evidence; `max_requests` is a mitigation for leaks, not a substitute for fixing them. Avoid broad watch paths in production. | Phase 6 |
| OPcache, JIT, Composer optimized autoload, and PHP 8.5 OPcache/file-cache changes | Runtime/bootstrap performance / Complete for launch-path wiring | OPcache removes parse/compile cost; PHP 8.5 makes OPcache always present and adds read-only file-cache support for immutable deploys. | `php -i`, launch scripts, production config, cold-start/bootstrap traces. `.php.d/perf.ini` sets 20000 accelerated files, tracing JIT, and larger OPcache memory. | Windows `start-app.ps1` already loaded `.php.d/perf.ini`; `start-app.sh` now exports the project scan directory with the correct PHP path separator for Unix-like shells and native Windows PHP under Git Bash. Keep JIT/preload/file-cache decisions evidence-driven and deployment-aware. | Phase 6 |
| PHP 8.5 engine optimizations and new syntax (`=== []`, `match(true)`, optimized core functions, pipe operator, clone-with) | Runtime + language modernization / Opportunistic | Engine improvements reduce need for micro-optimization; syntax can improve readability in isolated transformations or immutable value objects. | Hot paths dominated by core functions; legacy micro-optimized code that hurts readability; immutable DTO copy patterns. | Do not sweep for syntax churn; prefer readable code and let the engine optimize. Use new syntax only when touching code and it clarifies intent. | Opportunistic |
| PHP 8.5 persistent cURL share handles | External I/O performance / Targeted research | May reduce repeated connection setup to the same hosts for integrations such as AI/model catalogs, eBay, or other outbound APIs. | Repeated outbound HTTP calls to the same hosts; Guzzle/Laravel HTTP handler support; cURL extension/runtime support. | Research framework exposure before adopting; do not bypass Laravel HTTP abstractions for a speculative micro-win. | Phase 6 |
| PHP 8.5 URI extension | URL correctness + future simplification / Opportunistic | Could replace brittle URL parsing/normalization around OAuth, providers, callbacks, or Caddy/domain tooling. | Custom `parse_url`, string host/path manipulation, URL normalization helpers. | Use when touching URL-heavy code and extension availability is guaranteed; not a performance sweep. | Opportunistic |
| PHP 8.5 operations diagnostics (`php --ini=diff`, fatal backtraces, handler introspection, heap debugging) | Operations + debugging / Opportunistic | Helps diagnose config drift, native crashes, fatal timers, and memory growth in the FrankenPHP worker model. | Environment setup bugs, segfault/fatal incidents, unexplained memory growth, mismatch between CLI and server ini. | Capture in runbooks/tooling when the next runtime diagnostic task appears; avoid adding observability surface without use. | Phase 6 / runbooks |
| Dependency currency within major versions | Dependency policy / Complete for first pass | The root instructions require latest minor/patch within each major; modern feature catalogs stale quickly when patches lag. | `composer outdated --direct`. Current signal after updates: only `phpunit` 12 → 13 remains, and it is a major-version decision outside this policy. | Apply safe minor/patch updates with targeted verification; leave major upgrades to explicit plans. | Phase 6 |

## Phases

### Phase 1 — Modernization catalog and audit map (complete)

Goal: make the available framework/runtime feature set explicit before deeper audits.

- [x] Catalog current Laravel capabilities relevant to performance, correctness, request lifecycle, data/query behavior, cache behavior, and optional operational tooling. — amp/unknown-model
- [x] Catalog current Livewire capabilities relevant to rendering cost, request payloads, parent/child data flow, form state, and page persistence. — amp/unknown-model
- [x] Catalog current PHP 8.5, FrankenPHP, and Octane capabilities relevant to runtime performance, worker lifecycle, extension/config assumptions, and long-lived-process safety. — amp/unknown-model
- [x] Classify each entry by audit lane, adoption rule, risk, priority, and owning plan. — amp/unknown-model
- [x] Promote only evidence-backed "Now" and "targeted" entries into phase checklists; leave speculative entries explicitly deferred. — amp/unknown-model

Evidence: local catalog probes found `Cache::flexible` 1, `Cache::memo` 0, `chaperone` 0, `Concurrency::` 0, `defer()` 0, `dispatchAfterResponse` 0, deferred/background queue connection use 0, `cursorPaginate` / `lazyById` / `chunkById` 0 outside vendor, Livewire `#[Computed]` 0, `#[Async]` 0, `#[Json]` 0, `@island` 0, one app `#[Lazy]` component, and heavy adoption of `wire:navigate` / `@persist`. Runtime/dependency probes found a semver-safe Laravel patch available and a CLI ini path that does not load `.php.d/perf.ini`, so actual server launch-path verification is now a runtime audit item rather than an assumption.

### Phase 2 — Correctness guardrails (complete)

Goal: make silent model bugs loud in development.

Adopted in stages: full strict mode initially surfaced lazy-loading, mass-assignment, and missing-attribute failures; those were fixed before making the guardrail permanent. The Payroll `payroll_pay_item_code` blocker was resolved in `payroll-pay-item-code-reconciliation.md`, so BLB now runs full `Model::shouldBeStrict(! app()->isProduction())` outside production.

- [x] Enable and clear `preventLazyLoading` outside production; fixed the N+1s it surfaced in AI run inspection, claim utilization reporting, and inventory fitment attribute loading. — claude/opus-4.8
- [x] Clear mass-assignment and missing-attribute issues exposed by strict mode: test-only `AiRun.created_at` backdating, dead `Department.name` assignments, `Department.name` accessor truthfulness, and factory-hydrated `User.employee_id` / `company_id` faithfulness. — claude/opus-4.8
- [x] Reconcile Payroll's removed `payroll_pay_item_code` columns with mapping-table ownership so silently discarded assignments are no longer a blocker. — GPT-5.5/gpt-5.5
- [x] Keep full Eloquent strict mode active outside production in `AppServiceProvider::configureModels()`. — GPT-5.5/gpt-5.5
- [x] Preserve the test-infra fixes strict mode exposed: clear stale dev caches, keep PHPUnit memory at 512M, and ensure the project PHP ini enables required extensions such as sodium. — claude/opus-4.8

Evidence: historical full-suite verification recorded 2105 passing / 0 failed / 2 pre-existing skips with the project ini. Current code uses full strict mode in `AppServiceProvider` and the companion Payroll plan is complete.

### Phase 3 — Octane state-hygiene audit

Goal: no per-request state leaks across the worker.

- [x] Audited ~140 singleton bindings + all mutable `static` properties across `app/`. Surface is mostly clean: stateless services, or correctly `scoped` / in the `flush` list / explicitly `clear()`ed (e.g. `AgentExecutionContext` is cleared in `RunAgentTaskJob`'s `finally`). Static-state surface clean (3 props, all request-independent). — claude/opus-4.8
- [x] **P2 fixed — audit cross-user leak.** `RequestContext` (`Base\Audit`) is a `singleton` built once via `fromRequest()` (actor/IP/URL/company) and injected into `AuditRequestMiddleware`, but was **not** in `config/octane.php`'s `flush` list (its sibling `AuditBuffer` was) — so under the worker every later request's audit records would be stamped with the first request's actor/company. Added `RequestContext::class` to the flush list. — claude/opus-4.8
- [x] **P1 fixed — locale staleness leak.** `ApplicationLocaleContext` memoized the resolved locale write-once as a `singleton`, freezing it for the worker's life. Bound `LocaleContext` + the three display services that inject it (`NumberDisplayService`, `CurrencyDisplayService` in the Locale provider; `DateTimeDisplayService` in the DateTime provider) as `scoped`, so Octane's `FlushTemporaryContainerInstances` rebuilds them per request. Verified: `tests/Unit/Base/Locale` + `tests/Unit/Base/DateTime` (21), `LocalizationUiTest` (7), date-rendering Leave tests (18). — claude/opus-4.8
- [x] Fixed the AI control-plane follow-up: `RunDiagnosticService` is now scoped, so it no longer re-pins scoped date/time display services in a FrankenPHP worker. Added lifecycle coverage proving a fresh instance after scoped-instance flush. — amp/unknown-model

### Phase 4 — Livewire rendering ergonomics

Goal: reduce Livewire request payloads, rerender scope, and redundant derived work where page-weight or interaction evidence justifies the change.

**Adopted as a forward convention, not a sweep.** Per this plan's own Design Decisions ("reach for when it helps, not a blanket rewrite… churning working components for idiom alone violates the caution against forced abstractions"), a blind retrofit now would be low-value churn. So:

- [x] **Convention recorded** (see Public Contract): new and touched components use `#[Computed]` for repeated derived data, Form objects for non-trivial form state, and `#[Reactive]` for parent→child data flow. — claude/opus-4.8
- [x] Audited `#[Computed]` and Form-object candidates. Highest-value candidates are roster rendering data, employee and inventory item reference-data panels, shift-template builder state, and large create forms; none should be retrofitted blindly before page-weight evidence or a nearby touch. — amp/unknown-model
- [x] Audited islands, lazy/deferred loading, and lazy request-bundling candidates. Attendance roster/shift-template builders and Payroll/Leave workbenches are plausible measured-page candidates; detailed page evidence remains owned by `performance-page-rendering.md`. — amp/unknown-model
- [x] Audited `#[Async]`, `.async`, `#[Json]`, and renderless-action candidates. Status toggles, exports, and modal triggers have possible uses, but current actions either mutate visible state, need race protection, or are better left synchronous until interaction evidence exists. — amp/unknown-model
- [x] Audited nested component prop boundaries for `#[Reactive]` / `#[Locked]`; no broad safe retrofit was found, so this stays a touched-code convention. — amp/unknown-model

### Phase 5 — Data/query/cache performance

Goal: keep request-path data access bounded, cache hot derived reads without blocking users, and avoid duplicate expensive work across workers.

- [x] Menu tree (`MenuBuilder::buildAndCache`) moved from `Cache::remember` (hourly, blocking rebuild on expiry) to `Cache::flexible([3600, 21600])` — serves stale + refreshes after the response. `MenuRegistry` keeps `Cache::forever` (explicitly invalidated, no expiry to smooth). — claude/opus-4.8
- [x] Workflow status graphs (`StatusManager::getStatuses`) moved from blocking `remember` to `flexible([3600, 21600])`; mutation paths still forget the key, so stale serving only smooths natural expiry. — amp/unknown-model
- [x] Swept remaining `Cache::remember` reads. Only the trivial `app_version` cache warmer remains; no additional derived read needed conversion. — amp/unknown-model
- [x] Audited repeated same-key cache reads for `Cache::memo()` candidates. No safe material first-pass candidate was found; settings/authz/menu reads either already cache at a deeper layer or need mutation-sensitive behavior. — amp/unknown-model
- [x] Audited eager-loaded child loops for `chaperone()` candidates. Candidate shapes were false positives or low-value because child loops did not read the inverse parent in a way that strict loading would improve; keep `chaperone()` as a targeted fix for future lazy-loading evidence. — amp/unknown-model
- [x] Audited request-path unbounded collections and implemented the highest-signal fix: the eBay dashboard no longer loads all sales/orders/candidate items just to display small panels; sales aggregate in SQL for dashboard listings, trust-signal orders are scoped to relevant listing lines, fitment reuse candidates are capped in SQL, and listing stats reuse the dashboard listing summary. — amp/unknown-model
- [x] Audited duplicate expensive rebuilds, exports, syncs, and external calls for locks / `withoutOverlapping` / `funnel`. Existing model-catalog sync already uses a lock; import/export builders remain full-data by contract and need size evidence before adding queue/lock complexity. — amp/unknown-model

### Phase 6 — Request lifecycle, runtime configuration, and dependency currency

Goal: trim perceived latency, verify the runtime actually uses the intended performance configuration, and close safe dependency gaps.

- [x] Audited non-critical response-path work and implemented the material safe changes: Audit and Authz buffered writes now register named Laravel `defer()` callbacks with `always` semantics, preserving the old terminating behavior on failed/denied responses while moving work to the after-response phase. — amp/unknown-model
- [x] Audited jobs dispatched inside database transactions for `afterCommit` / `beforeCommit` needs. No queued job dispatch inside a transaction required a first-pass change; the workflow event dispatch already occurs after the transaction commits. — amp/unknown-model
- [x] Audited independent slow request/command operations for Laravel `Concurrency::run` / `Concurrency::defer`. No safe first-pass candidate justified process-overhead or fork-driver surface; keep it targeted for measured independent external calls or aggregates. — amp/unknown-model
- [x] Verified and aligned OPcache/JIT/autoload launch paths. Windows `start-app.ps1` already set `PHPRC` and `PHP_INI_SCAN_DIR`; `start-app.sh` now exports the project `.php.d` scan directory before starting Octane/queue/Vite, using `:` for Unix-like PHP and `;` plus `cygpath` for native Windows PHP under Git Bash. Local probes confirmed `.php.d/perf.ini` loads `opcache.max_accelerated_files=20000`, tracing JIT, and a 128M JIT buffer. — amp/unknown-model
- [x] Audited FrankenPHP/Octane worker count, request recycling, garbage threshold, compression, and watch-path assumptions. Current config is env-driven, uses request recycling, and Caddy already enables `zstd gzip`; no evidence supports further tuning yet. — amp/unknown-model
- [x] Researched PHP 8.5 persistent cURL share handles at the audit level. Existing outbound HTTP paths do not yet show same-host connection setup as a measured cost, so no Laravel HTTP abstraction bypass or handler customization is warranted. — amp/unknown-model
- [x] Applied the safe `laravel/framework` patch gap (13.12.0 → 13.13.0) with dependencies. A fresh `composer outdated --direct` now shows only `phpunit` 12 → 13, which remains a major-version decision. — amp/unknown-model
- [x] **`livewire/livewire` 4.3.0 → 4.3.1** applied (the only in-policy patch; `composer update livewire/livewire`, only `composer.lock` changed, published assets are gitignored). Verified on 4.3.1 with a Livewire slice (ChatView, AdminMenuAccess, TableRegistry — 14 passed). `phpunit` 12 → 13 is a major bump (out of the "within each major" policy; left on 12.x). — claude/opus-4.8
- [x] Decided — **defer all three** (none yet warranted, per the Strategic-Programming "don't carry speculative features" principle): **Pulse** (perf monitoring) is the most justified given the perf focus, but adds a DB-backed ingest + dashboard to carry — adopt if/when continuous perf monitoring becomes a priority (the `PULSE_ENABLED=false` env is already in `phpunit.xml`, so wiring is anticipated). **Precognition** — adopt when a form needs live server-side validation UX. **Pennant** — adopt when feature flags are actually needed. — claude/opus-4.8

Evidence for the final implementation batch: focused checks passed for AI control-plane lifecycle, Audit, Authz, Workflow, and eBay marketplace slices (110 tests / 369 assertions); broader Commerce marketplace, inventory, and catalog slices passed after the eBay query fix; Pint formatted the touched PHP files; Git Bash `bash -n` passed for the touched shell scripts; local PHP ini probing confirmed `.php.d/perf.ini` loads under the intended scan-dir environment.
