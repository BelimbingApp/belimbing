# FEAT-DISCOVERY

Intent: extend BLB auto-discovery infrastructure instead of adding manual module registration.

## When To Use

- Adding framework-level discovery for routes, menus, providers, or Livewire components.
- Standardizing how modules are detected from path conventions.

## Do Not Use When

- Module can use existing discovery patterns without framework changes.
- Change is feature-local and does not affect framework bootstrapping.

## Minimal File Pack

- `app/Base/Foundation/Providers/ProviderRegistry.php`
- `app/Base/Routing/RouteDiscoveryService.php`
- `app/Base/Menu/Services/MenuDiscoveryService.php`

## Reference Shape

- Discovery services expose `discover()` returning structured paths or mappings.
- Service providers consume discovery output and register resources.
- Sorting and deterministic order are enforced before registration.
- Validation runs at registry level (example: menu circular parent checks).

## Required Invariants

- Deterministic load order across runs.
- Independent module boot where possible; no hidden provider order assumptions.
- Fail fast on invalid provider classes.
- Prefer contracts and adapters over direct cross-module coupling.

## Implementation Skeleton

```php
public function discover(): array
{
    $items = [];

    foreach ($this->scanPatterns as $pattern) {
        foreach (glob(base_path($pattern)) ?: [] as $path) {
            $items[] = $path;
        }
    }

    sort($items);

    return $items;
}
```

## Test Checklist

- Newly placed module file is discovered without manual registration.
- Discovery order is stable.
- Invalid file/class handling fails clearly or logs deterministic warning.
- Existing modules remain loadable after changes.

## Common Pitfalls

- Adding one-off manual registration that bypasses discovery contract.
- Non-deterministic ordering causing flaky boot behavior.
- Scanning overly broad paths that increase startup cost.
