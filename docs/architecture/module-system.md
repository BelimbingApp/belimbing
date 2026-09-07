# Domain and Module System

**Document Type:** Architecture Specification
**Scope:** Application-code ownership, Domain and Module boundaries, lifecycle, discovery, variation, and delivery provenance
**Based On:** `docs/architecture/decisions/0001-four-root-application-topology.md`, `docs/brief.md`, and Ousterhout's *A Philosophy of Software Design*
**Last Updated:** 2026-09-06
**Related:** `docs/architecture/database.md`, `docs/architecture/people-connector.md`, `docs/architecture/settings.md`, `docs/modules/`, `docs/guides/extensions/private-extension-repositories.md`, `docs/guides/extensions/database-migrations.md`

## Overview

Belimbing organizes application code by ownership and change boundary:

- **Base** supplies framework infrastructure and cross-cutting platform mechanisms.
- **Core** is the required, platform-owned enterprise Domain.
- **Domains** are optional enterprise areas that operators can install, enable, disable, update, and uninstall.
- **Extensions** are deployment-owned customizations with intentionally relaxed semantic-placement rules.
- **Modules** are the full-stack ownership boundaries inside Core, Domains, and Extensions.

Application-owned PHP code has exactly four first-level roots below `app/`. The same topology applies in development, testing, and production; environment-specific root shapes are not supported.

The Platform Baseline is **Base + Core**. A fresh platform checkout can boot with that baseline alone. Optional Domains and Extensions compose additional capabilities without changing the baseline's ownership model.

### Vocabulary

| Term | Meaning |
|---|---|
| **Platform Baseline** | Base plus Core: the required code shipped and updated with the Belimbing platform. |
| **Base component** | A framework-infrastructure boundary below `app/Base`, such as Database, Menu, or Settings. It is not an enterprise Domain. |
| **Domain** | A coherent enterprise area containing one or more Modules. Core is the required Domain; other Domains are optional lifecycle units. |
| **Module** | A full-stack ownership and change boundary inside Core, a Domain, or an Extension. A Module may own code, schema, config, routes, views, assets, tests, and public contracts. |
| **Extension** | A deployment-owned collection of one or more Modules. It may be an overlay, adapter set, cross-Domain composition, or complete private capability. Its semantic placement is deliberately flexible. |
| **Source / repository** | Internal delivery and provenance detail: where a Domain or Extension came from and which revision is installed. It is not a business ownership boundary or the primary operator noun. |
| **Adapter** | A contribution implementing a stable host contract so deployments can vary rules or integrations without replacing the host Module. |
| **Slot** | A Module identity whose entire implementation may be supplied by one of several mutually exclusive sources. |

Domain describes business ownership. Module describes the contained implementation boundary. Source or repository describes delivery mechanics. Keep those concerns separate in code, persisted data, documentation, and operator UI.

## Four-root Topology

| Root | Namespace | Contains | Lifecycle |
|---|---|---|---|
| `app/Base/{Component}` | `App\Base\{Component}` | Framework infrastructure and shared platform mechanisms | Required; ships with the platform |
| `app/Core/{Module}` | `App\Core\{Module}` | Modules in the required Core Domain | Required; ships with the platform |
| `app/Domains/{Domain}/{Module}` | `App\Domains\{Domain}\{Module}` | Modules in an optional enterprise Domain | Installable, enableable, disableable, updateable, uninstallable |
| `app/Extensions/{Extension}/{Module}` | `App\Extensions\{Extension}\{Module}` | Deployment-owned Modules with relaxed semantic placement | Installed or removed as deployment-owned code; updateable by its source |

`App\Base\Foundation\ApplicationTopology` is the canonical implementation of these roots and their contribution patterns. Discovery services consume it rather than rebuilding path literals locally.

### Why Core is separate

Core is a Domain because it requires enterprise-domain knowledge and contains full-stack Modules such as Company, Employee, and User. Its dedicated root records a different ownership and lifecycle policy:

- Core is platform-owned.
- Core is always enabled.
- Core cannot be installed, disabled, or removed independently.
- Core updates with the Platform Baseline.

Putting Core under Base would incorrectly classify enterprise behavior as framework infrastructure. Putting it in the optional Domain collection would force every installer, state check, and discovery mechanism to special-case Core. The separate root keeps the logical Domain model and the physical lifecycle model truthful at the same time.

### Domain shape

An optional Domain root contains Modules and may also contain Domain-level metadata, tests, documentation, or contribution anchors when a discovery contract explicitly supports them:

```text
app/Domains/People/
├── Config/                 # optional Domain-level contribution anchor
├── Tests/                  # optional cross-Module Domain tests
├── Attendance/
├── Claim/
├── Leave/
└── Payroll/
```

The Domain is the lifecycle unit. Its Modules are the ownership boundaries. Installing or disabling `People` affects the whole Domain; it does not imply that each contained Module has an independent toggle.

Provider integration does not make two ownership boundaries one Domain. `PeopleConnector` is a separate optional Domain mounted at `app/Domains/PeopleConnector/`, with its own install, enable, update, disable, and uninstall lifecycle. It composes a selected HR provider through an anti-corruption boundary and owns provider connections and synchronization; it is not a Module inside `People` and is not a deployment-specific Extension. See `docs/architecture/people-connector.md` for the provider, transport, and data-ownership contract.

### Extension shape

Extensions are a deliberate escape hatch for deployment-owned composition:

```text
app/Extensions/SbGroup/
├── Config/                 # optional Extension-level contribution anchor
├── .agents/                # optional source-owned agent guidance
├── Qac/
└── Reporting/
```

An Extension may cross enterprise boundaries or contain customer-specific behavior. Belimbing does not force it into a strict Domain taxonomy. Relaxed placement does not relax authorization, tenancy, data safety, dependency declarations, test quality, or discovery rules.

## Naming and Stable Identity

### Physical names

Application ownership segments use PascalCase:

- Base components: `Foundation`, `Database`, `Menu`
- Core Modules: `Company`, `Employee`, `Geonames`
- Domains: `People`, `PeopleConnector`, `Commerce`, `Operation`
- Extension roots: `Ham`, `Kiat`, `SbGroup`
- Modules: `Payroll`, `AutoParts`, `Qac`
- Module-internal directories: `Config`, `Database`, `Models`, `Services`, `Views`

Conventional repository metadata retains its native spelling, including `.github`, `.agents`, `docs`, `composer.json`, and `README.md`. Config filenames use lowercase Laravel-style keys such as `Config/menu.php` and `Config/settings.php`.

Use singular PascalCase capability names for new Modules unless the domain term is inherently plural or the Module is an established aggregate workbench. User-facing labels and URL paths may still be plural.

### Logical identities

Persisted and external identities are stable, lowercase, kebab-case, and independent of the current filesystem or PHP namespace:

| Ownership path | Stable Module ID |
|---|---|
| `app/Core/Company` | `core/company` |
| `app/Domains/People/Payroll` | `people/payroll` |
| `app/Domains/PeopleConnector/Connector` | `people-connector/connector` |
| `app/Extensions/SbGroup/Qac` | `sb-group/qac` |

Do not derive durable identity by lowercasing an absolute path or serializing a checkout location. A physical move or namespace migration must not silently create a different Module.

When present, `composer.json` → `extra.blb.module` is authoritative. For Modules without a manifest, the framework may calculate the same conventional identifier from the four-root topology as a compatibility fallback. Once a manifest declares an ID, the path-derived value is not a second alias.

PHP class names are executable implementation references, not business identities. Persist a stable ID or purpose-built value object when execution does not require a class name. Long-lived serialized class references require an explicit compatibility and migration policy when namespaces change.

## Source, Repository, and Lifecycle

A source or repository records delivery provenance: remote/package identity, branch or release, revision, update state, and checkout health. It may contain one Domain, one Extension, or—in the Extension case—multiple otherwise unrelated Modules. Git ownership does not redefine logical ownership.

Nested Git repositories are the current composition mechanism. A future package-based mechanism is valid if it preserves:

- the four-root mounted path;
- the `App\` namespace contract;
- stable Domain and Module IDs;
- manifest and dependency semantics;
- owned views, assets, config, tests, and migrations;
- the same discovery surfaces and deterministic order.

Operator information architecture presents **Domains** first and shows their contained **Modules**. Source/repository detail appears only where installation, updates, revision health, or diagnostics require it.

### Core lifecycle

Core is always present and enabled. It has no independent install, disable, or uninstall lifecycle. Changes to Core ship as platform changes.

### Optional Domain lifecycle

An optional Domain is mounted at `app/Domains/{Domain}`.

- **Installed and enabled:** its conforming contributions participate in discovery.
- **Disabled:** its checkout remains, but all runtime contribution surfaces are excluded through `DomainState`; persistent data remains.
- **Updated:** its source advances and pending migrations run through the platform migration workflow.
- **Uninstalled:** its checkout is removed. Persistent data remains unless the operator explicitly chooses cleanup.

Removing code and deleting durable state are separate decisions. Database residue tooling compares installed code claims with existing tables, settings, and migration ledger entries so cleanup can be deliberate.

### Extension lifecycle

An Extension is mounted at `app/Extensions/{Extension}`. Presence controls installation: conforming Modules are discovered when the source exists and disappear from discovery when it is removed. Extension installation, update, migration, and cleanup use the same data-safety rules as optional Domains. Extensions do not inherit optional-Domain enable/disable semantics unless the platform later defines that lifecycle explicitly.

## Module Ownership

A Module directory is the full-stack ownership boundary. It includes only the surfaces it needs.

| Internal path | Ownership contract |
|---|---|
| `ServiceProvider.php` | Marks a provider-discoverable Module and registers behavior not covered by framework scanners. |
| `Database/Migrations/` | Schema owned by the Module and loaded by the platform migration flow. Laravel/bootstrap tables may remain in `database/migrations/`. |
| `Database/Seeders/`, `Database/Factories/` | Module-owned production/development data and factories. Follow `app/Base/Database/AGENTS.md`. |
| `Config/` | Structural contributions and Module config. Framework scanners merge only documented files. |
| `Routes/` | Web and API routes owned by the Module. |
| `Views/` | Module-owned Blade presentation, registered by the owning provider when a view namespace is needed. |
| `Assets/` | Optional frontend source. Assets are never injected globally without an explicit reviewed build entry/import. |
| `Models/`, `Services/`, `Livewire/`, `Events/`, `Listeners/`, `Contracts/`, `Http/` | Module implementation and public seams. |
| `Tests/` | Tests that travel with an optional Domain or Extension Module. |
| `composer.json` | Optional metadata declaring stable identity, version, dependencies, and published/consumed events. |

An entity is not automatically a Module. It is a domain object owned by a Module and may appear in models, tables, routes, UI, factories, and seeders. Split a Module when ownership, lifecycle, consumers, or regulatory burden justify a separate change boundary—not merely because another noun appears.

### Module manifests

The optional `extra.blb` manifest may declare:

- `module`: stable Module ID;
- `version`: Module contract version;
- `requires-modules`: hard Module dependencies and version constraints;
- `optional-modules`: integrations that may be absent;
- `publishes-events` and `consumes-events`: cross-Module event surfaces;
- `shared-tables`: tables explicitly exported to directly requiring Modules for raw-query access;
- `feature-flags`: map of stable flag identities to `{ default, description }` for per-tenant resolution through `App\Base\FeatureFlags\Services\FeatureFlags` (undeclared names refuse; `blb:feature-flags` lists the set).

Manifests support inventory, dependency health, migration preflight, and feature-flag discovery. They do not replace Composer's PHP dependency resolution, provider independence, or runtime authorization.

`requires-modules` is checked during provider resolution and again before Module-aware migration commands run. A required optional Domain must be installed and enabled. Non-wildcard constraints require the depended-on Module to publish a compatible version. Migration filename ordering must also keep requiring Modules after the migrations they depend on; see `docs/architecture/database.md`.

Per-migration schema maturity remains declared beside the migration through `IncubatingSchema`. Do not duplicate individual migration maturity in a package or source manifest.

### Module feature flags

The declaration, tenant resolution, override storage, and listing command shipped
together in [PR #660](https://github.com/BelimbingApp/belimbing/pull/660).
Declare flags in the owning Module's `composer.json` under
`extra.blb.feature-flags`. Each stable flag identity maps to an object with a
boolean `default` and a human-readable `description`, for example:

```json
"feature-flags": {
  "demo.preview": { "default": false, "description": "Enable the demo preview" }
}
```

This fragment belongs inside `extra.blb`; it is not a top-level Composer key.
Use explicit booleans. The [manifest reader](../../app/Base/Foundation/ModuleManifest/ModuleManifestReader.php)
normalizes an omitted default to `false` and an omitted description to an empty
string. The [registry](../../app/Base/FeatureFlags/Services/FeatureFlagRegistry.php)
collects declarations through the application topology and manifest reader,
excludes disabled optional Domains, and sorts by flag identity. Identities must
be unique across Modules: duplicates throw `DuplicateFeatureFlagException`.

Inject [FeatureFlags](../../app/Base/FeatureFlags/Services/FeatureFlags.php)
(`App\Base\FeatureFlags\Services\FeatureFlags`) into consumers and call
`enabled('demo.preview')`. Resolution requires the current tenant through
`TenantContext`; there is no implicit tenant or caller-supplied tenant argument.
For that tenant, a persisted override takes precedence over the descriptor
default, including an explicit `false` override of a `true` default.
`override('demo.preview', true)` stores an override and
`clearOverride('demo.preview')` restores the declared default. Storage is
[`base_feature_flag_overrides`](../../app/Base/FeatureFlags/Database/Migrations/0100_01_27_000000_create_base_feature_flag_overrides_table.php),
with one row per `(tenant_id, flag)`. Reads fall back to defaults when the service
cannot establish that the override table exists; writes require the migration.
These service methods do not authorize an operator: the calling application
surface must enforce its own authorization before changing overrides. Flags do
not replace access-control checks.

Reading, overriding, or clearing an undeclared name throws
`UndeclaredFeatureFlagException` with `Feature flag [<name>] is not declared in
any module descriptor.` Declare the flag in an enabled owning Module and correct
the consumer's name; an undeclared flag is not treated as disabled.

For operators, `php artisan blb:feature-flags` lists Flag, Module, Default,
Enabled, Overridden, and Description for the current tenant;
`php artisan blb:feature-flags --json` emits the same resolved inventory as
`flag`, `module`, `default`, `enabled`, `overridden`, and `description` fields.
The [command](../../app/Base/FeatureFlags/Console/Commands/ListFeatureFlagsCommand.php)
has no tenant-selection or mutation option: the invoking runtime must already
establish tenant context. Without it, the command exits with failure and
`No tenant is in context. Set a tenant before listing feature flags.` An empty
declared set succeeds (an explanatory table-mode message or `[]` in JSON).

Operators also have an Administration surface at
`admin/system/feature-flags` ([Livewire Index](../../app/Base/FeatureFlags/Livewire/Index.php)):
list declared flags with module, default, effective value, and override state for
the current tenant; toggle or clear writes through `FeatureFlags` (so undeclared
names still refuse) and records mutation audit rows on
`FeatureFlagOverride` keyed as `feature-flag / <name>`. Route capability
`admin.system.feature-flags.view`; mutations need
`admin.system.feature-flags.manage`.

## Discovery Contract

Discovery is convention-based, centralized, deterministic, and ownership-aware. Adding a conforming Module integrates its supported surfaces without editing a central registration list.

The universal runtime order is:

1. Base
2. Core
3. enabled Domains
4. Extensions

Within each root, providers follow the topological order of `requires-modules`, using alphabetical filesystem order among ready modules. Providerless modules remain in the graph so transitive requirements are honored. Cycles fail before provider registration with the cycle identities; a requirement on a later root is refused rather than inverting the four-root contract. Missing, disabled, or version-incompatible required modules also fail resolution. Rebuild cached configuration after changing manifests, as for provider changes. Foundation logs the loaded provider sequence at debug after boot, including boots using cached configuration. Extension-last ordering supports explicit contribution and decoration seams; it is not permission to replace arbitrary container bindings or depend on accidental provider order.

Every new cross-root scanner must:

1. use `ApplicationTopology` patterns;
2. preserve Base → Core → enabled Domains → Extensions order;
3. apply `DomainState` filtering to optional-Domain paths;
4. scan only roots where that artifact is supported;
5. sort deterministically and reject ambiguous duplicate identities;
6. document any source-level anchor separately from Module-level contributions.

### Composed-application refusals

Composition rejects conflicting ownership and impossible dependency graphs. The route and table checks landed in [#570](https://github.com/BelimbingApp/belimbing/pull/570); [#588](https://github.com/BelimbingApp/belimbing/pull/588) also enforces table ownership at boot. Provider dependency ordering landed in [#599](https://github.com/BelimbingApp/belimbing/pull/599). Route-name collision refusal landed in [#618](https://github.com/BelimbingApp/belimbing/pull/618). These checks apply to enabled Domains and discovered Extensions as well as Base and Core.

Descriptor validation that would refuse a mounted module whose `requires-modules` names an unmounted ID ([#608](https://github.com/BelimbingApp/belimbing/issues/608) / closed PR [#613](https://github.com/BelimbingApp/belimbing/pull/613)) did not land; missing dependencies still surface through provider-order resolution (`missing` / later-root / cycle) above. The composed-application smoke test ([#604](https://github.com/BelimbingApp/belimbing/issues/604) / PR [#604](https://github.com/BelimbingApp/belimbing/pull/604)) is not on `main` yet.

The following are literal diagnostic templates from the owning code. PHP replaces `%s` and interpolated variables with the detected identities and paths; a cycle message appends the closed chain of module IDs and a final period.

**Route ownership — `RouteCollisionException`.** [RouteDiscoveryService](../../app/Base/Routing/RouteDiscoveryService.php) refuses a later module route file that registers a key already owned by another file:

```text
Route %s is registered by more than one module route file: %s and %s. Laravel would keep only the last one; give each module its own URI.
```

The substitutions are the method/domain/URI key, first file, and later file. For a route without a domain constraint, a key is `GET /people/skills`. Give the competing module a distinct URI, or remove the obsolete route from a relocated module. Changing its route name alone does not resolve a key collision. Different HTTP methods remain distinct; declarations within the same file are outside this guard. This check runs when module route files are loaded; rebuild route caches after changing the composition.

**Route name ownership — `RouteCollisionException`.** The same [RouteDiscoveryService](../../app/Base/Routing/RouteDiscoveryService.php) also refuses a later module route file that registers a non-empty route name another file already owns ([#618](https://github.com/BelimbingApp/belimbing/pull/618)):

```text
Route name %s is registered by more than one module route file: %s and %s. Laravel would keep only the last one; give each module its own route name.
```

The substitutions are the colliding name, first file, and later file. Give the competing module a distinct name, or remove the obsolete registration. Reusing a name inside one file remains that module's own concern, matching the URI guard. Unnamed routes are ignored by this check. Distinct URIs do not excuse a shared name: Laravel's name map would still keep only the last registration.

**Table ownership — `ModuleManifestException`.** [ModuleMigrationDependencyChecker](../../app/Base/Database/Services/ModuleMigrationDependencyChecker.php) uses the same source-only check during Database provider boot and migration preflight. Its multiline exception starts with:

```text
Module migration dependency preflight failed.
```

Each conflicting table contributes this line:

```text
- Table %s is created by more than one module: %s. One module owns a table; remove or rename the table in every module but its owner.
```

The substitutions are the table name and the owners joined by ` and `, each formatted as `module-id (migration-file)`. Complete the relocation by removing the obsolete module's declaration, or give genuinely separate data a distinct table name. Renaming only the migration file cannot resolve shared table ownership. Repeated declarations within one module remain allowed. The check shares [TableRegistry](../../app/Base/Database/Models/TableRegistry.php)'s literal `Schema::create` / `->create*Table` scan; computed names and arbitrary runtime DDL are outside that scan. It neither queries nor repairs an existing database; deployed data changes still require the migration policy in [database architecture](database.md).

**Provider dependency order — `ModuleManifestException`.** [ModuleProviderOrder](../../app/Base/Foundation/Providers/ModuleProviderOrder.php) refuses these conditions during provider-list resolution, before registering the discovered providers:

| Literal diagnostic template | Cause | Required correction |
|---|---|---|
| `Cannot resolve provider order: %s requires %s (%s; constraint %s).` | Requiring module, required ID, issue kind (`missing` or `incompatible`), and declared version constraint. Disabled required Domains count as missing. | Install/enable the declared dependency or supply a compatible version; correct a mistaken manifest ID or constraint against the actual supported contract. |
| `$module cannot require later-root module $required.` | The required module belongs to a later root in Base → Core → Domains → Extensions. | Move the dependency to the appropriate ownership layer or replace the upward dependency with an explicit contribution contract; do not invert root order. |
| `Module dependency cycle: ` | Required edges form a cycle; the appended chain repeats its starting ID, for example `order/a -> order/z -> order/a.` | Remove the circular requirement by correcting ownership or extracting the shared contract. Alphabetical renaming does not break a dependency cycle. |

Providerless modules participate in dependency resolution. Rebuild cached configuration after manifest or provider changes: the cached provider list does not rerun the sorter. Migration preflight separately reports its own dependency and filename-order errors; those are distinct from these provider-resolution messages.

### Current surfaces

| Surface | Supported locations | Owner |
|---|---|---|
| Service providers | `app/Base/*/ServiceProvider.php`, `app/Core/*/ServiceProvider.php`, `app/Domains/*/*/ServiceProvider.php`, `app/Extensions/*/*/ServiceProvider.php` | `App\Base\Foundation\Providers\ProviderRegistry` |
| Migrations | `Database/Migrations/` under Base components, Core Modules, Domain Modules, and Extension Modules; plus Laravel `database/migrations/` | Base Database migration commands |
| Production/dev seeders | `Database/Seeders/` and `Database/Seeders/Dev/` under all four roots | Base Database seeder discovery |
| Menus | `Config/menu.php` under Base/Core Modules, Domain/Extension source anchors, and Domain/Extension Modules | `App\Base\Menu\Services\MenuDiscoveryService` |
| Routes | `Routes/web.php` and `Routes/api.php` under Base components, Core Modules, Domain Modules, and Extension Modules; a file that registers an HTTP method and URI, or a non-empty route name, an earlier file already registered refuses boot with `RouteCollisionException`, because Laravel would otherwise keep only the last route | `App\Base\Routing\RouteDiscoveryService` |
| Settings | Module-level `Config/settings.php` under all four roots | `App\Base\Settings\ServiceProvider` |
| Authorization | `Config/authz.php` under all four roots, including an explicit Extension source anchor where needed | `App\Base\Authz\ServiceProvider` |
| Audit, dashboard, and other contributions | The documented `Config/{surface}.php` under supported Module roots | Owning Base discovery service |
| Livewire components | `Livewire/` below supported Base components and Modules | `App\Base\Livewire\ComponentDiscoveryService` |
| Views | Not implicitly namespace-registered; the owning provider calls `loadViewsFrom()` | Module provider |
| Agent skills | project `.agents/skills`, Core Modules, Domain Modules, Extension sources, and Extension Modules; the first root to declare an id keeps it and later roots are shadowed, which `blb:ai:skills:list` reports | `App\Core\AI\Services\Orchestration\FilesystemSkillPackLoader` |
| Tailwind and Blade refresh | `resources/core/views`, plus installed `app/Core/*/Views`, `app/Domains/*/*/Views`, and `app/Extensions/*/*/Views` | Tailwind/Vite configuration |
| Tests | root `tests/`; Domain-level and Domain-Module `Tests/`; Extension-Module `Tests/` | PHPUnit and Pest configuration |
| Module manifests | Module-root `composer.json` containing `extra.blb` | `App\Base\Foundation\ModuleManifest\ModuleManifestReader` |

Source-level anchors are exceptions, not implicit Modules. They exist only for a surface that explicitly documents a collection-level contribution, such as a Domain's top-level menu bucket. A directory becomes a provider-discoverable Module only through its Module-shaped path and `ServiceProvider.php`.

## Module Communication

Keep public surfaces narrow and module internals hidden:

- **Events publish facts.** Event payloads use the producing Module's language and do not embed consumer-specific codes. A producer continues to work when no listener is installed.
- **Contracts support direct collaboration.** Synchronous consumers depend on a documented service contract, not another Module's tables or internal services.
- **Stable payloads are APIs.** Shipped event fields and contract meanings are not silently removed or renamed. Breaking changes require a versioned surface and consumer migration path.
- **Optional means optional.** A Module listed in `optional-modules` cannot be required for the producer to boot or complete its own transaction.
- **Dependencies point toward stable abstractions.** Base cannot depend on Core, Domain, or Extension implementation. Core cannot depend on optional Domain or Extension implementation. Domains cannot require a deployment-specific Extension.

When UI information architecture combines several Modules into one workflow, bridge that difference through explicit application services, read models, events, or contribution registries. Do not merge ownership boundaries merely to match a menu.

## Variation: Adapters and Slots

Variation is a contract decision before it is a repository decision.

### Adapters are the default

Use a contract plus adapters when implementations share an engine and differ in rules, integrations, or presentation contributions. The host Module owns the lifecycle, schema, and stable contract; a Domain or Extension contributes adapters through an explicit registry or discovery seam.

Examples include marketplace channel providers, country-specific statutory calculations, readiness contributors, report panels, and catalog presets. This keeps one engine maintained while allowing deployment-specific behavior.

### Slots replace a whole Module

Use a slot only when implementations differ enough in lifecycle, consumers, or regulation that sharing an engine would shallow both designs. The Module path and stable identity form the slot contract; a deployment supplies exactly one implementation.

Slot rules:

1. **Fixed identity.** Every variant of `people/payroll` mounts at `app/Domains/People/Payroll`, uses `App\Domains\People\Payroll`, and declares the same stable Module ID.
2. **Contract-only consumers.** Other Modules use documented events, contracts, and routes rather than implementation classes or tables.
3. **One owner at a time.** The parent Domain source does not track a default implementation that another source overlays. The selected source wholly owns the slot path.
4. **Deployment-time choice.** Changing variants on a live database is a data-migration project, not a runtime toggle.
5. **Documented surface.** The slot records its contracts, events, routes, menu contribution, persistence expectations, and compatibility policy so another implementation can be built without reading the default internals.

Prefer adapters until a real second whole-Module implementation exists. Do not pre-extract speculative slots.

## Testing Structure

- Framework, Core, and cross-Module tests live under root `tests/`, with Core tests grouped under `tests/Unit/Core/{Module}` or `tests/Feature/Core/{Module}` where applicable.
- Tests spanning an optional Domain live at `app/Domains/{Domain}/Tests/`.
- Tests owned by an optional Domain Module live at `app/Domains/{Domain}/{Module}/Tests/`.
- Extension Module tests live at `app/Extensions/{Extension}/{Module}/Tests/`.
- Module `Tests/Feature` files receive the application `TestCase` and database reset policy through `tests/Pest.php`.
- Test placement does not override ownership: a cross-root test may live centrally, but fixtures and helpers that belong to one Module should travel with that Module.

See `tests/AGENTS.md` for test value, isolation, and assertion-strength rules.

## Frontend Ownership

`resources/core/` is platform-owned shared presentation: application shell layouts, authentication layouts, reusable Blade components, design tokens, and shared JavaScript.

Domain and Extension page presentation belongs in the owning Module's `Views/` directory. Core Modules may use a local `Views/` directory for clearly Module-owned presentation; existing framework-shell presentation remains under `resources/core`. If a Module reveals a genuinely reusable framework component, promote that component to `resources/core` and keep the workflow screen with its Module.

Module-owned CSS or JavaScript belongs under the Module's `Assets/` directory and enters the build only through an explicit reviewed Vite import or entry. Do not create parallel global `resources/{domain}` or `resources/{extension}` trees.

Because optional source checkouts are ignored by the platform repository, Tailwind source entries and Vite refresh paths must explicitly cover every installed Core, Domain, and Extension view root. Adding a source must not leave its classes invisible to production builds.

## Configuration Ownership

- Root `config/` and Module `Config/` files hold structural definitions and bootstrap inputs.
- Framework scanners consume only documented contribution files; a new arbitrary config file is inert until its owner merges it.
- Runtime parameters and durable account preferences use `App\Base\Settings` at their allowed global, company, or user scope.
- Environment variables are for bootstrap/process inputs and external deployment tooling, not runtime-setting fallbacks.
- Secrets, OAuth tokens, executable paths, limits, and other post-boot parameters belong behind authorized settings surfaces when operators manage them.

The canonical classification and resolution rules live in `docs/architecture/settings.md`.

## Design Principles

### Deep Modules, simple interfaces

Each Module hides its persistence and implementation details behind a small public contract. Optional composition should increase capability without forcing unrelated Modules to understand its internals.

### Domains contain Modules

Domain is the enterprise and lifecycle noun; Module is the contained ownership noun. Operator UI, docs, paths, and APIs must preserve that hierarchy.

### Distinct business areas earn distinct Modules

Concepts that share a noun but differ in lifecycle, valuation, consumers, or regulatory burden belong in separate Modules. Sales inventory, maintenance supplies, and production materials should not become one sparse table behind a `type` flag. Cross-Domain reporting is a read model or insight query, not proof of shared ownership.

Do not scaffold speculative Modules. Apply this rule when a real second ownership model arrives.

### Extensions stay flexible but honest

An Extension may be a mixed bag by design. Its freedom is semantic, not structural: it still declares dependencies, respects contracts, enforces authorization and tenancy, owns migrations safely, and ships meaningful tests.

### Discovery over registration

Conforming placement is the registration mechanism. Central topology definitions, deterministic ordering, and complete Domain-state filtering keep that convenience safe. One-off globs and manual provider lists are architecture drift.

### Stable identity over physical location

Paths and namespaces may evolve; durable IDs do not. Persist logical identities and treat source/repository coordinates as replaceable provenance.
