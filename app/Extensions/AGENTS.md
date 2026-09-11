# Extensions Agent Guide

## Repository Boundary

- `app/Extensions/{Extension}/` may be a nested private Git repo. Check for `.git`
  before staging or pushing anything below it.
- Do not force-add ignored `app/Extensions/*` paths from the parent framework repo.
- Fix nested remotes inside the nested repo, not the parent checkout.
- Fresh install example: [Private Extension Repositories](../../docs/guides/extensions/private-extension-repositories.md).

## Installation and Discovery

Extensions are **not** discovered, and there is deliberately no `blb-extension` topic.

A Domain is published in an organisation this checkout's own git remotes already point at, so the `blb-domain` topic is enough to authorise listing it — see `app/Domains/AGENTS.md`. None of that holds for an Extension, for three separate reasons, any one of which is enough:

- **Extensions are private.** Catalog discovery reads GitHub anonymously and asks for public repositories. A private repository is not filtered out, it is unseeable.
- **They live under owners the platform has no reason to trust.** `kiatng/blb-ham`, `SB-Tape/blb-sbg` — accounts unrelated to the platform's remotes. Deriving them from `origin`/`upstream` would never find them, and should not.
- **Some owners are user accounts, not organisations.** The org repository endpoint does not answer for a user.

Beyond the mechanics, a self-applied topic would mean any account that tagged a repository appeared on an operator's install screen offering code to clone and migrate. That is an acceptable trade inside one organisation you control; it is not a trust model for third-party code.

### How an Extension is installed

An operator names the repository and supplies a credential for its owner. Both paths live under **System → Software → Domains**, "Available" tab:

| Path | What it is |
|------|------------|
| **Install from repository** | The operator pastes a repository URL, chooses placement (Domain or Extension), a PascalCase source name, and a stored GitHub credential. `SourceRepositoryInstaller` validates placement, namespace and every Module manifest before migrations run. Needs no catalog entry. |
| **Available Extensions** | Cards from the optional per-deployment `config('extensions.catalog')`, each showing whether a token is stored for its owner. |

Tokens are stored per GitHub **owner** under **System → Software → GitHub Access**. The owner is parsed from the repository URL, so the Extension's directory name need not match the account: `kiatng/blb-ham` installs into `app/Extensions/Ham`.

Naming a repository and storing a credential for its owner is an explicit, per-repository trust decision by the operator. That is the point, and it is why nothing here is automatic.

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
