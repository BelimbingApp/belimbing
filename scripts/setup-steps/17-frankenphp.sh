#!/usr/bin/env bash
# scripts/setup-steps/17-frankenphp.sh
# Title: FrankenPHP
# Purpose: Install FrankenPHP standalone binary for Octane
# Usage: ./scripts/setup-steps/17-frankenphp.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Detects the host OS and architecture
# - Installs FrankenPHP via Homebrew on macOS
# - Installs FrankenPHP from GitHub release binaries on Linux/WSL2
# - Verifies with `frankenphp version`
# - Persists detected version to setup state

set -euo pipefail

# Get script directory and project root
SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"

# Source shared utilities
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

# Environment (default to local if not provided, using Laravel standard)
APP_ENV="${1:-local}"

extract_frankenphp_version() {
    local version_output=$1
    local extracted
    extracted=$(printf '%s\n' "$version_output" | grep -oE '[0-9]+(\.[0-9]+){1,2}' | head -1 || true)
    printf '%s\n' "$extracted"
    return 0
}

resolve_frankenphp_tag() {
    resolve_latest_frankenphp_tag
    return 0
}

prompt_for_latest_upgrade() {
    local installed_version=$1
    local latest_version=$2

    if [[ ! -t 0 ]]; then
        return 1
    fi

    echo -e "${CYAN}A newer FrankenPHP release is available.${NC}"
    echo -e "  Installed: ${CYAN}${installed_version}${NC}"
    echo -e "  Latest:    ${CYAN}${latest_version}${NC}"
    ask_yes_no "Upgrade FrankenPHP now?" "n"
}

linux_binary_arch() {
    local machine_arch
    machine_arch=$(uname -m)

    case "$machine_arch" in
        x86_64) echo 'x86_64' ;;
        aarch64|arm64) echo 'aarch64' ;;
        *)
            echo -e "${RED}✗${NC} Unsupported Linux architecture: ${machine_arch}" >&2
            return 1
            ;;
    esac

    return 0
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
    echo -e "${YELLOW}⚠${NC} Installed to ${CYAN}$HOME/.local/bin/frankenphp${NC}"
    echo -e "  Ensure ${CYAN}$HOME/.local/bin${NC} is in your PATH"
    return 0
}

try_install_linux_binary() {
    local tag=$1
    local arch=$2
    local tmp_dir=$3

    local candidate_urls=(
        "https://github.com/dunglas/frankenphp/releases/download/${tag}/frankenphp-linux-${arch}"
        "https://github.com/dunglas/frankenphp/releases/download/${tag}/frankenphp-linux-${arch}.tar.gz"
    )

    local archive_path="$tmp_dir/frankenphp.tar.gz"
    local bin_path="$tmp_dir/frankenphp"
    local url

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
    local arch
    arch=$(linux_binary_arch) || return 1

    local tag
    tag=$(resolve_frankenphp_tag)

    echo -e "${CYAN}Installing FrankenPHP ${tag} for Linux (${arch})...${NC}"

    local tmp_dir
    tmp_dir=$(mktemp -d)
    trap "rm -rf '$tmp_dir'" RETURN

    if ! try_install_linux_binary "$tag" "$arch" "$tmp_dir"; then
        echo -e "${RED}✗${NC} Failed to download FrankenPHP release binary" >&2
        echo -e "  Expected release tag: ${CYAN}${tag}${NC}" >&2
        echo -e "  Manual install: ${CYAN}https://frankenphp.dev/docs/install/${NC}" >&2
        return 1
    fi

    return 0
}

install_frankenphp_macos() {
    if ! command_exists brew; then
        echo -e "${RED}✗${NC} Homebrew is required to install FrankenPHP on macOS" >&2
        echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
        return 1
    fi

    echo -e "${CYAN}Installing FrankenPHP via Homebrew...${NC}"
    brew install dunglas/frankenphp/frankenphp || brew upgrade dunglas/frankenphp/frankenphp
    return 0
}

main() {
    print_section_banner "FrankenPHP Setup - Belimbing (${APP_ENV})"

    # Load existing configuration
    load_setup_state

    local upgrade_requested=false
    if [[ "${2:-}" = '--upgrade' ]] || [[ "${BLB_UPDATE_DEPENDENCIES:-false}" = 'true' ]]; then
        upgrade_requested=true
    fi

    if command_exists frankenphp; then
        local existing_version
        existing_version=$(frankenphp version 2>/dev/null | head -1 || echo 'unknown')
        local existing_semver
        existing_semver=$(extract_frankenphp_version "$existing_version")

        if [[ -z "$existing_semver" ]]; then
            echo -e "${YELLOW}⚠${NC} FrankenPHP is installed, but its version could not be parsed: ${existing_version}"
            echo -e "  Re-run with ${CYAN}--upgrade${NC} if you want BLB to reinstall it."
            save_to_setup_state 'FRANKENPHP_VERSION' "$existing_version"
            echo ""
            echo -e "${GREEN}✓ FrankenPHP setup complete!${NC}"
            return 0
        fi

        local minimum_supported_version
        minimum_supported_version=$(get_frankenphp_minimum_version)

        if version_is_less_than "$existing_semver" "$minimum_supported_version"; then
            echo -e "${YELLOW}⚠${NC} FrankenPHP is installed but below BLB's supported minimum."
            echo -e "  Installed: ${CYAN}${existing_semver}${NC}"
            echo -e "  Required:  ${CYAN}${minimum_supported_version}${NC}"
            echo -e "  Upgrading now..."
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
                    echo -e "${GREEN}✓${NC} FrankenPHP already installed: ${existing_version}"
                    echo -e "  Minimum supported by BLB: ${CYAN}${minimum_supported_version}${NC}"
                    echo -e "  Newer release available:  ${CYAN}${latest_semver}${NC}"

                    save_to_setup_state 'FRANKENPHP_VERSION' "$existing_version"
                    echo ""
                    echo -e "${GREEN}✓ FrankenPHP setup complete!${NC}"
                    return 0
                fi
            fi
        fi

        if [[ "$upgrade_requested" = false ]]; then
            echo -e "${GREEN}✓${NC} FrankenPHP already installed: ${existing_version}"
            echo -e "  Minimum supported by BLB: ${CYAN}${minimum_supported_version}${NC}"

            save_to_setup_state 'FRANKENPHP_VERSION' "$existing_version"
            echo ""
            echo -e "${GREEN}✓ FrankenPHP setup complete!${NC}"
            return 0
        else
            echo -e "${CYAN}Upgrading FrankenPHP from ${existing_semver}...${NC}"
        fi

        upgrade_requested=true
    fi

    local os_type
    os_type=$(detect_os)

    case "$os_type" in
        macos)
            install_frankenphp_macos || exit 1
            ;;
        linux|wsl2)
            install_frankenphp_linux || exit 1
            ;;
        *)
            echo -e "${RED}✗${NC} Unsupported OS for automated FrankenPHP setup: ${os_type}" >&2
            echo -e "  Manual install: ${CYAN}https://frankenphp.dev/docs/install/${NC}" >&2
            exit 1
            ;;
    esac

    if ! command_exists frankenphp; then
        echo -e "${RED}✗${NC} FrankenPHP installation completed but executable was not found in PATH" >&2
        exit 1
    fi

    local installed_version
    installed_version=$(frankenphp version 2>/dev/null | head -1 || true)

    if [[ -z "$installed_version" ]]; then
        echo -e "${RED}✗${NC} FrankenPHP verification failed (frankenphp version returned no output)" >&2
        exit 1
    fi

    echo -e "${GREEN}✓${NC} FrankenPHP installed: ${installed_version}"

    save_to_setup_state 'FRANKENPHP_VERSION' "$installed_version"

    echo ""
    echo -e "${GREEN}✓ FrankenPHP setup complete!${NC}"
    return 0
}

main "$@"
