# Extensions Agent Guide

## Repository Boundary

- `extensions/{owner}/` may be a nested private Git repo. Check for `.git`
  before staging or pushing anything below it.
- Do not force-add ignored `extensions/*` paths from the parent framework repo.
- Fix nested remotes inside the nested repo, not the parent checkout.

## Layout

- Owner/module path segments use kebab-case.
- Module-owned Blade views live in `Views/`; do not create
  `resources/extensions/{owner}/`.

