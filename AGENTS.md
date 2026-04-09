# Belimbing (BLB) Architect & Agent Guidelines

## 1. Project Context
Belimbing (BLB) is an enterprise-grade **framework** built on Laravel, leveraging the TALL stack evolution:
- **PHP:** 8.5+
- **Framework:** Laravel 13
- **App Server:** FrankenPHP 1.12.1 (required for Belimbing's PHP worker model)
- **Frontend/Logic:** Livewire 4 + Tailwind CSS 4 + Alpine.js 3
- **Testing:** Pest 4
- **Linting:** Laravel Pint
- **Dependencies:** Always on the latest available minor/patch within each major version.

BLB is a higher-order framework layered on top of Laravel. It preserves compatibility where practical but will intentionally diverge when necessary to uphold BLB's architectural principles. BLB extends and adapts Laravel internals accordingly, guided by Ousterhout's design tenets: deep modules, simple interfaces, and clear boundaries.

Think of Laravel as the Level 0 foundation and BLB as a Level 1 framework built atop it — cohesive, opinionated, and extensible. BLB is not a mere Laravel application; it has no qualms about customizing Laravel to align with its architectural principles.

## 2. Development Philosophy: Early & Fluid
**Context:** Initialization phase — no external users, no production deployment. This gives *design freedom* to build correctly from the start. Do not treat it as permission to shortcut quality.

### Production Mindset
**No MVP mindset.** Build production-grade from day one. Scope may be small, but the bar is high: deep modules, clear contracts, zero tolerance for tech debt. If an approach would be unacceptable in production, it is unacceptable in the initialization phase too.

### Core Principles
- **Boy-Scout Rule:** Leave the codebase better than you found it. When editing a file or area, fix nearby issues (naming, dead code, missing tests, unclear comments) in the same change — small, scoped improvements compound. Specifically:
  - **After iterative changes**, verify the final state is clean: grep for stale references, orphaned entries, or commented-out cruft from earlier attempts.
  - **After moving or restructuring code**, confirm the old location has no leftover wrappers, empty containers, or dangling logic.
  - **After changing approach**, remove all artifacts of the abandoned path — dead branches, unused variables, stale TODO comments.
  - **In templates**, hunt cruft: stale Tailwind classes, unused Alpine `x-data` props, orphaned `wire:model` bindings, unreachable `@if` blocks.
  - **In PHP**, hunt cruft: unused `use` imports, unreachable `catch` blocks, dead methods, properties that lost their only caller.
- **Destructive Evolution:** Prioritize the best current design over backward compatibility. Drop tables, refactor schemas, and rewrite APIs freely — no migration paths for data. Use this freedom for structural improvement, not for cutting corners.
- **Strategic Programming:** Prefer structural solutions over tactical patches. Refactor immediately upon discovering design flaws (zero tolerance for tech debt); resist quick fixes and aim for simplicity to lower future costs.
- **Deep Modules:** Modules should provide powerful functionality through simple interfaces. Hide complexity; do not leak implementation details.

## 3. Laravel Customization: Embrace When Needed

**BLB is NOT a pure Laravel application.** It's a framework built on Laravel. BLB will diverge from Laravel defaults when necessary to uphold architectural principles.

**When you see opportunities to improve Laravel defaults for framework needs:**
1. **Flag it immediately** — Discuss with user before implementing
2. **Consider framework perspective** — How does this help adopters?
3. **Document the divergence** — Why BLB does it differently

## 4. Planning Through Plan Docs
When work needs a real plan, keep the **visible** plan in `docs/plans/` per `docs/plans/AGENTS.md`—that file is the sole actionable source of truth (not hidden session or tool-only state), update it and its preamble as design or implementation moves, use judgment so only substantive work gets a plan doc, and treat legacy `docs/todo/` files under `docs/todo/AGENTS.md` until migrated.

## 5. PHP Coding Conventions

### PHPDoc

#### Overridden Methods
- **Always document overridden methods** that change or extend parent behavior.
- Use `{@inheritdoc}` **only** when implementing abstract methods with identical behavior.
- **Explain what changed** compared to the parent implementation.

```php
// ✅ Documents the override's purpose
/**
 * Run the pending migrations.
 *
 * Overrides parent to handle module-aware seeding.
 */
protected function runMigrations(): void

// ❌ No explanation of what changed
/** {@inheritdoc} */
protected function runMigrations(): void
```

#### Annotations Formatting
- **Use double spaces** in PHPDoc `@param` annotations for alignment.
- Format: `@param  type  $paramName  Description`

```php
/**
 * @param  string  $seederClass  Fully qualified seeder class name
 * @param  string  $moduleName  Module name (e.g., 'Geonames')
 */
```

### Return Types
- **Always add return type declarations** to methods wherever possible.
- Use `: void` for methods that don't return a value, `: int` for exit codes, and specific types for all other methods.

### String Literals
- **Use single quotes (`'`)** unless interpolation or escape sequences are needed.

### Debug Logging
- **Use `blb_log_var()` for temporary debugging** — output goes to a dedicated file under `storage/logs/` instead of `laravel.log`.
- Signature: `blb_log_var(mixed $value, string $file = 'debug.log', array $context = [], string $level = 'info')`
- **Do not log secrets, tokens, passwords, or personal data.**
- **Remove temporary `blb_log_var()` calls once the issue is understood.**

### Avoiding Magic Methods
- **Prefer direct method calls over magic methods** when alternatives are available.

#### Eloquent Models — use `query()`:
```php
self::query()->updateOrCreate([...], [...]);       // ✅
SeederRegistry::query()->pending()->get();          // ✅
self::updateOrCreate([...], [...]);                 // ❌ magic __callStatic
```

#### Facades — prefer dependency injection:
```php
public function __construct(
    private readonly \Illuminate\Cache\Repository $cache  // ✅ DI
) {}
```

### Reducing Duplication
- **Extract repeated Livewire glue code into shared concerns** when the same behavior appears in three or more components.
- Reuse existing shared primitives: `ResetsPaginationOnSearch`, `SearchablePaginatedList`, `DecodesJsonFields`, `SavesValidatedFields`.
- Reuse `Actor::forUser()` and existing authz concerns instead of rebuilding inline.
- **Do not force abstractions for tiny duplication.** Extract only when it meaningfully reduces repetition.
- **Prefer `require` over `require_once`** for PHP config files that return arrays.

### Sonar Prevention Guard
- **Review touched files for common Sonar traps before finishing**: duplicate literals, unused imports/locals/fields, empty blocks, generic exceptions, overly nested conditionals, and accessibility mismatches.
- **Prefer structural fixes over cosmetic suppression**:
  - extract a private method before complexity becomes difficult to name
  - extract a constant before the same literal appears across several assertions or config branches
  - remove dead state rather than leaving an unused field, import, or variable in place
- **Use `NOSONAR` only for real false positives** and always explain the trust boundary or framework constraint in the comment.
- **In JavaScript / Node ESM**, prefer `node:` built-ins, `Number.parseInt`, top-level `await` in entry scripts, and narrow catches that rethrow unexpected errors.
- **In PHP services**, throw dedicated domain exceptions at module boundaries instead of generic `RuntimeException`/`Exception` when the failure belongs to a named subsystem. Nested guides apply this principle to their own subsystems; do not restate the rule — add domain-specific boundaries only.
- Nested `AGENTS.md` files (see table below) carry **domain-specific** guards for shell, browser, AI, and test code. The root rules above are the canonical source; nested files should reference, not repeat them.

## 6. Nested AGENTS.md Files

Agents should read the nearest AGENTS.md in the directory tree for context-specific instructions:

| Scope | File |
|-------|------|
| UI / Blade | `resources/core/views/AGENTS.md` |
| Database | `app/Base/Database/AGENTS.md` |
| Authz | `app/Base/Authz/AGENTS.md` |
| Foundation | `app/Base/Foundation/AGENTS.md` |
| Shell scripts | `scripts/AGENTS.md` |
| Docs | `docs/AGENTS.md` |

## 7. Module-First Placement Guard

Before creating new framework/module assets, verify placement against `docs/architecture/file-structure.md`.
If the task touches module config, migrations, or seeders, **stop and verify placement/prefix/registration rules first** before creating or moving files.
