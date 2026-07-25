# Extensions Agent Guide

## Repository Boundary

- `extensions/{owner}/` may be a nested private Git repo. Check for `.git`
  before staging or pushing anything below it.
- Do not force-add ignored `extensions/*` paths from the parent framework repo.
- Fix nested remotes inside the nested repo, not the parent checkout.
- Fresh install example: [Private Extension Repositories](../docs/guides/extensions/private-extension-repositories.md).

## Licensing

- Belimbing is [MIT](../LICENSE): extensions may be private and proprietary, licensed however their owner chooses. No licensing reason exists to keep a change out of an extension, or to move one in.
- Placement is still an architecture decision: platform-wide behavior belongs in `app/Base/`, shared domain logic in a module, licensee-specific logic in an extension. Put it where it belongs, not where the license pushes it.

## Layout

- Owner/module path segments use kebab-case.
- Module-owned Blade views live in `Views/`; do not create
  `resources/extensions/{owner}/`.

## UI

- Module `Views/` follow the same standards as Core: `DESIGN.md` (intent),
  `resources/core/views/AGENTS.md` (authoring rules).

## Contribution Surfaces

- Extension modules contribute menus, authz capabilities, and dashboard
  widgets through the same `Config/*.php` discovery contracts as internal
  modules — see `app/Modules/AGENTS.md`.
