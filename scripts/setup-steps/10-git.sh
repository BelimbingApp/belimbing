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
# - Auto-installs Git when missing (required prerequisite)
# - Asks permission before optional upgrades
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
readonly GIT_CORE_PPA='ppa:git-core/ppa'
readonly GIT_APT_UPDATE_TIMEOUT_SECONDS=180
readonly GIT_APT_INSTALL_TIMEOUT_SECONDS=300
readonly GIT_CORE_PPA_ADD_TIMEOUT_SECONDS=90

readonly -a APT_GIT_PREREQUISITES=(
    ca-certificates
    python3-launchpadlib
    software-properties-common
)

# Global variables (used throughout script)
LATEST_GIT_VERSION=$(resolve_latest_git_version)  # Latest available Git version (resolved via GitHub API)
if command_exists git; then
    CURRENT_GIT_VERSION=$(git --version | awk '{print $3}')
else
    CURRENT_GIT_VERSION="0"  # Not installed
fi
declare -A GIT_VERSION_CACHE  # Cache for check_git_version results (version -> result)

confirm_git_system_change() {
    local action=$1
    local target_version="${2:-}"

    local prompt="Allow Git ${action}? This changes system packages and may affect other applications"
    if [[ -n "$target_version" ]]; then
        prompt+=" (target: ${target_version})"
    fi

    if [[ -t 0 ]]; then
        ask_yes_no "$prompt" "y"
        return $?
    fi

    echo -e "${YELLOW}⚠${NC} Non-interactive mode: refusing Git ${action} without interactive confirmation"
    return 1
}

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

apt_git_candidate_version() {
    local candidate
    candidate=$(apt-cache policy git 2>/dev/null | awk '/Candidate:/ {print $2; exit}')

    if [[ -z "$candidate" || "$candidate" = "(none)" ]]; then
        return 1
    fi

    candidate=${candidate#*:}
    candidate=${candidate%%-*}
    echo "$candidate"
}

apt_git_candidate_meets_latest() {
    local candidate
    candidate=$(apt_git_candidate_version) || return 1

    compare_version "$candidate" "$LATEST_GIT_VERSION"
    local result=$?
    [[ $result -eq 0 || $result -eq 1 ]]
}

continue_with_existing_git() {
    local reason=$1

    if [[ "$CURRENT_GIT_VERSION" = "0" ]]; then
        return 1
    fi

    echo -e "${YELLOW}⚠${NC} ${reason}" >&2
    echo -e "${CYAN}ℹ${NC} Continuing with installed Git ${CURRENT_GIT_VERSION}; the latest-version upgrade is optional." >&2
    echo -e "${CYAN}ℹ${NC} Retry manually later with: ${CYAN}sudo add-apt-repository ${GIT_CORE_PPA} && sudo apt-get update && sudo apt-get install git${NC}" >&2
    return 0
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
                    run_setup_command_with_timeout "Refreshing apt package lists" "$GIT_APT_UPDATE_TIMEOUT_SECONDS" sudo apt-get update -qq || {
                        echo -e "${RED}✗${NC} Failed to update apt package lists" >&2
                        return 1
                    }
                    run_setup_command_with_timeout "Installing Git apt prerequisites" "$GIT_APT_INSTALL_TIMEOUT_SECONDS" sudo apt-get install -y -qq "${APT_GIT_PREREQUISITES[@]}" || {
                        echo -e "${RED}✗${NC} Failed to install apt prerequisites for Git setup" >&2
                        return 1
                    }
                    local ppa_added=true
                    run_setup_command_with_timeout "Adding ${GIT_CORE_PPA}" "$GIT_CORE_PPA_ADD_TIMEOUT_SECONDS" sudo add-apt-repository -y "$GIT_CORE_PPA" || {
                        ppa_added=false
                        echo -e "${YELLOW}⚠${NC} Could not add ${GIT_CORE_PPA}" >&2
                    }

                    if [[ "$ppa_added" = false ]]; then
                        if [[ "$needs_upgrade" = true ]]; then
                            continue_with_existing_git "Could not add ${GIT_CORE_PPA}; latest Git packages are unavailable from apt on this machine."
                            return 0
                        fi
                    else
                        run_setup_command_with_timeout "Refreshing apt package lists" "$GIT_APT_UPDATE_TIMEOUT_SECONDS" sudo apt-get update -qq || {
                            echo -e "${RED}✗${NC} Failed to update apt package lists after Git repository setup" >&2
                            return 1
                        }
                    fi

                    if [[ "$needs_upgrade" = true ]] && ! apt_git_candidate_meets_latest; then
                        local candidate_version
                        candidate_version=$(apt_git_candidate_version 2>/dev/null || echo "none")
                        continue_with_existing_git "Apt candidate for Git is ${candidate_version}, below requested ${LATEST_GIT_VERSION}. ${GIT_CORE_PPA} was not added or does not currently publish the requested version."
                        return 0
                    fi
                else
                    run_setup_command_with_timeout "Refreshing apt package lists" "$GIT_APT_UPDATE_TIMEOUT_SECONDS" sudo apt-get update -qq || {
                        echo -e "${RED}✗${NC} Failed to update apt package lists" >&2
                        return 1
                    }
                fi

                echo -e "${CYAN}Installing/upgrading Git via apt...${NC}"
                run_setup_command_with_timeout "Installing Git" "$GIT_APT_INSTALL_TIMEOUT_SECONDS" sudo apt-get install -y git || {
                    echo -e "${RED}✗ Failed to install Git${NC}"
                    return 1
                }

                # Verify we got a recent version (PPA should provide latest, but check anyway)
                if command_exists git; then
                    local installed_version
                    installed_version=$(git --version | awk '{print $3}')
                    if ! check_git_version "$installed_version"; then
                        if [[ "$needs_upgrade" = true ]]; then
                            CURRENT_GIT_VERSION="$installed_version"
                            continue_with_existing_git "Git upgrade did not reach requested version ${LATEST_GIT_VERSION} (installed: ${installed_version})."
                            return 0
                        fi

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

            if ! confirm_git_system_change 'upgrade' "$LATEST_GIT_VERSION"; then
                echo -e "${YELLOW}⚠${NC} Skipping Git upgrade"
                echo -e "${CYAN}ℹ${NC} You can upgrade Git later manually"
                exit 0
            fi

            echo ""
        fi
    else
        echo -e "${YELLOW}ℹ${NC} Git not found — installing required prerequisite"
        echo ""
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
    save_to_setup_state "GIT_VERSION" "$CURRENT_GIT_VERSION"
    update_env_file "BLB_GIT_EXECUTABLE" "$(command -v git)"

    echo -e "${GREEN}✓ Git setup complete!${NC}"
    echo -e "${CYAN}Installed:${NC}"
    echo -e "  • Git: $(git --version)"
    echo -e "  • Git executable: $(command -v git)"
    return 0
}

# Run main function
main "$@"
