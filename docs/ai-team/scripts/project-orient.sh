#!/usr/bin/env bash
# Belimbing-specific orientation. Keep repository-local project facts here so
# the shared operating guide and its generic mechanisms can move elsewhere.

set -u

echo "== Belimbing project: runtime contract =="
php_constraint=$(jq -r '.require.php' composer.json 2>/dev/null)
octane_constraint=$(jq -r '.require["laravel/octane"]' composer.json 2>/dev/null)
echo "  source  PHP ${php_constraint:-unknown}; Laravel Octane ${octane_constraint:-unknown} with FrankenPHP"
if command -v php >/dev/null 2>&1; then
  echo "  local   $(php -r 'echo "PHP ".PHP_VERSION;' 2>/dev/null)"
else
  echo "  MISSING php is not available on PATH"
fi

echo
echo "== Belimbing project: application topology on origin/main =="
for root in Base Core Domains Extensions; do
  count=$(git ls-tree -r --name-only origin/main -- "app/$root" 2>/dev/null | awk 'NF {seen=1} END {print seen+0}')
  if [ "$count" -eq 1 ]; then
    entries=$(git ls-tree -d --name-only "origin/main:app/$root" 2>/dev/null | paste -sd, -)
    echo "  app/$root  ${entries:-tracked files present}"
  else
    echo "  app/$root  absent"
  fi
done

if [ ! -d vendor ]; then
  echo
  echo "  NOTE  vendor/ is absent; run composer install before PHP validation."
fi
if [ ! -d node_modules ]; then
  echo "  NOTE  node_modules/ is absent; run bun install before frontend validation."
fi

cat <<'TXT'

== Belimbing project: commands worth knowing ==
  composer test                 clear config and run the Pest suite
  vendor/bin/pint --dirty       format changed PHP files
  bun run build                 build the Vite/Tailwind frontend
  composer dev                  start FrankenPHP, queues, scheduler, and Vite

Use a server started from your own branch when judging UI. FrankenPHP keeps
workers warm, so restart it when testing bootstrap, middleware, or discovery.
TXT
