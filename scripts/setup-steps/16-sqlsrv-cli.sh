#!/usr/bin/env bash
# Install the Microsoft SQL Server drivers for the separately installed system
# PHP CLI. This never targets FrankenPHP: its static runtime cannot load the
# ODBC extension safely.

set -euo pipefail

php_binary="${BLB_SYSTEM_PHP_BINARY:-php}"

if [[ "${1:-}" = '--php-binary' ]]; then
    php_binary="${2:-}"
fi

[[ -n "$php_binary" ]] || { echo 'A system PHP CLI is required. Set BLB_SYSTEM_PHP_BINARY or pass --php-binary <path>.' >&2; exit 1; }
command -v apt-get >/dev/null 2>&1 || { echo 'This installer currently supports Ubuntu/Debian hosts with apt-get.' >&2; exit 1; }
command -v "$php_binary" >/dev/null 2>&1 || { echo "System PHP CLI not found: $php_binary" >&2; exit 1; }
[[ -r /etc/os-release ]] || { echo 'Could not identify the host operating system.' >&2; exit 1; }

# shellcheck disable=SC1091
. /etc/os-release
[[ "$ID" = 'ubuntu' || "$ID" = 'debian' ]] || { echo "This installer supports Ubuntu/Debian hosts; found ${ID:-unknown}." >&2; exit 1; }
[[ -n "${VERSION_ID:-}" ]] || { echo 'Could not identify the host operating-system version.' >&2; exit 1; }

php_version=$("$php_binary" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
[[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Could not read the PHP version from $php_binary" >&2; exit 1; }

run_root() {
    if [[ $EUID -eq 0 ]]; then
        "$@"
    else
        sudo "$@"
    fi
}

echo "Installing Microsoft SQL Server support for PHP ${php_version} CLI ($php_binary)..."
run_root apt-get update
run_root apt-get install -y "php${php_version}-dev" php-pear unixodbc-dev gcc g++ make curl gpg

phpize_binary="$(command -v "phpize${php_version}" || true)"
[[ -n "$phpize_binary" ]] || { echo "PHP ${php_version} development tools did not provide phpize${php_version}." >&2; exit 1; }
# Parse the version field rather than matching a formatted line. phpize pads
# its output ("PHP Version:             8.5"), so a fixed-string grep for
# "PHP Version: 8.5" never matched and this guard refused a correct host.
phpize_version="$("$phpize_binary" --version 2>/dev/null | awk '/^PHP Version:/ { version = $3 } END { print version }')"
[[ "$phpize_version" == "$php_version" || "$phpize_version" == "$php_version".* ]] \
    || { echo "phpize${php_version} reports PHP ${phpize_version:-unknown}, which does not match $php_binary (PHP ${php_version})." >&2; exit 1; }

if [[ ! -f /usr/share/keyrings/microsoft-prod.gpg ]]; then
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | run_root gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
fi
if [[ ! -f /etc/apt/sources.list.d/mssql-release.list ]]; then
    curl -fsSL "https://packages.microsoft.com/config/${ID}/${VERSION_ID}/prod.list" | run_root tee /etc/apt/sources.list.d/mssql-release.list >/dev/null
fi

run_root apt-get update
run_root env ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc
run_root env PHP_PEAR_PHP_BIN="$(command -v "$php_binary")" pecl install sqlsrv pdo_sqlsrv
printf '; priority=20\nextension=sqlsrv.so\n' | run_root tee "/etc/php/${php_version}/mods-available/sqlsrv.ini" >/dev/null
printf '; priority=30\nextension=pdo_sqlsrv.so\n' | run_root tee "/etc/php/${php_version}/mods-available/pdo_sqlsrv.ini" >/dev/null
run_root phpenmod -v "$php_version" sqlsrv pdo_sqlsrv

"$php_binary" -r '
    $modules = array_map("strtolower", get_loaded_extensions());
    $drivers = array_map("strtolower", PDO::getAvailableDrivers());
    exit(in_array("sqlsrv", $modules, true) && in_array("pdo_sqlsrv", $modules, true) && in_array("sqlsrv", $drivers, true) ? 0 : 1);
' || { echo "SQL Server support was installed but is not loaded by $php_binary." >&2; exit 1; }

echo "SQL Server CLI support is ready. Keep BLB_SYSTEM_PHP_BINARY=$php_binary available to the scheduler and SBGroup."
