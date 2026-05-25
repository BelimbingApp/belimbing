# Extension Configuration Management

This document explains how extensions can add or override configuration in Belimbing.

## Overview

Extensions can modify configuration through several methods:
1. **Service Provider Config Merging** - Merge additional config at runtime
2. **Config File Publishing** - Publish and modify config files
3. **Runtime Config Override** - Set config values programmatically
4. **Environment-Based Overrides** - Use `.env` variables

---

## Method 1: Service Provider Config Merging (Recommended)

Extensions can merge their own configuration arrays into existing config files during the service provider's `boot()` method.

### Example: Adding Relationship Types

An extension can add new relationship types to the `company` config:

```php
<?php

namespace Extensions\SbGroup\Quality;

use Illuminate\Support\ServiceProvider;

class ServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Merge additional relationship types into company config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/quality.php',
            'company'
        );

        // Or merge programmatically
        $existingTypes = config('company.relationship_types', []);
        $extensionTypes = [
            [
                'code' => 'vendor',
                'name' => 'Vendor',
                'description' => 'Vendor relationship - consolidated supplier and agency',
                'is_external' => true,
                'is_active' => true,
                'metadata' => [
                    'allows_data_sharing' => true,
                    'default_permissions' => [
                        'view_purchase_orders',
                        'submit_invoices',
                        'view_shipments',
                        'submit_documents',
                    ],
                ],
            ],
        ];

        // Merge arrays (extension types take precedence for duplicates)
        config([
            'company.relationship_types' => array_merge($existingTypes, $extensionTypes),
        ]);
    }
}
```

### Merging Strategy

**Append to Arrays:**
```php
$existing = config('company.relationship_types', []);
$additional = [
    ['code' => 'new_type', 'name' => 'New Type'],
];
config(['company.relationship_types' => array_merge($existing, $additional)]);
```

**Override Specific Keys:**
```php
$existing = config('company.relationship_types', []);
// Update existing type
foreach ($existing as &$type) {
    if ($type['code'] === 'supplier') {
        $type['description'] = 'Updated description';
        break;
    }
}
config(['company.relationship_types' => $existing]);
```

**Complete Override:**
```php
// Replace entire config section
config(['company.relationship_types' => $newTypesArray]);
```

---

## Method 2: Config File Publishing

Extensions can publish their own config files that override or extend base config.

### Step 1: Create Config File in Extension

```
extensions/sb-group/qac/
├── Config/
│   └── qac.php                # Extension's config file (PascalCase dir, lowercase file)
└── ServiceProvider.php
```

### Step 2: Publish Config in Service Provider

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
        // Merge config during registration (before boot)
        $this->mergeConfigFrom(
            __DIR__ . '/Config/quality.php',
            'company'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config file (allows adopters to customize)
        $this->publishes([
            __DIR__ . '/Config/quality.php' => config_path('quality.php'),
        ], 'quality-config');
    }
}
```

### Step 3: Extension Config File Structure

```php
<?php
// extensions/sb-group/qac/Config/qac.php

return [
    'relationship_types' => [
        // Add new types or override existing ones
        [
            'code' => 'vendor',
            'name' => 'Vendor',
            'description' => 'Vendor relationship',
            'is_external' => true,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => ['view_orders', 'submit_invoices'],
            ],
        ],
    ],
];
```

**Note:** When using `mergeConfigFrom()`, Laravel automatically merges arrays recursively. Extension config values will be merged with base config, not replace it entirely.

---

## Method 3: Runtime Config Override

Extensions can override config values programmatically at runtime.

### Setting Config Values

```php
public function boot(): void
{
    // Override specific config value
    config(['company.relationship_types' => $customTypes]);

    // Or use the Config facade
    \Illuminate\Support\Facades\Config::set(
        'company.relationship_types',
        $customTypes
    );
}
```

### Conditional Override Based on Environment

```php
public function boot(): void
{
    // Only override in specific environments
    if (app()->environment('local', 'staging')) {
        $types = config('company.relationship_types', []);
        // Add debug/test types
        $types[] = [
            'code' => 'test',
            'name' => 'Test Company',
            // ...
        ];
        config(['company.relationship_types' => $types]);
    }
}
```

---

## Method 4: Environment-Based Overrides

Extensions can read from environment variables with sensible defaults.

### In Extension Config File

```php
<?php
// extensions/sb-group/qac/Config/qac.php

return [
    'relationship_types' => [
        [
            'code' => 'vendor',
            'name' => env('COMPANY_VENDOR_NAME', 'Vendor'),
            'description' => env('COMPANY_VENDOR_DESCRIPTION', 'Vendor relationship'),
            'is_external' => env('COMPANY_VENDOR_EXTERNAL', true),
            'is_active' => env('COMPANY_VENDOR_ACTIVE', true),
            'metadata' => [
                'api_key' => env('COMPANY_VENDOR_API_KEY'),
            ],
        ],
    ],
];
```

---

## Best Practices

### 1. Prefer Merging Over Replacing

```php
// ✅ Merge with existing config
$existing = config('company.relationship_types', []);
config(['company.relationship_types' => array_merge($existing, $newTypes)]);

// ❌ Replacing entire config (may break other extensions)
config(['company.relationship_types' => $newTypes]);
```

### 2. Use Namespaced Config Keys

```php
// ✅ Extension-specific config key
config(['quality.vendor_timeout' => 30]);

// ❌ Generic key that may collide
config(['timeout' => 30]);
```

### 3. Document Configuration Options

Always document what configuration your extension adds or modifies:

```php
/**
 * Merge extension configuration.
 *
 * This extension adds:
 * - 'vendor' relationship type to company.relationship_types
 * - 'api_timeout' setting to company.metadata
 */
public function register(): void
{
    $this->mergeConfigFrom(__DIR__ . '/Config/quality.php', 'company');
}
```

### 4. Provide Sensible Defaults

Always provide default values so the extension works out of the box:

```php
return [
    'relationship_types' => [
        [
            'code' => 'vendor',
            'name' => env('VENDOR_TYPE_NAME', 'Vendor'), // Default provided
            // ...
        ],
    ],
];
```

### 5. Allow Adopter Customization

Publish config files so adopters can customize:

```php
public function boot(): void
{
    $this->publishes([
        __DIR__ . '/Config/quality.php' => config_path('quality.php'),
    ], 'quality-config');
}
```

Adopters can then:
```bash
php artisan vendor:publish --tag=quality-config
```

And edit `config/quality.php` directly.

---

## Complete Example: Quality Extension

Here's a complete example of an extension that adds a "vendor" relationship type:

### Extension Structure

```
extensions/sb-group/qac/
├── Config/
│   └── qac.php
└── ServiceProvider.php
```

### Service Provider

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
        // Merge extension config with base config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/quality.php',
            'company'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config for adopter customization
        $this->publishes([
            __DIR__ . '/Config/quality.php' => config_path('quality.php'),
        ], 'quality-config');

        // Optionally remove 'supplier' and 'agency' if vendor replaces them
        if (config('company.replace_with_vendor', false)) {
            $types = collect(config('company.relationship_types', []))
                ->reject(fn($type) => in_array($type['code'], ['supplier', 'agency']))
                ->values()
                ->toArray();
            config(['company.relationship_types' => $types]);
        }
    }
}
```

### Extension Config File

```php
<?php

return [
    'relationship_types' => [
        [
            'code' => 'vendor',
            'name' => 'Vendor',
            'description' => 'Vendor relationship - consolidated supplier and agency relationships',
            'is_external' => true,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => [
                    'view_purchase_orders',
                    'submit_invoices',
                    'view_shipments',
                    'submit_documents',
                ],
            ],
        ],
    ],

    // Extension-specific config
    'replace_with_vendor' => env('COMPANY_REPLACE_WITH_VENDOR', false),
];
```

### Registering the Extension Service Provider

Extension providers are registered via `ProviderRegistry::resolve()` in `bootstrap/providers.php`:

```php
<?php

use App\Base\Foundation\Providers\ProviderRegistry;

return ProviderRegistry::resolve(
    appProviders: [
        App\Providers\AppServiceProvider::class,
        \Extensions\SbGroup\Quality\ServiceProvider::class,
    ]
);
```

The `ProviderRegistry` ensures a deterministic boot order: priority providers → Base infrastructure → business modules → app providers. Extension providers listed in `appProviders` load after all framework and module providers.

---

## Configuration Priority

When multiple extensions modify the same config, the order matters:

1. **Base config file** (`config/company.php`) - Loaded first
2. **Service Provider `register()`** - Merges in registration order
3. **Service Provider `boot()`** - Can override merged values
4. **Runtime `config()->set()`** - Highest priority, overrides everything

**Example:**
```
Base config → Extension A merges → Extension B merges → Extension C overrides
```

---

## Testing Configuration

Test that your extension properly merges/overrides config:

```php
<?php

namespace Tests\Extensions\SbGroup\Quality;

use Tests\TestCase;
use Illuminate\Support\Facades\Config;

class QualityConfigTest extends TestCase
{
    public function test_vendor_type_is_added(): void
    {
        $types = config('company.relationship_types', []);
        $codes = array_column($types, 'code');

        $this->assertContains('vendor', $codes);
    }

    public function test_base_types_are_preserved(): void
    {
        $types = config('company.relationship_types', []);
        $codes = array_column($types, 'code');

        $this->assertContains('customer', $codes);
        $this->assertContains('partner', $codes);
    }
}
```

---

## Summary

Extensions can modify configuration through:

1. ✅ **`mergeConfigFrom()`** - Best for merging arrays
2. ✅ **`config()->set()`** - For runtime overrides
3. ✅ **Publishing config files** - Allows adopter customization
4. ✅ **Environment variables** - For environment-specific values

Always:
- Document what config your extension modifies
- Provide sensible defaults
- Allow adopter customization
- Test configuration merging
