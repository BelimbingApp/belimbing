# FEAT-DISCOVERY

Intent: extend BLB auto-discovery while preserving the centralized four-root topology and complete optional-Domain state filtering.

## When To Use

- Adding framework-level discovery for providers, routes, menus, config contributions, migrations, seeders, Livewire components, tests, views, assets, or agent skills.
- Standardizing how artifacts are found across more than one application root.
- Adding an explicit Domain- or Extension-level contribution anchor above Module roots.

## Do Not Use When

- A Module can use an existing discovery contract without changing framework behavior.
- The change is local to one Module and its provider can register it through an existing supported seam.
- The proposal creates a fifth application root or an environment-specific topology.

## Minimal File Pack

- `docs/architecture/module-system.md`
- `app/Base/Foundation/ApplicationTopology.php`
- `app/Base/Foundation/Services/DomainState.php`
- `app/Base/Foundation/Providers/ProviderRegistry.php`
- the owning discovery service and its focused test

## Reference Shape

- `ApplicationTopology` defines all root and contribution patterns.
- Discovery preserves Base → Core → enabled Domains → Extensions order.
- `DomainState` removes disabled optional-Domain paths from every runtime surface.
- Discovery methods return structured paths or mappings; their owning provider registers the result.
- Paths are sorted deterministically before registration.
- Registries reject ambiguous duplicate identities rather than letting load order decide silently.

## Contribution Locations

| Ownership kind | Shape | Namespace |
|---|---|---|
| Base component | `app/Base/{Component}` | `App\Base\{Component}` |
| Core Module | `app/Core/{Module}` | `App\Core\{Module}` |
| optional Domain Module | `app/Domains/{Domain}/{Module}` | `App\Domains\{Domain}\{Module}` |
| Extension Module | `app/Extensions/{Extension}/{Module}` | `App\Extensions\{Extension}\{Module}` |

Physical ownership segments are PascalCase. Stable logical IDs remain kebab-case and path-independent.

## Module Config Discovery Convention

A Module influences framework behavior by declaring the documented `Config/{name}.php` file locally. Framework discovery merges only the keys that surface owns, so Modules do not edit Base config.

| Framework surface | Discovered file | Typical keys | Owning discovery |
|---|---|---|---|
| Authz | `Config/authz.php` | `domains`, `capabilities`, `roles` | `Authz\ServiceProvider` |
| Menu | `Config/menu.php` | `items` | `Menu\Services\MenuDiscoveryService` |
| Audit | `Config/audit.php` | `exclude_models` | `Audit\ServiceProvider` |
| Settings | `Config/settings.php` | setting definitions and runtime claims | `Settings\ServiceProvider` |

Use `ApplicationTopology::contributionPatterns('Config/{name}.php')` when the surface supports the standard four-root Module shape. If a surface also supports a Domain or Extension source anchor, add `domainPattern()` or `extensionSourcePattern()` explicitly and document why collection-level ownership is necessary. A source anchor is not an implicit Module.

## Required Invariants

- Exactly four application roots: Base, Core, Domains, Extensions.
- Deterministic Base → Core → enabled Domains → Extensions order.
- Complete `DomainState` filtering for optional-Domain contributions.
- Independent provider boot where practical; no hidden ordering dependency.
- Extension-last loading is used only through explicit contribution or decoration seams.
- Fail fast on invalid provider classes and duplicate stable identities.
- No local string globs that reconstruct topology already expressed by `ApplicationTopology`.
- No manual central registration list for a convention-discoverable artifact.

## Implementation Skeleton

```php
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;

public function discover(): array
{
    $items = [];

    foreach (ApplicationTopology::contributionPatterns('Config/example.php') as $pattern) {
        foreach (DomainState::filterPaths(glob($pattern) ?: []) as $path) {
            $items[] = $path;
        }
    }

    $items = array_values(array_unique($items));
    sort($items);

    return $items;
}
```

If the discovery result must preserve root precedence after sorting, sort within each pattern and append the groups in `ApplicationTopology` order instead of globally sorting the merged list.

## Test Checklist

- A conforming artifact in each supported root is discovered without manual registration.
- Base, Core, Domain, and Extension contributions resolve in the documented order.
- A disabled optional Domain contributes nothing to the new surface.
- Re-enabling the Domain restores discovery without editing its files.
- Invalid or duplicate contributions fail clearly and deterministically.
- Unsupported source-level files remain inert.
- Existing Modules remain loadable after the change.

## Common Pitfalls

- Adding a one-off glob for only the Module that motivated the feature.
- Forgetting Core because it no longer sits inside the optional Domain collection.
- Treating an Extension root as a strict business Domain.
- Filtering providers for disabled Domains while still loading their routes, config, or migrations.
- Globally sorting paths and accidentally erasing the four-root precedence contract.
- Assuming an installed nested repository is visible to Tailwind or Vite without an explicit source/refresh path.
