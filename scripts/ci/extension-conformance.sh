#!/usr/bin/env bash
set -euo pipefail

root=${1:-.}
[[ -d "$root" ]] || { echo "extension-conformance: missing root: $root" >&2; exit 2; }

mapfile -d '' manifests < <(find "$root" -mindepth 2 -maxdepth 3 -name composer.json -print0 | sort -z)
((${#manifests[@]} > 0)) || { echo 'extension-conformance: no Module composer.json found' >&2; exit 1; }

for manifest in "${manifests[@]}"; do
    php scripts/ci/validate-extension-manifest.php "$manifest"
done

mapfile -d '' php_files < <(find "$root" -type f -name '*.php' -print0 | sort -z)
if ((${#php_files[@]} > 0)); then
    php scripts/ci/validate-php-syntax.php "${php_files[@]}"

    authorable_php=()
    for path in "${php_files[@]}"; do
        relative=${path#./}
        if [[ "$relative" == */Database/Migrations/*.php ]] && git -C "$root" ls-files --error-unmatch "${relative#"${root#./}"/}" >/dev/null 2>&1; then
            continue
        fi
        authorable_php+=("$path")
    done
    ((${#authorable_php[@]} == 0)) || vendor/bin/pint --test -- "${authorable_php[@]}"
fi

mapfile -d '' tests < <(find "$root" -type f -path '*/Tests/*Test.php' -print0 | sort -z)
((${#tests[@]} == 0)) || vendor/bin/pest "${tests[@]}"

if find "$root" -type f \( -path '*/Assets/*.js' -o -path '*/Assets/*.css' \) -print -quit | grep -q .; then
    [[ -f "$root/package.json" && -f "$root/bun.lock" ]] || {
        echo 'extension-conformance: owned assets require package.json and bun.lock' >&2; exit 1;
    }
    bun install --cwd "$root" --frozen-lockfile
    bun run --cwd "$root" build
fi

echo "extension-conformance: passed ${#manifests[@]} Module manifest(s)"
