#!/usr/bin/env bash
# scripts/setup-steps/10-git.sh
# Title: Git Version Control
# Purpose: Install and configure Git for Belimbing
# Usage: ./scripts/setup-steps/10-git.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Checks for Git installation and version
# - Compares installed version against latest available
# - Installs or upgrades Git if needed (via Xcode CLI Tools on macOS, package manager on Linux)
# - Verifies Git installation and saves state

set -euo pipefail

# Get script directory and project root
SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"  # Points to scripts/setup-steps/
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"  # Points to scripts/
PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"  # Points to project root

# Source shared utilities (order matters: config.sh before validation.sh)
# shellcheck source=../shared/colors.sh
source "$SCRIPTS_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=../shared/runtime.sh
source "$SCRIPTS_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=../shared/config.sh
source "$SCRIPTS_DIR/shared/config.sh"
# shellcheck source=../shared/validation.sh
source "$SCRIPTS_DIR/shared/validation.sh"
# shellcheck source=../shared/interactive.sh
source "$SCRIPTS_DIR/shared/interactive.sh"

# Environment (default to local if not provided, using Laravel standard)
APP_ENV="${1:-local}"

# Global variables (used throughout script)
LATEST_GIT_VERSION=$(resolve_latest_git_version)  # Latest available Git version (resolved via GitHub API)
if command_exists git; then
    CURRENT_GIT_VERSION=$(git --version | awk '{print $3}')
else
    CURRENT_GIT_VERSION="0"  # Not installed
fi
declare -A GIT_VERSION_CACHE  # Cache for check_git_version results (version -> result)

# Check if Git version needs upgrade
# Caches results based on git_version to avoid redundant comparisons
# Usage: check_git_version "2.40.0" or check_git_version (defaults to CURRENT_GIT_VERSION)
check_git_version() {
    local git_version=${1:-$CURRENT_GIT_VERSION}

    # Check cache first
    if [[ -n "${GIT_VERSION_CACHE[$git_version]:-}" ]]; then
        return "${GIT_VERSION_CACHE[$git_version]}"
    fi

    # Use version comparison helper from versions.sh
    compare_version "$git_version" "$LATEST_GIT_VERSION"
    local result=$?

    # Cache the result
    if [[ $result -eq 0 ]] || [[ $result -eq 1 ]]; then
        GIT_VERSION_CACHE[$git_version]=0  # Version meets or exceeds latest
        return 0
    else
        GIT_VERSION_CACHE[$git_version]=1  # Version needs upgrade
        return 1
    fi
}

# Install Git if needed
install_git() {
    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Installing Git...${NC}"
    echo ""

    case "$os_type" in
        macos)
            # Xcode Command Line Tools installation (includes Git)
            # Note: This will show a GUI prompt on macOS
            echo -e "${CYAN}Installing Xcode Command Line Tools (includes Git)...${NC}"
            xcode-select --install

            # Wait for user to complete the installation
            echo -e "${YELLOW}Please complete the Xcode Command Line Tools installation in the dialog.${NC}"
            echo -e "${YELLOW}Press Enter when installation is complete...${NC}"
            read -r
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                # Check if Git is already installed and if version needs upgrade
                local needs_upgrade=false
                if [[ "$CURRENT_GIT_VERSION" != "0" ]] && ! check_git_version; then
                    needs_upgrade=true
                fi

                # If upgrade needed or Git not installed, use Git's official PPA for latest version
                if [[ "$needs_upgrade" = true ]] || [[ "$CURRENT_GIT_VERSION" = "0" ]]; then
                    echo -e "${CYAN}Adding Git's official PPA for latest version...${NC}"
                    sudo apt-get update -qq
                    sudo apt-get install -y -qq software-properties-common || true
                    sudo add-apt-repository -y ppa:git-core/ppa 2>/dev/null || {
                        echo -e "${YELLOW}⚠${NC} Could not add Git PPA, trying default repositories...${NC}"
                    }
                    sudo apt-get update -qq
                else
                    sudo apt-get update -qq
                fi

                echo -e "${CYAN}Installing/upgrading Git via apt...${NC}"
                sudo apt-get install -y git || {
                    echo -e "${RED}✗ Failed to install Git${NC}"
                    return 1
                }

                # Verify we got a recent version (PPA should provide latest, but check anyway)
                if command_exists git; then
                    local installed_version
                    installed_version=$(git --version | awk '{print $3}')
                    if ! check_git_version "$installed_version"; then
                        echo -e "${YELLOW}⚠${NC} Installed Git version $installed_version is older than latest ${LATEST_GIT_VERSION}"
                        echo -e "${CYAN}ℹ${NC} The PPA may not have updated yet, or there may be a repository issue"
                        echo -e "${CYAN}ℹ${NC} You can try: ${CYAN}sudo apt-get update && sudo apt-get upgrade git${NC}"
                    fi
                fi
            elif command_exists yum; then
                echo -e "${CYAN}Installing Git via yum...${NC}"
                sudo yum install -y git || {
                    echo -e "${RED}✗ Failed to install Git${NC}"
                    return 1
                }
            elif command_exists dnf; then
                echo -e "${CYAN}Installing Git via dnf...${NC}"
                sudo dnf install -y git || {
                    echo -e "${RED}✗ Failed to install Git${NC}"
                    return 1
                }
            else
                echo -e "${RED}✗ Package manager not supported${NC}"
                echo -e "  Please install Git manually from: ${CYAN}https://git-scm.com${NC}"
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗ OS not supported for auto-install${NC}"
            echo -e "  Please install Git manually from: ${CYAN}https://git-scm.com${NC}"
            return 1
            ;;
    esac

    # Verify installation and update global CURRENT_GIT_VERSION
    if command_exists git; then
        CURRENT_GIT_VERSION=$(git --version | awk '{print $3}')
        echo -e "${GREEN}✓${NC} Git installed successfully: $CURRENT_GIT_VERSION"
        return 0
    fi

    echo -e "${RED}✗${NC} Git installation failed"
    return 1
}

# Main setup function
main() {
    print_section_banner "Git Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Check if Git is already installed
    if [[ "$CURRENT_GIT_VERSION" != "0" ]]; then
        if check_git_version; then
            echo -e "${GREEN}✓${NC} Git is already installed: $CURRENT_GIT_VERSION (latest: ${LATEST_GIT_VERSION})"
            echo -e "${GREEN}✓${NC} Git setup complete (already satisfied)"
            exit 0
        else
            echo -e "${YELLOW}⚠${NC} Git is installed: $CURRENT_GIT_VERSION (latest: ${LATEST_GIT_VERSION})"
            echo ""

            if [[ -t 0 ]]; then
                if ask_yes_no "Upgrade Git to ${LATEST_GIT_VERSION}?" "y"; then
                    echo ""
                    # Continue to installation/upgrade
                else
                    echo -e "${YELLOW}⚠${NC} Skipping Git upgrade"
                    echo -e "${CYAN}ℹ${NC} You can upgrade Git later manually"
                    exit 0
                fi
            else
                echo -e "${CYAN}Non-interactive mode: Will upgrade Git...${NC}"
                echo ""
            fi
        fi
    fi

    # Install Git
    if install_git; then
        echo -e "${GREEN}✓${NC} Git is ready"
    else
        echo -e "${RED}✗${NC} Git installation failed"
        echo ""
        echo -e "${YELLOW}Please install Git manually:${NC}"
        echo -e "  • macOS: ${CYAN}xcode-select --install${NC}"
        echo -e "  • Linux: ${CYAN}sudo apt-get install git${NC}"
        echo -e "  • Manual: ${CYAN}https://git-scm.com${NC}"
        exit 1
    fi

    echo ""

    # Save state
    save_to_setup_state "GIT_VERSION" "$CURRENT_GIT_VERSION" "$PROJECT_ROOT"

    echo -e "${GREEN}✓ Git setup complete!${NC}"
    echo -e "${CYAN}Installed:${NC}"
    echo -e "  • Git: $(git --version)"
    return 0
}

# Run main function
main "$@"
