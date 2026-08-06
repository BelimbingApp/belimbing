# Extensions Agent Guide

## Repository Boundary

- `app/Extensions/{Extension}/` may be a nested private Git repo. Check for `.git`
  before staging or pushing anything below it.
- Do not force-add ignored `app/Extensions/*` paths from the parent framework repo.
- Fix nested remotes inside the nested repo, not the parent checkout.
- Fresh install example: [Private Extension Repositories](../../docs/guides/extensions/private-extension-repositories.md).

## Licensing

- Belimbing is [MIT](../../LICENSE): Extensions may be private and proprietary, licensed however their owner chooses. No licensing reason exists to keep a change out of an Extension, or to move one in.
- Placement is still an architecture decision: platform-wide behavior belongs in `app/Base/`, required product capability in `app/Core/`, optional enterprise capability in `app/Domains/`, and deployment-specific behavior in `app/Extensions/`. Put it where it belongs, not where the license pushes it.

## Layout

- Physical Extension and Module path segments use PascalCase: `app/Extensions/{Extension}/{Module}`. Stable external identities stay lowercase and path-independent, such as `sb-group/qac`; never derive one by lowercasing a directory.
- An Extension is intentionally a semantically relaxed mixed bag, but its Module boundaries and integration surfaces must remain explicit.
- Module-owned Blade views live in `Views/`; do not create a parallel Extension-specific shared resources tree.

## UI

- Module `Views/` follow the same standards as Core: `DESIGN.md` (intent),
  `resources/core/views/AGENTS.md` (authoring rules).

## Contribution Surfaces

- Extension Modules contribute menus, authz capabilities, and dashboard widgets through the same `Config/*.php` discovery contracts as Domain Modules — see `app/Domains/AGENTS.md`.
