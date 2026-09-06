#!/usr/bin/env bash
# Refuse growth of the committed Larastan/PHPStan ignore baseline for app/Base
# (#651). Shrinking is allowed; raising phpstan-baseline.max requires an
# intentional review of new debt.
set -euo pipefail

root=$(git rev-parse --show-toplevel 2>/dev/null || pwd)
cd "$root"

baseline=${1:-phpstan-baseline.neon}
max_file=${2:-phpstan-baseline.max}

[[ -f "$baseline" ]] || { echo "missing baseline: $baseline" >&2; exit 2; }
[[ -f "$max_file" ]] || { echo "missing max file: $max_file" >&2; exit 2; }

count=$(python3 - "$baseline" <<'PY'
import re, sys
text = open(sys.argv[1], encoding='utf-8').read()
counts = [int(m.group(1)) for m in re.finditer(r'(?m)^\s+count:\s+(\d+)\s*$', text)]
print(sum(counts))
PY
)

max=$(tr -d '[:space:]' < "$max_file")
[[ "$max" =~ ^[0-9]+$ ]] || { echo "max file must be an integer: $max_file" >&2; exit 2; }

echo "phpstan baseline count: $count (max $max)"

if (( count > max )); then
  echo "phpstan baseline count grew from max $max to $count — fix findings or intentionally raise phpstan-baseline.max" >&2
  exit 1
fi
