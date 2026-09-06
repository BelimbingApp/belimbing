#!/usr/bin/env bash
#
# Run one Pest invocation, record wall time and counts for the CI timing
# summary (#614). Output still streams to the job log.
#
#   MATRIX_SUITE=<job-label> TIMING_DIR=timing \
#     scripts/ci/record-pest-timing.sh <suite-label> -- <pest-args...>
#
set -euo pipefail

suite_label="${1:-}"
shift || true
if [[ -z "$suite_label" || "${1:-}" != "--" || $# -lt 2 ]]; then
  echo "usage: MATRIX_SUITE=<job> $0 <suite-label> -- <pest-args...>" >&2
  exit 2
fi
shift # --

job="${MATRIX_SUITE:-$suite_label}"
timing_dir="${TIMING_DIR:-timing}"
mkdir -p "$timing_dir"

safe="$(printf '%s' "$suite_label" | tr -c 'A-Za-z0-9._-' '_')"
json_path="$timing_dir/${job}__${safe}.json"
log="$(mktemp)"
trap 'rm -f -- "$log"' EXIT

start_ns="$(date +%s%N)"
set +e
./vendor/bin/pest "$@" 2>&1 | tee "$log"
status=${PIPESTATUS[0]}
set -e
end_ns="$(date +%s%N)"

python3 - "$json_path" "$job" "$suite_label" "$start_ns" "$end_ns" "$log" <<'PY'
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

json_path, job, suite, start_ns, end_ns, log_path = sys.argv[1:7]
wall = (int(end_ns) - int(start_ns)) / 1_000_000_000
text = Path(log_path).read_text(encoding="utf-8", errors="replace")

tests = 0
assertions = 0
# Pest footer: "Tests:    5 passed (8 assertions)" / "1 failed, 4 passed (8 assertions)"
tests_line = re.search(r"(?m)^\s*Tests:\s*(.+)$", text)
if tests_line:
    body = tests_line.group(1)
    assertions_match = re.search(r"\((\d+)\s+assertions?\)", body)
    if assertions_match:
        assertions = int(assertions_match.group(1))
    # Sum every "<n> <word>" count except the assertions parenthetical.
    for count in re.findall(r"(\d+)\s+(?:passed|failed|skipped|todos?|risky|incomplete|deprecated)", body):
        tests += int(count)

duration_match = re.search(r"(?m)^\s*Duration:\s*([0-9.]+)s\s*$", text)
pest_duration = float(duration_match.group(1)) if duration_match else None

payload = {
    "job": job,
    "suite": suite,
    "wall_seconds": round(wall, 3),
    "tests": tests,
    "assertions": assertions,
}
if pest_duration is not None:
    payload["pest_duration_seconds"] = pest_duration

Path(json_path).write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")

summary = (
    f"### Suite timing — `{suite}` (job `{job}`)\n\n"
    f"| Suite | Wall (s) | Tests | Assertions |\n"
    f"| --- | ---: | ---: | ---: |\n"
    f"| {suite} | {payload['wall_seconds']:.3f} | {tests} | {assertions} |\n"
)
print(summary, end="")
summary_path = __import__("os").environ.get("GITHUB_STEP_SUMMARY")
if summary_path:
    with open(summary_path, "a", encoding="utf-8") as handle:
        handle.write(summary)
PY

exit "$status"
