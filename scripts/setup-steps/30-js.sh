#!/usr/bin/env bash
# scripts/setup-steps/30-js.sh
# Title: JavaScript Runtime (Bun/Node.js)
# Purpose: Install and configure Bun or Node.js for Belimbing
# Usage: ./scripts/setup-steps/30-js.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Checks for Bun installation (preferred)
# - Installs Bun if selected
# - Falls back to Node.js/npm if Bun not available
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
                curl -fsSL https://bun.sh/install | bash || {
                    echo -e "${RED}✗${NC} Failed to install Bun" >&2
                    return 1
                }
            fi
            ;;
        linux|wsl2)
            # Use official installer
            echo -e "${CYAN}Installing Bun via official installer...${NC}"
            curl -fsSL https://bun.sh/install | bash || {
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
        node)
            (cd "$PROJECT_ROOT" && npm install) || {
                echo -e "${RED}✗${NC} npm install failed" >&2
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

# Handle successful Bun setup/installation
handle_bun_success() {
    local bun_version
    bun_version=$(get_bun_version)
    save_to_setup_state "JS_RUNTIME" "bun"
    save_to_setup_state "BUN_VERSION" "$bun_version"

    install_js_dependencies bun || exit 1

    echo -e "${GREEN}✓ JavaScript runtime setup complete!${NC}"
    exit 0
}

# Check if Node.js is available (lazy check for fallback only)
check_node_as_fallback() {
    if command_exists node && command_exists npm; then
        return 0
    fi
    return 1
}

# Handle successful Node.js setup/installation
handle_node_success() {
    local node_version npm_version
    node_version=$(node --version 2>/dev/null || echo "$UNKNOWN_VERSION")
    npm_version=$(npm --version 2>/dev/null || echo "$UNKNOWN_VERSION")
    save_to_setup_state "JS_RUNTIME" "node"
    save_to_setup_state "NODE_VERSION" "$node_version"
    save_to_setup_state "NPM_VERSION" "$npm_version"

    install_js_dependencies node || exit 1

    echo -e "${GREEN}✓ JavaScript runtime setup complete!${NC}"
    exit 0
}

# Install Node.js and npm
install_nodejs() {
    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Installing Node.js and npm...${NC}"
    echo ""

    case "$os_type" in
        macos)
            if command_exists brew; then
                echo -e "${CYAN}Installing Node.js via Homebrew...${NC}"
                brew install node || {
                    echo -e "${RED}✗${NC} Failed to install Node.js" >&2
                    return 1
                }
            else
                echo -e "${RED}✗${NC} Homebrew required for Node.js installation on macOS" >&2
                echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
                echo -e "  Or install Node.js manually: ${CYAN}https://nodejs.org${NC}" >&2
                return 1
            fi
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                echo -e "${CYAN}Installing Node.js via apt...${NC}"
                curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash - || {
                    echo -e "${YELLOW}Using default repository...${NC}"
                }
                sudo apt-get update -qq
                sudo apt-get install -y -qq nodejs || {
                    echo -e "${RED}✗${NC} Failed to install Node.js" >&2
                    return 1
                }
            elif command_exists yum; then
                echo -e "${CYAN}Installing Node.js via yum...${NC}"
                curl -fsSL https://rpm.nodesource.com/setup_lts.x | sudo bash - || {
                    echo -e "${YELLOW}Using default repository...${NC}"
                }
                sudo yum install -y nodejs npm || {
                    echo -e "${RED}✗${NC} Failed to install Node.js" >&2
                    return 1
                }
            elif command_exists dnf; then
                echo -e "${CYAN}Installing Node.js via dnf...${NC}"
                curl -fsSL https://rpm.nodesource.com/setup_lts.x | sudo bash - || {
                    echo -e "${YELLOW}Using default repository...${NC}"
                }
                sudo dnf install -y nodejs npm || {
                    echo -e "${RED}✗${NC} Failed to install Node.js" >&2
                    return 1
                }
            else
                echo -e "${RED}✗${NC} Package manager not supported" >&2
                echo -e "  Please install Node.js manually: ${CYAN}https://nodejs.org${NC}" >&2
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for auto-install" >&2
            echo -e "  Please install Node.js manually: ${CYAN}https://nodejs.org${NC}" >&2
            return 1
            ;;
    esac

    # Verify installation
    if command_exists node && command_exists npm; then
        local node_version npm_version
        node_version=$(node --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        npm_version=$(npm --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo ""
        echo -e "${GREEN}✓${NC} Node.js installed successfully: $node_version"
        echo -e "${GREEN}✓${NC} npm installed successfully: $npm_version"
        return 0
    fi

    echo ""
    echo -e "${RED}✗${NC} Node.js/npm installation verification failed" >&2
    return 1
}

# Main setup function
main() {
    print_section_banner "JavaScript Runtime Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Step 1: Check if Bun exists (PATH or default location)
    if command_exists bun; then
        local bun_version
        bun_version=$(get_bun_version)
        echo -e "${GREEN}✓${NC} Bun already installed: $bun_version"
        echo -e "${CYAN}ℹ${NC} Bun will be used (replaces Node.js and npm)"
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
        echo ""
        handle_bun_success
    fi

    # Step 2: Bun not found - offer to install
    # Check for Node.js only if we need to show it as context or fallback
    local has_node=false
    if check_node_as_fallback; then
        has_node=true
    fi

    if [[ "$has_node" = true ]]; then
        local node_version
        node_version=$(node --version 2>/dev/null || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Node.js already installed: $node_version"
        echo ""
        echo -e "${YELLOW}ℹ${NC} Note: Bun is the preferred runtime for this project (offers better performance and a built-in package manager)"
        echo ""
    else
        echo -e "${YELLOW}ℹ${NC} No JavaScript runtime found"
        echo ""
    fi

    local latest_bun_version
    latest_bun_version=$(get_latest_bun_version_with_prefix)
    echo -e "${CYAN}Options:${NC}"
    echo ""
    echo -e "  ${GREEN}1. Bun (Recommended - Latest: ${latest_bun_version})${NC}"
    echo -e "     • Faster than Node.js"
    echo -e "     • Built-in package manager (replaces npm)"
    echo -e "     • Modern JavaScript runtime"
    if [[ "$has_node" = true ]]; then
        echo -e "     • ${YELLOW}Note:${NC} Bun will replace Node.js for this project"
    fi
    echo ""
    echo -e "  ${YELLOW}2. Node.js + npm${NC}"
    echo -e "     • Traditional JavaScript runtime"
    echo -e "     • Widely supported"
    echo -e "     • More established ecosystem"
    echo -e "     • ${YELLOW}Note:${NC} Bun is preferred and can be installed later"
    echo ""

    if [[ -t 0 ]]; then
        # Interactive mode
        local choice
        choice=$(ask_input "Choose JavaScript runtime (1 for Bun, 2 for Node.js)" "1")

        case "$choice" in
            1|bun|Bun)
                if [[ "$has_node" = true ]]; then
                    local node_version
                    node_version=$(node --version 2>/dev/null || echo "$UNKNOWN_VERSION")
                    echo -e "${YELLOW}ℹ${NC} Node.js $node_version detected"
                    echo -e "${CYAN}ℹ${NC} Bun will be used instead of Node.js for this project"
                    echo -e "${CYAN}ℹ${NC} Node.js will remain installed but won't be used by Belimbing"
                    echo ""
                fi
                echo -e "${CYAN}Installing Bun...${NC}"
                echo ""
                if install_bun; then
                    handle_bun_success
                else
                    echo -e "${RED}✗${NC} Bun installation failed"
                    echo ""
                    # Fallback to Node.js if available
                    if [[ "$has_node" = true ]]; then
                        echo -e "${YELLOW}Falling back to Node.js...${NC}"
                        echo ""
                        handle_node_success
                    else
                        echo -e "${YELLOW}Please install Bun manually:${NC}"
                        echo -e "  ${CYAN}https://bun.sh${NC}"
                        exit 1
                    fi
                fi
                ;;
            2|node|Node.js|npm)
                if [[ "$has_node" = true ]]; then
                    # Node.js already installed
                    handle_node_success
                else
                    echo -e "${CYAN}Installing Node.js...${NC}"
                    echo ""
                    if install_nodejs; then
                        handle_node_success
                    else
                        echo -e "${RED}✗${NC} Node.js installation failed"
                        echo ""
                        echo -e "${YELLOW}Please install Node.js manually:${NC}"
                        echo -e "  ${CYAN}https://nodejs.org${NC}"
                        exit 1
                    fi
                fi
                ;;
            *)
                echo -e "${RED}✗${NC} Invalid choice" >&2
                exit 1
                ;;
        esac
    else
        # Non-interactive mode - default to Bun
        if [[ "$has_node" = true ]]; then
            local node_version
            node_version=$(node --version 2>/dev/null || echo "$UNKNOWN_VERSION")
            echo -e "${YELLOW}ℹ${NC} Node.js $node_version detected"
            echo -e "${CYAN}ℹ${NC} Installing Bun (will replace Node.js for this project)...${NC}"
        else
            echo -e "${CYAN}Non-interactive mode: Installing Bun (default)...${NC}"
        fi
        echo ""
        if install_bun; then
            handle_bun_success
        else
            echo -e "${YELLOW}Falling back to Node.js...${NC}"
            echo ""
            if [[ "$has_node" = true ]]; then
                handle_node_success
            elif install_nodejs; then
                handle_node_success
            else
                exit 1
            fi
        fi
    fi
    return 0
}

# Run main function
main "$@"
