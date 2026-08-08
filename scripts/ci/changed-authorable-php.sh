#!/usr/bin/env bash
set -euo pipefail

# Print changed PHP files that are safe for Pint to inspect. Existing migration
# files are hash-immutable; newly added migrations remain authorable.
base=${1:?usage: changed-authorable-php.sh <base> [head]}
head=${2:-HEAD}

git diff --diff-filter=ACMR --name-only -z "$base" "$head" -- '*.php' |
while IFS= read -r -d '' path; do
    [[ -f "$path" ]] || continue
    if [[ "$path" =~ ^(.*/)?Database/Migrations/[^/]+\.php$ || "$path" =~ ^database/migrations/[^/]+\.php$ ]]; then
        git cat-file -e "$base:$path" 2>/dev/null && continue
    fi
    printf '%s\0' "$path"
done
