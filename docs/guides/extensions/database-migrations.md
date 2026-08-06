# Extension Database Migrations

**Document Type:** Developer Guide
**Last Updated:** 2026-08-06

This guide explains how extensions can create and manage database tables in the Belimbing application platform.

## Overview

Extensions can create their own database tables by placing migration files in their `Database/Migrations/` directory. BLB's Base database layer discovers extension migrations from that path, so extension providers do not need to register migration paths.

## Extension Structure

Extension Modules follow a two-level `{Extension}/{Module}/` layout under `app/Extensions/`. Both ownership segments use PascalCase:

```
app/Extensions/
├── {Extension}/               # Deployment-owned Extension source
│   └── {Module}/
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
└── Acme/
    └── Analytics/
        └── [same structure]
```

## Module Manifest and Dependencies

Extension modules may publish a module-root `composer.json` with an `extra.blb` block. The manifest is optional for simple extensions, but it is required when another module needs to depend on this extension by version, and it is the recommended way for an extension to declare its own hard dependencies.

```json
{
    "name": "acme/quality",
    "type": "blb-module",
    "autoload": {
        "psr-4": {
            "App\\Extensions\\Acme\\Quality\\": ""
        }
    },
    "extra": {
        "blb": {
            "module": "acme/quality",
            "version": "0.1.0",
            "description": "ACME quality extension.",
            "requires-modules": {
                "core/company": "*",
                "core/employee": "*"
            },
            "optional-modules": {},
            "schema": {
                "default": "incubating"
            }
        }
    }
}
```

`extra.blb.module` is the stable BLB identity. Use a lowercase, kebab-case `{extension}/{module}` ID such as `sb-group/qac`; it is independent of the PascalCase physical path. When present, this manifest identity is authoritative and the filesystem path is not a second alias. `extra.blb.requires-modules` declares hard dependencies by Module identity; `*` means any installed version, while a non-wildcard constraint requires the required Module to publish `extra.blb.version`. BLB accepts common Composer-style constraints such as exact versions, comparison ranges, caret/tilde ranges, wildcards, and `||` alternatives.

Before any module-aware migration command registers migration paths, BLB validates the installed manifest graph. Required modules must be installed and enabled, version constraints must be compatible, and migration filenames must make the dependency executable: the requiring module's earliest migration filename must sort after the latest migration filename in every required module that ships migrations. Laravel still sorts migrations by filename, so fix preflight failures by installing/enabling the required module, relaxing or correcting the constraint, or renaming migrations so the required module sorts first. Duplicate migration names across module paths are blocked because Laravel would otherwise keep only one file for that name. Explicit `--path` scopes choose what Laravel runs, but they do not bypass this global dependency preflight.

Nested-git Extensions and future package-delivered Extensions use the same Module-root manifest. Per-file schema maturity stays in the migration via `IncubatingSchema`. The `extra.blb.schema` block is only a coarse package default for a future packaged source, useful for saying a whole pre-release source defaults to `incubating`; do not list individual migration files there.

## Table Naming Conventions

**Critical**: Extension tables must be prefixed with the Extension and Module identity to prevent conflicts.

### Format
```
{extension}_{module}_{entity}
```

### Examples
- `sbg_quality_inspections` — SB Group Extension, Quality Module, inspections entity
- `sbg_quality_inspection_items` — SB Group Extension, Quality Module, inspection items entity
- `acme_billing_invoices` — Acme Extension, Billing Module, invoices entity
- `acme_billing_invoice_lines` — Acme Extension, Billing Module, invoice lines entity

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

Refer to `app/Base/Database/AGENTS.md` for the canonical migration conventions. In particular, use the shared rules there for primary keys, foreign keys, timestamps, and soft deletes.

- **Year Prefix**: Extension migrations use real years (`2026+`), not layered prefixes

### Step 3: Reference Core Tables

If your extension needs to reference core platform tables, use proper foreign key constraints:

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
app/Extensions/{Extension}/{Module}/Database/Migrations/
```

Disabled optional Domains are excluded from migration discovery. Extension migrations are discovered from `app/Extensions/*/*/Database/Migrations/` after Base, Core, and enabled Domain migrations, then checked against the manifest dependency preflight described above. The Extension Module still needs `ServiceProvider.php` for provider discovery and any Module-owned services, config, commands, views, or authz integration, but migrations do not need `loadMigrationsFrom()`.

### Step 1: Create Service Provider

Create a `ServiceProvider.php` at your module's root directory:

```php
<?php

namespace App\Extensions\SbGroup\Quality;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
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

Extension providers are discovered automatically via `ProviderRegistry::resolve()` in `bootstrap/providers.php`. The registry scans `app/Extensions/*/*/ServiceProvider.php` after Base, Core, and enabled Domains, so no manual registration is needed—place `ServiceProvider.php` at the Module root.

If your extension is not being discovered, verify that:
1. The file is at `app/Extensions/{Extension}/{Module}/ServiceProvider.php`
2. The namespace matches the PascalCase directory structure (for example, `App\Extensions\SbGroup\Qac`)
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
php artisan migrate --path=app/Extensions/SbGroup/Qac/Database/Migrations
```

### Rolling Back Extension Migrations

To rollback extension migrations:

```bash
php artisan migrate:rollback --path=app/Extensions/SbGroup/Qac/Database/Migrations
```

## Schema Incubation and Data-only Replay

Use `IncubatingSchema` only for in-progress schema that the local/testing `migrate --dev` workflow may rebuild. It is a per-migration source declaration, not a manifest setting and never a production permission to discard data. Follow the complete maturity and dependency rules in `app/Base/Database/AGENTS.md` and `docs/architecture/database.md`.

A later stable migration that only derives or normalizes data may need to rerun after an incubating table is rebuilt. Declare `ReplaysAfterIncubatingSchema` when that migration is first authored:

```php
<?php

use App\Base\Database\Concerns\ReplaysAfterIncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use ReplaysAfterIncubatingSchema;

    public function up(): void
    {
        DB::table('sbg_quality_inspections')->updateOrInsert(
            ['code' => 'default'],
            ['name' => 'Default inspection'],
        );
    }

    public function down(): void
    {
        DB::table('sbg_quality_inspections')->where('code', 'default')->delete();
    }
};
```

This marker is not schema maturity and is not a shortcut around migration safety:

- `up()` must be idempotent and data-only, with no schema-mutating `Schema` calls or raw DDL. The preflight permits only its explicit read-only schema-introspection methods.
- Referenced incubating tables must appear as literal names in source so preflight can detect them.
- `down()` removes only derived state or is an intentional no-op; it must not restore obsolete values.
- The marker affects local/testing incubating replay only. It does not rerun an applied migration during production/staging `migrate`.
- Do not add it to an already-applied stable migration merely to silence preflight. Retrofitting requires an explicit recovery procedure or accepted ADR with replay-safety evidence; behavior changes require a new forward migration.

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

Order your migrations to respect foreign key dependencies. For cross-module dependencies, declare the module dependency in `extra.blb.requires-modules` and choose migration filenames that sort after the required module's migrations:

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
app/Extensions/SbGroup/
└── Quality/
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

namespace App\Extensions\SbGroup\Quality;

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
1. Verify the migration file is under `app/Extensions/{Extension}/{Module}/Database/Migrations/`
2. Verify migration file follows Laravel naming convention (`YYYY_MM_DD_HHMMSS_description.php`)
3. Ensure BLB's database migration commands are active for your environment
4. Clear config cache: `php artisan config:clear`

### Table Name Conflicts

**Problem**: Migration fails with "Table already exists" error.

**Solutions**:
1. Ensure table name uses the full prefix: `{extension}_{module}_{entity}`
2. Check for duplicate migration files
3. Verify migration hasn't already run: `php artisan migrate:status`

### Foreign Key Errors

**Problem**: Foreign key constraint fails.

**Solutions**:
1. Ensure referenced table exists (check migration order — core tables load before extensions)
2. Verify foreign key column type matches referenced primary key
3. Check that referenced table uses `id()` method (UNSIGNED BIGINT)

### Module Dependency Preflight Fails

**Problem**: Migration command fails before running migrations with "Module migration dependency preflight failed."

**Solutions**:
1. Install and enable the module named in `requires-modules`
2. If the requirement has a version constraint, verify the required module publishes a compatible `extra.blb.version`
3. Rename the extension migration timestamps so every required module's migrations sort first
4. Rename duplicate migration filenames so each module migration has a unique basename
5. Remove or relax a manifest requirement only when the extension truly works without that module

## Related Documentation

- [Database Migration Guidelines](../../../app/Base/Database/AGENTS.md) - Core migration standards
- [Extension Configuration Overrides](./config-overrides.md) - Config management
- [Extension Structure](../../architecture/module-system.md) - Overall extension architecture
