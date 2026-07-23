#!/usr/bin/env bash
# scripts/setup-steps/30-js.sh
# Title: JavaScript Runtime (Bun)
# Purpose: Install and configure Bun for Belimbing
# Usage: ./scripts/setup-steps/30-js.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Checks for Bun installation
# - Installs Bun if selected
# - Requires Bun because Belimbing's dev scripts use bun/bunx directly
# - Verifies JavaScript runtime is available

set -euo pipefail

readonly UNKNOWN_VERSION="unknown"

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

# Add Bun to PATH permanently
add_bun_to_path_permanently() {
    local path_export="export PATH=\"\$HOME/.bun/bin:\$PATH\""

    # Detect shell and determine config file
    local shell_config=""
    local current_shell
    current_shell=$(basename "${SHELL:-bash}")

    case "$current_shell" in
        zsh)
            shell_config="$HOME/.zshrc"
            ;;
        bash)
            shell_config="$HOME/.bashrc"
            # Also check .bash_profile on macOS
            if [[ "$OSTYPE" == "darwin"* ]] && [[ -f "$HOME/.bash_profile" ]]; then
                shell_config="$HOME/.bash_profile"
            fi
            ;;
        fish)
            shell_config="$HOME/.config/fish/config.fish"
            path_export="set -gx PATH \$HOME/.bun/bin \$PATH"
            # Create fish config directory if it doesn't exist
            if [[ ! -d "$HOME/.config/fish" ]]; then
                mkdir -p "$HOME/.config/fish"
            fi
            ;;
        *)
            # Default to .bashrc for unknown shells
            shell_config="$HOME/.bashrc"
            ;;
    esac

    # Check if PATH entry already exists
    if [[ -f "$shell_config" ]] && grep -q "\.bun/bin" "$shell_config" 2>/dev/null; then
        echo -e "${CYAN}ℹ${NC} Bun PATH entry already exists in ${CYAN}$shell_config${NC}"
        return 0
    fi

    # Add PATH entry to config file
    if [[ -n "$shell_config" ]]; then
        # Create config file if it doesn't exist
        if [[ ! -f "$shell_config" ]]; then
            touch "$shell_config"
        fi

        # Add PATH export
        echo "" >> "$shell_config"
        echo "# Bun - added by Belimbing setup" >> "$shell_config"
        echo "$path_export" >> "$shell_config"

        echo -e "${GREEN}✓${NC} Added Bun to PATH in ${CYAN}$shell_config${NC}"
        echo -e "${CYAN}ℹ${NC} Restart your shell or run: ${CYAN}source $shell_config${NC}"
        return 0
    else
        echo -e "${YELLOW}⚠${NC} Could not determine shell config file" >&2
        return 1
    fi
}

# Install Bun
install_bun() {
    # Check if Bun is already installed at default location
    if [[ -f "$HOME/.bun/bin/bun" ]]; then
        echo -e "${GREEN}✓${NC} Bun already installed at ~/.bun/bin/bun"
        # Add to PATH for this session
        export PATH="$HOME/.bun/bin:$PATH"
        local bun_version
        bun_version=$("$HOME/.bun/bin/bun" --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Bun version: $bun_version"

        # Check if Bun is in PATH, if not add it permanently
        if ! command_exists bun; then
            echo -e "${CYAN}Adding Bun to PATH permanently...${NC}"
            add_bun_to_path_permanently
        fi
        return 0
    fi

    # Check if Bun is already in PATH
    if command_exists bun; then
        local bun_version
        bun_version=$(bun --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Bun already installed: $bun_version"
        return 0
    fi

    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Installing Bun...${NC}"
    echo ""

    case "$os_type" in
        macos)
            if command_exists brew; then
                echo -e "${CYAN}Installing Bun via Homebrew...${NC}"
                brew install oven-sh/bun/bun || {
                    echo -e "${RED}✗${NC} Failed to install Bun via Homebrew" >&2
                    return 1
                }
            else
                # Use official installer
                echo -e "${CYAN}Installing Bun via official installer...${NC}"
                curl -fsSL --proto '=https' --proto-redir '=https' https://bun.sh/install | bash || {
                    echo -e "${RED}✗${NC} Failed to install Bun" >&2
                    return 1
                }
            fi
            ;;
        linux|wsl2)
            # Use official installer
            echo -e "${CYAN}Installing Bun via official installer...${NC}"
            curl -fsSL --proto '=https' --proto-redir '=https' https://bun.sh/install | bash || {
                echo -e "${RED}✗${NC} Failed to install Bun" >&2
                return 1
            }
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for auto-install" >&2
            echo -e "  Please install Bun manually: ${CYAN}https://bun.sh${NC}" >&2
            return 1
            ;;
    esac

    # Verify installation - check both PATH and default location
    if command_exists bun; then
        local bun_version
        bun_version=$(bun --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo ""
        echo -e "${GREEN}✓${NC} Bun installed successfully: $bun_version"
        return 0
    elif [[ -f "$HOME/.bun/bin/bun" ]]; then
        # Bun installed but not in PATH - add it permanently
        export PATH="$HOME/.bun/bin:$PATH"
        local bun_version
        bun_version=$("$HOME/.bun/bin/bun" --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo ""
        echo -e "${GREEN}✓${NC} Bun installed successfully: $bun_version"
        echo -e "${CYAN}Adding Bun to PATH permanently...${NC}"
        add_bun_to_path_permanently
        return 0
    fi

    echo ""
    echo -e "${RED}✗${NC} Bun installation verification failed" >&2
    return 1
}

# Get Bun version (centralized logic)
get_bun_version() {
    if command_exists bun; then
        bun --version 2>/dev/null || echo "$UNKNOWN_VERSION"
    elif [[ -f "$HOME/.bun/bin/bun" ]]; then
        "$HOME/.bun/bin/bun" --version 2>/dev/null || echo "$UNKNOWN_VERSION"
    else
        echo "$UNKNOWN_VERSION"
    fi
    return 0
}

is_bun_up_to_date() {
    local installed_version=$1
    local latest_version=$2

    installed_version="${installed_version#v}"
    latest_version="${latest_version#v}"

    compare_version "$installed_version" "$latest_version"
    local result=$?

    if [[ $result -eq 0 ]] || [[ $result -eq 1 ]]; then
        return 0
    fi

    return 1
}

confirm_bun_upgrade() {
    local current_version=$1
    local latest_version=$2

    local prompt="Upgrade Bun from ${current_version} to ${latest_version}? This may affect other applications"

    if [[ -t 0 ]]; then
        ask_yes_no "$prompt" "y"
        return $?
    fi

    echo -e "${YELLOW}⚠${NC} Non-interactive mode: skipping Bun upgrade prompt"
    return 1
}

upgrade_bun() {
    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Upgrading Bun...${NC}"
    echo ""

    case "$os_type" in
        macos)
            if command_exists brew; then
                brew upgrade oven-sh/bun/bun 2>/dev/null || brew upgrade bun 2>/dev/null || brew install oven-sh/bun/bun 2>/dev/null || {
                    echo -e "${RED}✗${NC} Failed to upgrade Bun via Homebrew" >&2
                    return 1
                }
            else
                curl -fsSL --proto '=https' --proto-redir '=https' https://bun.sh/install | bash || {
                    echo -e "${RED}✗${NC} Failed to upgrade Bun" >&2
                    return 1
                }
            fi
            ;;
        linux|wsl2)
            curl -fsSL --proto '=https' --proto-redir '=https' https://bun.sh/install | bash || {
                echo -e "${RED}✗${NC} Failed to upgrade Bun" >&2
                return 1
            }
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for Bun auto-upgrade" >&2
            return 1
            ;;
    esac

    if [[ -f "$HOME/.bun/bin/bun" ]]; then
        export PATH="$HOME/.bun/bin:$PATH"
    fi

    if command_exists bun; then
        local bun_version
        bun_version=$(bun --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Bun upgraded: $bun_version"
        return 0
    fi

    echo -e "${RED}✗${NC} Bun upgrade verification failed" >&2
    return 1
}

# Install project JS dependencies using the active runtime.
# Must be called after the runtime binary is confirmed available.
install_js_dependencies() {
    local runtime=$1

    echo -e "${CYAN}Installing JavaScript dependencies...${NC}"

    case "$runtime" in
        bun)
            (cd "$PROJECT_ROOT" && bun install) || {
                echo -e "${RED}✗${NC} bun install failed" >&2
                return 1
            }
            ;;
        *)
            echo -e "${RED}✗${NC} Unknown runtime: $runtime" >&2
            return 1
            ;;
    esac

    echo -e "${GREEN}✓${NC} JavaScript dependencies installed"
}

ensure_bunx_available() {
    if command_exists bunx; then
        return 0
    fi

    local bun_path
    bun_path=$(command -v bun 2>/dev/null || true)

    if [[ -n "$bun_path" ]]; then
        local bun_dir
        bun_dir=$(dirname "$bun_path")

        if [[ -w "$bun_dir" ]]; then
            ln -sf "$bun_path" "$bun_dir/bunx"
        fi
    fi

    if command_exists bunx; then
        return 0
    fi

    echo -e "${RED}✗${NC} bunx not found after Bun setup" >&2
    echo -e "  Reinstall Bun or add a bunx shim that points to the Bun binary." >&2
    return 1
}

# Handle successful Bun setup/installation
handle_bun_success() {
    local bun_version
    bun_version=$(get_bun_version)
    ensure_bunx_available || exit 1

    save_to_setup_state "JS_RUNTIME" "bun"
    save_to_setup_state "BUN_VERSION" "$bun_version"

    install_js_dependencies bun || exit 1

    echo -e "${GREEN}✓ JavaScript runtime setup complete!${NC}"
    exit 0
}

# Check if Node.js is available so we can explain that Bun is still required.
check_node_available() {
    if command_exists node && command_exists npm; then
        return 0
    fi
    return 1
}

# Main setup function
main() {
    print_section_banner "JavaScript Runtime Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    local latest_bun_version
    latest_bun_version=$(get_latest_bun_version_with_prefix)

    # Step 1: Check if Bun exists (PATH or default location)
    if command_exists bun; then
        local bun_version
        bun_version=$(get_bun_version)
        echo -e "${GREEN}✓${NC} Bun already installed: $bun_version"
        echo -e "${CYAN}ℹ${NC} Bun will be used (replaces Node.js and npm)"

        if [[ "$bun_version" != "$UNKNOWN_VERSION" ]] && ! is_bun_up_to_date "$bun_version" "$latest_bun_version"; then
            echo -e "${YELLOW}⚠${NC} Bun is behind latest release (${latest_bun_version})"
            if confirm_bun_upgrade "$bun_version" "$latest_bun_version"; then
                echo ""
                if ! upgrade_bun; then
                    echo -e "${YELLOW}⚠${NC} Continuing with existing Bun version"
                fi
            else
                echo -e "${YELLOW}⚠${NC} Skipping Bun upgrade"
            fi
        fi

        echo ""
        handle_bun_success
    elif [[ -f "$HOME/.bun/bin/bun" ]]; then
        # Bun installed but not in PATH - add it
        export PATH="$HOME/.bun/bin:$PATH"
        add_bun_to_path_permanently
        local bun_version
        bun_version=$(get_bun_version)
        echo -e "${GREEN}✓${NC} Bun already installed: $bun_version"
        echo -e "${CYAN}ℹ${NC} Bun will be used (replaces Node.js and npm)"

        if [[ "$bun_version" != "$UNKNOWN_VERSION" ]] && ! is_bun_up_to_date "$bun_version" "$latest_bun_version"; then
            echo -e "${YELLOW}⚠${NC} Bun is behind latest release (${latest_bun_version})"
            if confirm_bun_upgrade "$bun_version" "$latest_bun_version"; then
                echo ""
                if ! upgrade_bun; then
                    echo -e "${YELLOW}⚠${NC} Continuing with existing Bun version"
                fi
            else
                echo -e "${YELLOW}⚠${NC} Skipping Bun upgrade"
            fi
        fi

        echo ""
        handle_bun_success
    fi

    # Step 2: Bun not found - install it. Node/npm alone cannot run this
    # project's dev workflow because composer.json and package.json invoke bun/bunx.
    local has_node=false
    if check_node_available; then
        has_node=true
    fi

    if [[ "$has_node" = true ]]; then
        local node_version
        node_version=$(node --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Node.js already installed: $node_version"
        echo -e "${YELLOW}ℹ${NC} Belimbing still requires Bun for local development scripts"
        echo ""
    else
        echo -e "${YELLOW}ℹ${NC} No JavaScript runtime found"
        echo ""
    fi

    if [[ -t 0 ]]; then
        if ! ask_yes_no "Install Bun ${latest_bun_version} for Belimbing?" "y"; then
            echo -e "${RED}✗${NC} Bun is required for the Belimbing dev workflow" >&2
            exit 1
        fi
    else
        echo -e "${CYAN}Non-interactive mode: installing Bun (required)...${NC}"
    fi

    echo ""
    if install_bun; then
        handle_bun_success
    fi

    echo -e "${RED}✗${NC} Bun installation failed" >&2
    echo -e "${YELLOW}Please install Bun manually:${NC}" >&2
    echo -e "  ${CYAN}https://bun.sh${NC}" >&2
    exit 1
    return 0
}

# Run main function
main "$@"
