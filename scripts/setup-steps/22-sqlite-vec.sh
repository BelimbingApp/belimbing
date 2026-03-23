#!/usr/bin/env bash
# scripts/setup-steps/22-sqlite-vec.sh
# Title: SQLite-Vec Extension
# Purpose: Install sqlite-vec loadable extension for AI vector search
# Usage: ./scripts/setup-steps/22-sqlite-vec.sh [local|staging|production|testing]
#
# This script:
# - Downloads the sqlite-vec prebuilt loadable extension
# - Installs to storage/app/sqlite-ext/
# - Verifies the extension loads in PHP
# - Configures the extension directory path

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

INSTALL_DIR="$PROJECT_ROOT/storage/app/sqlite-ext"
GITHUB_RELEASE_URL="https://github.com/asg017/sqlite-vec/releases/download"

# Resolve the target sqlite-vec version by querying the GitHub releases API.
# Falls back to the pinned SQLITE_VEC_VERSION from versions.sh if the API is
# unreachable (e.g. air-gapped environments or CI without network access).
resolve_sqlite_vec_version() {
    local fallback
    fallback=$(get_sqlite_vec_version)

    local latest
    latest=$(curl -fsSL --max-time 5 \
        'https://api.github.com/repos/asg017/sqlite-vec/releases/latest' 2>/dev/null \
        | grep '"tag_name"' \
        | sed 's/.*"tag_name": *"\([^"]*\)".*/\1/')

    if [[ -n "$latest" ]]; then
        echo "$latest"
    else
        echo "$fallback"
    fi
}

# Detect platform and set archive/extension names
detect_platform() {
    local os_type
    os_type=$(detect_os)

    local arch
    arch=$(uname -m)

    local platform=""
    local ext_suffix=""

    case "$os_type" in
        macos)
            ext_suffix="dylib"
            case "$arch" in
                x86_64)  platform="macos-x86_64" ;;
                arm64)   platform="macos-aarch64" ;;
                aarch64) platform="macos-aarch64" ;;
                *)
                    echo -e "${RED}${CROSS_MARK} Unsupported macOS architecture: $arch${NC}" >&2
                    return 1
                    ;;
            esac
            ;;
        linux|wsl2)
            ext_suffix="so"
            case "$arch" in
                x86_64)  platform="linux-x86_64" ;;
                aarch64) platform="linux-aarch64" ;;
                *)
                    echo -e "${RED}${CROSS_MARK} Unsupported Linux architecture: $arch${NC}" >&2
                    return 1
                    ;;
            esac
            ;;
        *)
            echo -e "${RED}${CROSS_MARK} Unsupported OS: $os_type${NC}" >&2
            return 1
            ;;
    esac

    echo "$platform $ext_suffix"
}

# Verify the extension loads correctly in PHP
verify_extension() {
    local install_dir="$1"

    # Determine the correct extension filename
    local ext_file="vec0"
    if [[ -f "$install_dir/vec0.so" ]]; then
        ext_file="vec0.so"
    elif [[ -f "$install_dir/vec0.dylib" ]]; then
        ext_file="vec0.dylib"
    fi

    local version_output
    version_output=$(php -d "sqlite3.extension_dir=$install_dir" -r "\$db = new SQLite3(':memory:'); \$db->loadExtension('$ext_file'); echo \$db->querySingle('SELECT vec_version()');" 2>/dev/null) || return 1

    if [[ -n "$version_output" ]]; then
        echo "$version_output"
        return 0
    fi
    return 1
}

# Check if already installed at the target version
check_existing() {
    if [[ ! -d "$INSTALL_DIR" ]]; then
        return 1
    fi

    # Check for extension file (either .so or .dylib)
    if [[ ! -f "$INSTALL_DIR/vec0.so" && ! -f "$INSTALL_DIR/vec0.dylib" ]]; then
        return 1
    fi

    # Verify it loads and compare version against target (strip leading 'v')
    local installed_version
    installed_version=$(verify_extension "$INSTALL_DIR" 2>/dev/null) || return 1

    local target_version="${SQLITE_VEC_VERSION#v}"
    [[ "$installed_version" == "$target_version" ]]
}

# Download and install the extension
download_extension() {
    local platform="$1"
    local ext_suffix="$2"

    local version_no_v="${SQLITE_VEC_VERSION#v}"
    local archive_name="sqlite-vec-${version_no_v}-loadable-${platform}.tar.gz"
    local download_url="${GITHUB_RELEASE_URL}/${SQLITE_VEC_VERSION}/${archive_name}"

    echo -e "${CYAN}Downloading sqlite-vec ${SQLITE_VEC_VERSION} for ${platform}...${NC}"
    echo -e "  ${CYAN}URL:${NC} $download_url"

    # Create install directory
    mkdir -p "$INSTALL_DIR"

    # Download to temp directory
    local tmp_dir
    tmp_dir=$(mktemp -d)
    trap "rm -rf '$tmp_dir'" EXIT

    if ! curl -fsSL "$download_url" -o "$tmp_dir/$archive_name"; then
        echo -e "${RED}${CROSS_MARK} Failed to download sqlite-vec${NC}" >&2
        echo -e "  Check the URL: ${CYAN}$download_url${NC}" >&2
        return 1
    fi

    echo -e "${CYAN}Extracting...${NC}"

    if ! tar -xzf "$tmp_dir/$archive_name" -C "$tmp_dir"; then
        echo -e "${RED}${CROSS_MARK} Failed to extract archive${NC}" >&2
        return 1
    fi

    # Find and copy the extension file
    local ext_file
    ext_file=$(find "$tmp_dir" -name "vec0.${ext_suffix}" -type f | head -1)

    if [[ -z "$ext_file" ]]; then
        echo -e "${RED}${CROSS_MARK} Extension file vec0.${ext_suffix} not found in archive${NC}" >&2
        echo -e "  Archive contents:" >&2
        ls -la "$tmp_dir"/ >&2
        return 1
    fi

    cp "$ext_file" "$INSTALL_DIR/vec0.${ext_suffix}"
    chmod 755 "$INSTALL_DIR/vec0.${ext_suffix}"

    echo -e "${GREEN}${CHECK_MARK}${NC} Extension installed to ${CYAN}${INSTALL_DIR}/vec0.${ext_suffix}${NC}"
}

# Main setup function
main() {
    print_section_banner "SQLite-Vec Extension Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Resolve target version (GitHub API with pinned fallback)
    local SQLITE_VEC_VERSION
    SQLITE_VEC_VERSION=$(resolve_sqlite_vec_version)

    # Check if already installed
    if check_existing; then
        local installed_version
        installed_version=$(verify_extension "$INSTALL_DIR")
        echo -e "${GREEN}${CHECK_MARK}${NC} sqlite-vec already installed and working: ${GREEN}${installed_version}${NC}"
        echo -e "  ${CYAN}Location:${NC} $INSTALL_DIR"

        save_to_setup_state "SQLITE_VEC_VERSION" "$SQLITE_VEC_VERSION"

        echo ""
        echo -e "${GREEN}${CHECK_MARK} SQLite-Vec setup complete!${NC}"
        echo ""
        return 0
    fi

    # Detect platform
    local platform_info
    platform_info=$(detect_platform) || exit 1

    local platform ext_suffix
    platform=$(echo "$platform_info" | cut -d' ' -f1)
    ext_suffix=$(echo "$platform_info" | cut -d' ' -f2)

    echo -e "${CYAN}Platform:${NC} ${platform} (extension: vec0.${ext_suffix})"
    echo ""

    # Download and install
    if ! download_extension "$platform" "$ext_suffix"; then
        echo -e "${RED}${CROSS_MARK} sqlite-vec installation failed${NC}" >&2
        exit 1
    fi

    echo ""

    # Verify the extension loads in PHP
    echo -e "${CYAN}Verifying extension loads in PHP...${NC}"

    local vec_version
    if vec_version=$(verify_extension "$INSTALL_DIR"); then
        echo -e "${GREEN}${CHECK_MARK}${NC} sqlite-vec loaded successfully: ${GREEN}${vec_version}${NC}"
    else
        echo -e "${RED}${CROSS_MARK} Extension failed to load in PHP${NC}" >&2
        echo -e "  ${YELLOW}Check that PHP has SQLite3 support enabled${NC}" >&2
        echo -e "  ${CYAN}Extension directory:${NC} $INSTALL_DIR" >&2
        exit 1
    fi

    echo ""

    # Save state
    save_to_setup_state "SQLITE_VEC_VERSION" "$SQLITE_VEC_VERSION"

    echo ""
    echo -e "${GREEN}${CHECK_MARK} SQLite-Vec setup complete!${NC}"
    echo ""
    echo -e "${CYAN}Installed:${NC}"
    echo -e "  ${BULLET} sqlite-vec: ${vec_version}"
    echo -e "  ${BULLET} Location: $INSTALL_DIR"
    echo ""
}

# Run main function
main "$@"
