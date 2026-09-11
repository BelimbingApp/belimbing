#!/usr/bin/env bash
# Install mbstring for the separately installed system PHP CLI. This never
# targets FrankenPHP, whose static runtime has a different extension set.

set -euo pipefail

php_binary="${BLB_SYSTEM_PHP_BINARY:-php}"

if [[ "${1:-}" = '--php-binary' ]]; then
    php_binary="${2:-}"
fi

[[ -n "$php_binary" ]] || { echo 'A system PHP CLI is required. Set BLB_SYSTEM_PHP_BINARY or pass --php-binary <path>.' >&2; exit 1; }
command -v apt-get >/dev/null 2>&1 || { echo 'This installer currently supports Ubuntu/Debian hosts with apt-get.' >&2; exit 1; }
command -v "$php_binary" >/dev/null 2>&1 || { echo "System PHP CLI not found: $php_binary" >&2; exit 1; }

php_version=$("$php_binary" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
[[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Could not read the PHP version from $php_binary" >&2; exit 1; }

run_root() {
    if [[ $EUID -eq 0 ]]; then
        "$@"
    else
        sudo "$@"
    fi
}

echo "Installing mbstring for PHP ${php_version} CLI ($php_binary)..."
run_root apt-get update
run_root apt-get install -y "php${php_version}-mbstring"
run_root phpenmod -v "$php_version" mbstring

"$php_binary" -m | tr '[:upper:]' '[:lower:]' | grep -qx 'mbstring' \
    || { echo "mbstring was installed but is not loaded by $php_binary." >&2; exit 1; }

echo "mbstring CLI support is ready. Keep BLB_SYSTEM_PHP_BINARY=$php_binary available to the scheduler and SBGroup."
