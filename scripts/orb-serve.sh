#!/usr/bin/env bash
# Start FrankenPHP/Octane for Amp orb portal exposure.
#
# The amp service runner sets $PORT (listen here) and $PUBLIC_URL (portal HTTPS
# URL). This script ensures the Octane worker stub exists, exports the env vars
# Caddy needs, and starts Octane with the orb-specific Caddyfile.

set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${PORT:-8000}"
export PORT

# Ensure the Octane worker stub exists (gitignored, normally created by
# octane:install; we copy the vendor stub to avoid install side effects).
if [[ ! -f public/frankenphp-worker.php ]]; then
    cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php \
       public/frankenphp-worker.php
fi

# Let Laravel generate absolute URLs that match the public portal URL.
if [[ -n "${PUBLIC_URL:-}" ]]; then
    export APP_URL="$PUBLIC_URL"
    export APP_SCHEME=https
fi

export CADDY_SERVER_ADMIN_PORT="${CADDY_SERVER_ADMIN_PORT:-2020}"

exec php artisan octane:start \
    --server=frankenphp \
    --caddyfile=Caddyfile.orb \
    --host=127.0.0.1 \
    --port="$PORT" \
    --admin-port="$CADDY_SERVER_ADMIN_PORT"
