#!/usr/bin/env bash
#
# Hand a claimed draft PR to independent review. Re-asserts the issue-closing
# keyword before the author (or anyone) can leave draft with a rewritten body
# that dropped what claim.sh wrote.
#
#   CLAIM_AGENT=<stable-agent-id> docs/ai-team/scripts/ready.sh <pr-number>
#
# Optional: READY_ISSUE=<n> when the PR title/branch does not carry (#n) /
# issue-<n>. The agent label on the PR must match CLAIM_AGENT.

set -euo pipefail

pr="${1:-}"
agent="${CLAIM_AGENT:-}"

if [[ $# -ne 1 || ! "$pr" =~ ^[0-9]+$ ]]; then
  echo "usage: CLAIM_AGENT=<stable-agent-id> $0 <pr-number>" >&2
  exit 2
fi

if [[ ! "$agent" =~ ^[a-z0-9]+([._-][a-z0-9]+)*$ ]]; then
  echo "CLAIM_AGENT must be a lower-case stable agent id (without agent:)" >&2
  exit 2
fi

repo=$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null) || {
  echo "cannot resolve the repository from gh" >&2
  exit 2
}

pr_json=$(gh pr view "$pr" --repo "$repo" \
  --json number,title,body,headRefName,labels,isDraft,state 2>/dev/null) || {
  echo "cannot read PR #$pr from $repo" >&2
  exit 2
}

state=$(jq -r .state <<<"$pr_json")
if [[ "$state" != "OPEN" ]]; then
  echo "refusing #$pr: state is $state" >&2
  exit 1
fi

holders=$(jq -r '[.labels[].name | select(startswith("agent:"))] | join(",")' <<<"$pr_json")
if [[ "$holders" != "agent:$agent" ]]; then
  echo "refusing #$pr: expected sole owner agent:$agent, found [${holders:-none}]" >&2
  exit 1
fi

issue="${READY_ISSUE:-}"
if [[ -z "$issue" ]]; then
  title=$(jq -r '.title // ""' <<<"$pr_json")
  branch=$(jq -r '.headRefName // ""' <<<"$pr_json")
  if [[ "$title" =~ \(#([0-9]+)\) ]]; then
    issue="${BASH_REMATCH[1]}"
  elif [[ "$branch" =~ (^|[-_/])issue-?([0-9]+)($|[-_/]) ]]; then
    issue="${BASH_REMATCH[2]}"
  fi
fi

if [[ -z "$issue" || ! "$issue" =~ ^[0-9]+$ ]]; then
  echo "refusing #$pr: cannot derive issue number; pass READY_ISSUE=<n>" >&2
  exit 1
fi

body=$(jq -r '.body // ""' <<<"$pr_json")
# GitHub closing keywords are case-insensitive; keep Closes for consistency with claim.sh.
if ! grep -qiE "(^|[^A-Za-z])(close[sd]?|fix(e[sd])?|resolve[sd]?)[[:space:]]+#${issue}([^0-9]|$)" <<<"$body"; then
  if [[ -n "$body" && "$body" != *$'\n' ]]; then
    body+=$'\n'
  fi
  body+=$'\n'"Closes #${issue}"$'\n'
  body_file=$(mktemp)
  trap 'rm -f "$body_file"' EXIT
  printf '%s' "$body" >"$body_file"
  gh pr edit "$pr" --repo "$repo" --body-file "$body_file"
  echo "re-asserted Closes #$issue on PR #$pr"
fi

if [[ $(jq -r .isDraft <<<"$pr_json") == "true" ]]; then
  gh pr ready "$pr" --repo "$repo"
fi

# Label edits are best-effort when a label is already absent/present.
gh pr edit "$pr" --repo "$repo" --remove-label task:active >/dev/null 2>&1 || true
gh pr edit "$pr" --repo "$repo" --add-label task:review >/dev/null
gh issue edit "$issue" --repo "$repo" --remove-label task:active >/dev/null 2>&1 || true
gh issue edit "$issue" --repo "$repo" --add-label task:review >/dev/null

echo "PR #$pr ready for review (Closes #$issue)"
