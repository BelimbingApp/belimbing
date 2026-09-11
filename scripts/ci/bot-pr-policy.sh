#!/usr/bin/env bash
#
# Accept a bot-maintenance PR whose diff is confined to one machine-generated
# profile (#728): coverage baseline, Unit/Feature shard timings + membership, Pest
# timing baseline, Livewire action-debt baselines (#775), or a Domain descriptor
# + composed surface.
# Anything else — an extra path, a mix of profiles, or an empty diff — fails and
# names the unexpected path(s). The independent-review workflow uses this as the
# review substitute for labelled PRs; it must stay fail-closed.
#
#   bot-pr-policy.sh <base-sha> <head-sha>
#
set -euo pipefail

base=${1:?usage: bot-pr-policy.sh <base> <head>}
head=${2:?usage: bot-pr-policy.sh <base> <head>}

for endpoint in "$base" "$head"; do
  if ! git cat-file -e "$endpoint^{commit}" 2>/dev/null; then
    echo "bot-pr-policy: endpoint $endpoint is not present; refusing to judge" >&2
    exit 2
  fi
done

mapfile -t changed < <(git diff --name-only "$base" "$head" | sed '/^$/d' | sort -u)
if ((${#changed[@]} == 0)); then
  echo "bot-pr-policy: empty diff between $base and $head" >&2
  exit 1
fi

coverage_profile=(
  tests/ci/platform-coverage-baseline.json
)
timing_profile=(
  scripts/ci/platform-feature-shard-timings.json
  scripts/ci/platform-feature-shards.json
  scripts/ci/platform-unit-shard-timings.json
  scripts/ci/platform-unit-shards.json
)
pest_timing_profile=(
  tests/ci/pest-timing-baseline.json
)
livewire_actions_profile=(
  tests/ci/livewire-actions-baselines/platform.json
  tests/ci/livewire-actions-baselines/people.json
  tests/ci/livewire-actions-baselines/people-connector.json
  tests/ci/livewire-actions-baselines/commerce.json
  tests/ci/livewire-actions-baselines/operation.json
)

is_subset_of() {
  local -n allow=$1
  local path allowed
  for path in "${changed[@]}"; do
    allowed=0
    for candidate in "${allow[@]}"; do
      if [[ "$path" == "$candidate" ]]; then
        allowed=1
        break
      fi
    done
    if [[ "$allowed" -ne 1 ]]; then
      return 1
    fi
  done
  return 0
}

if is_subset_of coverage_profile || is_subset_of timing_profile || is_subset_of pest_timing_profile || is_subset_of livewire_actions_profile; then
  printf 'bot-pr-policy: accepted (%s)\n' "$(IFS=','; echo "${changed[*]}")"
  exit 0
fi

union=("${coverage_profile[@]}" "${timing_profile[@]}" "${pest_timing_profile[@]}" "${livewire_actions_profile[@]}")
extras=()
for path in "${changed[@]}"; do
  in_union=0
  for candidate in "${union[@]}"; do
    if [[ "$path" == "$candidate" ]]; then
      in_union=1
      break
    fi
  done
  if [[ "$in_union" -ne 1 ]]; then
    extras+=("$path")
  fi
done

if ((${#extras[@]} > 0)); then
  printf 'bot-pr-policy: unexpected path(s): %s\n' "${extras[*]}" >&2
  exit 1
fi

printf 'bot-pr-policy: mixes maintenance profiles: %s\n' "${changed[*]}" >&2
exit 1
