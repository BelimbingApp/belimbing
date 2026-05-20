# Belimbing (BLB) Architect & Agent Guidelines

## 1. Project Context
Belimbing (BLB) is a Laravel-based framework:
- **PHP:** 8.5+
- **Framework:** Laravel 13
- **App Server:** FrankenPHP 1.12.3 (required for BLB's PHP worker model)
- **Frontend/Logic:** Livewire 4 + Tailwind CSS 4 + Alpine.js 3
- **Testing:** Pest 4
- **Linting:** Laravel Pint
- **Dependencies:** latest minor/patch within each major version.

BLB extends Laravel; keep compatibility where practical, diverge when architecture requires. Vision and quality bar: `docs/brief.md`.

## 2. Development Philosophy

Initialization phase — design freedom, not a license to shortcut. Build production-grade from the start.

### Core Principles

- **Low Entropy:** system-wide, not ticket-only. Drift noticed anywhere (even off-task): fix or plan now — no silent deferral.
  - **Small corrections:** fix immediately; do not defer.
  - **Larger corrections:** add a plan under `docs/plans/` in this pass; implementation may follow later, but the plan must exist now.
  - **Completeness:** when modifying an artifact, consider its full purpose. Ask "what else belongs here?"
- **Strategic Programming (Ousterhout):** invest 10–20% extra effort in design over the tactical path. When plurality is on the roadmap — not speculation — and cost-now is small while cost-later requires a data migration over existing rows, design for it now. Speculative or expensive-to-carry items still get deferred.
- **Destructive Evolution:** best current design over backward compatibility. Drop or rewrite schemas on unstable tables only; stable tables survive `migrate:fresh` (per `app/Base/Database/AGENTS.md`). Rewrite APIs freely — no migration paths for seed/schema data. Persisted user data (prefs, content, configs) is harder to discard than tables; shape it for known-recurring needs from day one.
- **Deep Modules (Ousterhout):** powerful functionality through simple interfaces. Hide complexity; do not leak implementation details. Define errors out of existence where the type system can carry the proof.
- **Honesty:** names, persisted values, APIs, docs, UI copy must be truthful and grounded in code/data. Prefer shared types and existing rules over ad hoc strings or duplicated logic.

## 3. Plan Docs
Real plans live in `docs/plans/` per `docs/plans/AGENTS.md` — single source of truth.

## 4. PHP Conventions

- **Debug logging:** `blb_log_var(mixed $value, string $file = 'debug.log', array $context = [], string $level = 'info')` — writes under `storage/logs/`, not `laravel.log`.
- **Reuse Livewire concerns** when behavior repeats in 3+ components: `ResetsPaginationOnSearch`, `SearchablePaginatedList`, `DecodesJsonFields`, `SavesValidatedFields`, `Actor::forUser()`, existing authz concerns. Don't force abstractions for tiny duplication.
- **`require` over `require_once`** for PHP config files returning arrays.
- **Never `useCurrent()`** on `timestamp` columns — captures DB session TZ, not UTC. Set `now()` from app code.
- **Throw domain exceptions** at module boundaries, not generic `RuntimeException`/`Exception`, when the failure belongs to a named subsystem.

## 5. Module-First Placement
Verify placement against `docs/architecture/file-structure.md` before creating module assets (config, migrations, seeders). When in doubt, stop and check first.
