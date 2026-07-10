---
name: blb-repo-sync
description: >-
  Syncs the Belimbing platform repo and nested-git Distribution Bundles
  (domains, extensions) on main only, then migrates when needed per APP_ENV.
  Use when the user asks to pull, push, rebase, sync, or update checkouts.
  Do not use on non-main branches.
---

# BLB Repo Sync

Belimbing is a composed deployment: the **platform repo** (`BelimbingApp/belimbing`) plus **nested git checkouts** that are gitignored in the parent. Sync every nested checkout installed under the platform root.

**`main` only.** If any checkout is not on `main`, skip that checkout and report it — do not pull, push, rebase, or migrate for it. Do not switch branches.

## Discover Checkouts

```bash
find <platform-root> -name .git -type d 2>/dev/null
```

Typical paths (skip anything not cloned):

| Path | Example remote |
|------|----------------|
| `<platform-root>` | `BelimbingApp/belimbing` |
| `app/Modules/Commerce` | `BelimbingApp/blb-commerce` |
| `app/Modules/Operation` | `BelimbingApp/blb-operation` |
| `app/Modules/People` | `BelimbingApp/blb-people` |
| `extensions/ham` | `kiatng/blb-ham` |
| `extensions/kiat` | `kiatng/blb-kiat` |
| `extensions/sb-group` | `kiatng/blb-sbg` |

`app/Modules/Core` lives in the platform repo. Before acting: `git -C <path> status -sb`.

## Sync Workflow

Confirm each checkout is on `main` (`git -C <path> rev-parse --abbrev-ref HEAD`). Platform first, then domains, then extensions. Finish conflicts in one checkout before the next.

### Pull (default)

```bash
git -C <path> fetch origin
git -C <path> pull --ff-only origin main
```

### Rebase

```bash
git -C <path> fetch origin
git -C <path> rebase origin/main
```

### Push

Commit and push **per nested repo** — platform commits never include gitignored domain/extension trees.

```bash
git -C <path> push origin main
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
