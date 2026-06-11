# Extension Database Migrations

This guide explains how extensions can create and manage database tables in the Belimbing framework.

## Overview

Extensions can create their own database tables by placing migration files in their `Database/Migrations/` directory. BLB's Base database layer discovers extension migrations from that path, so extension providers do not need to register migration paths.

## Extension Structure

Extensions follow a two-level `{owner}/{module}/` layout under `extensions/`:

```
extensions/
├── {owner}/                   # Licensee, vendor, or organization name
│   └── {module}/
│       ├── Config/
│       │   └── quality.php
│       ├── Database/
│       │   ├── Migrations/    # Extension migrations (PascalCase)
│       │   │   └── 2026_01_01_000000_create_sbg_quality_inspections_table.php
│       │   └── Seeders/
│       ├── Models/
│       ├── Services/
│       ├── Routes/
│       │   └── web.php
│       └── ServiceProvider.php  # Module root
│
└── another-vendor/
    └── analytics/
        └── [same structure]
```

## Table Naming Conventions

**Critical**: Extension tables must be prefixed with the owner and module name to prevent conflicts.

### Format
```
{owner}_{module}_{entity}
```

### Examples
- `sbg_quality_inspections` — SBG owner, quality module, inspections entity
- `sbg_quality_inspection_items` — SBG owner, quality module, inspection items entity
- `acme_billing_invoices` — ACME owner, billing module, invoices entity
- `acme_billing_invoice_lines` — ACME owner, billing module, invoice lines entity

### Why This Matters

1. **Namespace Isolation**: Prevents conflicts between extensions and core modules
2. **Visual Distinction**: Developers can instantly identify extension tables
3. **Selective Management**: Easier to backup, migrate, or remove extension data

## Creating Migration Files

### Step 1: Create Migration File

Create a migration file in your extension's `Database/Migrations/` directory:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sbg_quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sbg_quality_inspections');
    }
};
```

### Step 2: Follow Database Standards

Refer to `app/Base/Database/AGENTS.md` for migration standards:

- **Primary Keys**: Use `id()` method (creates `UNSIGNED BIGINT`)
- **Foreign Keys**: Use `foreignId()` method (creates `UNSIGNED BIGINT`)
- **Timestamps**: Include `$table->timestamps()` for created_at/updated_at
- **Soft Deletes**: Consider `$table->softDeletes()` if logical deletion is needed
- **Year Prefix**: Extension migrations use real years (`2026+`), not layered prefixes

### Step 3: Reference Core Tables

If your extension needs to reference core framework tables, use proper foreign key constraints:

```php
Schema::create('sbg_quality_audit_assignments', function (Blueprint $table) {
    $table->id();

    // Reference core companies table
    $table->foreignId('company_id')
          ->constrained('companies')
          ->cascadeOnDelete();

    // Reference core users table
    $table->foreignId('user_id')
          ->nullable()
          ->constrained('users')
          ->nullOnDelete();

    $table->string('assignment_data');
    $table->timestamps();
});
```

## Migration Discovery

Extension migrations are discovered automatically from:

```text
extensions/{owner}/{module}/Database/Migrations/
```

The extension still needs `ServiceProvider.php` for provider discovery and any module-owned services, config, commands, views, or authz integration, but migrations do not need `loadMigrationsFrom()`.

### Step 1: Create Service Provider

Create a `ServiceProvider.php` at your module's root directory:

```php
<?php

namespace Extensions\SbGroup\Quality;

use Illuminate\Support\ServiceProvider;

class ServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register any bindings here
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register views, commands, schedules, or other module behavior here.
    }
}
```

### Step 2: Register Service Provider

Extension providers are discovered automatically via `ProviderRegistry::resolve()` in `bootstrap/providers.php`. The registry scans `extensions/*/*/ServiceProvider.php`, so no manual registration is needed — just place your `ServiceProvider.php` at the module root and it will be picked up.

If your extension is not being discovered, verify that:
1. The file is at `extensions/{owner}/{module}/ServiceProvider.php`
2. The namespace matches the directory structure (e.g., `Extensions\SbGroup\Qac`)
3. Clear the config cache: `php artisan config:clear`

## Running Migrations

Once your migration files are in `Database/Migrations/`, BLB will automatically include your extension migrations when you run:

```bash
php artisan migrate
```

This will run all migrations, including those from extensions.

### Running Extension Migrations Only

To run only your extension's migrations (useful for testing):

```bash
php artisan migrate --path=extensions/sb-group/qac/Database/Migrations
```

### Rolling Back Extension Migrations

To rollback extension migrations:

```bash
php artisan migrate:rollback --path=extensions/sb-group/qac/Database/Migrations
```

## Migration Best Practices

### 1. Use Descriptive Names

Migration filenames should clearly describe what they do:

```
✅ Good:
2026_01_15_120000_create_sbg_quality_inspections_table.php
2026_01_20_090000_add_logo_url_to_sbg_quality_inspections_table.php
2026_02_01_100000_create_sbg_quality_inspection_items_table.php

❌ Bad:
2026_01_01_000000_migration.php
2026_01_01_000001_update.php
```

### 2. One Table Per Migration (Recommended)

Keep migrations focused and granular:

```php
// ✅ Good: One migration for one table
Schema::create('sbg_quality_inspections', function (Blueprint $table) {
    // ...
});

// ❌ Avoid: Multiple unrelated tables in one migration
Schema::create('sbg_quality_inspections', function (Blueprint $table) {
    // ...
});
Schema::create('sbg_billing_invoices', function (Blueprint $table) {
    // ...
});
```

**Exception**: If tables are truly inseparable and always created/dropped together, combining them is acceptable.

### 3. Always Implement `down()` Method

Ensure your migrations can be rolled back:

```php
public function down(): void
{
    Schema::dropIfExists('sbg_quality_inspections');
}
```

### 4. Use Transactions When Possible

For data migrations (not schema changes), wrap in transactions:

```php
use Illuminate\Support\Facades\DB;

public function up(): void
{
    DB::transaction(function () {
        // Data migration logic
    });
}
```

### 5. Handle Foreign Key Dependencies

Order your migrations to respect foreign key dependencies:

```php
// Migration 1: Create base table
Schema::create('sbg_quality_inspections', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});

// Migration 2: Create dependent table (runs after Migration 1)
Schema::create('sbg_quality_inspection_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('inspection_id')->constrained('sbg_quality_inspections');
});
```

## Example: Complete Extension Migration

Here's a complete example of an extension with migrations:

### Directory Structure

```
extensions/sb-group/
└── quality/
    ├── Database/
    │   └── Migrations/
    │       ├── 2026_01_01_000000_create_sbg_quality_inspections_table.php
    │       └── 2026_01_02_000000_create_sbg_quality_inspection_items_table.php
    ├── Models/
    ├── Services/
    └── ServiceProvider.php
```

### Service Provider

```php
<?php

namespace Extensions\SbGroup\Quality;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        // No migration registration is required.
    }
}
```

### Migration File

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sbg_quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbg_quality_inspections');
    }
};
```

## Troubleshooting

### Migrations Not Found

**Problem**: `php artisan migrate` doesn't find extension migrations.

**Solutions**:
1. Verify the migration file is under `extensions/{owner}/{module}/Database/Migrations/`
2. Verify migration file follows Laravel naming convention (`YYYY_MM_DD_HHMMSS_description.php`)
3. Ensure BLB's database migration commands are active for your environment
4. Clear config cache: `php artisan config:clear`

### Table Name Conflicts

**Problem**: Migration fails with "Table already exists" error.

**Solutions**:
1. Ensure table name uses the full prefix: `{owner}_{module}_{entity}`
2. Check for duplicate migration files
3. Verify migration hasn't already run: `php artisan migrate:status`

### Foreign Key Errors

**Problem**: Foreign key constraint fails.

**Solutions**:
1. Ensure referenced table exists (check migration order — core tables load before extensions)
2. Verify foreign key column type matches referenced primary key
3. Check that referenced table uses `id()` method (UNSIGNED BIGINT)

## Related Documentation

- [Database Migration Guidelines](../../../app/Base/Database/AGENTS.md) - Core migration standards
- [Extension Configuration Overrides](./config-overrides.md) - Config management
- [Extension Structure](../../architecture/module-system.md) - Overall extension architecture
