# Belimbing Contribution Guide

This guide is for adopters and contributors who want to submit changes to Belimbing.

## Before You Start

1. Read [Project Vision](../brief.md) to understand the framework direction.
2. Review architecture conventions in [Architecture](../architecture/).
3. Agree to the [Contributor License Agreement](../../CLA.md).

## Repository Model

Use three remotes in your local clone:

- `upstream`: `https://github.com/BelimbingApp/belimbing.git`
- `fork`: your public fork of `BelimbingApp/belimbing` (for PR branches)
- `origin`: your private working repository (optional)

Why this model:

- Pull requests to `BelimbingApp/belimbing` work best from a branch in the same fork network.
- A standalone private mirror is useful for internal work, but cannot be used directly as PR head for upstream.

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
composer test
./vendor/bin/pint --test
```

If your change touches frontend/build tooling, also run:

```bash
bun run build
```

5. Commit with a clear message.

```bash
git add -A
git commit -m "feat: short description"
```

6. Push to your fork (not private mirror) for PR.

```bash
git push -u fork feature/short-description
```

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
