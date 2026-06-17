## Commit and push

Check the root repo and nested git worktrees/sub-repos that actually have changes, commit each on main and push.

## Sync

Check the root repo and nested git worktrees/sub-repos; on each checkout on main, fetch and pull from its tracking remote. If pull fails with conflicts, resolve them in the affected files, finish the merge or rebase, and verify the repo is clean before moving to the next checkout.
