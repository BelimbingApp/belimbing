# Belimbing Contribution Guide

This guide is for adopters and contributors who want to submit changes to Belimbing.

## Before You Start

1. Read [Project Vision](../brief.md) to understand the platform direction.
2. Review architecture conventions in [Architecture](../architecture/).
3. Agree to the [Contributor License Agreement](../../CLA.md).

## Repository Model

Use the standard fork workflow, with an optional private working remote if your team needs one:

- `upstream`: `https://github.com/BelimbingApp/belimbing.git`
- `origin`: your public fork of `BelimbingApp/belimbing`
- `work`: your private working repository (optional)

Set the remotes up explicitly when you clone for the first time:

```bash
git clone git@github.com:<your-github-username-or-org>/belimbing.git
cd belimbing
git remote add upstream git@github.com:BelimbingApp/belimbing.git

# Optional private working mirror for internal collaboration.
git remote add work git@github.com:<your-company-or-org>/belimbing.git
```

Why this model:

- Pull requests to `BelimbingApp/belimbing` work best from a branch in the same fork network.
- A standalone private mirror is useful for internal work, but cannot be used directly as the PR head for upstream.
- Private licensee extension work should use the separate workflow in [Private Extension Repositories](extensions/private-extension-repositories.md).

## Standard Contribution Flow

1. Sync your local `main` from upstream.

```bash
git checkout main
git pull upstream main
```

2. Create a focused branch.

```bash
git checkout -b feature/short-description
```

3. Make changes with production quality in mind.

4. Run checks before committing.

```bash
vendor/bin/pint --dirty
php artisan test --stop-on-failure
```

If your change touches frontend/build tooling, also run:

```bash
bun run build
```

> **Fresh checkout?** Feature tests that render pages fail with
> `ViteManifestNotFoundException` until the frontend is built once:
> `bun install --frozen-lockfile && bun run build` (frozen, matching CI, so a
> setup command never rewrites dependency state). A test that does not need the layout can
> call `$this->withoutVite()` instead. CI builds assets before testing, so
> this bites only local runs.
>
> **Git worktree?** A worktree starts without `vendor/`, `public/build/`, and
> `.env`. **Copy** them from the main checkout — never symlink `vendor/`: the
> Composer autoloader computes `$baseDir` from `dirname(__DIR__)`, and PHP
> resolves `__DIR__` through symlinks (realpath semantics), so it lands on the
> symlink *target* — the main checkout — no matter what path you traversed.
> The PSR-4 map then points `App\` at the main checkout's `app/`, and the
> suite passes while silently testing that checkout's branch instead of the
> worktree's. A relative symlink resolves the same way; copy for real.
>
> The copy has one tax of its own: a vendor built where nested Domain repos
> are checked out carries classmap entries for `app/Domains/…` files a
> platform-only worktree does not have. A full-suite failure there looks like
> `include(…/app/Domains/…): Failed to open stream` — that is the source
> checkout's classmap talking, not your branch. Confirm by re-running the
> failing test in the main checkout (same code, passes there ⇒ environment),
> and leave it out of your worktree's verdicts.

5. Commit with a clear message.

```bash
git add -A
git commit -m "feat: short description"
```

6. Push to your fork for the upstream PR.

```bash
git push -u origin feature/short-description
```

If your team uses a private working mirror, push there separately as needed.

7. Create a PR to upstream.

```bash
gh pr create \
  --repo BelimbingApp/belimbing \
  --base main \
  --head <your-github-username-or-org>:feature/short-description \
  --fill
```

## PR Scope Expectations

- Keep PRs small and focused.
- Include tests for behavior changes.
- Update documentation when behavior or setup changes.
- Avoid unrelated refactors in the same PR.

## Commit and PR Quality Checklist

- Code follows repository conventions from `AGENTS.md`.
- No dead code, stale comments, or temporary debug artifacts.
- Setup scripts are idempotent where possible.
- Security-sensitive values (tokens, passwords, secrets) are never committed.

## After Merge

```bash
git checkout main
git pull upstream main
git branch -d feature/short-description
```

Optionally delete the remote branch from your fork.

## Need Help?

- Open a draft PR early to discuss direction.
- Link related docs under `docs/` in your PR description.
- For larger design changes, describe module boundaries and contracts first.
