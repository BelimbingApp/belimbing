#!/usr/bin/env bash
#
# Set or clear a *named* review hold — one label per holding reviewer, so two
# reviewers with independent open findings on the same PR never collapse into
# one anonymous `hold:review` boolean that either can remove (#385).
#
#   CLAIM_AGENT=<stable-agent-id> docs/ai-team/scripts/hold.sh review add <pr-number>
#   CLAIM_AGENT=<stable-agent-id> docs/ai-team/scripts/hold.sh review clear <pr-number>
#
# `add` creates the PR-scoped label `hold:review:<agent>` (creating the label
# itself on first use, the same lazy pattern claim.sh uses for `agent:<id>`)
# and applies it. Plain `clear` (no --steward) removes only CLAIM_AGENT's own
# label — never another holder's.
#
# Steward transfer of an unresponsive holder's hold — the one case where a
# third party may clear a hold that isn't theirs — requires the target agent
# and a recorded reason, both mandatory together, never inferred:
#
#   CLAIM_AGENT=<steward-id> docs/ai-team/scripts/hold.sh review clear <pr-number> \
#     --steward <holder-agent> --reason "<what was discharged, and how you know>"
#
# This clears exactly hold:review:<holder-agent> — no other holder's label is
# touched — and posts the reason as a headered PR comment, so "I named the
# absent holder in a comment" (once unread prose) becomes durable, attributed
# evidence the gate's history and any later reader can see. Skipping this path
# for a bare `gh pr edit --remove-label` is exactly the failure this script
# exists to prevent: the tool becomes the thing you route around at the one
# moment attribution matters most.
#
# hold:author is unaffected — it names the PR's one author lane already, so it
# has no multi-holder ambiguity to fix.

set -euo pipefail

kind="${1:-}"
action="${2:-}"
pr="${3:-}"
shift 3 2>/dev/null || true
agent="${CLAIM_AGENT:-}"

usage() {
  cat >&2 <<'EOF'
usage:
  CLAIM_AGENT=<stable-agent-id> hold.sh review add   <pr-number>
  CLAIM_AGENT=<stable-agent-id> hold.sh review clear  <pr-number>
  CLAIM_AGENT=<steward-id>      hold.sh review clear  <pr-number> --steward <holder-agent> --reason "<evidence>"
EOF
  exit 2
}

[[ "$kind" == "review" ]] || usage
[[ "$action" == "add" || "$action" == "clear" ]] || usage
[[ "$pr" =~ ^[0-9]+$ ]] || usage

steward_target=""
reason=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --steward) steward_target="${2:-}"; shift 2 ;;
    --reason)  reason="${2:-}"; shift 2 ;;
    *) usage ;;
  esac
done

if [[ -n "$steward_target" || -n "$reason" ]]; then
  [[ "$action" == "clear" ]] || { echo "--steward/--reason only apply to clear" >&2; usage; }
  [[ -n "$steward_target" && -n "$reason" ]] || {
    echo "--steward and --reason must both be given — a steward transfer without a recorded reason is exactly the prose the gate does not read" >&2
    exit 2
  }
fi

if [[ ! "$agent" =~ ^[a-z0-9]+([._-][a-z0-9]+)*$ ]]; then
  echo "CLAIM_AGENT must be a lower-case stable agent id (without agent:)" >&2
  exit 2
fi

if [[ -n "$steward_target" ]]; then
  if [[ ! "$steward_target" =~ ^[a-z0-9]+([._-][a-z0-9]+)*$ ]]; then
    echo "--steward must be a lower-case stable agent id (without agent:)" >&2
    exit 2
  fi
  if [[ "$steward_target" == "$agent" ]]; then
    echo "refusing: --steward $steward_target is your own id — clear your own hold without --steward" >&2
    exit 2
  fi
fi

repo=$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null) || {
  echo "cannot resolve the repository from gh" >&2
  exit 2
}

pr_json=$(gh pr view "$pr" --repo "$repo" --json number,state 2>/dev/null) || {
  echo "cannot read PR #$pr from $repo" >&2
  exit 2
}

state=$(jq -r .state <<<"$pr_json")
if [[ "$state" != "OPEN" ]]; then
  echo "refusing #$pr: state is $state" >&2
  exit 1
fi

holder="${steward_target:-$agent}"
hold_label="hold:review:$holder"

if [[ "$action" == "add" ]]; then
  # Labels on live Issues and PRs are the identity registry (see claim.sh) —
  # create this holder's label only after validation, before it's applied.
  # `$label` is a jq keyword (label $out | break $out) and a parse error as a
  # jq variable name (#403) — named `$want` here for the same reason.
  labels=$(gh label list --repo "$repo" --limit 1000 --json name 2>/dev/null) || {
    echo "cannot read labels from $repo" >&2
    exit 2
  }
  if ! jq -e --arg want "$hold_label" 'any(.name == $want)' <<<"$labels" >/dev/null; then
    gh label create "$hold_label" --repo "$repo" --color "b60205" \
      --description "AI-team review hold: open finding from $holder — that agent clears it"
  fi
  gh pr edit "$pr" --repo "$repo" --add-label "$hold_label" >/dev/null
  echo "set $hold_label on PR #$pr"
else
  gh pr edit "$pr" --repo "$repo" --remove-label "$hold_label" >/dev/null 2>&1 || true
  if [[ -n "$steward_target" ]]; then
    comment_file=$(mktemp)
    trap 'rm -f "$comment_file"' EXIT
    {
      printf '**From:** %s\n\n' "$agent"
      printf '**Type:** status\n\n'
      printf 'Steward-cleared %s — %s clearing on their behalf as an unresponsive holder.\n\n' \
        "$hold_label" "$steward_target"
      printf 'Discharge evidence: %s\n' "$reason"
    } >"$comment_file"
    gh pr comment "$pr" --repo "$repo" --body-file "$comment_file" >/dev/null
    echo "steward-cleared $hold_label on PR #$pr (reason recorded on the PR)"
  else
    echo "cleared $hold_label on PR #$pr"
  fi
fi
