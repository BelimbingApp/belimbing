# Laravel Command Lifecycle and Override Patterns

**Document Type:** Tutorial
**Purpose:** Understand Laravel command registration lifecycle and how to properly override built-in commands
**Related:** [Database Service Provider](../../app/Base/Database/ServiceProvider.php), [Migrate Command](../../app/Base/Database/MigrateCommand.php)
**Last Updated:** 2026-01-16

---

## Overview

This tutorial documents the Laravel command registration lifecycle based on actual debugging of the `migrate` command override. Understanding this lifecycle is crucial when extending Laravel's built-in commands.

---

## Problem: Overriding Laravel's MigrateCommand

We needed to add a `--module` option to Laravel's `migrate` command. The option accepts comma-delimited, case-sensitive module names (e.g., `--module=Geonames,Users`). The challenge was determining the correct binding method (`extend()` vs `singleton()`) and when to register it.

---

## Laravel Command Registration Lifecycle

### 1. Service Provider Registration Order

```php
// bootstrap/providers.php
return [
    App\Base\Database\ServiceProvider::class,  // Our provider (runs first)
    App\Providers\AppServiceProvider::class,
    App\Providers\VoltServiceProvider::class,
];
```

**Key Point:** Our `DatabaseServiceProvider` runs before Laravel's `MigrationServiceProvider`.

### 2. Laravel's MigrationServiceProvider (Deferred)

Laravel's `MigrationServiceProvider` is a **deferred provider** (`implements DeferrableProvider`), meaning:
- It's only loaded when needed (when a migration command is called)
- It binds commands using their **class name directly**, not aliases
- It registers `MigrateCommand` via: `$this->app->singleton(MigrateCommand::class, ...)`

**Location:** `vendor/laravel/framework/src/Illuminate/Database/MigrationServiceProvider.php`

### 3. Command Registration Methods

#### Method A: `singleton()` - Direct Binding

```php
$this->app->singleton(MigrateCommand::class, function ($app) {
    return new CustomMigrateCommand(...);
});
```

**Behavior:**
- Binds the class immediately
- If Laravel hasn't bound it yet, this creates the binding
- If Laravel binds it later, it may override this binding

#### Method B: `extend()` - Extension Binding

```php
$this->app->extend(MigrateCommand::class, function ($command, $app) {
    return new CustomMigrateCommand(...);
});
```

**Behavior:**
- Only called when the binding is **resolved** (lazy)
- Runs **after** the original binding exists
- Receives the original command instance as `$command` parameter
- Perfect for overriding deferred provider bindings

---

## Debugging Results

Debug logs revealed the actual lifecycle:

```
[2026-01-16 05:57:15] local.DEBUG: [DatabaseServiceProvider] Registering extend() for MigrateCommand
[2026-01-16 05:57:15] local.DEBUG: [DatabaseServiceProvider] Registering singleton() for MigrateCommand
[2026-01-16 05:57:18] local.DEBUG: [DatabaseServiceProvider] extend() callback called - Laravel command was already bound
[2026-01-16 05:57:18] local.DEBUG: [MigrateCommand] Constructor called
[2026-01-16 05:57:18] local.DEBUG: [MigrateCommand] configure() called
```

### Key Findings:

1. **Both bindings are registered** during service provider boot
2. **Only `extend()` callback is called** - "Laravel command was already bound"
3. **`singleton()` callback is never called** - YAGNI (You Aren't Gonna Need It)
4. **Constructor is called from `extend()` callback** - confirmed via stack trace

---

## Command Lifecycle Sequence

```
1. Application Bootstrap
   └── Service Providers Registered (in order from bootstrap/providers.php)
       └── DatabaseServiceProvider::register() called
           ├── extend() registered (callback registered, not called yet)
           └── singleton() registered (callback registered, not called yet)

2. Artisan Command Invocation
   └── `php artisan migrate --module=Geonames`
       └── Laravel resolves 'migrate' command
           └── MigrationServiceProvider (deferred) loads
               └── Binds MigrateCommand::class via singleton()
                   └── Resolves MigrateCommand::class binding
                       └── extend() callback called ✅ (runs our custom command)
                       └── singleton() callback never called ❌ (YAGNI)
                           └── Custom MigrateCommand instantiated
                               └── configure() called
                                   └── --module option added
                                       └── handle() called
                                           └── Module migrations loaded
```

---

## Solution: Use `extend()` Only

### Why `extend()` Works

1. **Deferred Provider Timing**: `MigrationServiceProvider` loads **after** our service provider
2. **Lazy Resolution**: `extend()` callback runs when the binding is resolved, not when registered
3. **Receives Original**: The callback receives Laravel's original command, allowing us to replace it

### Why `singleton()` Doesn't Work

1. **Binding Conflict**: If we bind first, Laravel's deferred provider may override it
2. **Timing Issue**: Singleton binding happens at registration time, not resolution time
3. **Never Called**: Since `extend()` handles it, `singleton()` callback is never invoked

---

## Final Implementation

```php
// app/Base/Database/ServiceProvider.php
public function register(): void
{
    // Override Laravel's MigrateCommand by extending the binding
    // Laravel's MigrationServiceProvider (deferred) binds MigrateCommand::class directly,
    // so we extend the class name, not an alias. The extend() callback runs when
    // the binding is resolved, after Laravel's MigrationServiceProvider registers it.
    $this->app->extend(LaravelMigrateCommand::class, function ($command, $app) {
        return new MigrateCommand(
            $app->make(Migrator::class),
            $app->make(Dispatcher::class)
        );
    });
}
```

---

## Key Takeaways

### When Overriding Deferred Provider Commands

1. **Use `extend()`, not `singleton()`**
   - Deferred providers bind commands when they're first resolved
   - `extend()` runs at resolution time, after the original binding exists
   - `singleton()` binds at registration time, which may be too early

2. **Bind by Class Name, Not Alias**
   - Laravel's `MigrationServiceProvider` binds `MigrateCommand::class` directly
   - Use the full class name: `Illuminate\Database\Console\Migrations\MigrateCommand::class`

3. **Service Provider Order Matters**
   - Our provider runs before Laravel's deferred provider
   - But `extend()` callback runs **after** deferred provider binds the command

4. **Debug When Unsure**
   - Add temporary `\Log::debug()` statements
   - Check stack traces to understand call order
   - Remove debug code once understood

---

## Testing the Lifecycle

To verify the command lifecycle:

```bash
# Run with debug logging (note: case-sensitive module name)
php artisan migrate --module=Geonames --pretend

# Run with multiple modules (comma-delimited)
php artisan migrate --module=Geonames,Users --pretend

# Check logs
tail -f storage/logs/laravel.log | grep -E "(DatabaseServiceProvider|MigrateCommand)"
```

---

## Related Patterns

### Overriding Non-Deferred Commands

For commands from non-deferred providers, you might need `singleton()` instead:

```php
// Only if the provider is NOT deferred
$this->app->singleton(SomeCommand::class, function ($app) {
    return new CustomSomeCommand(...);
});
```

### Extending Commands vs Replacing

- **`extend()`**: Replace the entire command (what we did)
- **Wrapper Pattern**: Could wrap and delegate, but simpler to replace

---

**Note:** This tutorial is based on actual debugging of Laravel 13.x's command registration system. The lifecycle may vary in different Laravel versions.
