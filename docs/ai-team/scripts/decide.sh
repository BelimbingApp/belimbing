#!/usr/bin/env bash
# decide.sh — autonomous deliberation and voting on the owning issue (#430).
#
# Replaces "stop and ask the owner" for product/architecture choices with one
# machine-readable flow: propose, vote, close. State lives entirely in
# structured comments (board.sh's **From:**/**Type:** header plus this
# script's own **Decision:**/**Option:**/... fields) — no new labels, so
# gate.sh, orient.sh, and board.sh's existing comment-scanning idioms all
# apply unchanged.
#
#   CLAIM_AGENT=<id> decide.sh propose <issue> --id <decision-id> \
#     --question "<question>" --options "optA,optB,optC" --recommend optA \
#     [--deadline-minutes N] [evidence/trade-off body…]
#
#   CLAIM_AGENT=<id> decide.sh vote <issue> --id <decision-id> --option optA \
#     [rationale, tied to the authority stack…]
#
#   CLAIM_AGENT=<id> decide.sh close <issue> --id <decision-id> \
#     [--decision <option> --rationale "<tie-break/available-tally reasoning>"] \
#     [--owner <agent>] [--revisit-if "<condition that would reopen this>"]
#
#   decide.sh status <issue> [--id <decision-id>]
#
# Quorum: with 3+ currently active agents (an open PR or an open agent:*
# issue — the same roster board.sh's hygiene pass already scans), 3 distinct
# attributable votes are quorum. With fewer active agents, every one of them
# must vote. A clear majority among quorum-reached votes closes on its own;
# a tie, or a closed deadline without quorum, requires the closer to pass
# --decision/--rationale explicitly — this script never guesses a tie-break,
# it only refuses to let the round stall.
#
# What this cannot do: repeal an explicit owner prohibition, a repository
# safety rule, review independence, a live hold, or a missing external
# credential/permission. Those are recorded as a recommendation and a
# request for the specific missing authority, never voted around (see
# docs/ai-team/README.md, "Autonomous deliberation").

set -uo pipefail

REPO="${DECIDE_REPO:-${BOARD_REPO:-BelimbingApp/belimbing}}"
BOARD_SH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/board.sh"
MAX_DEADLINE_MINUTES=30

AGENT_RE='^[a-z0-9]+([._-][a-z0-9]+)*$'
DECISION_ID_RE='^[a-z0-9]+(-[a-z0-9]+)*$'
OPTION_RE='^[A-Za-z0-9][A-Za-z0-9 _.-]*$'

usage() {
  sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'
  exit 2
}

command="${1:-}"
[ -n "$command" ] || usage
shift

agent="${CLAIM_AGENT:-}"
require_agent() {
  if [[ ! "$agent" =~ $AGENT_RE ]]; then
    echo "CLAIM_AGENT must be a lower-case stable agent id (without agent:)" >&2
    exit 2
  fi
}

require_decision_id() {
  local id="$1"
  if [[ ! "$id" =~ $DECISION_ID_RE ]]; then
    echo "--id must be lower-case kebab-case (e.g. locale-fallback-order): got '$id'" >&2
    exit 2
  fi
}

resolve_repo() {
  gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null || printf '%s' "$REPO"
}

# Every agent with a live lane: an open PR or an open agent:*-labelled issue.
# Same roster board.sh's hygiene pass already scans (#385's active-lanes
# definition) — reused here rather than invented separately, so "active" has
# exactly one meaning across the charter.
active_agents() {
  local repo="$1"
  {
    gh pr list --repo "$repo" --state open --limit 100 --json labels \
      --jq '.[].labels[].name | select(startswith("agent:")) | ltrimstr("agent:")' 2>/dev/null
    gh issue list --repo "$repo" --state open --limit 100 --json labels \
      --jq '.[].labels[].name | select(startswith("agent:")) | ltrimstr("agent:")' 2>/dev/null
  } | sort -u
}

fetch_comments() {
  local repo="$1" issue="$2"
  gh issue view "$issue" --repo "$repo" --json comments 2>/dev/null
}

# Filters $comments (issue-view JSON) down to the structured decide.sh
# entries: exactly one **From:** agent, a **Type:** of proposal/vote/decision,
# and a **Decision:** id — reusing gate.sh's "collect every capture, accept
# only an unambiguous single match" idiom for both From and Decision so a
# malformed header excludes a post from the tally instead of corrupting it.
DECIDE_JQ_COMMON='
  # capture() on a non-matching line produces no output at all, not null — so
  # a bare "capture(...).v // fallback" inside split("\n")[] applies the
  # fallback PER LINE, turning one field into one output per line of the
  # body (mostly the fallback) instead of a single value. Collecting into an
  # array first, the way from_agent always did, is the only safe form; every
  # field extractor here goes through it.
  def one_capture_raw(pattern):
    [((.body // "") | split("\n")[] | capture(pattern; "i") | .v)]
    | unique
    | if length == 1 then .[0] else "" end;
  def one_capture(pattern): one_capture_raw(pattern) | ascii_downcase;
  def from_agent: one_capture("^\\*\\*From:\\*\\*[[:space:]]*(?<v>[a-z0-9]+(?:[._-][a-z0-9]+)*)(?:[[:space:]]|$)");
  def decide_type: one_capture("^\\*\\*Type:\\*\\*[[:space:]]*(?<v>[a-z]+)[[:space:]]*$");
  def decision_id: one_capture("^\\*\\*Decision:\\*\\*[[:space:]]*(?<v>[a-z0-9][a-z0-9-]*)[[:space:]]*$");
  # Unambiguous From plus a matching Decision id — callers add their own
  # decide_type == "proposal"/"vote"/"decision" check, since the type and the
  # id are independent facts about the same comment.
  def structured($id):
    from_agent != "" and decision_id == $id;
'

propose() {
  local issue="${1:-}"; shift || true
  [[ "$issue" =~ ^[0-9]+$ ]] || { echo "propose: issue number required" >&2; exit 2; }

  local id="" question="" options_csv="" recommend="" deadline_minutes="$MAX_DEADLINE_MINUTES"
  local body=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --id) id="${2:-}"; shift 2 ;;
      --question) question="${2:-}"; shift 2 ;;
      --options) options_csv="${2:-}"; shift 2 ;;
      --recommend) recommend="${2:-}"; shift 2 ;;
      --deadline-minutes) deadline_minutes="${2:-}"; shift 2 ;;
      *) body="${body:+$body }$1"; shift ;;
    esac
  done

  require_agent
  [ -n "$id" ] || { echo "propose: --id required" >&2; exit 2; }
  require_decision_id "$id"
  [ -n "$question" ] || { echo "propose: --question required" >&2; exit 2; }
  [ -n "$options_csv" ] || { echo "propose: --options required (comma-separated)" >&2; exit 2; }
  [ -n "$recommend" ] || { echo "propose: --recommend required" >&2; exit 2; }
  [[ "$deadline_minutes" =~ ^[0-9]+$ ]] && [ "$deadline_minutes" -ge 1 ] && [ "$deadline_minutes" -le "$MAX_DEADLINE_MINUTES" ] || {
    echo "propose: --deadline-minutes must be 1..$MAX_DEADLINE_MINUTES (one heartbeat)" >&2
    exit 2
  }

  local -a options=()
  local opt trimmed
  IFS=',' read -ra options <<<"$options_csv"
  local seen="" clean_options=()
  for opt in "${options[@]}"; do
    trimmed="${opt#"${opt%%[![:space:]]*}"}"
    trimmed="${trimmed%"${trimmed##*[![:space:]]}"}"
    [ -n "$trimmed" ] || { echo "propose: empty option in --options" >&2; exit 2; }
    [[ "$trimmed" =~ $OPTION_RE ]] || { echo "propose: option '$trimmed' has characters outside [A-Za-z0-9 _.-] (commas separate options, so they cannot appear inside one)" >&2; exit 2; }
    case ",$seen," in
      *",$trimmed,"*) echo "propose: duplicate option '$trimmed'" >&2; exit 2 ;;
    esac
    seen="${seen:+$seen,}$trimmed"
    clean_options+=("$trimmed")
  done
  [ "${#clean_options[@]}" -ge 2 ] || { echo "propose: need at least 2 distinct options" >&2; exit 2; }
  case ",$seen," in
    *",$recommend,"*) ;;
    *) echo "propose: --recommend '$recommend' is not one of the declared --options" >&2; exit 2 ;;
  esac

  local repo; repo=$(resolve_repo)
  local comments; comments=$(fetch_comments "$repo" "$issue") || { echo "propose: cannot read #$issue from $repo" >&2; exit 2; }

  local existing
  existing=$(printf '%s' "$comments" | jq -r --arg id "$id" "$DECIDE_JQ_COMMON"'
    [.comments[] | select(structured($id) and decide_type == "proposal")] | length' 2>/dev/null || echo 0)
  if [ "${existing:-0}" -gt 0 ]; then
    echo "propose: decision id '$id' already has a proposal on #$issue — decision ids are permanent per issue, pick a new one" >&2
    exit 1
  fi

  local deadline
  deadline=$(date -u -d "+${deadline_minutes} minutes" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null) \
    || deadline=$(date -u -v"+${deadline_minutes}M" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null) \
    || { echo "propose: could not compute the deadline timestamp" >&2; exit 2; }

  local payload
  payload=$(printf '**Decision:** %s\n**Options:** %s\n**Recommend:** %s\n**Deadline:** %s\n\n%s\n\n%s' \
    "$id" "$seen" "$recommend" "$deadline" "$question" "$body")

  CLAIM_AGENT="$agent" "$BOARD_SH" post "$issue" --agent "$agent" --type proposal "$payload" || {
    echo "propose: could not post '$id' to #$issue — nothing recorded" >&2
    exit 2
  }
  echo "proposed '$id' on #$issue — options: $seen — deadline $deadline"
}

vote() {
  local issue="${1:-}"; shift || true
  [[ "$issue" =~ ^[0-9]+$ ]] || { echo "vote: issue number required" >&2; exit 2; }

  local id="" option="" body=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --id) id="${2:-}"; shift 2 ;;
      --option) option="${2:-}"; shift 2 ;;
      *) body="${body:+$body }$1"; shift ;;
    esac
  done

  require_agent
  [ -n "$id" ] || { echo "vote: --id required" >&2; exit 2; }
  require_decision_id "$id"
  [ -n "$option" ] || { echo "vote: --option required" >&2; exit 2; }
  case "$option" in
    *,*) echo "vote: --option must name exactly one option, not a list" >&2; exit 2 ;;
  esac

  local repo; repo=$(resolve_repo)
  local comments; comments=$(fetch_comments "$repo" "$issue") || { echo "vote: cannot read #$issue from $repo" >&2; exit 2; }

  local proposal
  proposal=$(printf '%s' "$comments" | jq -c --arg id "$id" "$DECIDE_JQ_COMMON"'
    def opts: one_capture_raw("^\\*\\*Options:\\*\\*[[:space:]]*(?<v>.+)$");
    [.comments[] | select(structured($id) and decide_type == "proposal") | {options: opts, createdAt}]
    | if length == 1 then .[0] else null end' 2>/dev/null)
  if [ -z "$proposal" ] || [ "$proposal" = "null" ]; then
    echo "vote: no open proposal '$id' found on #$issue — check the id, or propose it first" >&2
    exit 1
  fi

  local closed
  closed=$(printf '%s' "$comments" | jq -r --arg id "$id" "$DECIDE_JQ_COMMON"'
    [.comments[] | select(structured($id) and decide_type == "decision")] | length' 2>/dev/null || echo 0)
  if [ "${closed:-0}" -gt 0 ]; then
    echo "vote: '$id' on #$issue is already closed — this round is over" >&2
    exit 1
  fi

  local declared_options
  declared_options=",$(printf '%s' "$proposal" | jq -r '.options'),"
  case "$declared_options" in
    *",$option,"*) ;;
    *)
      echo "vote: '$option' is not one of this proposal's declared options ($(printf '%s' "$proposal" | jq -r '.options'))" >&2
      exit 2
      ;;
  esac

  local payload
  payload=$(printf '**Decision:** %s\n**Option:** %s\n\n%s' "$id" "$option" "$body")

  CLAIM_AGENT="$agent" "$BOARD_SH" post "$issue" --agent "$agent" --type vote "$payload" || {
    echo "vote: could not post the vote on #$issue — nothing recorded" >&2
    exit 2
  }
  echo "voted '$option' on '$id' (#$issue)"
}

# Latest well-formed vote per agent for $id, cast after the proposal, with an
# unambiguous **From:** and exactly one declared **Option:** value. A vote
# with 0 or 2+ **Option:** lines, an unrecognized option, an ambiguous
# **From:**, or a decision id that does not exactly match is excluded here —
# never guessed into the tally.
tally_votes() {
  local comments="$1" id="$2" proposal_created_at="$3" declared_options_json="$4"
  printf '%s' "$comments" | jq -c \
    --arg id "$id" --arg after "$proposal_created_at" --argjson opts "$declared_options_json" \
    "$DECIDE_JQ_COMMON"'
    def one_option:
      [((.body // "") | split("\n")[] | capture("^\\*\\*Option:\\*\\*[[:space:]]*(?<v>.+)$"; "i").v)]
      | unique
      | if length == 1 and (. [0] as $o | $opts | index($o)) != null then .[0] else "" end;
    [.comments[]
     | select(decide_type == "vote" and decision_id == $id and .createdAt > $after)
     | . + {agent: from_agent, option: one_option}
     | select(.agent != "" and .option != "")]
    | sort_by(.agent, .createdAt)
    | group_by(.agent)
    | map(last)
  '
}

close() {
  local issue="${1:-}"; shift || true
  [[ "$issue" =~ ^[0-9]+$ ]] || { echo "close: issue number required" >&2; exit 2; }

  local id="" decision="" rationale="" owner="" revisit=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --id) id="${2:-}"; shift 2 ;;
      --decision) decision="${2:-}"; shift 2 ;;
      --rationale) rationale="${2:-}"; shift 2 ;;
      --owner) owner="${2:-}"; shift 2 ;;
      --revisit-if) revisit="${2:-}"; shift 2 ;;
      *) echo "close: unrecognized argument '$1'" >&2; exit 2 ;;
    esac
  done

  require_agent
  [ -n "$id" ] || { echo "close: --id required" >&2; exit 2; }
  require_decision_id "$id"
  owner="${owner:-$agent}"
  if [[ ! "$owner" =~ $AGENT_RE ]]; then
    echo "close: --owner must be a lower-case stable agent id" >&2
    exit 2
  fi

  local repo; repo=$(resolve_repo)
  local comments; comments=$(fetch_comments "$repo" "$issue") || { echo "close: cannot read #$issue from $repo" >&2; exit 2; }

  local proposal
  proposal=$(printf '%s' "$comments" | jq -c --arg id "$id" "$DECIDE_JQ_COMMON"'
    def opts: one_capture_raw("^\\*\\*Options:\\*\\*[[:space:]]*(?<v>.+)$");
    def deadline: one_capture_raw("^\\*\\*Deadline:\\*\\*[[:space:]]*(?<v>.+)$");
    [.comments[] | select(structured($id) and decide_type == "proposal") | {options: opts, deadline: deadline, createdAt, proposer: from_agent}]
    | if length == 1 then .[0] else null end' 2>/dev/null)
  if [ -z "$proposal" ] || [ "$proposal" = "null" ]; then
    echo "close: no proposal '$id' found on #$issue" >&2
    exit 1
  fi

  local already_closed
  already_closed=$(printf '%s' "$comments" | jq -r --arg id "$id" "$DECIDE_JQ_COMMON"'
    [.comments[] | select(structured($id) and decide_type == "decision")] | length' 2>/dev/null || echo 0)
  if [ "${already_closed:-0}" -gt 0 ]; then
    echo "close: '$id' on #$issue is already closed" >&2
    exit 1
  fi

  local options_csv proposal_created_at deadline_str
  options_csv=$(printf '%s' "$proposal" | jq -r '.options')
  proposal_created_at=$(printf '%s' "$proposal" | jq -r '.createdAt')
  deadline_str=$(printf '%s' "$proposal" | jq -r '.deadline')
  local options_json; options_json=$(printf '%s\n' "$options_csv" | jq -R 'split(",")')

  local votes; votes=$(tally_votes "$comments" "$id" "$proposal_created_at" "$options_json")
  local voting_agents_json; voting_agents_json=$(printf '%s' "$votes" | jq -c '[.[].agent] | unique')
  local voter_count; voter_count=$(printf '%s' "$voting_agents_json" | jq 'length')

  local roster; roster=$(active_agents "$repo")
  local roster_json; roster_json=$(printf '%s\n' "$roster" | jq -R 'select(length > 0)' | jq -s '.')
  local roster_count; roster_count=$(printf '%s' "$roster_json" | jq 'length')
  if [ "$roster_count" -eq 0 ]; then
    echo "close: no currently active agents found (open PRs or open agent:* issues) — cannot establish a quorum roster; check gh connectivity before closing" >&2
    exit 2
  fi

  local quorum_met="false"
  if [ "$roster_count" -ge 3 ]; then
    [ "$voter_count" -ge 3 ] && quorum_met="true"
  else
    quorum_met=$(jq -n --argjson roster "$roster_json" --argjson voters "$voting_agents_json" \
      '(($roster - ($roster - $voters)) | length) == ($roster | length)')
  fi

  local now_epoch deadline_epoch deadline_passed="false"
  now_epoch=$(date -u +%s)
  deadline_epoch=$(date -u -d "$deadline_str" +%s 2>/dev/null || date -u -jf '%Y-%m-%dT%H:%M:%SZ' "$deadline_str" +%s 2>/dev/null)
  if [ -n "${deadline_epoch:-}" ] && [ "$now_epoch" -ge "$deadline_epoch" ]; then
    deadline_passed="true"
  fi

  local tally_json; tally_json=$(printf '%s' "$votes" | jq -c 'group_by(.option) | map({option: .[0].option, count: length}) | sort_by(-.count, .option)')
  local top_count; top_count=$(printf '%s' "$tally_json" | jq '[.[].count] | max // 0')
  local leaders_json; leaders_json=$(printf '%s' "$tally_json" | jq -c --argjson top "$top_count" '[.[] | select(.count == $top) | .option]')
  local leader_count; leader_count=$(printf '%s' "$leaders_json" | jq 'length')
  local is_tie="false"
  [ "$top_count" -gt 0 ] && [ "$leader_count" -gt 1 ] && is_tie="true"

  local tally_summary; tally_summary=$(printf '%s' "$tally_json" | jq -r 'map("\(.option)=\(.count)") | join(", ")')
  [ -n "$tally_summary" ] || tally_summary="(no valid votes recorded)"

  local chosen="" needs_rationale="false" quorum_note=""
  if [ "$quorum_met" = "true" ] && [ "$is_tie" = "false" ] && [ "$top_count" -gt 0 ]; then
    chosen=$(printf '%s' "$leaders_json" | jq -r '.[0]')
    quorum_note="met (active=$roster_count, voted=$voter_count)"
    if [ -n "$decision" ] && [ "$decision" != "$chosen" ]; then
      echo "close: --decision '$decision' overrides a clear quorum majority of '$chosen' — that is exactly the override the authority stack forbids without a recorded reason; use it only when the majority itself is the tie/expired case, or drop --decision to accept '$chosen'" >&2
      exit 2
    fi
  elif [ "$quorum_met" = "true" ] && [ "$is_tie" = "true" ]; then
    needs_rationale="true"
    quorum_note="met but tied (active=$roster_count, voted=$voter_count, tied: $(printf '%s' "$leaders_json" | jq -r 'join(", ")'))"
  elif [ "$deadline_passed" = "true" ]; then
    needs_rationale="true"
    quorum_note="not met by the deadline (active=$roster_count, voted=$voter_count) — round does not stall, the deciding agent records the available tally"
  else
    echo "close: '$id' on #$issue is not yet decidable — quorum not met (active=$roster_count, voted=$voter_count) and the deadline ($deadline_str) has not passed. Vote, or wait for the deadline." >&2
    exit 1
  fi

  if [ "$needs_rationale" = "true" ]; then
    [ -n "$decision" ] && [ -n "$rationale" ] || {
      echo "close: quorum is $quorum_note — this requires an explicit --decision <option> and --rationale \"<why, tied to the authority stack>\" from the closer; the script does not guess a tie-break" >&2
      exit 2
    }
    local ok=""
    case ",$options_csv," in
      *",$decision,"*) ok=1 ;;
    esac
    [ -n "$ok" ] || { echo "close: --decision '$decision' is not one of the proposal's declared options ($options_csv)" >&2; exit 2; }
    chosen="$decision"
  fi

  [ -n "$revisit" ] || revisit="new evidence that changes the trade-offs recorded above, or an explicit owner override"

  local minority
  minority=$(printf '%s' "$votes" | jq -r --arg chosen "$chosen" -c '
    [.[] | select(.option != $chosen)]
    | group_by(.agent)
    | map(.[0])
    | .[]
    | "- \(.agent) → \(.option)"' 2>/dev/null)
  [ -n "$minority" ] || minority="(no dissenting votes recorded)"

  local payload
  payload=$(printf '**Decision:** %s\n**Chosen:** %s\n**Tally:** %s\n**Quorum:** %s\n**Deciding-Agent:** %s\n**Implementation-Owner:** %s\n**Revisit-If:** %s\n' \
    "$id" "$chosen" "$tally_summary" "$quorum_note" "$agent" "$owner" "$revisit")
  if [ -n "$rationale" ]; then
    payload="${payload}**Tie-Break:** ${rationale}
"
  fi
  payload="${payload}
Minority votes:
${minority}"

  CLAIM_AGENT="$agent" "$BOARD_SH" post "$issue" --agent "$agent" --type decision "$payload" || {
    echo "close: could not post the decision record on #$issue — nothing recorded, round is still open" >&2
    exit 2
  }
  echo "closed '$id' on #$issue — chosen: $chosen (quorum $quorum_note)"
}

status() {
  local issue="${1:-}"; shift || true
  [[ "$issue" =~ ^[0-9]+$ ]] || { echo "status: issue number required" >&2; exit 2; }

  local only_id=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --id) only_id="${2:-}"; shift 2 ;;
      *) shift ;;
    esac
  done

  local repo; repo=$(resolve_repo)
  local comments; comments=$(fetch_comments "$repo" "$issue") || { echo "status: cannot read #$issue from $repo" >&2; exit 2; }

  local proposals
  proposals=$(printf '%s' "$comments" | jq -c "$DECIDE_JQ_COMMON"'
    def opts: one_capture_raw("^\\*\\*Options:\\*\\*[[:space:]]*(?<v>.+)$");
    def deadline: one_capture_raw("^\\*\\*Deadline:\\*\\*[[:space:]]*(?<v>.+)$");
    [.comments[] | select(from_agent != "" and decide_type == "proposal") | {id: decision_id, options: opts, deadline: deadline, createdAt, proposer: from_agent}]
    | unique_by(.id)')

  local closed_ids
  closed_ids=$(printf '%s' "$comments" | jq -c "$DECIDE_JQ_COMMON"'
    [.comments[] | select(from_agent != "" and decide_type == "decision") | decision_id] | unique')

  local rows; rows=$(printf '%s' "$proposals" | jq -c --argjson closed "$closed_ids" '[.[] | select(([.id] | inside($closed)) | not)]')
  if [ -n "$only_id" ]; then
    rows=$(printf '%s' "$rows" | jq -c --arg id "$only_id" '[.[] | select(.id == $id)]')
  fi

  local count; count=$(printf '%s' "$rows" | jq 'length')
  if [ "$count" -eq 0 ]; then
    [ -n "$only_id" ] && echo "status: '$only_id' on #$issue is not open (closed, or never proposed)"
    return 0
  fi

  local now_epoch; now_epoch=$(date -u +%s)
  printf '%s' "$rows" | jq -r '.[] | [.id, .deadline, .proposer, .options] | @tsv' | \
  while IFS=$'\t' read -r rid rdeadline rproposer roptions; do
    local votes voter_count deadline_epoch state
    votes=$(tally_votes "$comments" "$rid" "$(printf '%s' "$proposals" | jq -r --arg id "$rid" '.[] | select(.id == $id) | .createdAt')" "$(printf '%s\n' "$roptions" | jq -R 'split(",")')")
    voter_count=$(printf '%s' "$votes" | jq -c '[.[].agent] | unique | length')
    deadline_epoch=$(date -u -d "$rdeadline" +%s 2>/dev/null || date -u -jf '%Y-%m-%dT%H:%M:%SZ' "$rdeadline" +%s 2>/dev/null)
    state="open"
    if [ -n "${deadline_epoch:-}" ] && [ "$now_epoch" -ge "$deadline_epoch" ]; then
      state="deadline passed — ready to close"
    fi
    echo "  #$issue '$rid' (by $rproposer, options: $roptions) — $voter_count vote(s) so far, deadline $rdeadline ($state)"
  done
}

case "$command" in
  propose) propose "$@" ;;
  vote)    vote "$@" ;;
  close)   close "$@" ;;
  status)  status "$@" ;;
  *) usage ;;
esac
