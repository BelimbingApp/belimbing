# Belimbing (BLB) Architect & Agent Guidelines

## 1. Project Context
Belimbing (BLB) is an enterprise-grade **framework** built on Laravel, leveraging the TALL stack evolution:
- **Framework:** Laravel 12+
- **Frontend/Logic:** Livewire Volt + Tailwind CSS + Alpine.js
- **Testing:** Pest PHP
- **Linting:** Laravel Pint
- **Dependencies:** Use the latest available versions for all packages and dependencies.

BLB is a higher-order framework layered on top of Laravel. It preserves compatibility where practical but will intentionally diverge when necessary to uphold BLB’s architectural principles. BLB extends and adapts Laravel internals accordingly, guided by Ousterhout’s design tenets: deep modules, simple interfaces, and clear boundaries.

Think of Laravel as the Level 0 foundation and BLB as a Level 1 framework built atop it — cohesive, opinionated, and extensible. BLB is not a mere Laravel application; it has no qualms about customizing Laravel to align with its architectural principles.

## 2. Development Philosophy: Early & Fluid
**Context:** Initialization phase — no external users, no production deployment. This gives *design freedom* to build correctly from the start. Do not treat it as permission to shortcut quality.

### Production Mindset
**No MVP mindset.** Build production-grade from day one. Scope may be small, but the bar is high: deep modules, clear contracts, zero tolerance for tech debt. If an approach would be unacceptable in production, it is unacceptable in the initialization phase too.

### Core Principles
- **Boy-Scout Rule:** Leave the codebase better than you found it. When editing a file or area, fix nearby issues (naming, dead code, missing tests, unclear comments) in the same change — small, scoped improvements compound.
- **Destructive Evolution:** Prioritize the best current design over backward compatibility. Drop tables, refactor schemas, and rewrite APIs freely — no migration paths for data. Use this freedom for structural improvement, not for cutting corners.
- **Strategic Programming:** Prefer structural solutions over tactical patches. Refactor immediately upon discovering design flaws (zero tolerance for tech debt); resist quick fixes and aim for simplicity to lower future costs.
- **Deep Modules:** Modules should provide powerful functionality through simple interfaces. Hide complexity; do not leak implementation details.

## 3. Laravel Customization: Embrace When Needed

**BLB is NOT a pure Laravel application.** It's a framework built on Laravel.

### When to Customize Laravel

**BLB will diverge from Laravel defaults when necessary to uphold architectural principles.**

**Example (Already Implemented):** Module-aware migrations
- Laravel: Migrations in `database/migrations/` only
- BLB: Auto-discover from module directories, support `--module` flag, seeder registry

### Agent Responsibility

**When you see opportunities to improve Laravel defaults for framework needs:**
1. **Flag it immediately** - Discuss with user before implementing
2. **Consider framework perspective** - How does this help adopters?
3. **Document the divergence** - Why BLB does it differently

## 4. Top-Down Planning
When you are tasked to create a plan:
- **State the problem’s essence in one sentence.** If you cannot, the design is fuzzy.
- **Define the public interface first.** What operations exist, what they promise, and what they will not do.
- **Decompose into major responsibilities.** Identify 2–4 top-level components; defer internal details.
- **Sketch each component’s contract.** Inputs, outputs, invariants; avoid implementation details.
- **Define module-level policies.** Document whether the module retries, propagates, or wraps errors.
- **Identify expected uses and call patterns.** Understanding callers helps you choose interfaces that feel obvious.
- **Spot potential “complexity hotspots.”** Note inputs that may grow, error cases, or cross-cutting concerns.
- **Stop before coding.** Planning ends at contracts and structure; implementation comes after approval.

## 5. PHP Coding Conventions

### PHPDoc

#### Overridden Methods
- **Always document overridden methods** that change or extend parent behavior
- Use `{@inheritdoc}` **only** when implementing abstract methods with identical behavior
- **Explain what changed** compared to the parent implementation

```php
// ✅ Good - Documents the override's purpose
/**
 * Run the pending migrations.
 *
 * Overrides parent to handle module-aware seeding.
 */
protected function runMigrations(): void
{
    // ... implementation
}

// ✅ Good - Extends parent behavior
/**
 * Configure the command options by adding --module to the parent definition.
 *
 * {@inheritdoc}
 */
protected function configure(): void
{
    parent::configure();
    // ... add custom options
}

// ❌ Avoid - No explanation of what changed
/**
 * {@inheritdoc}
 */
protected function runMigrations(): void
{
    // ... completely different implementation
}
```

**Rationale:** Explicit documentation makes the override's purpose clear and helps maintainers understand what differs from the parent. This aligns with the "Deep Modules" principle of clear interfaces.

#### Annotations Formatting
- **Use double spaces** in PHPDoc `@param` annotations for alignment
- Format: `@param  type  $paramName  Description`
- Double spaces improve readability, especially with multiple parameters of different type lengths

```php
// ✅ Good - Double spaces for alignment
/**
 * Register a seeder in the registry.
 *
 * @param  string  $seederClass  Fully qualified seeder class name
 * @param  string  $moduleName  Module name (e.g., 'Geonames')
 * @param  string  $modulePath  Module path (e.g., 'app/Modules/Core/Geonames')
 * @param  string  $migrationFile  Migration file that registered this seeder
 */
public static function register(
    string $seederClass,
    string $moduleName,
    string $modulePath,
    string $migrationFile
): void {
    // ... implementation
}

// ❌ Avoid - Single space (inconsistent alignment)
/**
 * Extract module path from migration file path.
 *
 * @param string $migrationPath Full path to migration file
 * @return string|null Module path (e.g., 'app/Modules/Core/Geonames')
 */
```

**Rationale:** Double spaces provide visual alignment that makes PHPDoc blocks easier to scan, especially with multiple parameters. This follows Laravel's convention and improves code readability.

### Return Types
- **Always add return type declarations** to methods wherever possible
- Explicit return types improve type safety, IDE support, and static analysis
- Use `: void` for methods that don't return a value, `: int` for exit codes (e.g., command handlers), and specific types for all other methods

```php
// ✅ Good - Explicit return type
public function handle(): int
{
    return parent::handle();
}

public function getModules(): array
{
    return $this->modules;
}

public function loadMigrations(): void
{
    // ... implementation
}

// ❌ Avoid - Missing return type
public function handle()
{
    return parent::handle();
}

public function getModules()
{
    return $this->modules;
}
```

**Rationale:** Return type declarations provide compile-time type checking, better IDE autocomplete, and make method contracts explicit. This reduces bugs, improves code clarity, and enables better static analysis tooling.

### String Literals
- **Use single quotes (`'`) for string literals** unless interpolation or escape sequences are needed
- Use double quotes (`"`) only when:
  - Variable interpolation is required: `"Hello $name"`
  - Escape sequences are needed: `"\n"`, `"\t"`

```php
// ✅ Preferred
$name = 'John';
$class = 'App\Models\User';
$sql = 'SELECT * FROM users WHERE active = 1';

// ❌ Avoid (unless interpolation needed)
$name = "John";
$class = "App\Models\User";

// ✅ Acceptable (interpolation/escapes)
$greeting = "Hello $name";
$message = "Line 1\nLine 2";
```

### Avoiding Magic Methods
- **Prefer direct method calls over magic methods** when alternatives are available
- Magic methods (`__call`, `__callStatic`) reduce IDE support, type safety, and static analysis capabilities
- Use direct method calls or explicit interfaces instead of relying on Laravel's magic method system

#### Eloquent Models
Use `query()` to bypass magic methods for static Eloquent calls:

```php
// ✅ Preferred - Direct method call, better IDE support
// Builder methods
self::query()->updateOrCreate(
    ['seeder_class' => $seederClass],
    ['status' => self::STATUS_PENDING]
);

// Scope methods
SeederRegistry::query()->pending()->get();
SeederRegistry::query()->runnable()->forModules(['Geonames'])->get();

// ❌ Avoid - Magic method, requires @method annotation for IDE support
self::updateOrCreate(
    ['seeder_class' => $seederClass],
    ['status' => self::STATUS_PENDING]
);
SeederRegistry::pending()->get();
```

#### Facades
Prefer dependency injection over Facades when possible. If Facades are necessary, use `Facade::getFacadeRoot()` for direct access:

```php
// ✅ Preferred - Dependency injection
public function __construct(
    private readonly \Illuminate\Cache\Repository $cache
) {}

public function get(string $key): mixed
{
    return $this->cache->get($key);
}

// ✅ Acceptable - Facade with direct root access (when DI not feasible)
use Illuminate\Support\Facades\Cache;

$cache = Cache::getFacadeRoot();
$value = $cache->get($key);

// ❌ Avoid - Magic method via Facade
$value = Cache::get($key);  // Requires IDE helper or @method annotations
```

#### Collections
Use direct method calls on Collection instances:

```php
// ✅ Preferred - Direct method call
$collection = collect([1, 2, 3]);
$filtered = $collection->filter(fn($item) => $item > 1);

// ❌ Avoid - Magic method (if applicable)
// Note: Most Collection methods are direct, but be aware of any magic method usage
```

**Rationale:** Direct method calls provide better IDE autocomplete, "Go to Definition" support, type checking, and static analysis. This improves code clarity, reduces the need for `@method` PHPDoc annotations, and makes refactoring safer. While Laravel's magic methods are convenient, they come at the cost of developer experience and tooling support.

### Console Commands

**BLB framework commands must use the `blb:` prefix** to distinguish them from Laravel built-ins and third-party packages.

#### Command Naming Convention

```php
// ✅ Good - BLB-specific command with blb: prefix
#[AsCommand(name: 'blb:ai:catalog:sync')]
class AiCatalogSyncCommand extends Command
{
    protected $signature = 'blb:ai:catalog:sync
                            {--force : Force re-download}';
    // ...
}

// ✅ Good - Intentional Laravel override (no prefix)
#[AsCommand(name: 'migrate')]
class MigrateCommand extends \Illuminate\Database\Console\Migrations\MigrateCommand
{
    // Extends Laravel's migrate with module awareness
}

// ❌ Avoid - No prefix on new BLB command
#[AsCommand(name: 'ai:catalog:sync')]  // Could clash with packages
```

**Rules:**
- **New BLB commands:** Always use `blb:` prefix (e.g., `blb:ai:catalog:sync`, `blb:module:scaffold`)
- **Laravel overrides:** No prefix when intentionally replacing Laravel built-ins (e.g., `migrate`, `db:seed`)
- **Discoverability:** Users can run `php artisan list blb` to see all framework commands grouped together

**Rationale:** The `blb:` namespace prevents command name collisions with Laravel core and third-party packages, signals framework ownership, and improves command discoverability. BLB is a framework, not an application — its commands should be clearly identifiable as framework infrastructure.

## 6. Nested AGENTS.md Files
This project uses nested AGENTS.md files for specialized guidance. Agents should read the nearest AGENTS.md in the directory tree for context-specific instructions:

| Scope | File | Covers |
|-------|------|--------|
| UI / Blade | `resources/views/AGENTS.md` | Component-first design, semantic tokens, spacing, typography, accessibility, i18n, performance, component inventory |
| Database | `app/Base/Database/AGENTS.md` | Module-aware migrations, seeder registry, ID standards, development workflow |
| Docs | `docs/AGENTS.md` | Documentation directory structure and placement |

**Cursor users:** `.cursor/rules/ui-architect.mdc` is a thin adapter that triggers on `*.blade.php` and references `resources/views/AGENTS.md`. The AGENTS.md file is the canonical source; do not duplicate rules in `.cursor/rules/`.

## 7. Module-First Placement Guard

Before creating new framework/module assets, verify placement against `docs/architecture/file-structure.md`.
If the task touches module config, migrations, or seeders, **stop and verify placement/prefix/registration rules first** before creating or moving files.

**Pre-implementation checklist for new module work:**

- **Config:** Module config in module `Config/` (not root `config/`); register with `mergeConfigFrom(__DIR__.'/Config/<name>.php', '<name>')` in the module service provider; keep key stable (`config('<name>...')`). Use root `config/*.php` only for framework-wide defaults.
- **Authz:** If modifying any `Config/authz.php`, sync database via `php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder"`.
- **Migrations & seeders:** Migration location/prefix and seeder strategy → `app/Base/Database/AGENTS.md` and `docs/architecture/database.md`.
