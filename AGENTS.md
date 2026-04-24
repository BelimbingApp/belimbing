# Belimbing (BLB) Architect & Agent Guidelines

## 1. Project Context
Belimbing (BLB) is a Laravel-based framework:
- **PHP:** 8.5+
- **Framework:** Laravel 13
- **App Server:** FrankenPHP 1.12.2 (required for Belimbing's PHP worker model)
- **Frontend/Logic:** Livewire 4 + Tailwind CSS 4 + Alpine.js 3
- **Testing:** Pest 4
- **Linting:** Laravel Pint
- **Dependencies:** Always on the latest available minor/patch within each major version.

BLB extends Laravel (not a stock app): keep compatibility where practical, but diverge and customize when its architecture requires. Favor deep modules, simple interfaces, and clear boundaries.

## 2. Development Philosophy: Early & Fluid
**Context:** Initialization phase — no external users, no production deployment. This gives *design freedom* to build correctly from the start. Do not treat it as permission to shortcut quality.

### Production Mindset
**No MVP mindset.** Build to production standards from the start—initialization is not an excuse for shortcuts.

### Core Principles
- **System Stewardship:** You are not just a task executor — you are a steward of the entire system's health. Think beyond the immediate task and notice entropy: inconsistent patterns, leaky abstractions, repeated workarounds, structural drift.
  - **Boy-scout rule** — fix in passing: dead code, stale references, naming issues, orphaned artifacts.
  - **Completeness** — when creating or modifying an artifact (config, schema, policy), consider its full purpose, not just what the current task demands. Ask "what else belongs here?" by examining the module's full scope.
  - **Reduce system entropy** — when you notice something larger (a pattern that should be unified, an abstraction that's leaking across modules, duplication that signals a missing primitive), create a follow-up plan doc in `docs/plans/` to capture the problem and proposed improvement.
- **Destructive Evolution:** Prioritize the best current design over backward compatibility. Drop tables, refactor schemas, and rewrite APIs freely — no migration paths for data. Use this freedom for structural improvement, not for cutting corners.
- **Deep Modules:** Modules should provide powerful functionality through simple interfaces. Hide complexity; do not leak implementation details.
- **Honesty:** Names, persisted values, APIs, docs, and UI copy should be truthful, transparent, and grounded in facts from code and data; prefer shared types and existing rules over ad hoc strings or duplicated logic.

## 3. Planning Through Plan Docs
When work needs a real plan, keep the **visible** plan in `docs/plans/` per `docs/plans/AGENTS.md`. It is the SSOT. Use it.

## 4. PHP Coding Conventions

### Debug Logging
- **Use `blb_log_var()` for temporary debugging** — output goes to a dedicated file under `storage/logs/` instead of `laravel.log`.
- Signature: `blb_log_var(mixed $value, string $file = 'debug.log', array $context = [], string $level = 'info')`

### Reducing Duplication
- **Extract repeated Livewire glue code into shared concerns** when the same behavior appears in three or more components.
- Reuse existing shared primitives: `ResetsPaginationOnSearch`, `SearchablePaginatedList`, `DecodesJsonFields`, `SavesValidatedFields`.
- Reuse `Actor::forUser()` and existing authz concerns instead of rebuilding inline.
- **Do not force abstractions for tiny duplication.** Extract only when it meaningfully reduces repetition.
- **Prefer `require` over `require_once`** for PHP config files that return arrays.

### Database / Schema
- **Never use `useCurrent()` on `timestamp` columns** — it captures DB session TZ, not UTC. Set `now()` from app code.

### Exceptions
- **In PHP services**, throw dedicated domain exceptions at module boundaries instead of generic `RuntimeException`/`Exception` when the failure belongs to a named subsystem.

## 5. Module-First Placement Guard
Before creating new framework/module assets, verify placement against `docs/architecture/file-structure.md`.
If the task touches module config, migrations, or seeders, **stop and verify placement/prefix/registration rules first** before creating or moving files.
