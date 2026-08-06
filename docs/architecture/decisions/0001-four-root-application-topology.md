# ADR 0001: Four-root application topology

**Document Type:** Architecture Decision Record
**Status:** Accepted
**Scope:** Runtime code ownership, namespaces, discovery roots, migration compatibility, and operator vocabulary
**Last Updated:** 2026-08-06

## Context

Belimbing's current filesystem encodes domains below `app/Modules`, keeps the required Core domain in that same optional-domain collection, and places licensee extensions outside `app/`. The resulting names obscure the actual hierarchy: domains contain modules, Core is a required enterprise domain with a different ownership and lifecycle policy, and extensions are deliberately flexible deployment-owned customizations. The external `extensions/` root also requires a bespoke autoloader and kebab/Pascal path conversion that ordinary application code does not need.

The existing topology has become a public contract through PHP namespaces, discovery globs, nested-git checkout paths, CI, tests, documentation, persisted module provenance, and serialized class names. Changing it is therefore an architecture migration across the platform and every installed source repository, not a directory cleanup.

## Decision

Belimbing application code has exactly four first-level roots under `app/`:

| Root | Namespace | Responsibility |
|---|---|---|
| `app/Base/{Component}` | `App\Base\...` | Framework infrastructure and shared platform mechanisms. |
| `app/Core/{Module}` | `App\Core\...` | The required, platform-owned Core enterprise domain. |
| `app/Domains/{Domain}/{Module}` | `App\Domains\...` | Installable enterprise domains containing full-stack modules. |
| `app/Extensions/{Extension}/{Module}` | `App\Extensions\...` | Deployment-owned customizations with deliberately relaxed semantic-placement rules. |

Core remains a Domain in product and architecture language. Its dedicated root records a real ownership and lifecycle distinction: it ships and updates with the platform, is always enabled, and cannot be installed or removed independently. Optional domains use the same logical Domain model but are mounted below `app/Domains` as separately managed sources.

Modules remain the full-stack ownership and change boundaries inside Core, Domains, and Extensions. Base contains infrastructure components rather than enterprise domains. Extension code may be a tenant overlay, adapter, cross-domain composition, or a complete private capability at the deployment owner's discretion; it still follows platform quality, discovery, dependency, and safety contracts.

Application ownership segments below `app/`—components, domains, extension sources, modules, and module-internal directories—use PascalCase. Conventional repository metadata such as `.github`, `docs`, and files keeps its native naming. Stable logical identifiers remain kebab-case and path-independent, including `core/company`, `people/payroll`, and extension identities such as `sb-group/qac`.

Provider and artifact discovery order is deterministic:

1. Base
2. Core
3. enabled Domains
4. installed Extensions

Loading installed Extensions last supports explicit contribution and override seams; it is not permission to depend on accidental provider ordering or replace arbitrary bindings.

Operator-facing software composition uses **Domains** as the primary noun, with Modules shown within each Domain. The implementation may retain a small internal source/repository abstraction for Git or future package provenance, but **Distribution Bundle** is retired as a product and general architecture term.

The migration is a coordinated hard filesystem cutover across all installed repositories. Stable persisted identifiers are migrated where possible, and legacy PHP class resolution is retained only as a bounded Composer-loaded compatibility mechanism for previously serialized jobs, events, workflows, polymorphic references, and immutable pre-cutover migrations that may still execute during a fresh install or explicit replay. No dual filesystem topology becomes a supported steady state.

## Alternatives Considered

### Keep `app/Modules`

Rejected because the first-level children are domains while modules live one level below. It preserves an increasingly misleading contract and continues to mix Core's platform lifecycle with optional domains.

### Put Core at `app/Domains/Core`

Semantically valid but rejected because every optional-domain installer, ignore rule, source scanner, and lifecycle operation would retain a Core exception. A dedicated `app/Core` root makes the platform-owned boundary and Base → Core → Domains dependency order explicit.

### Put Core at `app/Base/Core`

Rejected because Base and Core are peers in the Platform Baseline. Nesting Core under Base would falsely make an enterprise domain a framework-infrastructure component and obscure the intended dependency direction.

### Keep extensions at repository-root `extensions/`

Rejected because those classes are runtime application code. Moving them below `app/Extensions` enables normal `App\` PSR-4 resolution, removes the custom extension autoloader, and keeps nested-git and private-license boundaries intact through source ownership and ignore rules rather than a special PHP root.

## Consequences

- The filesystem, namespaces, discovery code, tests, CI, build inputs, documentation, and nested-repository workflows must change together.
- `App\Modules\Core\...` becomes `App\Core\...`; other `App\Modules\...` classes become `App\Domains\...`; `Extensions\...` becomes `App\Extensions\...`.
- Domain repositories mount at `app/Domains/{Domain}`. Extension repositories mount at `app/Extensions/{Extension}` and use PascalCase internal directories.
- Core no longer needs exclusion branches in optional-domain lifecycle code.
- The bespoke `ExtensionAutoloader`, its kebab/Pascal filesystem lookup, and its former Composer entry are removed. Composer instead loads one bounded legacy-alias bootstrap for the three retired namespace prefixes.
- Existing stored filesystem paths and executable class references require an idempotent compatibility migration and bounded legacy resolution.
- The alias bootstrap remains required while a supported fresh install, rollback, or replay can execute an immutable migration containing a retired class name. Removing it requires either a canonical migration baseline/squash or proof that no executable migration or retained payload can request a retired namespace.
- The forward topology normalizer is a stable, idempotent, data-only migration. It declares `ReplaysAfterIncubatingSchema` so a local incubating-schema rebuild can replay normalization without making the normalizer incubating or authorizing schema mutation.
- Source repositories that span multiple logical areas remain valid for Extensions; logical Domain identity is not inferred from Git ownership.
- Future discovery mechanisms must consume centralized root definitions rather than independently inventing topology strings.
