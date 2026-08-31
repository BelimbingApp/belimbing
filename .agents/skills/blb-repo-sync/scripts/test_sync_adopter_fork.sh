#!/usr/bin/env bash
#
# Hermetic tests for sync-adopter-fork.sh (#450). Builds bare local repos to
# stand in for `origin` (adopter fork) and `upstream` (framework source),
# each with its own commits, then exercises the script against a clone.
#
# Every test section gets its own fresh pair of bare repos: sections that
# --integrate mutate origin_bare by pushing to it, and reusing one shared
# pair across sections silently changes what a later section is actually
# testing against.
#
# Run: bash .agents/skills/blb-repo-sync/scripts/test_sync_adopter_fork.sh

set -u

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT="$HERE/sync-adopter-fork.sh"

pass=0
fail=0

report() {
  if [ "$1" -eq 0 ]; then
    pass=$((pass + 1))
    echo "  ok      $2"
  else
    fail=$((fail + 1))
    echo "  FAIL    $2"
  fi
}

git_c() {
  git -c user.name="test" -c user.email="test@example.invalid" "$@"
}

sandbox=$(mktemp -d)
cleanup() { rm -rf "$sandbox"; }
trap cleanup EXIT

# Builds a fresh origin.git (branch master) + upstream.git (branch main) pair
# under $1, with origin carrying two adopter-only commits and upstream
# carrying two framework-only commits that do not touch the adopter's file —
# a clean, non-conflicting divergence. Echoes "<origin_bare> <upstream_bare>".
build_fixture() {
  local base="$1"
  local origin_bare="$base/origin.git"
  local upstream_bare="$base/upstream.git"
  local seed="$base/seed"

  git_c init -q --bare -b master "$origin_bare"
  git_c init -q --bare -b main "$upstream_bare"
  git_c init -q -b main "$seed"

  (
    cd "$seed" || exit 1
    echo "framework v1" > README.md
    git_c add README.md
    git_c commit -qm "framework: initial"
    git_c remote add upstream "$upstream_bare"
    git_c push -q upstream main

    git_c branch master
    git_c checkout -q master
    git_c remote add origin "$origin_bare"

    echo "adopter setting A" > adopter.txt
    git_c add adopter.txt
    git_c commit -qm "adopter: setting A"
    echo "adopter setting B" >> adopter.txt
    git_c add adopter.txt
    git_c commit -qm "adopter: setting B"
    git_c push -q origin master

    git_c checkout -q main
    echo "framework v2" > README.md
    git_c commit -qam "framework: v2"
    echo "framework v3" > README.md
    git_c commit -qam "framework: v3"
    git_c push -q upstream main
  ) >/dev/null

  echo "$origin_bare $upstream_bare"
}

fresh_checkout() {
  local dir="$1" origin_bare="$2" upstream_bare="$3"
  rm -rf "$dir"
  git_c clone -q "$origin_bare" "$dir" >/dev/null 2>&1
  (cd "$dir" && git_c remote add upstream "$upstream_bare" && git_c checkout -q master)
}

echo "== report-only mode makes no changes =="
section="$sandbox/report"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"
before_origin=$(git_c --git-dir="$origin_bare" rev-parse master)
out=$(cd "$work" && bash "$SCRIPT" --stable-branch master 2>&1)
rc=$?
report "$rc" "report-only exits 0"
printf '%s\n' "$out" | grep -q "2 commit(s) not on" && report 0 "reports adopter-only count" || report 1 "reports adopter-only count"
printf '%s\n' "$out" | grep -q "not pushing\|report only" && report 0 "declines to integrate without --integrate" || report 1 "declines to integrate without --integrate"
after_origin=$(git_c --git-dir="$origin_bare" rev-parse master)
report $([ "$before_origin" = "$after_origin" ] && echo 0 || echo 1) "origin/master untouched by report-only run"

echo
echo "== --integrate performs a clean non-conflicting merge and pushes =="
section="$sandbox/integrate"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"
out=$(cd "$work" && bash "$SCRIPT" --stable-branch master --integrate 2>&1)
rc=$?
report "$rc" "integrate exits 0 on a clean merge"
(cd "$work" && git_c log --oneline | grep -q "adopter: setting A") && report 0 "adopter commit A survives the merge" || report 1 "adopter commit A survives the merge"
(cd "$work" && git_c log --oneline | grep -q "adopter: setting B") && report 0 "adopter commit B survives the merge" || report 1 "adopter commit B survives the merge"
(cd "$work" && git_c log --oneline | grep -q "framework: v3") && report 0 "upstream commit v3 is incorporated" || report 1 "upstream commit v3 is incorporated"
pushed=$(git_c --git-dir="$origin_bare" rev-parse master)
local_head=$(cd "$work" && git_c rev-parse master)
report $([ "$pushed" = "$local_head" ] && echo 0 || echo 1) "origin/master advanced to the merge result"
(cd "$work" && [ -z "$(git_c status --porcelain)" ]) && report 0 "tree clean after integration" || report 1 "tree clean after integration"

echo
echo "== a real conflict aborts cleanly, no partial merge, no push =="
section="$sandbox/conflict"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
# Both sides now touch README.md: upstream via v2/v3, origin via this commit
# pushed on top of the fixture's adopter commits — a genuine conflict, not
# just divergence.
conflict_pusher="$section/conflict-pusher"
git_c clone -q "$origin_bare" "$conflict_pusher" >/dev/null 2>&1
(cd "$conflict_pusher" && echo "adopter changed the README too" > README.md && git_c add README.md && git_c commit -qm "adopter: touches README (will conflict)" && git_c push -q origin master) >/dev/null
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"
before_origin=$(git_c --git-dir="$origin_bare" rev-parse master)
out=$(cd "$work" && bash "$SCRIPT" --stable-branch master --integrate 2>&1)
rc=$?
report $([ "$rc" -eq 2 ] && echo 0 || echo 1) "conflict exits 2"
(cd "$work" && git_c status --porcelain | grep -qv '^??') && report 1 "tree left clean after aborted merge" || report 0 "tree left clean after aborted merge"
(cd "$work" && [ ! -f .git/MERGE_HEAD ]) && report 0 "no merge left in progress" || report 1 "no merge left in progress"
after_origin=$(git_c --git-dir="$origin_bare" rev-parse master)
report $([ "$before_origin" = "$after_origin" ] && echo 0 || echo 1) "origin/master untouched after aborted conflict (never pushed)"

echo
echo "== a concurrent push on origin is refused, never force-pushed =="
section="$sandbox/race"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"
# Simulate someone else pushing to origin/master after our clone.
other="$section/other-push"
git_c clone -q "$origin_bare" "$other" >/dev/null 2>&1
(cd "$other" && echo "someone else's change" > racer.txt && git_c add racer.txt && git_c commit -qm "someone else: concurrent commit" && git_c push -q origin master) >/dev/null
out=$(cd "$work" && bash "$SCRIPT" --stable-branch master --integrate 2>&1)
rc=$?
report $([ "$rc" -eq 1 ] && echo 0 || echo 1) "refuses when local isn't in sync with origin before integrating (exit 1)"
printf '%s\n' "$out" | grep -qi "force" && report 1 "script never mentions --force as a recovery path" || report 0 "script never mentions --force as a recovery path"
grep 'git push' "$SCRIPT" | grep -qE -- '--force|-f\b|force-with-lease' && report 1 "no git push invocation carries a force flag" || report 0 "no git push invocation carries a force flag"

echo
echo "== wrong current branch is refused before touching anything =="
section="$sandbox/wrongbranch"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"
(cd "$work" && git_c checkout -q -b some-other-branch)
out=$(cd "$work" && bash "$SCRIPT" --stable-branch master 2>&1)
rc=$?
report $([ "$rc" -eq 1 ] && echo 0 || echo 1) "refuses to run when checked-out branch isn't the stated stable branch"

echo
echo "-------------------------------------------"
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
