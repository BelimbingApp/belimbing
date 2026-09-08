#!/usr/bin/env bash
#
# Secrets audit (#780): list every `secrets.*` reference across the workflow
# directory and refuse any name that is absent from the checked-in allowlist
# docs/ci/secrets.json (name, purpose, owner, rotation date). A rotation date
# older than 90 days is a warning, not a failure; a malformed allowlist entry
# (missing or blank name/purpose/owner/rotated, or a non-ISO date) fails.
# GITHUB_TOKEN is the workflow-scoped installation token, not a repository
# secret, so it is exempt. No secret values are read — only workflow text.
#
# Both reference forms are matched and normalised to one name:
# `secrets.NAME` and `secrets['NAME']` / `secrets["NAME"]`.
#
# Out of static reach (#825): a computed index such as
# `secrets[format('TOKEN_{0}', github.ref_name)]` cannot be resolved to a
# name, and `secrets: inherit` on a reusable-workflow call passes every
# repository secret without naming any. Those forms are warned (and refused
# under `--strict`) rather than silently counted as allowlisted.
#
#   token-audit.sh [--workflows DIR] [--allowlist FILE] [--today YYYY-MM-DD] [--strict]
#
set -euo pipefail

workflows=.github/workflows
allowlist=docs/ci/secrets.json
today=$(date -u +%Y-%m-%d)
max_age_days=90
strict=0

while (($# > 0)); do
  case "$1" in
    --workflows) workflows=${2:?--workflows needs a directory}; shift 2 ;;
    --allowlist) allowlist=${2:?--allowlist needs a file}; shift 2 ;;
    --today) today=${2:?--today needs YYYY-MM-DD}; shift 2 ;;
    --strict) strict=1; shift ;;
    *) echo "token-audit: unknown argument $1" >&2; exit 2 ;;
  esac
done

if [[ ! -d "$workflows" ]]; then
  echo "token-audit: workflow directory $workflows is missing" >&2
  exit 2
fi
if [[ ! -f "$allowlist" ]]; then
  echo "token-audit: allowlist $allowlist is missing" >&2
  exit 1
fi

# name<TAB>file, one row per referencing file, GITHUB_TOKEN exempt.
# secrets.NAME and secrets['NAME'] / secrets["NAME"] both normalise to NAME.
mapfile -t references < <(
  grep -rHoE "secrets\.[A-Za-z_][A-Za-z0-9_]*|secrets\[[[:space:]]*['\"][A-Za-z_][A-Za-z0-9_]*['\"][[:space:]]*\]" \
    --include='*.yml' --include='*.yaml' "$workflows" \
    | sed -E "s/^([^:]+):secrets\.(.*)\$/\2\t\1/; s/^([^:]+):secrets\[[[:space:]]*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"][[:space:]]*\]\$/\2\t\1/" \
    | grep -v $'^GITHUB_TOKEN\t' \
    | sort -u || true
)

# Forms the static name scan cannot resolve: computed secrets[...] keys, and
# secrets: inherit on a reusable-workflow call. path<TAB>form, unique.
mapfile -t unverifiable < <(
  {
    # Any secrets[ that is not a quoted literal identifier is a computed key.
    while IFS= read -r -d '' file; do
      grep -nE 'secrets\[' -- "$file" \
        | grep -vE "secrets\[[[:space:]]*['\"][A-Za-z_][A-Za-z0-9_]*['\"][[:space:]]*\]" \
        | while IFS= read -r hit; do
            printf '%s\t%s\n' "$file" 'computed-key'
          done
    done < <(find "$workflows" \( -name '*.yml' -o -name '*.yaml' \) -print0)

    while IFS= read -r -d '' file; do
      if grep -qE '^[[:space:]]*secrets:[[:space:]]*inherit[[:space:]]*$' -- "$file"; then
        printf '%s\t%s\n' "$file" 'secrets: inherit'
      fi
    done < <(find "$workflows" \( -name '*.yml' -o -name '*.yaml' \) -print0)
  } | sort -u || true
)

unresolvable=${#unverifiable[@]}
# mapfile yields one empty element when there is no input under some bash
# versions; treat a single blank row as zero hits.
if (( unresolvable == 1 )) && [[ -z "${unverifiable[0]:-}" ]]; then
  unresolvable=0
  unverifiable=()
fi

for row in "${unverifiable[@]+"${unverifiable[@]}"}"; do
  [[ -z "$row" ]] && continue
  path=${row%%$'\t'*}
  form=${row#*$'\t'}
  echo "::warning file=${path}::token-audit cannot verify ${form}"
done
if (( unresolvable > 0 )); then
  echo "token-audit: ${unresolvable} reference(s) not statically verifiable"
fi

REFERENCES=$(printf '%s\n' "${references[@]}") TODAY=$today MAX_AGE_DAYS=$max_age_days \
UNRESOLVABLE=$unresolvable STRICT=$strict \
python3 - "$allowlist" <<'PY'
import json
import os
import sys
from datetime import date

allowlist_path = sys.argv[1]
today = date.fromisoformat(os.environ["TODAY"])
max_age = int(os.environ["MAX_AGE_DAYS"])
unresolvable = int(os.environ["UNRESOLVABLE"])
strict = os.environ["STRICT"] == "1"
failures = []

try:
    with open(allowlist_path, encoding="utf-8") as handle:
        payload = json.load(handle)
except ValueError as exc:
    print(f"token-audit: {allowlist_path} is not valid JSON: {exc}", file=sys.stderr)
    sys.exit(1)

entries = payload.get("secrets") if isinstance(payload, dict) else None
if not isinstance(entries, list):
    print(f"token-audit: {allowlist_path} must be an object with a 'secrets' array", file=sys.stderr)
    sys.exit(1)

allowed = {}
for index, entry in enumerate(entries):
    label = f"{allowlist_path} entry {index}"
    if not isinstance(entry, dict):
        failures.append(f"{label}: not an object")
        continue
    label = f"{allowlist_path} entry {entry.get('name') or index}"
    for field in ("name", "purpose", "owner", "rotated"):
        value = entry.get(field)
        if not isinstance(value, str) or not value.strip():
            failures.append(f"{label}: missing {field}")
    rotated = entry.get("rotated")
    if isinstance(rotated, str) and rotated.strip():
        try:
            entry["_rotated"] = date.fromisoformat(rotated)
        except ValueError:
            failures.append(f"{label}: rotated {rotated!r} is not an ISO date (YYYY-MM-DD)")
    name = entry.get("name")
    if isinstance(name, str) and name.strip():
        if name in allowed:
            failures.append(f"{label}: duplicate name")
        allowed[name] = entry

referenced = {}
for row in os.environ["REFERENCES"].splitlines():
    if not row.strip():
        continue
    name, path = row.split("\t", 1)
    referenced.setdefault(name, []).append(path)

print(f"token-audit: {len(referenced)} secret name(s) referenced under workflows "
      f"(GITHUB_TOKEN exempt), {len(allowed)} allowlisted in {allowlist_path}")
for name in sorted(referenced):
    files = ", ".join(sorted(referenced[name]))
    entry = allowed.get(name)
    if entry is None:
        failures.append(f"secret {name} is referenced ({files}) but absent from {allowlist_path}")
        print(f"  {name}: UNLISTED ({files})")
        continue
    owner = entry.get("owner") or "?"
    print(f"  {name}: owner={owner} rotated={entry.get('rotated') or '?'} — {entry.get('purpose') or '?'} ({files})")
    rotated = entry.get("_rotated")
    if rotated is not None:
        age = (today - rotated).days
        if age > max_age:
            print(f"::warning::secret {name} was last rotated {rotated} ({age} days ago, "
                  f"limit {max_age}); owner {owner} should rotate it")

for name in sorted(set(allowed) - set(referenced)):
    print(f"::warning::allowlisted secret {name} is not referenced by any workflow; drop it from {allowlist_path} once retired")

if failures:
    for failure in failures:
        print(f"token-audit: {failure}", file=sys.stderr)
    sys.exit(1)
if unresolvable > 0:
    print("token-audit: every statically resolvable referenced secret is allowlisted")
    if strict:
        print(
            f"token-audit: --strict refuses {unresolvable} reference(s) not statically verifiable",
            file=sys.stderr,
        )
        sys.exit(1)
else:
    print("token-audit: every referenced secret is allowlisted")
PY
