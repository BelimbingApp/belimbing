#!/usr/bin/env bash
set -euo pipefail

# Refuse a PR that edits docs/ai-team/ directly instead of through the
# subtree pull (ai-team#26). docs/ai-team/ is a git-subtree-split mount: a
# direct edit forks it silently and is discarded on the next pull — the
# pattern behind #467, #473, and the trigger regression traced through #474's
# review (#475).
#
# `git subtree pull --squash` always generates a commit whose subject starts
# with `Squashed 'docs/ai-team/' changes from `; that prefix is not something
# a hand edit reproduces by accident. A legitimate pull's own merge commit is
# allowed too, recognized by its second parent carrying that same subject.
# Every OTHER commit that touches a path under docs/ai-team/ is refused.

readonly SQUASH_PREFIX="Squashed 'docs/ai-team/' changes from "

base=${1:?usage: mount-guard.sh <base> [head]}
head=${2:-HEAD}

is_squash_commit() {
    [[ "$(git log -1 --format=%s "$1")" == "$SQUASH_PREFIX"* ]]
}

is_pull_merge_commit() {
    local commit="$1" parents second_parent
    parents=$(git log -1 --format=%P "$commit")
    [[ "$(wc -w <<< "$parents")" -ge 2 ]] || return 1
    second_parent=$(awk '{print $2}' <<< "$parents")
    is_squash_commit "$second_parent"
}

# A merge commit only *introduces* a mount change when the mount differs from
# EVERY parent. A branch-refresh merge ("Merge branch 'main' into <topic>")
# shows main's legitimate pulls in its first-parent diff but matches its main
# parent exactly — first contact with a refreshed Dependabot PR (#462) proved
# the first-parent-only diff refuses exactly that innocent shape.
merge_introduces_mount_change() {
    local commit="$1" parent
    for parent in $(git log -1 --format=%P "$commit"); do
        git diff --name-only "$parent" "$commit" -- 'docs/ai-team/' 2>/dev/null | grep -q . || return 1
    done
    return 0
}

offenders=()
while IFS= read -r commit; do
    [[ -n "$commit" ]] || continue
    git diff --name-only "$commit^" "$commit" -- 'docs/ai-team/' 2>/dev/null | grep -q . || continue
    is_squash_commit "$commit" && continue
    is_pull_merge_commit "$commit" && continue
    if [[ "$(git log -1 --format=%P "$commit" | wc -w)" -ge 2 ]] && ! merge_introduces_mount_change "$commit"; then
        continue
    fi
    offenders+=("$commit $(git log -1 --format=%s "$commit")")
done < <(git rev-list "$base..$head")

if [[ "${#offenders[@]}" -gt 0 ]]; then
    echo "refusing: docs/ai-team/ was edited directly instead of through the subtree pull (ai-team#26)." >&2
    echo "docs/ai-team/ is a git-subtree-split mount; a direct edit forks it silently and is discarded" >&2
    echo "on the next pull. Make this change upstream in BelimbingApp/ai-team, then pull it in with:" >&2
    echo "  git subtree pull --prefix=docs/ai-team ai-team-upstream package-mount --squash" >&2
    echo "Adopter-owned deviations (workflow triggers, etc.) belong under .github/workflows/, outside" >&2
    echo "docs/ai-team/ itself — see #474 for a worked example." >&2
    echo "Offending commit(s):" >&2
    printf '  %s\n' "${offenders[@]}" >&2
    exit 1
fi

echo "docs/ai-team/ changes in this range are all subtree-pull commits"
