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
echo "== origin already moved before the run starts is refused at preflight =="
section="$sandbox/stale-before-start"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"
# Simulate someone else pushing to origin/master before our clone's own fetch.
other="$section/other-push"
git_c clone -q "$origin_bare" "$other" >/dev/null 2>&1
(cd "$other" && echo "someone else's change" > racer.txt && git_c add racer.txt && git_c commit -qm "someone else: concurrent commit" && git_c push -q origin master) >/dev/null
out=$(cd "$work" && bash "$SCRIPT" --stable-branch master --integrate 2>&1)
rc=$?
report $([ "$rc" -eq 1 ] && echo 0 || echo 1) "refuses when local isn't in sync with origin before integrating (exit 1)"
printf '%s\n' "$out" | grep -qi "force" && report 1 "script never mentions --force as a recovery path" || report 0 "script never mentions --force as a recovery path"
grep 'git push' "$SCRIPT" | grep -qE -- '--force|-f\b|force-with-lease' && report 1 "no git push invocation carries a force flag" || report 0 "no git push invocation carries a force flag"

echo
echo "== origin moves mid-run, after this script's own fetch: push fails, local un-pushed merge is undone, a re-run then succeeds =="
# The section above races before the script's own fetch, so the preflight
# (comparing against what that fetch just saw) correctly refuses before ever
# reaching the merge or the push — it never exercises push rejection itself.
# #450 review (codex-gpt-5): the real race is a push landing on origin AFTER
# this script's fetch but BEFORE its own push — preflight passes (its
# comparison is against the already-fetched, now-stale ref), the merge
# succeeds locally, and only the final push discovers the race.
#
# An earlier version of this test landed the race through a test-only
# SYNC_ADOPTER_FORK_TEST_HOOK env var that the production script would eval —
# a second review round (codex-gpt-5) correctly rejected that: a Git-mutating
# maintenance script should not carry an undocumented command-execution path
# just to make a test deterministic. Landed instead through a one-shot git
# `pre-push` hook installed only in this test's own throwaway checkout: git
# runs it immediately before sending the real push, so it races in the exact
# window every time, with nothing added to the script under test.
section="$sandbox/race-after-fetch"
mkdir -p "$section"
read -r origin_bare upstream_bare < <(build_fixture "$section")
work="$section/work"
fresh_checkout "$work" "$origin_bare" "$upstream_bare"

# The racer's commit is made (not pushed) before the hook even exists, so its
# SHA is known independently of anything the script under test does. An
# equality check against a SHA read only from the same bare origin the
# script pushed to (#450 review, codex-gpt-5) cannot distinguish "origin is
# exactly the racer's commit" from "the script itself recovered and pushed
# something that happens to match" — this SHA is the control.
racer="$section/racer-push"
git_c clone -q "$origin_bare" "$racer" >/dev/null 2>&1
echo "raced in mid-run" > "$racer/racer.txt"
(cd "$racer" && git_c add racer.txt && git_c commit -qm "someone else: landed mid-run") >/dev/null
racer_sha=$(cd "$racer" && git_c rev-parse HEAD)

# Hermetic regardless of the host's own git config (#450 review, codex-gpt-5:
# a developer- or system-level core.hooksPath would otherwise silently make
# git skip the hook this test installs below, into $work's own .git/hooks).
git_c -C "$work" config core.hooksPath "$(cd "$work" && git_c rev-parse --absolute-git-dir)/hooks"

hooks_dir=$(cd "$work" && git_c rev-parse --absolute-git-dir)/hooks
mkdir -p "$hooks_dir"
cat > "$hooks_dir/pre-push" <<HOOK
#!/usr/bin/env bash
set -eu
# One-shot: push the racer's already-made commit to origin, then remove this
# hook so it never fires again (including on the deliberate re-run later).
rm -- "\$0"
git -C "$racer" push -q origin master
HOOK
chmod +x "$hooks_dir/pre-push"

out=$(cd "$work" && bash "$SCRIPT" --stable-branch master --integrate 2>&1)
rc=$?
origin_tip_after_rejection=$(git_c --git-dir="$origin_bare" rev-parse master)
local_after_rejection=$(cd "$work" && git_c rev-parse master)

report $([ "$rc" -eq 3 ] && echo 0 || echo 1) "the raced push exits 3"
printf '%s\n' "$out" | grep -q "push rejected" && report 0 "reports the push as rejected" || report 1 "reports the push as rejected"
printf '%s\n' "$out" | grep -qE -- '--force|force-with-lease' && report 1 "no --force flag mentioned as a recovery path" || report 0 "no --force flag mentioned as a recovery path"
report $([ "$origin_tip_after_rejection" = "$racer_sha" ] && echo 0 || echo 1) "origin sits at exactly the racer's independently-known commit right after the rejection — not something the script itself pushed"
report $([ "$local_after_rejection" = "$racer_sha" ] && echo 0 || echo 1) "local master is reset to exactly the racer's commit right after the rejection, before any rerun"

rerun_out=$(cd "$work" && bash "$SCRIPT" --stable-branch master --integrate 2>&1)
rerun_rc=$?
report $([ "$rerun_rc" -eq 0 ] && echo 0 || echo 1) "re-running as advised succeeds instead of hitting the preflight refusal"
(cd "$work" && git_c log --oneline | grep -q "someone else: landed mid-run") && report 0 "the re-run's merge includes the commit that raced in" || report 1 "the re-run's merge includes the commit that raced in"
(cd "$work" && git_c log --oneline | grep -q "framework: v3") && report 0 "the re-run's merge still includes the original upstream commits" || report 1 "the re-run's merge still includes the original upstream commits"
final_origin=$(git_c --git-dir="$origin_bare" rev-parse master)
final_local=$(cd "$work" && git_c rev-parse master)
report $([ "$final_origin" = "$final_local" ] && echo 0 || echo 1) "origin only ever advances from a successful push — the re-run's own push, not the earlier rejected one"

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
echo "== every valued option with no value fails fast instead of hanging =="
# #450 review (codex-gpt-5): `${2:-}` + `shift 2` with no $2 left `shift`
# failing under set -u without set -e, so $# never decreased and the parser
# re-matched forever. Each valued option wires its own require_value() call
# independently, so each is its own regression, not one shared code path —
# table-driven rather than spot-checking just --stable-branch. Bounded with
# `timeout` so a real regression here fails this test instead of hanging the
# whole suite. The arity check runs before any git operation, so no fixture
# checkout is needed — a plain directory is enough.
plain="$sandbox/missing-value"
mkdir -p "$plain"
for flag in --stable-branch --origin-remote --upstream-remote --upstream-branch -C; do
  out=$(cd "$plain" && timeout 5 bash "$SCRIPT" "$flag" 2>&1)
  rc=$?
  report $([ "$rc" -eq 1 ] && echo 0 || echo 1) "'$flag' with no following value exits 1, not a timeout (124) or a hang"
  printf '%s\n' "$out" | grep -q "requires a value" && report 0 "'$flag': explains which option was missing its value" || report 1 "'$flag': explains which option was missing its value"
done

echo
echo "-------------------------------------------"
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
