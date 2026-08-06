# Foundation Agent Guidelines

## Service Provider Independence

`App\Base\Foundation\ApplicationTopology` is the single source of truth for the four application roots. `App\Base\Foundation\Providers\ProviderRegistry` discovers providers in this deterministic order:

1. Base components: `app/Base/*/ServiceProvider.php`
2. Core modules: `app/Core/*/ServiceProvider.php`
3. enabled Domain modules: `app/Domains/*/*/ServiceProvider.php`
4. Extension modules: `app/Extensions/*/*/ServiceProvider.php`

Because discovery is automatic, providers must be **independent by default**:
- Do not rely on another provider being manually registered in bootstrap.
- Prefer contracts and adapter bindings over direct module-to-module coupling.
- Provide safe local defaults in each module so it can boot in isolation.
- Treat provider ordering as a deterministic framework contract, not a hidden dependency.
- Route all new discovery patterns through `ApplicationTopology`; do not reconstruct root paths locally.
- Apply `DomainState` filtering to every optional-Domain discovery surface. Disabling a Domain must exclude all of its contributions, not only its provider.

When cross-module integration is needed, invert dependencies through contracts owned by the consuming module.
