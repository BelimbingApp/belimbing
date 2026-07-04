# Extensions Agent Guide

## Repository Boundary

- `extensions/{owner}/` may be a nested private Git repo. Check for `.git`
  before staging or pushing anything below it.
- Do not force-add ignored `extensions/*` paths from the parent framework repo.
- Fix nested remotes inside the nested repo, not the parent checkout.
- Fresh install example: [Private Extension Repositories](../docs/guides/extensions/private-extension-repositories.md).

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
