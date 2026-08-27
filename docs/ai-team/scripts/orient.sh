#!/usr/bin/env bash
#
# Everything a teammate needs to start working, in one command.
#
#   docs/ai-team/scripts/orient.sh
#
# This exists because orientation is our largest repeated cost: every agent that
# starts pays for it, and the short-lived ones pay for it once per task. Prose
# cannot tell you who holds a file right now; this can.
#
set -u

ROOT=$(git rev-parse --show-toplevel 2>/dev/null) || { echo "not a git checkout" >&2; exit 2; }
cd "$ROOT" || exit 2
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# A halt must reach every agent regardless of tool, so it lives on the board and
# surfaces here — the one command every agent runs each tick. An open issue
# labelled `ops:halt` means the team stands down; it is set and cleared by the
# owner, or the steward on the owner's word. Printed first so a stand-down that
# went out on one tool's private channel is not missed by agents on another.
if ! REPO=$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null); then
  echo "== operations =="
  echo "  *** HALT STATUS UNKNOWN — STAND DOWN ***"
  echo "  Cannot resolve this repository through gh; do not claim new work."
  exit 2
fi

"$SCRIPT_DIR/halt_status.sh" "$REPO"
halt_status=$?
[ "$halt_status" -eq 0 ] || exit "$halt_status"

echo
echo "== active leader/steward =="
stewards=$(gh issue list --repo "$REPO" --state open --label "ops:steward" \
  --json number,title,labels \
  --jq '.[] | ([.labels[].name | select(startswith("agent:"))] | join(", ")) as $agents
        | "  #\(.number) [\(if $agents == "" then "MISSING agent label" else $agents end)] \(.title)"' \
  2>/dev/null)
steward_status=$?
if [ "$steward_status" -ne 0 ]; then
  echo "  unavailable — inspect the board before relying on steward backstops"
elif [ -z "$stewards" ]; then
  echo "  none appointed"
else
  printf '%s\n' "$stewards"
  steward_count=$(printf '%s\n' "$stewards" | wc -l | tr -d ' ')
  if [ "$steward_count" -ne 1 ]; then
    echo "  WARNING expected exactly one active ops:steward issue"
  fi
fi

echo
echo "== main =="
git fetch -q origin main 2>/dev/null
echo "  origin/main  $(git log origin/main --oneline -1)"
branch=$(git rev-parse --abbrev-ref HEAD)
if [ "$branch" != "main" ]; then
  if git merge-base --is-ancestor origin/main HEAD 2>/dev/null; then
    echo "  $branch: contains origin/main"
  else
    echo "  $branch: BEHIND origin/main — merge it in before you ask anyone to review"
  fi
fi

if [ -x "$SCRIPT_DIR/project-orient.sh" ]; then
  echo
  "$SCRIPT_DIR/project-orient.sh"
fi

echo
echo "== open pull requests — who holds what =="
gh pr list --repo "$REPO" --state open --limit 40 \
  --json number,title,isDraft,labels,headRefName \
  --jq '.[]|"  #\(.number) \(if .isDraft then "[draft]" else "        " end) \(.title[0:62])
        \(.headRefName)  \([.labels[].name]|join(" "))"' 2>/dev/null \
  || echo "  (gh unavailable)"

echo
echo "== holds that have been addressed — the author pushed after the label =="
# A hold transfers the obligation to whoever set it, and nothing else tells that
# person when it comes due. A review hold once remained for 75 minutes after the
# author had already fixed it because the reviewer never re-checked the PR.
#
# Every agent commits as the same handle, so the API cannot say WHICH agent set a
# label. All open holds are listed rather than filtered to yours: the cost of
# seeing someone else's is one glance, and the cost of hiding your own is the
# 75 minutes.
held=$(gh pr list --repo "$REPO" --state open --limit 60 \
         --json number,labels,title \
         --jq '.[]|select([.labels[].name]|any(startswith("hold:")))|"\(.number)\t\([.labels[].name]|map(select(startswith("hold:")))|join(","))\t\(.title[0:52])"' 2>/dev/null)

if [ -z "$held" ]; then
  echo "  none"
else
  printf '%s\n' "$held" | while IFS=$'\t' read -r n labels title; do
    # Latest application of any hold label; a hold can be set, cleared and set again.
    set_at=$(gh api "repos/$REPO/issues/$n/timeline" --paginate \
      --jq '[.[]|select(.event=="labeled" and (.label.name|startswith("hold:")))|.created_at]|last' 2>/dev/null)
    head_at=$(gh api "repos/$REPO/pulls/$n/commits" \
      --jq '[.[]|.commit.committer.date]|last' 2>/dev/null)
    echo "  #$n [$labels] $title"

    if [ -n "$set_at" ] && [ -n "$head_at" ]; then
      s_e=$(date -d "$set_at" +%s 2>/dev/null)
      h_e=$(date -d "$head_at" +%s 2>/dev/null)
      if [ -n "$s_e" ] && [ -n "$h_e" ] && [ "$h_e" -gt "$s_e" ]; then
        mins=$(( (h_e - s_e) / 60 ))
        waited=$(( ($(date +%s) - h_e) / 60 ))
        echo "        label set $set_at, head pushed +${mins}m later — WAITING ${waited}m. Re-review or clear."
      else
        echo "        no push since the label — the ball is with the author."
      fi
    fi
  done
fi

echo
echo "== reachability — self-reported at claim; where the owner was when written (#360) =="
echo "   (agents move between sessions; the board itself always reaches everyone)"
# The channel, not a session name: holds are clearable only by their owner, so
# reaching the owner is a correctness dependency of the hold mechanism, and
# the board is the one channel spanning every lineage, harness, and machine.
reach_prs=$(gh pr list --repo "$REPO" --state open --limit 40 \
  --json number,labels,body,updatedAt 2>/dev/null) || reach_prs='[]'
[ -n "$reach_prs" ] || reach_prs='[]'

printf '%s' "$reach_prs" | jq -r '.[]
    | ([.labels[].name | select(startswith("agent:"))] | join(",")) as $agents
    | select($agents != "")
    | ((((.body // "") | capture("\\*\\*Reachable:\\*\\*\\s*(?<c>[^\\r\\n]+)") | .c)?) // "board (assumed — no roster line)") as $channel
    | "  #\(.number) [\($agents)] reachable: \($channel) · last seen \(.updatedAt)"' 2>/dev/null \
  || echo "  (gh unavailable)"

# The lane owner is not the person the steward usually needs (#373 review):
# on the motivating incident the lane said agent:fable while the unreachable
# agent was the HOLD owner, whom no label records. The review stream does
# record them — the **From:** markers gate.sh parses — so for each held PR,
# name the agents whose latest verdict is changes-required, with their
# channel resolved from their own lane row above, else board.
agent_channels=$(printf '%s' "$reach_prs" | jq -r '.[]
    | ([.labels[].name | select(startswith("agent:")) | sub("^agent:"; "")] | .[]) as $a
    | ((((.body // "") | capture("\\*\\*Reachable:\\*\\*\\s*(?<c>[^\\r\\n]+)") | .c)?) // "") as $c
    | select($c != "")
    | "\($a)\t\($c)"' 2>/dev/null)

printf '%s' "$reach_prs" | jq -r '.[]
    | select([.labels[].name] | any(startswith("hold:")))
    | .number' 2>/dev/null \
  | while read -r held_pr; do
      [ -n "$held_pr" ] || continue
      gh api "repos/$REPO/pulls/$held_pr/reviews" --paginate 2>/dev/null \
        | jq -s 'add // []' 2>/dev/null \
        | jq -r '
            [.[]
             | ((((.body // "") | split("\n") | .[0]
                 | capture("^\\*\\*From:\\*\\*\\s*(?<a>[a-z0-9]+([._-][a-z0-9]+)*)"; "i") | .a)?) // "") as $agent
             | select($agent != "")
             | (([(.body // "") | split("\n")[]
                 | (capture("^\\*\\*Verdict:\\*\\*\\s*(?<v>accept( with follow-up)?|changes required)\\s*$"; "i") | .v)?
                 | select(. != null) | ascii_downcase] | last) // "") as $verdict
             | select($verdict != "")
             | {agent: $agent, verdict: $verdict, at: (.submitted_at // "")}]
            | group_by(.agent) | map(max_by(.at))
            | .[] | select(.verdict == "changes required") | .agent' 2>/dev/null \
        | while read -r holder; do
            [ -n "$holder" ] || continue
            channel=$(printf '%s\n' "$agent_channels" | awk -F'\t' -v a="$holder" '$1 == a {print $2; exit}')
            echo "  #$held_pr [hold] held by $holder — reachable: ${channel:-board (assumed — no lane of their own)}"
          done
    done

# The window between claim and PR: an agent-labelled issue with no open PR
# referencing it has an owner but no lane row — exactly when reaching the
# claimer matters most. Board-assumed; issues carry no roster line.
printf '%s' "$reach_prs" | jq -r '[.[] | .body // ""] | join("\n")' 2>/dev/null > /tmp/orient-reach-bodies.$$
gh issue list --repo "$REPO" --state open --limit 60 --json number,labels 2>/dev/null \
  | jq -r '.[]
      | ([.labels[].name | select(startswith("agent:"))] | join(",")) as $agents
      | select($agents != "")
      | "\(.number)\t\($agents)"' 2>/dev/null \
  | while IFS=$'\t' read -r inum iagents; do
      [ -n "$inum" ] || continue
      grep -q "#$inum" /tmp/orient-reach-bodies.$$ 2>/dev/null && continue
      echo "  #$inum [$iagents] (claimed, no PR yet) reachable: board (assumed)"
    done
rm -f /tmp/orient-reach-bodies.$$

echo
echo "== ready and unclaimed — no agent:* label =="
gh issue list --repo "$REPO" --state open --label "task:ready" --limit 40 \
  --json number,title,labels \
  --jq '.[]|select([.labels[].name]|any(startswith("agent:"))|not)|"  #\(.number) \(.title[0:70])"' 2>/dev/null \
  || echo "  (gh unavailable)"
# Unqueued issues — no task:* and no agent:* label — were invisible here for
# two missions because nothing produced task:ready, and the queue read as
# empty ("nothing to do") while work sat open (#366). Surface them in the
# same section; claim.sh accepts them directly.
gh issue list --repo "$REPO" --state open --limit 100 \
  --json number,title,labels \
  --jq '.[]|select(([.labels[].name] | any(startswith("agent:")) or any(startswith("task:")) or any(. == "ops:halt") | not))|"  #\(.number) (unqueued — no task label) \(.title[0:52])"' 2>/dev/null

echo
echo "== blocked =="
gh issue list --repo "$REPO" --state open --label "task:blocked" --limit 40 \
  --json number,title --jq '.[]|"  #\(.number) \(.title[0:70])"' 2>/dev/null

echo
echo "== review-queue hygiene — unmergeable before review effort is spent (#366) =="
# A lane that bypassed claim.sh/ready.sh reaches task:review without the
# closing reference the gate requires; three PRs arrived unmergeable that way
# in one mission and nothing said so until merge time. Flag it where agents
# look, before a reviewer pays for it.
gh pr list --repo "$REPO" --state open --label "task:review" --limit 40   --json number,title,body 2>/dev/null   | jq -r '[.[] | select((.body // "") | test("(?i)closes #[0-9]+") | not)]
           | if length == 0
             then "  ok      every task:review PR carries a closing reference"
             else .[] | "  #\(.number) has no Closes #N — run ready.sh before review effort is spent — \(.title[0:48])"
             end' 2>/dev/null   || echo "  (gh unavailable)"

echo
echo "== label hygiene — these are invisible to the queries above =="
gh issue list --repo "$REPO" --state open --label "task:done" --limit 40 \
  --json number,title --jq '.[]|"  #\(.number) OPEN but labelled task:done — \(.title[0:56])"' 2>/dev/null
gh issue list --repo "$REPO" --state open --limit 100 --json number,title,labels \
  --jq '[.[]|select([.labels[].name]|map(select(startswith("task:")))|length > 1)]
        |.[]|"  #\(.number) carries two task:* labels — \(.title[0:56])"' 2>/dev/null

echo
BOARD_REPO="$REPO" "$(dirname "$0")/board.sh" hygiene
