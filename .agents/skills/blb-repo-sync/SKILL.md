---
name: blb-repo-sync
description: >-
  Syncs the Belimbing platform repo and nested-git Domain and Extension
  repositories on main, or an adopter fork's stable branch via the
  explicit integration mode below, then migrates when needed per
  APP_ENV. Use when the user asks to pull, push, rebase, sync, or update
  checkouts.
---

# BLB Repo Sync

Belimbing is a composed deployment: the **platform repo** (`BelimbingApp/belimbing`) plus **nested git checkouts** that are gitignored in the parent. Sync every nested checkout installed under the platform root. Treat repository identity as runtime data from each checkout; never infer a private owner or remote from its filesystem path.

**`main` only, for this workflow.** If a checkout's local or upstream branch is not `main`, skip it here and report it — do not pull, push, rebase, or migrate for it in the steps below. Do not switch branches. An adopter fork whose stable deployment branch is legitimately not `main` (commonly `master`, carrying the fork's own commits) is not a skip case — see "Adopter Fork Sync" below.

## Discover Checkouts

```bash
find <platform-root> -name .git \( -type d -o -type f \) 2>/dev/null
```

Typical paths (skip anything not cloned):

| Path | Repository example |
|------|--------------------|
| `<platform-root>` | `BelimbingApp/belimbing` |
| `app/Domains/Commerce` | `BelimbingApp/blb-commerce` |
| `app/Domains/Operation` | `BelimbingApp/blb-operation` |
| `app/Domains/People` | `BelimbingApp/blb-people` |
| `app/Extensions/Ham` | `blb-ham` (example name; discover its configured remote) |

`app/Core` lives in the platform repo. Before acting:

```bash
git -C <path> status -sb
branch=$(git -C <path> rev-parse --abbrev-ref HEAD)
remote=$(git -C <path> config --get "branch.${branch}.remote")
merge_ref=$(git -C <path> config --get "branch.${branch}.merge")
upstream_branch=${merge_ref#refs/heads/}
git -C <path> remote get-url "$remote"
```

If a checkout has no configured remote/merge ref, its local or upstream branch is not `main`, or its remote URL cannot be read, skip and report it. Never add, rename, or rewrite a remote during sync.

## Sync Workflow

Confirm each checkout and its upstream are on `main` (`git -C <path> rev-parse --abbrev-ref HEAD`). Sync in application discovery order: platform first (Base and Core), then optional Domains, then Extensions. Finish conflicts in one checkout before the next.

### Pull (default)

```bash
git -C <path> fetch <remote>
git -C <path> pull --ff-only <remote> <upstream-branch>
```

### Rebase

```bash
git -C <path> fetch <remote>
git -C <path> rebase <remote>/<upstream-branch>
```

### Push

Commit and push **per nested repo** — platform commits never include gitignored domain/extension trees.

```bash
git -C <path> push <remote> HEAD:<upstream-branch>
```

### Conflicts

Resolve in the nested repo that conflicted, finish merge/rebase, confirm clean status, then continue.

## Adopter Fork Sync

Some deployments fork the platform repo and carry their own commits on a stable branch — commonly `master` — while `upstream/main` remains the framework source. That is two legitimately diverged histories, not a checkout to skip: the fork's commits are never on `upstream`, and `upstream`'s commits are never on the fork's `origin` until integrated. `pull --ff-only` cannot express that safely.

Use `scripts/sync-adopter-fork.sh` for this case instead of the plain pull/rebase steps above:

```bash
# Report divergence only — fetches both remotes, changes nothing.
.agents/skills/blb-repo-sync/scripts/sync-adopter-fork.sh --stable-branch master

# Perform the integration: merge, then push (never force).
.agents/skills/blb-repo-sync/scripts/sync-adopter-fork.sh --stable-branch master --integrate
```

By default it reads `origin/<stable-branch>` and `upstream/main`; override with `--origin-remote`, `--upstream-remote`, `--upstream-branch` if a checkout's remotes are named differently, and `-C <path>` to run against a specific nested checkout. Requires being checked out on the exact branch named by `--stable-branch` first — it never switches branches for you.

What it guarantees:

- **Report before act.** Without `--integrate` it only fetches and prints how many commits each side is missing from the other; nothing is merged or pushed.
- **No force, ever.** It never passes `--force`/`--force-with-lease` to `git push`. A push rejected because `origin` moved since the last fetch is reported and left for a re-run — never retried with force.
- **No partial state.** A merge conflict aborts the merge (`git merge --abort`) before exiting; the tree is left exactly as it was, with nothing pushed.
- **Refuses instead of guessing.** A dirty tree, a local stable branch not in sync with its own remote, or being checked out on the wrong branch all refuse cleanly (exit 1) rather than acting on an assumption.

Tests: `bash .agents/skills/blb-repo-sync/scripts/test_sync_adopter_fork.sh` — hermetic, builds local bare repos for both remotes, covers a clean integration, a real merge conflict, and a concurrent push racing the local integration.

## Migrate After Sync

Migrate only when needed. Skip if no pulled commit touched migration paths (`**/Database/Migrations/**`, `database/migrations/**`) and `php artisan migrate:status` shows nothing Pending.

When needed: read `APP_ENV` from `.env`, then run migrate **once** from the platform root (module/extension migrations auto-load).

| `APP_ENV` | Command |
|-----------|---------|
| `local` | `php artisan migrate --dev` |
| staging / production | `php artisan migrate` (add `--seed` only when intentional) |

Never `migrate --dev` outside `local`. Never `migrate:fresh`. If incubating-schema guard blocks a non-local migrate, stop and report — do not bypass.

## Output

1. Per-repo summary (path, action, divergence).
2. Whether migrate ran: `APP_ENV`, command, pending → applied — or skipped (no migration changes / nothing pending).
3. Skipped checkouts (not on `main`, not installed) or blockers.
