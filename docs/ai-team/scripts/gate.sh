#!/usr/bin/env bash
#
# Gate a pull request merge. Prints every verdict and exits non-zero unless all
# of them pass.
#
#   docs/ai-team/scripts/gate.sh <pr-number> [<reviewed-sha>]
#
# Run it as its OWN command and chain the merge to it:
#
#   REPO=$(gh repo view --json nameWithOwner --jq .nameWithOwner)
#   docs/ai-team/scripts/gate.sh 408 abc1234 \
#     && gh api -X PUT "repos/$REPO/pulls/408/merge" -f merge_method=merge
#
# Never put the checks and the merge inside one compound command where the merge
# can still run when a check fails. That is exactly how #382 reached main while
# BEHIND it: the check printed its warning and the merge went ahead anyway.
#
# Why a script rather than branch protection: the "Protect main" ruleset does set
# strict_required_status_checks_policy, but it lists both shared human accounts
# as bypass actors with bypass_mode "always" — and those accounts are every agent
# we have. GitHub will not stop us. This will.
#
set -u

PR="${1:-}"
if [ -z "$PR" ]; then
  echo "usage: gate.sh <pr-number> [<reviewed-sha>]" >&2
  exit 2
fi

ROOT=$(git rev-parse --show-toplevel 2>/dev/null) || { echo "not a git checkout" >&2; exit 2; }
cd "$ROOT" || exit 2

REPO=$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null)
[ -n "$REPO" ] || { echo "cannot resolve the repository from gh" >&2; exit 2; }

# The gate fetches and proves branch containment against *origin*, while gh
# resolves $REPO independently. If origin is a fork, PR lookup can succeed
# against the canonical repository while containment is proven against the
# fork's stale main — a false PASS for a head behind canonical main. So origin
# must BE the canonical repository, verified before any verdict is printed.
# The *resolved* URL is what git will actually fetch from — insteadOf
# rewrites included — and the containment proof is only as canonical as
# that transport. Tests get hermeticity by shimming git on PATH, never by
# weakening this invariant.
origin_url=$(git remote get-url origin 2>/dev/null)
origin_repo=$(printf '%s' "$origin_url" | sed -E 's#^(https://github\.com/|git@github\.com:|ssh://git@github\.com/)##; s#\.git$##')
[ "$origin_repo" = "$REPO" ] || {
  echo "origin is '$origin_url' but gh resolves the repository as '$REPO'." >&2
  echo "The gate proves containment against origin/main, so origin must be the" >&2
  echo "canonical repository. Run from a clone whose origin is $REPO." >&2
  exit 2
}

# One fetch of PR state; every check below reads from it.
pr=$(gh pr view "$PR" --repo "$REPO" \
       --json headRefOid,headRefName,isDraft,state,mergeable,labels 2>/dev/null)
[ -n "$pr" ] || { echo "cannot read PR #$PR from $REPO" >&2; exit 2; }

remote_head=$(printf '%s' "$pr" | jq -r .headRefOid)

REVIEWED="${2:-}"
if [ -z "$REVIEWED" ]; then
  REVIEWED="$remote_head"
  echo "note: no reviewed SHA given — gating the current head $REVIEWED."
  echo "      Pass the SHA you actually reviewed, so a push after your review fails this gate."
elif [ "${#REVIEWED}" -lt 40 ]; then
  # An abbreviation is only usable once the canonical repository resolves it
  # to exactly one commit; every later comparison and check-run query then
  # uses that full SHA, so the merged-is-verified contract stays exact.
  if [ "${#REVIEWED}" -lt 12 ]; then
    echo "reviewed SHA '$REVIEWED' is too short (<12 chars) to identify a commit safely — pass at least 12, ideally all 40." >&2
    exit 2
  fi
  resolved=$(gh api "repos/$REPO/commits/$REVIEWED" --jq .sha 2>/dev/null)
  if [ "${#resolved}" -ne 40 ] || printf '%s' "$resolved" | grep -q '[^0-9a-f]'; then
    echo "reviewed SHA '$REVIEWED' does not resolve to a single commit in $REPO (unknown or ambiguous)." >&2
    exit 2
  fi
  echo "note: resolved abbreviated $REVIEWED to $resolved via $REPO."
  REVIEWED="$resolved"
fi

git fetch -q origin main 2>/dev/null
git cat-file -e "${REVIEWED}^{commit}" 2>/dev/null || git fetch -q origin "pull/$PR/head" 2>/dev/null

fail=0
say_ok()   { echo "  ok      $*"; }
say_bad()  { echo "  BLOCKED $*"; fail=1; }

echo "gate: $REPO #$PR at ${REVIEWED:0:8}"

# 1. Open, and not a draft. A draft is somebody's claim, not a deliverable.
state=$(printf '%s' "$pr" | jq -r .state)
draft=$(printf '%s' "$pr" | jq -r .isDraft)
[ "$state" = "OPEN" ] && say_ok "state is OPEN" || say_bad "state is $state"
[ "$draft" = "false" ] && say_ok "not a draft" || say_bad "PR is a DRAFT — never merge someone's claim"

# 2. Up to date with main. CI green on a tree that never existed on main is not
#    evidence about main. #326 landed red exactly this way.
if git merge-base --is-ancestor origin/main "$REVIEWED" 2>/dev/null; then
  say_ok "contains origin/main ($(git rev-parse --short origin/main))"
else
  say_bad "BEHIND origin/main ($(git rev-parse --short origin/main)) — merge main into the branch first"
fi

# 3. Checks on the REVIEWED sha, not on the PR, not on the branch.
# Judge the LATEST run of each check NAME, not every run on the SHA. A
# superseded run stays on the commit forever: `concurrency: cancel-in-progress`
# leaves a `cancelled` entry behind whenever a PR is force-pushed or pushed
# twice quickly, and counting it blocked #432 while all four of those checks had
# already passed on the same SHA (#433). `neutral` is likewise not a failure --
# CodeQL reports it transiently before settling.
runs=$(gh api "repos/$REPO/commits/$REVIEWED/check-runs" --paginate 2>/dev/null)

latest=$(printf '%s' "$runs" | jq -c '
  [.check_runs[]]
  | group_by(.name)
  | map(sort_by(.started_at, .completed_at) | last)' 2>/dev/null)

n=$(printf '%s' "$latest" | jq -r 'length' 2>/dev/null || echo 0)
bad=$(printf '%s' "$latest" | jq -r \
      '[.[]|select(.status!="completed" or (.conclusion|IN("success","skipped","neutral")|not))]|length' \
      2>/dev/null || echo 1)
min=${GATE_MIN_CHECKS:-6}
if [ "${n:-0}" -lt "$min" ] || [ "${bad:-1}" != "0" ]; then
  say_bad "checks on ${REVIEWED:0:8}: $n distinct, $bad not passing (need >=$min, 0 bad)"
  printf '%s' "$latest" | jq -r \
    '.[]|select(.status!="completed" or (.conclusion|IN("success","skipped","neutral")|not))
        |"            \(.name): \(.status)/\(.conclusion // "pending")"'
else
  say_ok "$n distinct checks on ${REVIEWED:0:8}, latest run of each passing"
fi

# 4. Holds. hold:author is the author mid-fix; hold:review is a reviewer with an
#    open finding. Either one stops the merge, and only its owner clears it.
labels=$(printf '%s' "$pr" | jq -r '[.labels[].name]|join(",")')
echo "  labels: ${labels:-none}"
for h in hold:author hold:review; do
  case ",$labels," in
    *",$h,"*) say_bad "$h is set — the label's owner clears it, not you" ;;
    *)        say_ok "no $h" ;;
  esac
done

# 5. Ready state and independent exact-head review. GitHub accounts are shared,
# so account identity is only corroboration: the stable **From:** marker must
# differ from the PR's one agent:<id> lane. Native APPROVED reviews count; a
# shared-account COMMENTED review carries an explicit **Verdict:** accept marker.
case ",$labels," in
  *",task:review,"*) say_ok "task:review is set" ;;
  *)                 say_bad "task:review is not set — the author has not handed off a final head" ;;
esac

author_agents=$(printf '%s' "$pr" | jq -c \
  '[.labels[].name | select(startswith("agent:")) | ltrimstr("agent:")] | unique' \
  2>/dev/null || echo '[]')
author_count=$(printf '%s' "$author_agents" | jq -r 'length' 2>/dev/null || echo 0)
author_agent=$(printf '%s' "$author_agents" | jq -r '.[0] // ""' 2>/dev/null)
if [ "$author_count" = "1" ]; then
  say_ok "author lane is agent:$author_agent"
else
  say_bad "expected exactly one agent:<id> author lane, found $author_count"
fi

# `gh api --paginate` prints one JSON array per page. Slurp and flatten those
# pages before deriving the latest machine verdict for each stable reviewer.
reviews=$(gh api "repos/$REPO/pulls/$PR/reviews" --paginate 2>/dev/null \
  | jq -s 'add // []' 2>/dev/null)
[ -n "$reviews" ] || reviews='[]'

latest_reviews=$(printf '%s' "$reviews" | jq -c --arg sha "$REVIEWED" '
  def from_agent:
    ([((.body // "") | split("\n")[]
       | capture("^\\*\\*From:\\*\\*[[:space:]]*(?<agent>[a-z0-9]+(?:[._-][a-z0-9]+)*)(?:[[:space:]]|$)"; "i").agent
       | ascii_downcase)][0] // "");
  def explicit_verdict:
    ([((.body // "") | split("\n")[]
       | capture("^\\*\\*Verdict:\\*\\*[[:space:]]*(?<verdict>accept(?: with follow-up)?|changes required)[[:space:]]*$"; "i").verdict
       | ascii_downcase)][0] // "");
  [.[]
   | select(.commit_id == $sha)
   | . + {agent: from_agent, explicit_verdict: explicit_verdict}
   | . + {verdict:
       (if .state == "CHANGES_REQUESTED" or .explicit_verdict == "changes required"
        then "changes required"
        elif .state == "APPROVED"
             or .explicit_verdict == "accept"
             or .explicit_verdict == "accept with follow-up"
        then "accept"
        else ""
        end)}
   | select(.agent != "" and .verdict != "")]
  | sort_by(.agent, .submitted_at, .id)
  | group_by(.agent)
  | map(last)
' 2>/dev/null || echo '[]')

accepted_agents=$(printf '%s' "$latest_reviews" | jq -r --arg author "$author_agent" \
  '[.[] | select(.agent != $author and .verdict == "accept") | .agent] | unique | join(",")' \
  2>/dev/null)
blocking_agents=$(printf '%s' "$latest_reviews" | jq -r --arg author "$author_agent" \
  '[.[] | select(.agent != $author and .verdict == "changes required") | .agent] | unique | join(",")' \
  2>/dev/null)

if [ -n "$accepted_agents" ]; then
  say_ok "independent exact-head acceptance from $accepted_agents"
else
  say_bad "no independent exact-head acceptance; require **From:** <reviewer> plus APPROVED or **Verdict:** accept"
fi
[ -z "$blocking_agents" ] \
  && say_ok "no independent exact-head changes-required verdict" \
  || say_bad "independent exact-head changes required by $blocking_agents"

# 6. The head has not moved since the review. GitHub's PR head also lags a push
#    by minutes, so compare the branch ref too.
if [ "$remote_head" = "$REVIEWED" ]; then
  say_ok "PR head is the reviewed SHA"
else
  # Abbreviations were resolved to a full SHA up front, so this comparison is
  # deliberately exact: what merges must be exactly what was verified.
  say_bad "PR head is $remote_head but you reviewed $REVIEWED — re-review the new head"
fi
# Only meaningful while the PR is open: the branch is normally deleted on merge,
# and that 404 means "merged", not "diverged".
if [ "$state" = "OPEN" ]; then
  branch=$(printf '%s' "$pr" | jq -r .headRefName)
  ref=$(gh api "repos/$REPO/git/refs/heads/$branch" --jq .object.sha 2>/dev/null)
  case "$ref" in
    [0-9a-f][0-9a-f]*)
      [ "$ref" = "$remote_head" ] \
        || say_bad "branch $branch is at ${ref:0:8} but the PR head says ${remote_head:0:8} — a push has not propagated yet" ;;
    *) echo "  note: no branch ref for $branch (deleted, or a fork)" ;;
  esac
fi

# 7. Something to merge at all. Our claim protocol is an empty draft PR, so every
#    claim starts as exactly this shape; #450 was taken out of draft and labelled
#    task:review, and every other check passed it (#453). Zero changed files is
#    the unambiguous case -- a mode-only or rename change still reports files.
# awk rather than `paste | bc`: bc is not installed everywhere, and its absence
# was silent -- an empty $files fell through to the zero branch and accused a
# healthy PR of being an empty claim (#598). END{print s+0} also yields 0 rather
# than nothing on no input, so the check below stands on its own.
files=$(gh api "repos/$REPO/pulls/$PR/files" --paginate --jq 'length' 2>/dev/null | awk '{s+=$1} END{print s+0}')
if [ "${files:-0}" -eq 0 ] 2>/dev/null; then
  say_bad "no changed files — an empty PR is a claim, not a deliverable"
else
  say_ok "$files changed file(s)"
fi

# 8. Conflicts. mergeStateStatus is permanently BLOCKED for us and carries no
#    information; mergeable does.
mergeable=$(printf '%s' "$pr" | jq -r .mergeable)
[ "$mergeable" = "CONFLICTING" ] && say_bad "CONFLICTING with the base branch" || say_ok "mergeable: $mergeable"

# 9. Not a check — the last word on the PR, so a hold written as prose by
#    somebody who did not know about the label is still in front of you.
echo "  --- last 3 comments ---"
gh pr view "$PR" --repo "$REPO" --json comments \
  --jq '.comments[-3:][]|"            \(.createdAt) \(.author.login): \(.body[0:100]|gsub("\n";" "))"' 2>/dev/null

if [ "$fail" = "0" ]; then
  echo "GATE: PASS"
else
  echo "GATE: FAIL"
fi
exit "$fail"
