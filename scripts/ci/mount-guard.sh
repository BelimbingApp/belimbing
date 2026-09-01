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

# Fail-closed diff (Copilot's finding on #479): `git diff --quiet` exits 0 for
# no difference, 1 for a difference, and >1 on error — a missing parent object
# in a shallow or corrupt checkout must abort the guard loudly, never read as
# "no mount change". lint.yml checks out with fetch-depth: 0, so an error here
# is a broken environment, not a normal shape.
mount_diff() {
    local status
    git diff --quiet "$1" "$2" -- 'docs/ai-team/' 2>/dev/null
    status=$?
    if [[ "$status" -gt 1 ]]; then
        echo "mount-guard: git diff $1..$2 failed (missing objects or corrupt checkout); refusing to judge the range" >&2
        exit 2
    fi
    return "$status"
}

# A merge commit only *introduces* a mount change when the mount differs from
# EVERY parent. A branch-refresh merge ("Merge branch 'main' into <topic>")
# shows main's legitimate pulls in its first-parent diff but matches its main
# parent exactly — first contact with a refreshed Dependabot PR (#462) proved
# the first-parent-only diff refuses exactly that innocent shape.
merge_introduces_mount_change() {
    local commit="$1" parent
    for parent in $(git log -1 --format=%P "$commit"); do
        # Exit 0 from mount_diff means the mount matches this parent — the
        # merge introduced nothing.
        if mount_diff "$parent" "$commit"; then
            return 1
        fi
    done
    return 0
}

# The range enumeration itself must fail closed: in a shallow or partial
# checkout `git rev-list` errors on unknown endpoints, and an empty loop would
# read as "nothing to judge" (the same finding, one level up).
for endpoint in "$base" "$head"; do
    if ! git cat-file -e "$endpoint^{commit}" 2>/dev/null; then
        echo "mount-guard: endpoint $endpoint is not present in this checkout; refusing to judge the range" >&2
        exit 2
    fi
done
range_commits=$(git rev-list "$base..$head") || {
    echo "mount-guard: git rev-list $base..$head failed; refusing to judge the range" >&2
    exit 2
}

offenders=()
while IFS= read -r commit; do
    [[ -n "$commit" ]] || continue
    mount_diff "$commit^" "$commit" && continue
    is_squash_commit "$commit" && continue
    is_pull_merge_commit "$commit" && continue
    if [[ "$(git log -1 --format=%P "$commit" | wc -w)" -ge 2 ]] && ! merge_introduces_mount_change "$commit"; then
        continue
    fi
    offenders+=("$commit $(git log -1 --format=%s "$commit")")
done <<< "$range_commits"

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
