# Belimbing (BLB) Architect & Agent Guidelines

## 1. Project Context
Belimbing (BLB) is a Laravel-based application platform:
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

- **Low Entropy:** system-wide, not ticket-only. Drift noticed anywhere (even off-task): fix or plan now — no silent deferral. Do not let development cost block entropy reduction.
  - **Small corrections:** fix immediately; do not defer.
  - **Larger corrections:** add a plan under `docs/plans/` in this pass; implementation may follow later, but the plan must exist now.
  - **Completeness:** when modifying an artifact, consider its full purpose. Ask "what else belongs here?"
- **Strategic Programming (Ousterhout):** invest 10–20% extra effort in design over the tactical path. When plurality is on the roadmap — not speculation — and cost-now is small while cost-later requires a data migration over existing rows, design for it now. Speculative or expensive-to-carry items still get deferred.
- **Progressive Evolution:** build the best design current knowledge justifies. As understanding improves, refactor, simplify, dedup, delete, relocate, rename, improve abstractions, make schema maturity explicit, and reduce entropy.
- **Deep Modules (Ousterhout):** powerful functionality through simple interfaces. Hide complexity; do not leak implementation details. Define errors out of existence where the type system can carry the proof.
- **Exceptional Experience:** Treat UX and UI quality as first-class architecture; every interface must honor `DESIGN.md`.
- **Information Architecture:** organize UI by user workflow; organize code by ownership and change boundary. Bridge explicitly when they differ.
- **Honesty:** names, persisted values, APIs, docs, UI copy must be truthful and grounded in code/data. Prefer shared types and existing rules over ad hoc strings or duplicated logic.
- **Opinionated defaults:** at the framework and product-contract layer, prefer one good blessed path over configurable demos, feature flags for hypotheticals, or option sprawl — kill options when the rubric picks a winner. Business logic and modules remain customizable; opinionation guards the shared shell, UX contract, and platform conventions.

## 3. Plan Docs
Real plans live in `docs/plans/` per `docs/plans/AGENTS.md` — single source of truth.

## 4. PHP Conventions

- **Debug logging:** `blb_log_var(mixed $value, string $file = 'debug.log', array $context = [], string $level = 'info')` — writes under `storage/logs/`, not `laravel.log`.
- **Reuse Livewire concerns** when behavior repeats in 3+ components: `ResetsPaginationOnSearch`, `SearchablePaginatedList`, `DecodesJsonFields`, `SavesValidatedFields`, `Actor::forUser()`, existing authz concerns. Don't force abstractions for tiny duplication.
- **`require` over `require_once`** for PHP config files returning arrays.
- **Never `useCurrent()`** on `timestamp` columns — captures DB session TZ, not UTC. Set `now()` from app code.
- **Throw domain exceptions** at module boundaries, not generic `RuntimeException`/`Exception`, when the failure belongs to a named subsystem.
- **Tenant-owned tables carry `tenant_id`** — new tables holding tenant-owned data include an indexed `tenant_id`; high-volume tables denormalize it even when derivable via `company_id`. Read the current tenant only through `App\Base\Tenancy\Contracts\TenantContext` and fail closed on null. See `docs/architecture/tenancy.md`.

## 5. Four-Root Application Placement

Application-owned PHP code belongs to exactly one of four roots. Verify placement against `docs/architecture/module-system.md` before creating config, migrations, seeders, views, assets, or tests.

- **`app/Base/{Component}` (`App\Base`)** — framework infrastructure and cross-cutting primitives. Base components are not business domains.
- **`app/Core/{Module}` (`App\Core`)** — the required Core Domain. Core owns Modules, ships with the platform, and cannot be disabled or uninstalled independently.
- **`app/Domains/{Domain}/{Module}` (`App\Domains`)** — optional business Domains such as `People`, `Commerce`, and `Operation`. A Domain is the installable, enableable, disableable, and updateable unit; it contains one or more Modules.
- **`app/Extensions/{Extension}/{Module}` (`App\Extensions`)** — operator- or user-chosen Extensions. Extensions are intentionally a relaxed mixed bag, but their module boundaries and integration surfaces must still be explicit.

Physical ownership segments (`Component`, `Domain`, `Extension`, `Module`) use PascalCase. Persisted and external identities are stable, lowercase, path-independent IDs such as `core/company`, `people/payroll`, and `sb-group/qac`; never derive an identity by lowercasing a current filesystem path.

Discovery order is a framework contract: **Base → Core → enabled Domains → Extensions**. Use `App\Base\Foundation\ApplicationTopology` rather than adding one-off root globs.

- **Presentation follows ownership.** Domain and Extension views live in the owning Module's `Views/`; Core Modules may also own local views when they are not shared framework presentation. Do not scatter Module views under new `resources/*` trees. All follow `DESIGN.md` and `resources/core/views/AGENTS.md`.
- **The shared shell stays framework-owned.** Reusable Blade components, the application shell, and framework-wide tokens live under `resources/core`.
- **Module assets are explicit.** If a Module genuinely needs owned CSS or JavaScript, keep source in its `Assets/` directory and wire it through an explicit reviewed Vite entry/import. Do not inject global scripts/styles.
- **Promote deliberately.** If a Module view reveals a reusable framework component, extract it to `resources/core` and keep the Module screen in its owning Module.

## 6. Version Control & Workflow

- **Where direct commits to `main` are authorized, work on `main`.** Do not keep other branches. Otherwise, follow the repo's authority and workflow.
- **Never leave work unpushed or unmerged.**
- **Land cross-repo changes together.** Note merge order when one repo depends on another.
