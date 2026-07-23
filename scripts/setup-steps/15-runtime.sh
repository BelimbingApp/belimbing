#!/usr/bin/env bash
# scripts/setup-steps/15-runtime.sh
# Title: PHP Runtime & Composer
# Purpose: Install FrankenPHP (self-contained PHP runtime) and Composer
# Usage: ./scripts/setup-steps/15-runtime.sh [local|staging|production|testing] [--upgrade]
#
# FrankenPHP ships its own statically-linked PHP on Linux and a self-contained
# binary on macOS and Windows — no separate PHP installation is required on any
# platform. After FrankenPHP is installed this step creates a thin `php` wrapper
# at /usr/local/bin/php (or $HOME/.local/bin/php) so that Composer and artisan
# can find PHP without knowing about FrankenPHP. That is identical to the Windows
# approach where setup.ps1 uses $HOME/.frankenphp/php.exe directly.

set -euo pipefail

SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"

# shellcheck source=../shared/colors.sh
source "$SCRIPTS_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=../shared/runtime.sh
source "$SCRIPTS_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=../shared/config.sh
source "$SCRIPTS_DIR/shared/config.sh" 2>/dev/null || true
# shellcheck source=../shared/validation.sh
source "$SCRIPTS_DIR/shared/validation.sh" 2>/dev/null || true
# shellcheck source=../shared/interactive.sh
source "$SCRIPTS_DIR/shared/interactive.sh" 2>/dev/null || true

APP_ENV="${1:-local}"
UPGRADE_REQUESTED=false
if [[ "${2:-}" = '--upgrade' ]] || [[ "${BLB_UPDATE_DEPENDENCIES:-false}" = 'true' ]]; then
    UPGRADE_REQUESTED=true
fi

readonly UNKNOWN_VERSION='unknown'
readonly PHP_VERSION_COMMAND='echo PHP_VERSION;'
readonly COMPOSER_INSTALLER_URL='https://getcomposer.org/installer'
readonly COMPOSER_SIGNATURE_URL='https://composer.github.io/installer.sig'
readonly FRANKENPHP_VERSION_STATE_KEY='FRANKENPHP_VERSION'

# ── FrankenPHP ───────────────────────────────────────────────────────────────

extract_frankenphp_version() {
    local version_output=$1
    printf '%s\n' "$version_output" | grep -oE '[0-9]+(\.[0-9]+){1,2}' | head -1 || true
}

resolve_frankenphp_tag() {
    resolve_latest_frankenphp_tag
}

prompt_for_latest_upgrade() {
    local installed_version=$1 latest_version=$2
    [[ -t 0 ]] || return 1
    echo -e "${CYAN}A newer FrankenPHP release is available.${NC}"
    echo -e "  Installed: ${CYAN}${installed_version}${NC}"
    echo -e "  Latest:    ${CYAN}${latest_version}${NC}"
    ask_yes_no "Upgrade FrankenPHP now?" "n"
}

linux_binary_arch() {
    case "$(uname -m)" in
        x86_64)        echo 'x86_64' ;;
        aarch64|arm64) echo 'aarch64' ;;
        *)
            echo -e "${RED}✗${NC} Unsupported Linux architecture: $(uname -m)" >&2
            return 1
            ;;
    esac
}

install_binary_to_path() {
    local source_file=$1
    if [[ -w '/usr/local/bin' ]]; then
        install -m 0755 "$source_file" /usr/local/bin/frankenphp
        return 0
    fi
    if sudo -n true 2>/dev/null; then
        sudo install -m 0755 "$source_file" /usr/local/bin/frankenphp
        return 0
    fi
    mkdir -p "$HOME/.local/bin"
    install -m 0755 "$source_file" "$HOME/.local/bin/frankenphp"
    echo -e "${YELLOW}⚠${NC} Installed to ${CYAN}$HOME/.local/bin/frankenphp${NC} — ensure that directory is in PATH"
}

try_install_linux_binary() {
    local tag=$1 arch=$2 tmp_dir=$3

    local candidate_urls=(
        "https://github.com/dunglas/frankenphp/releases/download/${tag}/frankenphp-linux-${arch}-gnu"
        "https://github.com/dunglas/frankenphp/releases/download/${tag}/frankenphp-linux-${arch}"
        "https://github.com/dunglas/frankenphp/releases/download/${tag}/frankenphp-linux-${arch}.tar.gz"
    )

    local archive_path="$tmp_dir/frankenphp.tar.gz"
    local bin_path="$tmp_dir/frankenphp"

    for url in "${candidate_urls[@]}"; do
        if [[ "$url" == *.tar.gz ]]; then
            if curl -fsSL "$url" -o "$archive_path"; then
                tar -xzf "$archive_path" -C "$tmp_dir"
                if [[ -f "$bin_path" ]]; then
                    install_binary_to_path "$bin_path"
                    return 0
                fi
            fi
            continue
        fi
        if curl -fsSL "$url" -o "$bin_path"; then
            install_binary_to_path "$bin_path"
            return 0
        fi
    done

    return 1
}

install_frankenphp_linux() {
    local arch tag tmp_dir
    arch=$(linux_binary_arch) || return 1
    tag=$(resolve_frankenphp_tag)

    echo -e "${CYAN}Installing FrankenPHP ${tag} for Linux (${arch}, GNU-capable binary)...${NC}"

    tmp_dir=$(mktemp -d)
    trap "rm -rf '$tmp_dir'" RETURN

    if ! try_install_linux_binary "$tag" "$arch" "$tmp_dir"; then
        echo -e "${RED}✗${NC} Failed to download FrankenPHP release binary" >&2
        echo -e "  Expected release tag: ${CYAN}${tag}${NC}" >&2
        echo -e "  Manual install: ${CYAN}https://frankenphp.dev/docs/install/${NC}" >&2
        return 1
    fi
}

install_frankenphp_macos() {
    command_exists brew || {
        echo -e "${RED}✗${NC} Homebrew is required to install FrankenPHP on macOS" >&2
        echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
        return 1
    }
    echo -e "${CYAN}Installing FrankenPHP via Homebrew...${NC}"
    brew install dunglas/frankenphp/frankenphp || brew upgrade dunglas/frankenphp/frankenphp
}

frankenphp_supports_sqlite_loadable_extensions() {
    command_exists frankenphp || return 1

    local tmp_dir output supported=false
    tmp_dir=$(mktemp -d)

    touch "$tmp_dir/blb-empty"
    printf 'sqlite3.extension_dir=%s\n' "$tmp_dir" > "$tmp_dir/sqlite-loadable-test.ini"

    output=$(PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$tmp_dir" frankenphp php-cli -r '
        $db = new SQLite3(":memory:");
        @$db->loadExtension("blb-empty");
        echo error_get_last()["message"] ?? "";
    ' 2>&1 || true)

    if [[ "$output" != *"Dynamic loading not supported"* && "$output" != *"SQLite Extensions are disabled"* ]]; then
        supported=true
    fi

    rm -rf "$tmp_dir"
    [[ "$supported" = true ]]
}

setup_frankenphp() {
    local upgrade_requested=$UPGRADE_REQUESTED

    if command_exists frankenphp; then
        local existing_version existing_semver
        existing_version=$(frankenphp version 2>/dev/null | head -1 || echo 'unknown')
        existing_semver=$(extract_frankenphp_version "$existing_version")

        if [[ -z "$existing_semver" ]]; then
            echo -e "${YELLOW}⚠${NC} FrankenPHP installed but version could not be parsed: ${existing_version}"
            echo -e "  Re-run with ${CYAN}--upgrade${NC} to reinstall."
            save_to_setup_state "$FRANKENPHP_VERSION_STATE_KEY" "$existing_version"
            return 0
        fi

        local minimum_version
        minimum_version=$(get_frankenphp_minimum_version)

        if version_is_less_than "$existing_semver" "$minimum_version"; then
            echo -e "${YELLOW}⚠${NC} FrankenPHP ${existing_semver} is below the BLB minimum (${minimum_version}) — upgrading..."
            upgrade_requested=true
        elif [[ "$(detect_os)" =~ ^(linux|wsl2)$ ]] && ! frankenphp_supports_sqlite_loadable_extensions; then
            echo -e "${YELLOW}⚠${NC} FrankenPHP ${existing_semver} cannot load SQLite extensions — replacing with GNU-capable binary..."
            upgrade_requested=true
        elif [[ "$upgrade_requested" = false ]]; then
            local latest_tag latest_semver
            latest_tag=$(resolve_frankenphp_tag)
            latest_semver=$(extract_frankenphp_version "$latest_tag")

            if [[ -n "$latest_semver" ]] && version_is_less_than "$existing_semver" "$latest_semver"; then
                if prompt_for_latest_upgrade "$existing_semver" "$latest_semver"; then
                    echo -e "${CYAN}Upgrading FrankenPHP from ${existing_semver} to ${latest_semver}...${NC}"
                    upgrade_requested=true
                else
                    echo -e "${GREEN}✓${NC} FrankenPHP ${existing_version} (newer release ${latest_semver} available)"
                    save_to_setup_state "$FRANKENPHP_VERSION_STATE_KEY" "$existing_version"
                    return 0
                fi
            fi
        fi

        if [[ "$upgrade_requested" = false ]]; then
            echo -e "${GREEN}✓${NC} FrankenPHP already installed: ${existing_version}"
            save_to_setup_state "$FRANKENPHP_VERSION_STATE_KEY" "$existing_version"
            return 0
        fi

        echo -e "${CYAN}Upgrading FrankenPHP from ${existing_semver}...${NC}"
    fi

    local os_type
    os_type=$(detect_os)

    case "$os_type" in
        macos)      install_frankenphp_macos || return 1 ;;
        linux|wsl2) install_frankenphp_linux || return 1 ;;
        *)
            echo -e "${RED}✗${NC} Unsupported OS for automated FrankenPHP install: ${os_type}" >&2
            echo -e "  Manual install: ${CYAN}https://frankenphp.dev/docs/install/${NC}" >&2
            return 1
            ;;
    esac

    command_exists frankenphp || {
        echo -e "${RED}✗${NC} FrankenPHP installed but executable not found in PATH" >&2
        return 1
    }

    local installed_version
    installed_version=$(frankenphp version 2>/dev/null | head -1 || true)
    [[ -n "$installed_version" ]] || { echo -e "${RED}✗${NC} FrankenPHP verification failed" >&2; return 1; }

    echo -e "${GREEN}✓${NC} FrankenPHP installed: ${installed_version}"
    save_to_setup_state "$FRANKENPHP_VERSION_STATE_KEY" "$installed_version"
}

# ── PHP CLI wrapper ──────────────────────────────────────────────────────────

check_php_version() {
    command_exists php || return 1
    local php_version
    php_version=$(php -r "$PHP_VERSION_COMMAND" 2>/dev/null || echo "0.0.0")
    check_php_version_meets_minimum "$php_version"
}

php_cli_supports_composer_flags() {
    php -d memory_limit=123M -r 'exit(ini_get("memory_limit") === "123M" ? 0 : 1);' >/dev/null 2>&1 || return 1
    php --version >/dev/null 2>&1 || return 1
    php -r 'exit(getenv("PHP_BINARY") !== "" ? 0 : 1);' >/dev/null 2>&1 || return 1

    if command_exists composer && ! composer --version 2>/dev/null | grep -q '^Composer '; then
        return 1
    fi

    return 0
}

php_cli_wrapper_needs_refresh() {
    local php_path
    php_path=$(command -v php 2>/dev/null || true)
    [[ -n "$php_path" && -f "$php_path" && -r "$php_path" ]] || return 1

    grep -q 'frankenphp php-cli' "$php_path" 2>/dev/null || return 1
    grep -q 'resolved_php=.*command -v -- "\$0"' "$php_path" 2>/dev/null && return 1

    return 0
}

setup_php_cli() {
    if ! php_cli_wrapper_needs_refresh && check_php_version && php_cli_supports_composer_flags; then
        echo -e "${GREEN}✓${NC} PHP CLI: $(php -r "$PHP_VERSION_COMMAND")"
        return 0
    fi

    if php_cli_wrapper_needs_refresh; then
        echo -e "${YELLOW}⚠${NC} PHP CLI wrapper is missing BLB runtime metadata — recreating wrapper"
    elif check_php_version; then
        echo -e "${YELLOW}⚠${NC} PHP CLI exists but does not accept Composer's PHP flags — recreating wrapper"
    fi

    # FrankenPHP bundles PHP — create a thin wrapper so `php` resolves for
    # Composer and artisan without requiring a separate system PHP installation.
    command_exists frankenphp || {
        echo -e "${RED}✗${NC} FrankenPHP not in PATH — cannot create PHP CLI wrapper" >&2
        return 1
    }

    local wrapper_dir='/usr/local/bin'
    if [[ ! -w "$wrapper_dir" ]] && ! sudo -n true 2>/dev/null; then
        wrapper_dir="$HOME/.local/bin"
        mkdir -p "$wrapper_dir"
        echo -e "${YELLOW}⚠${NC} No sudo access — installing PHP wrapper to ${CYAN}${wrapper_dir}${NC} (ensure it is in PATH)"
    fi

    local wrapper_path="$wrapper_dir/php"
    local tmp_wrapper
    tmp_wrapper=$(mktemp)
cat > "$tmp_wrapper" <<'EOF'
#!/usr/bin/env bash
args=()
ini_entries=()
mode=""

if [[ -z "${PHP_BINARY:-}" ]]; then
    if [[ "$0" = /* ]]; then
        export PHP_BINARY="$0"
    else
        resolved_php=$(command -v -- "$0" 2>/dev/null || command -v php 2>/dev/null || true)
        [[ -n "$resolved_php" ]] && export PHP_BINARY="$resolved_php"
    fi
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --)
            args+=("$@")
            break
            ;;
        -d|--define)
            shift
            if [[ $# -gt 0 ]]; then
                ini_entries+=("$1")
                shift
            fi
            ;;
        -d*)
            ini_entries+=("${1#-d}")
            shift
            ;;
        --define=*)
            ini_entries+=("${1#--define=}")
            shift
            ;;
        -v|--version)
            mode="version"
            shift
            ;;
        -m)
            mode="modules"
            shift
            ;;
        -i|--info)
            mode="info"
            shift
            ;;
        --ini)
            mode="ini"
            shift
            ;;
        *)
            args+=("$@")
            break
            ;;
    esac
done

if [[ ${#ini_entries[@]} -gt 0 ]]; then
    ini_dir=$(mktemp -d)
    trap 'rm -rf "$ini_dir"' EXIT

    for entry in "${ini_entries[@]}"; do
        [[ -n "$entry" ]] && printf '%s\n' "$entry"
    done > "$ini_dir/99-blb-cli-flags.ini"

    if [[ -n "${PHP_INI_SCAN_DIR:-}" ]]; then
        export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR}:$ini_dir"
    else
        export PHP_INI_SCAN_DIR=":$ini_dir"
    fi
fi

case "$mode" in
    version)
        exec frankenphp php-cli -r 'printf("PHP %s (cli)\n", PHP_VERSION); printf("Zend Engine v%s\n", zend_version());'
        ;;
    modules)
        exec frankenphp php-cli -r '
            $modules = get_loaded_extensions(false);
            sort($modules, SORT_NATURAL | SORT_FLAG_CASE);
            echo "[PHP Modules]\n";
            foreach ($modules as $module) {
                echo $module, "\n";
            }
            echo "\n[Zend Modules]\n";
            $zendModules = get_loaded_extensions(true);
            sort($zendModules, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($zendModules as $module) {
                echo $module, "\n";
            }
        '
        ;;
    info)
        exec frankenphp php-cli -r 'phpinfo();'
        ;;
    ini)
        exec frankenphp php-cli -r '
            printf("Configuration File (php.ini) Path: %s\n", PHP_CONFIG_FILE_PATH);
            printf("Loaded Configuration File:         %s\n", php_ini_loaded_file() ?: "(none)");
            printf("Scan for additional .ini files in: %s\n", php_ini_scanned_files() ? dirname(strtok(php_ini_scanned_files(), ",")) : "(none)");
            printf("Additional .ini files parsed:      %s\n", php_ini_scanned_files() ?: "(none)");
        '
        ;;
esac

exec frankenphp php-cli "${args[@]}"
EOF

    if [[ -w "$wrapper_dir" ]]; then
        install -m 0755 "$tmp_wrapper" "$wrapper_path"
        rm -f "$tmp_wrapper"
    else
        sudo install -m 0755 "$tmp_wrapper" "$wrapper_path"
        rm -f "$tmp_wrapper"
    fi

    check_php_version || {
        echo -e "${RED}✗${NC} PHP CLI wrapper verification failed" >&2
        return 1
    }

    echo -e "${GREEN}✓${NC} PHP CLI wrapper created: $(php -r "$PHP_VERSION_COMMAND") via FrankenPHP"
    save_to_setup_state "PHP_VERSION" "$(php -r "$PHP_VERSION_COMMAND")"
}

# ── PHP extension verification ───────────────────────────────────────────────

# Extensions required on all platforms (mirrors composer.json ext-* requires).
REQUIRED_EXTENSIONS=(ctype dom fileinfo filter intl mbstring openssl pdo pdo_sqlite session tokenizer xml zip)
# Linux/macOS-only (FrankenPHP bundles these on Linux; not needed on Windows).
PLATFORM_EXTENSIONS=(pcntl posix)

verify_extensions() {
    local missing=()

    local all_exts=("${REQUIRED_EXTENSIONS[@]}" "${PLATFORM_EXTENSIONS[@]}")
    local ext loaded
    for ext in "${all_exts[@]}"; do
        loaded=$(php -r "echo extension_loaded('$ext') ? 'yes' : 'no';")
        if [[ "$loaded" != 'yes' ]]; then
            missing+=("$ext")
        fi
    done

    if [[ "${#missing[@]}" -eq 0 ]]; then
        echo -e "${GREEN}✓${NC} All required PHP extensions loaded"
        return 0
    fi

    echo -e "${RED}✗${NC} Missing PHP extensions: ${missing[*]}" >&2
    echo -e "  FrankenPHP bundles all required extensions — ensure you are using the FrankenPHP PHP wrapper." >&2
    return 1
}

# ── Composer ─────────────────────────────────────────────────────────────────

install_composer() {
    echo -e "${CYAN}Installing Composer...${NC}"

    command_exists curl || { echo -e "${RED}✗${NC} curl is required to download Composer" >&2; return 1; }
    command_exists php || { echo -e "${RED}✗${NC} php is required to install Composer" >&2; return 1; }

    local composer_installer
    composer_installer=$(mktemp)

    curl -fsSL --proto '=https' --proto-redir '=https' --max-time 30 "$COMPOSER_INSTALLER_URL" -o "$composer_installer" || {
        echo -e "${RED}✗${NC} Failed to download Composer installer" >&2
        rm -f "$composer_installer"; return 1
    }

    local expected_signature actual_signature
    expected_signature=$(curl -fsSL --proto '=https' --proto-redir '=https' --max-time 30 "$COMPOSER_SIGNATURE_URL") || {
        echo -e "${RED}✗${NC} Failed to download Composer installer signature" >&2
        rm -f "$composer_installer"; return 1
    }
    actual_signature=$(php -r "echo hash_file('sha384', '$composer_installer');")

    if [[ "$expected_signature" != "$actual_signature" ]]; then
        echo -e "${RED}✗${NC} Composer installer signature mismatch" >&2
        rm -f "$composer_installer"; return 1
    fi

    local install_dir='/usr/local/bin'
    local can_sudo=false
    if sudo -n true 2>/dev/null; then
        can_sudo=true
    fi

    if [[ ! -w "$install_dir" && "$can_sudo" = false ]]; then
        install_dir="$HOME/.local/bin"
        mkdir -p "$install_dir"
        echo -e "${YELLOW}Installing Composer to user directory:${NC} ${CYAN}${install_dir}${NC}"
    fi

    local composer_phar="$install_dir/composer.phar"
    local composer_bin="$install_dir/composer"
    local php_bin
    php_bin=$(command -v php)

    if [[ -w "$install_dir" ]]; then
        php "$composer_installer" --install-dir="$install_dir" --filename=composer.phar || {
            echo -e "${RED}✗${NC} Failed to install Composer" >&2
            rm -f "$composer_installer"; return 1
        }
    else
        sudo php "$composer_installer" --install-dir="$install_dir" --filename=composer.phar || {
            echo -e "${RED}✗${NC} Failed to install Composer" >&2
            rm -f "$composer_installer"; return 1
        }
    fi

    local tmp_composer
    tmp_composer=$(mktemp)
    cat > "$tmp_composer" <<EOF
#!/usr/bin/env bash
export PHP_BINARY="$php_bin"
exec "$php_bin" "$composer_phar" "\$@"
EOF

    if [[ -w "$install_dir" ]]; then
        install -m 0755 "$tmp_composer" "$composer_bin"
        rm -f "$tmp_composer"
    else
        sudo install -m 0755 "$tmp_composer" "$composer_bin"
        rm -f "$tmp_composer"
    fi

    rm -f "$composer_installer"

    command_exists composer || {
        echo -e "${YELLOW}⚠${NC} Composer installed but not in PATH — restart your shell or add the install directory to PATH" >&2
        return 1
    }

    local composer_version
    composer_version=$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")
    echo -e "${GREEN}✓${NC} Composer installed: ${composer_version}"
    composer self-update --quiet 2>/dev/null || sudo composer self-update --quiet 2>/dev/null || true
}

setup_composer() {
    if command_exists composer; then
        local composer_version
        composer_version=$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Composer already installed: ${composer_version}"

        if composer self-update --quiet 2>/dev/null || sudo composer self-update --quiet 2>/dev/null; then
            local updated_version
            updated_version=$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")
            [[ "$composer_version" != "$updated_version" ]] && echo -e "${GREEN}✓${NC} Composer updated: ${updated_version}"
        fi
        return 0
    fi

    echo -e "${YELLOW}ℹ${NC} Composer not found"
    install_composer || {
        echo -e "${RED}✗${NC} Composer installation failed — install manually: ${CYAN}https://getcomposer.org/download/${NC}"
        exit 1
    }

    save_to_setup_state "COMPOSER_VERSION" "$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")"
}

# ── Main ─────────────────────────────────────────────────────────────────────

main() {
    print_section_banner "PHP Runtime & Composer ($APP_ENV)"
    load_setup_state

    setup_frankenphp
    echo ""
    setup_php_cli
    echo ""
    verify_extensions
    echo ""
    setup_composer
    echo ""
    echo -e "${GREEN}✓ PHP runtime & Composer setup complete!${NC}"
}

main "$@"
