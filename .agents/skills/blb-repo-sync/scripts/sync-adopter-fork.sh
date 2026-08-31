#!/usr/bin/env bash
#
# Sync an adopter fork whose stable deployment branch is not `main` (#450).
#
# The main-only workflow in SKILL.md assumes the checkout's local branch and
# the framework source branch are the same ref. An adopter fork with its own
# commits on a stable branch (commonly `master`) has two legitimately
# diverged histories: the fork's own commits are never on the framework
# remote, and the framework's commits are never on the fork's remote until
# integrated. `pull --ff-only` cannot express that; this script performs the
# safe non-fast-forward integration explicitly, and only when asked to.
#
# Usage:
#   sync-adopter-fork.sh --stable-branch <branch> [options]
#
# Options:
#   --stable-branch <name>     Required. The adopter's local/origin stable
#                               branch (e.g. master).
#   --origin-remote <name>     Remote carrying the stable branch. Default: origin.
#   --upstream-remote <name>   Remote carrying the framework source. Default: upstream.
#   --upstream-branch <name>   Framework source branch. Default: main.
#   --integrate                Perform the merge and push. Without this flag,
#                               the script only fetches and reports divergence.
#   -C <path>                  Run as if started in <path>. Default: cwd.
#
# Exit codes: 0 report-only or successful integration; 1 refused (dirty tree,
# stable branch behind its remote, current branch mismatch, current branch is
# the upstream branch itself); 2 merge conflict (aborted, tree left clean);
# 3 push rejected (never retried with force).

set -u

repo_path="."
stable_branch=""
origin_remote="origin"
upstream_remote="upstream"
upstream_branch="main"
integrate=0

while [ $# -gt 0 ]; do
  case "$1" in
    --stable-branch) stable_branch="${2:-}"; shift 2 ;;
    --origin-remote) origin_remote="${2:-}"; shift 2 ;;
    --upstream-remote) upstream_remote="${2:-}"; shift 2 ;;
    --upstream-branch) upstream_branch="${2:-}"; shift 2 ;;
    --integrate) integrate=1; shift ;;
    -C) repo_path="${2:-}"; shift 2 ;;
    *) echo "sync-adopter-fork.sh: unrecognized argument '$1'" >&2; exit 1 ;;
  esac
done

if [ -z "$stable_branch" ]; then
  echo "sync-adopter-fork.sh: --stable-branch is required" >&2
  exit 1
fi

cd "$repo_path" || exit 1
ROOT=$(git rev-parse --show-toplevel 2>/dev/null) || { echo "not a git checkout" >&2; exit 1; }
cd "$ROOT" || exit 1

current_branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
if [ "$current_branch" != "$stable_branch" ]; then
  echo "refusing: checked out '$current_branch', not the stated stable branch '$stable_branch' — check out $stable_branch first, this script never switches branches for you" >&2
  exit 1
fi

if [ "$stable_branch" = "$upstream_branch" ] && [ "$origin_remote" = "$upstream_remote" ]; then
  echo "note: stable branch equals the upstream branch on the same remote — this is the main-only case; use the plain pull/rebase workflow in SKILL.md instead of this script" >&2
  exit 1
fi

echo "== fetching =="
if ! git fetch "$origin_remote" "$stable_branch" 2>&1; then
  echo "refusing: could not fetch $origin_remote/$stable_branch" >&2
  exit 1
fi
if ! git fetch "$upstream_remote" "$upstream_branch" 2>&1; then
  echo "refusing: could not fetch $upstream_remote/$upstream_branch" >&2
  exit 1
fi

origin_ref="$origin_remote/$stable_branch"
upstream_ref="$upstream_remote/$upstream_branch"

echo
echo "== divergence =="
counts=$(git rev-list --left-right --count "$origin_ref...$upstream_ref" 2>/dev/null)
adopter_only=$(printf '%s' "$counts" | awk '{print $1}')
upstream_only=$(printf '%s' "$counts" | awk '{print $2}')
echo "  $origin_ref has $adopter_only commit(s) not on $upstream_ref"
echo "  $upstream_ref has $upstream_only commit(s) not on $origin_ref"

if [ "$integrate" -eq 0 ]; then
  echo
  echo "report only (pass --integrate to merge and push)"
  exit 0
fi

echo
echo "== preflight =="

if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
  echo "refusing: working tree is not clean" >&2
  exit 1
fi

local_sha=$(git rev-parse "$stable_branch")
origin_sha=$(git rev-parse "$origin_ref")
if [ "$local_sha" != "$origin_sha" ]; then
  echo "refusing: local $stable_branch ($local_sha) is not $origin_ref ($origin_sha) — sync local to origin first, this script never resets a branch for you" >&2
  exit 1
fi

if [ "$upstream_only" = "0" ]; then
  echo "nothing to integrate: $origin_ref already contains $upstream_ref"
  exit 0
fi

echo "  clean, in sync with $origin_ref, proceeding"

echo
echo "== integrating =="
if ! git merge --no-edit "$upstream_ref" 2>&1; then
  echo "conflict — aborting the merge and leaving the tree as it was" >&2
  git merge --abort
  git status --porcelain | grep -q . && echo "warning: tree not clean after abort, inspect manually" >&2
  exit 2
fi

echo
echo "== proving the push is a fast-forward on the remote =="
# A plain `git push` refuses a non-fast-forward on its own; the explicit
# ancestry check here exists so a refusal is diagnosed before the network
# round-trip, and so this script's own code never carries a --force flag
# that a future edit could accidentally reach.
if ! git merge-base --is-ancestor "$origin_ref" HEAD; then
  echo "refusing: $origin_ref is no longer an ancestor of the merge result — something rewrote it since the fetch above; not pushing" >&2
  exit 3
fi

if ! git push "$origin_remote" "$stable_branch:$stable_branch" 2>&1; then
  echo "push rejected — $origin_remote/$stable_branch moved since the fetch above (someone else pushed concurrently)." >&2
  echo "not retrying with force. Re-run this script: it will fetch again and merge onto the new tip." >&2
  exit 3
fi

echo
echo "integrated $upstream_only commit(s) from $upstream_ref into $stable_branch and pushed to $origin_remote"
