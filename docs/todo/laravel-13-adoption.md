# Laravel 13 Adoption Completion

## Problem Essence

BLB's Laravel 13 adoption needed to be completed across the whole repository, not just in the dependency graph. The real work was aligning the runtime contract, config defaults, generated artifacts, compatibility-sensitive code paths, and docs/prompts with the framework version already in use.

## Status

**State:** Complete  
**Current Phase:** Complete — validation finished  
**What changed:** The repository now presents one coherent Laravel 13 / PHP 8.5+ platform contract, the Laravel 13 config drift is reconciled, the committed helper artifacts are refreshed, and the Quality module collection issue exposed by the upgrade is fixed.

### Completion Evidence

- `composer.json` now declares `php:^8.5` alongside `laravel/framework:^13.3.0`, and `composer.lock` was refreshed to match.
- GitHub Actions test coverage now pins PHP 8.5 instead of relying on `latest`.
- `config/cache.php`, `config/database.php`, and `config/session.php` now use the adopted Laravel 13 cache / Redis / session defaults and explicitly set cache/session serialization policy.
- Root guidance, architecture docs, agent context, Kodi's system prompt, tutorial notes, and tool examples now describe the current stack instead of stale version assumptions.
- `_ide_helper.php` was regenerated and now reports Laravel 13.3.0.
- Full validation passed with `vendor/bin/pint --dirty`, `npm run build`, and `php artisan test --stop-on-failure` (`1384 passed`).

## Desired Outcome

BLB should truthfully and consistently operate as a Laravel 13 framework distribution: dependency constraints, PHP support policy, CI/runtime expectations, configuration defaults, upgrade-sensitive code paths, and both human- and agent-facing documentation should all align.

## Public Contract

After this work:

- BLB advertises one coherent platform contract: Laravel 13 and a single supported PHP floor.
- Dependency constraints, lockfiles, CI workflows, setup guidance, and agent prompts describe the same runtime story.
- Laravel 13 security and configuration changes are either adopted directly or overridden intentionally with BLB-specific reasoning.
- Known Laravel 13 behavioral changes with plausible BLB impact are audited and resolved before the upgrade is considered complete.

## Top-Level Components

### 1. Runtime and dependency contract

Owns Composer constraints, lockfile consistency, CI PHP versions, and setup/development guidance.

### 2. Laravel 13 skeleton and config alignment

Owns targeted reconciliation with Laravel 13's application skeleton, especially cache, Redis, session, and request-forgery defaults.

### 3. BLB code compatibility audit

Owns review of framework touchpoints affected by Laravel 13 behavior changes, including queue events, `upsert`, route precedence, test helpers, and generated artifacts.

### 4. Human and agent documentation

Owns every place where BLB explains its stack to humans or to AI agents so the repo reflects the current platform contract.

### 5. Verification

Owns the final confidence pass: dependency install, asset build, test suite, and any targeted smoke checks needed for Laravel 13-sensitive areas.

## Design Decisions

### Treat this as an adoption-completion pass, not a raw upgrade

The framework dependency had already moved to Laravel 13, so the main risk was silent drift: mismatched PHP constraints, stale docs, and unreviewed config / behavior changes.

### Keep BLB's public PHP contract at 8.5+

Laravel 13 only requires PHP 8.3+, but BLB already positions itself as a PHP 8.5+ framework in agent guidance, setup scripts, and project context. The adopted path aligns Composer, CI, and documentation upward to BLB's stated runtime target instead of broadening support downward.

### Compare against Laravel 13's skeleton surgically

We reviewed the upstream Laravel 13 application skeleton and adopted the parts that matter to BLB's contract and security posture. BLB remains free to diverge where the divergence is explicit and justified.

### Prefer explicit configuration over framework fallbacks

Laravel 13 changes cache / Redis / session generated defaults and adds `cache.serializable_classes` hardening. BLB now sets these deliberately so behavior does not depend on framework fallback generation rules.

### Update agent-facing prompts as first-class upgrade work

BLB relies on in-repo prompts and guidance for coding agents. Leaving those stale would cause future code generation and review work to reason from the wrong framework contract.

## Phases

### Phase 1 — Establish the platform contract

**Goal:** Make the repository's declared runtime and dependency policy internally consistent before touching secondary surfaces.

- [x] Raise `composer.json`'s PHP constraint to match the chosen BLB floor (`^8.5`) and refresh the lockfile accordingly.
- [x] Audit CI workflows, setup scripts, and local development docs so they no longer imply broader PHP support than BLB intends.
- [x] Record the exact supported stack in one authoritative place, then update all nearby references that contradict it.
- [x] Capture whether any contributor tooling still assumes an older framework or PHP contract.

**Risks:** If we leave the PHP constraint lower than the actual locked dependency graph, installs can fail in confusing ways and downstream adopters will receive contradictory signals about what BLB supports.

**Evidence:** Composer, CI, docs, setup expectations, and agent prompts now all align on PHP 8.5+ and Laravel 13.

### Phase 2 — Reconcile Laravel 13 skeleton and config changes

**Goal:** Bring BLB's application skeleton and configuration onto an intentional Laravel 13 baseline.

- [x] Compare BLB's config/bootstrap surface against Laravel 13's skeleton, starting with `bootstrap/app.php`, `config/cache.php`, `config/database.php`, and `config/session.php`.
- [x] Decide whether BLB keeps legacy cache / Redis / session naming or switches to Laravel 13 naming; encode the decision explicitly instead of relying on generated fallbacks.
- [x] Add and document `cache.serializable_classes` with a BLB-safe default policy.
- [x] Review request-forgery middleware references and test exclusions so any direct alias usage moves to `PreventRequestForgery` where needed.
- [x] Regenerate framework-derived helper artifacts so committed metadata matches the current framework version.

**Assumption:** BLB keeps intentional divergences, but each one should be explicit and documented rather than inherited accidentally from an older skeleton.

**Evidence:** Cache and Redis prefixes now use the adopted 13.x naming, session config now carries explicit serialization policy, and the request-forgery audit found no first-party alias references that needed code changes.

### Phase 3 — Audit upgrade-sensitive BLB code paths

**Goal:** Prove that Laravel 13's behavior changes do not introduce hidden breakage in BLB modules.

- [x] Audit every `upsert(...)` call to ensure `uniqueBy` is always non-empty and structurally correct.
- [x] Review queue listeners / instrumentation for Laravel 13 event payload renames (`JobAttempted`, `QueueBusy`) even if initial grep suggests low impact.
- [x] Audit route registration for any cases where domain-route precedence could change behavior.
- [x] Check tests and support utilities for assumptions that changed in Laravel 13 (`Str` factory reset, pagination view names, `Container::call` nullable defaults, manager `extend` closure binding).
- [x] Review any custom contract implementations or wrappers that might need new Laravel 13 signatures.

**Evidence:** The `upsert(...)` call sites already provide non-empty unique keys, no first-party `JobAttempted` / `QueueBusy` listeners or domain-route registrations were found, and the Quality module needed one targeted Laravel 13 fix: overriding `QualityRecord::newCollection()` so Laravel no longer tries to instantiate the abstract base model during collection metadata resolution.

### Phase 4 — Update docs, prompts, and generated guidance

**Goal:** Remove stale version drift from the repository's public and internal guidance.

- [x] Update root guidance (`AGENTS.md`) and any nested agent docs that still describe the wrong stack contract.
- [x] Update human-facing project docs (`README.md`, `docs/brief.md`, relevant guides/tutorials) to reflect the final stack contract.
- [x] Update AI prompt assets such as Kodi's system prompt so coding agents reason from Laravel 13 semantics.
- [x] Refresh generated helper / metadata files that encode framework version strings when they are committed artifacts.

**Non-goal:** This phase should not rewrite docs unrelated to the stack contract; it is a consistency sweep, not a general documentation refresh.

**Evidence:** Root agent guidance, development context docs, tutorial notes, tool examples, and generated IDE helper output now all describe the current platform.

### Phase 5 — Verify the completed upgrade

**Goal:** Demonstrate that the adoption is complete and stable.

- [x] Reinstall PHP and JS dependencies from the final manifests / lockfiles.
- [x] Run the standard quality gates: `vendor/bin/pint --dirty`, `npm run build`, and `php artisan test --stop-on-failure`.
- [x] Add targeted smoke checks for any Laravel 13-sensitive areas touched during the audit.
- [x] Update this document with evidence and mark completed phases as the work lands.

**Evidence:** Targeted verification passed for `tests/Unit/Modules/Core/AI/Services/AgenticRuntimeTest.php` and `tests/Feature/Quality/QualityWorkflowUiTest.php`, followed by a full suite pass (`1384 passed`).

## Recommended Implementation Order

1. Lock the platform contract first.
2. Reconcile config and skeleton drift second.
3. Run the focused compatibility audit third.
4. Sweep docs and prompts once the technical contract is settled.
5. Finish with full verification and phase-by-phase evidence in this document.
