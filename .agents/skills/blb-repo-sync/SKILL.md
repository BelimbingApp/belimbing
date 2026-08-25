---
name: blb-repo-sync
description: >-
  Syncs the Belimbing platform repo and nested-git Domain and Extension
  repositories on main only, then migrates when needed per APP_ENV.
  Use when the user asks to pull, push, rebase, sync, or update checkouts.
  Do not use on non-main branches.
---

# BLB Repo Sync

Belimbing is a composed deployment: the **platform repo** (`BelimbingApp/belimbing`) plus **nested git checkouts** that are gitignored in the parent. Sync every nested checkout installed under the platform root. Treat repository identity as runtime data from each checkout; never infer a private owner or remote from its filesystem path.

**`main` only.** If any checkout is not on `main`, skip that checkout and report it — do not pull, push, rebase, or migrate for it. Do not switch branches.

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
