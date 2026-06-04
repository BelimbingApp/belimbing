# Laravel Package Asset Publishing Pattern

**Document Type:** Tutorial
**Purpose:** Explain how Laravel packages publish migrations, models, seeders, and other assets using the standard publishing pattern
**Related:** [Database Migrations](../database/MIGRATION_WORKFLOW.md), [Extension Database Migrations](../extensions/database-migrations.md)
**Last Updated:** 2026-01-XX

---

## Overview

Laravel packages use a standardized pattern to publish customizable assets (migrations, models, configs, etc.) to your application. This tutorial explains how this pattern works using the `nevadskiy/laravel-geonames` package as a real-world example.

---

## Why Packages Publish Assets

Packages need to publish assets to your application because:

1. **Customization**: Developers need to modify migrations, models, or configurations to fit their specific needs
2. **Version Control**: Published files are tracked in your repository, not in vendor directory
3. **Modification Safety**: Files in `vendor/` are overwritten during package updates; published files remain untouched
4. **Laravel Convention**: Laravel expects migrations, models, and seeders in specific application directories

---

## The Standard Publishing Pattern

### 1. Package Structure

Laravel packages store template files (called "stubs") in a `stubs/` directory:

```
vendor/package-name/
├── src/
│   └── PackageServiceProvider.php
├── stubs/
│   ├── database/
│   │   └── migrations/
│   │       └── 2020_01_01_000000_create_table.stub
│   ├── app/
│   │   └── Models/
│   │       └── Model.stub
│   └── config/
│       └── package.php
└── composer.json
```

### 2. Service Provider Registration

The package registers its Service Provider in `composer.json`:

```json
{
    "extra": {
        "laravel": {
            "providers": [
                "Vendor\\Package\\PackageServiceProvider"
            ]
        }
    }
}
```

Laravel auto-discovers this during `composer install/update`.

### 3. Publishing in Service Provider

The Service Provider's `boot()` method registers publishable assets:

```php
public function boot(): void
{
    // Register publishable assets
    $this->publishMigrations();
    $this->publishModels();
    $this->publishConfig();
}
```

### 4. The `publishes()` Method

Laravel's `publishes()` method maps source files to destination paths:

```php
protected function publishMigrations(): void
{
    $this->publishes([
        // Source path => Destination path
        __DIR__ . '/../stubs/database/migrations/create_table.stub' =>
            base_path('database/migrations/2020_01_01_000000_create_table.php'),
    ], 'package-tag');
}
```

**Key Points:**
- First array key: Source file path in package
- Array value: Destination path in your application
- Second parameter: Tag name for grouping related assets

### 5. Dynamic File Discovery

For multiple files, packages use dynamic discovery. The `laravel-geonames` package demonstrates this:

```php
protected function stubPaths(string $path): array
{
    $path = trim($path, '/');

    return collect((new Filesystem())->allFiles(__DIR__ . '/../stubs/' . $path))
        ->mapWithKeys(function (SplFileInfo $file) use ($path) {
            return [
                // Source: vendor/package/stubs/database/migrations/file.stub
                $file->getPathname() =>
                    // Destination: database/migrations/file.php
                    base_path($path . '/' . Str::replaceLast('.stub', '.php', $file->getFilename()))
            ];
        })
        ->all();
}
```

**How it works:**
1. Scans the `stubs/` directory recursively
2. Finds all `.stub` files in the specified path
3. Maps each stub to your application directory
4. Converts `.stub` extension to `.php` (or appropriate extension)

---

## Real-World Example: laravel-geonames

Let's trace through how `laravel-geonames` publishes its migrations:

### Step 1: Package Structure

```
vendor/nevadskiy/laravel-geonames/
├── src/
│   └── GeonamesServiceProvider.php
└── stubs/
    └── database/
        └── migrations/
            ├── 2020_06_06_100000_create_continents_table.stub
            ├── 2020_06_06_200000_create_countries_table.stub
            ├── 2020_06_06_300000_create_divisions_table.stub
            └── 2020_06_06_400000_create_cities_table.stub
```

### Step 2: Service Provider Code

```php
// GeonamesServiceProvider.php
public function boot(): void
{
    $this->publishMigrations();
}

protected function publishMigrations(): void
{
    // Calls stubPaths() which discovers all .stub files
    $this->publishes(
        $this->stubPaths('database/migrations'),
        'geonames-migrations'
    );
}

protected function stubPaths(string $path): array
{
    $path = trim($path, '/');

    return collect((new Filesystem())->allFiles(__DIR__ . '/../stubs/' . $path))
        ->mapWithKeys(function (SplFileInfo $file) use ($path) {
            return [
                $file->getPathname() =>
                    base_path($path . '/' . Str::replaceLast('.stub', '.php', $file->getFilename()))
            ];
        })
        ->all();
}
```

### Step 3: Publishing Command

When you run:

```bash
php artisan vendor:publish --tag=geonames-migrations
```

Laravel:
1. Finds all assets tagged with `geonames-migrations`
2. Copies each stub file to your application
3. Converts `.stub` to `.php` extension

### Step 4: Result

Files are copied to your application:

```
database/migrations/
├── 2020_06_06_100000_create_continents_table.php  ← Copied from .stub
├── 2020_06_06_200000_create_countries_table.php   ← Copied from .stub
├── 2020_06_06_300000_create_divisions_table.php   ← Copied from .stub
└── 2020_06_06_400000_create_cities_table.php      ← Copied from .stub
```

---

## Publishing Tags

Packages group related assets using tags. Common patterns:

```php
// Config files
$this->publishes([...], 'package-config');

// Migrations
$this->publishes([...], 'package-migrations');

// Models
$this->publishes([...], 'package-models');

// Seeders
$this->publishes([...], 'package-seeders');

// All assets (for convenience)
$this->publishes([...], 'package');  // Includes all of the above
```

### Publishing Multiple Tags

You can publish multiple tags at once:

```bash
# Publish specific tags
php artisan vendor:publish --tag=geonames-migrations --tag=geonames-models

# Publish all package assets
php artisan vendor:publish --provider="Nevadskiy\Geonames\GeonamesServiceProvider"
```

---

## Common Publishing Patterns

### Pattern 1: Simple Single File

```php
protected function publishConfig(): void
{
    $this->publishes([
        __DIR__ . '/../config/package.php' => config_path('package.php'),
    ], 'package-config');
}
```

### Pattern 2: Multiple Files with Pattern

```php
protected function publishMigrations(): void
{
    $this->publishes(
        $this->stubPaths('database/migrations'),
        'package-migrations'
    );
}

protected function stubPaths(string $path): array
{
    return collect((new Filesystem())->allFiles(__DIR__ . '/../stubs/' . $path))
        ->mapWithKeys(function (SplFileInfo $file) use ($path) {
            return [
                $file->getPathname() =>
                    base_path($path . '/' . Str::replaceLast('.stub', '.php', $file->getFilename()))
            ];
        })
        ->all();
}
```

### Pattern 3: Conditional Publishing

```php
protected function publishAssets(): void
{
    if ($this->app->runningInConsole()) {
        $this->publishes([...], 'package-assets');
    }
}
```

### Pattern 4: Publishing with Merging

For config files that should merge with existing config:

```php
public function boot(): void
{
    // Merge config (allows overriding specific keys)
    $this->mergeConfigFrom(
        __DIR__ . '/../config/package.php',
        'package'
    );

    // Publish config (for full customization)
    $this->publishes([
        __DIR__ . '/../config/package.php' => config_path('package.php'),
    ], 'package-config');
}
```

**Difference:**
- `mergeConfigFrom()`: Merges with existing config, allows partial overrides
- `publishes()`: Copies entire file, replaces existing config

---

## How to Use Published Assets

### 1. Publish Assets

```bash
# Publish specific tag
php artisan vendor:publish --tag=geonames-migrations

# Publish all assets from a provider
php artisan vendor:publish --provider="Nevadskiy\Geonames\GeonamesServiceProvider"

# List all available publishable assets
php artisan vendor:publish
```

### 2. Customize Published Files

Once published, files are in your application and can be modified:

```php
// database/migrations/2020_06_06_400000_create_cities_table.php
Schema::create('cities', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    // Add your custom columns here
    $table->string('custom_field')->nullable();
    // ...
});
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Updates Don't Overwrite

When you update the package, published files remain unchanged. To get updated stubs:

```bash
# Force overwrite existing files (⚠️ will lose customizations)
php artisan vendor:publish --tag=geonames-migrations --force
```

---

## Adding Table Prefixes to Vendor-Published Migrations

When publishing migrations from vendor packages, you may want to add a prefix to table names to follow your project's naming conventions or avoid conflicts with other packages. This process involves modifying the published migration files and related model files.

### Overview

Adding a prefix to vendor-published migrations requires changes across multiple files to ensure consistency:
1. Migration files must use the prefixed table name in the create and drop operations
2. Foreign key references must point to the prefixed table names
3. Model files must specify the prefixed table name explicitly

### Step-by-Step Process

#### Step 1: Identify All Affected Tables

First, identify all tables created by the vendor-published migrations. This typically includes:
- Main entity tables (e.g., continents, countries, divisions, cities)
- Translation tables (e.g., continent_translations, country_translations)
- Any junction or pivot tables

Create a list mapping original table names to their prefixed versions. For example, if using the prefix "geoname_", map each table like "continents" becomes "geoname_continents".

#### Step 2: Identify Foreign Key Relationships

Review all migration files to identify foreign key relationships between tables. These relationships define which tables reference other tables through foreign keys.

For each foreign key relationship, note:
- The source table (the table containing the foreign key)
- The target table (the table being referenced)
- The foreign key column name

You'll need to update all references to point to the prefixed table names.

#### Step 3: Modify Migration Files - Table Names

For each migration file, update the table name in two places:
- The `Schema::create()` statement that creates the table
- The `Schema::dropIfExists()` statement that drops the table in rollback

Change the table name from the original (e.g., "cities") to the prefixed version (e.g., "geoname_cities").

#### Step 4: Modify Migration Files - Foreign Key References

For each foreign key constraint in the migration files, update the reference to point to the prefixed table name.

In the foreign key definition, locate the `on()` method that specifies which table is being referenced. Change this from the original table name to the prefixed version.

#### Step 5: Update Model Files

Locate all Eloquent model files that correspond to the tables you've prefixed. For each model, add a `protected $table` property that explicitly specifies the prefixed table name.

This property tells Eloquent to use the prefixed table name instead of the default convention, which would be based on the model class name.

#### Step 6: Check Translation Models

If the package uses translation models (typically handled by packages like `nevadskiy/laravel-translatable`), verify whether these models need explicit table names specified.

Translation tables typically follow a pattern like "model_translations", so if you prefixed the main table as "geoname_cities", the translation table would be "geoname_city_translations". Ensure translation models reference the correct prefixed translation table names.

#### Step 7: Verify Seeder Compatibility

Check if any seeder files or package code directly references table names. If seeders use Eloquent models, they should work automatically once models are updated. However, if seeders contain raw database queries with hardcoded table names, those will need updating.

#### Step 8: Handle Existing Migrations

If migrations have already been run, you'll need to decide on a migration strategy:

- **Option A: Rollback and Re-run**: Rollback the affected migrations, update the files, then re-run the migrations. This works if you're in development and can afford to lose data.

- **Option B: Create New Migrations**: Create new migrations that rename existing tables using `Schema::rename()` from the old table name to the prefixed version. This preserves existing data but requires careful handling of foreign key constraints during the rename operation.

- **Option C: Manual Database Updates**: Manually rename tables in the database and update foreign key constraints, then update migration files to match. This is more complex but preserves all data.

#### Step 9: Test All Relationships

After making changes, verify that all relationships work correctly:
- Test model relationships (e.g., `$city->country()` should work)
- Verify foreign key constraints are properly established
- Test cascade delete operations if applicable
- Check that translation relationships function correctly

#### Step 10: Update Documentation

Document the prefix you've applied and any deviations from the original package structure. This helps future developers understand why tables are prefixed and ensures consistency if more tables are added later.

#### Step 11: Plan for Package Updates

When the vendor package is updated and new migrations are published, you'll need to:
- Review new migration files for additional tables that need prefixing
- Check if any new foreign key relationships were added
- Update new migration files with your prefix before running them
- Verify that new models (if published) also include the table prefix property

### Considerations

**Naming Convention Compliance**: Adding prefixes helps comply with project naming conventions, especially when mixing tables from multiple packages or when a naming standard requires prefixes for third-party tables.

**Conflict Prevention**: Prefixes prevent naming conflicts between different packages or between package tables and your application's core tables.

**Maintenance Overhead**: Adding prefixes means you'll need to maintain these changes when the package is updated. Each update requires reviewing new migrations and applying the prefix consistently.

**Testing Requirements**: After adding prefixes, thoroughly test all functionality that interacts with these tables to ensure nothing breaks due to the table name changes.

**Documentation Importance**: Keep clear documentation of your prefix choice and how to apply it to new migrations from package updates. This ensures consistency across the team and over time.

---

## Stub File Format

Stub files are regular PHP files with `.stub` extension. They become actual files when published:

**Stub file** (`create_table.stub`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{table_name}}', function (Blueprint $table) {
            $table->id();
            // ...
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{table_name}}');
    }
};
```

**Published file** (`create_table.php`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            // ...
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
```

**Note:** Some packages use template placeholders (like `{{table_name}}`) and replace them during publishing. Laravel's publishing system doesn't do this automatically; packages need custom logic for placeholders.

---

## Best Practices

### For Package Developers

1. **Use Descriptive Tags**: Tag names should clearly identify what they publish
   ```php
   'package-migrations'  ✅ Good
   'migrations'          ❌ Too generic (conflicts with other packages)
   ```

2. **Group Related Assets**: Use consistent naming
   ```php
   'package-migrations'
   'package-models'
   'package-seeders'
   'package-config'
   ```

3. **Provide Defaults**: Always include sensible defaults in stubs
4. **Document Publishing**: Clearly document what gets published and why
5. **Use Versioned Paths**: Include version/dates in migration filenames

### For Package Users

1. **Publish After Install**: Publish assets immediately after installing a package
2. **Review Published Files**: Check what was published and understand the structure
3. **Customize Carefully**: Document any customizations you make
4. **Version Control**: Commit published files to your repository
5. **Handle Updates**: Review package updates for new stubs, merge manually if needed

---

## Common Publishing Scenarios

### Scenario 1: Installing a Package

```bash
# 1. Install package
composer require vendor/package-name

# 2. Publish assets
php artisan vendor:publish --tag=package-migrations
php artisan vendor:publish --tag=package-config

# 3. Review and customize
# Edit published files as needed

# 4. Run migrations
php artisan migrate
```

### Scenario 2: Updating a Package

```bash
# 1. Update package
composer update vendor/package-name

# 2. Check for new migrations/assets
php artisan vendor:publish

# 3. Publish new assets if needed
php artisan vendor:publish --tag=package-migrations

# 4. Review changes (diff published files with new stubs)
# Merge any new features from updated stubs

# 5. Run new migrations
php artisan migrate
```

### Scenario 3: Multiple Environments

```bash
# Development: Publish and customize
php artisan vendor:publish --tag=package-migrations

# Production: Just publish (don't customize)
php artisan vendor:publish --tag=package-migrations --force
```

---

## Troubleshooting

### Files Not Published

**Problem**: Running `vendor:publish` but files don't appear

**Solutions**:
1. Check Service Provider is registered:
   ```bash
   php artisan package:discover
   ```
2. Verify tag name:
   ```bash
   php artisan vendor:publish  # Lists all available tags
   ```
3. Check file permissions on destination directory

### Files Overwritten After Update

**Problem**: Package update overwrote customizations

**Cause**: Files in `vendor/` are overwritten, but published files should remain untouched

**Solution**: Only published files in your application directory are safe. Files modified in `vendor/` will be overwritten. Always publish and modify in your application.

### Conflicting Migrations

**Problem**: Multiple packages publish migrations with same timestamp

**Solution**: Manually adjust timestamps after publishing:
```bash
# Rename to avoid conflicts
mv database/migrations/2020_01_01_000000_package1_table.php \
   database/migrations/2020_01_01_000100_package1_table.php
```

---

## Summary

The Laravel package asset publishing pattern:

1. **Stores templates** as `.stub` files in package `stubs/` directory
2. **Registers assets** in Service Provider's `boot()` method using `publishes()`
3. **Uses tags** to group related publishable assets
4. **Discovers files** dynamically using `Filesystem::allFiles()`
5. **Copies to application** when `vendor:publish` command is run
6. **Allows customization** of published files without affecting package updates

This pattern enables packages to provide default implementations while giving developers full control to customize as needed.

---

## Related Documentation

- [Laravel Package Development](https://laravel.com/docs/packages) - Official Laravel package development guide
- [Database Migrations Workflow](../database/MIGRATION_WORKFLOW.md) - How migrations work in this project
- [Extension Database Migrations](../extensions/database-migrations.md) - Publishing migrations in extensions
- [Laravel Service Providers](https://laravel.com/docs/providers) - Understanding Service Providers
