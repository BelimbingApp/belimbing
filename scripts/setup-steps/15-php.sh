#!/usr/bin/env bash
# scripts/setup-steps/15-php.sh
# Title: PHP & Composer
# Purpose: Install and configure PHP and Composer for Belimbing
# Usage: ./scripts/setup-steps/15-php.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Checks for PHP installation (requires PHP 8.5+)
# - Auto-installs PHP if missing (required prerequisite)
# - Checks for Composer installation
# - Auto-installs Composer if missing
# - Verifies PHP and Composer are available

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
readonly UNKNOWN_VERSION='unknown'
readonly PHP_VERSION_COMMAND='echo PHP_VERSION;'

# Check PHP version (requires 8.5+)
check_php_version() {
    if ! command_exists php; then
        return 1
    fi

    local php_version
    php_version=$(php -r "$PHP_VERSION_COMMAND" 2>/dev/null || echo "0.0.0")

    # Use version helper function from versions.sh
    if check_php_version_meets_minimum "$php_version"; then
        return 0
    fi
    return 1
}

# Upgrade PHP to required version
upgrade_php() {
    local os_type
    os_type=$(detect_os)

    local required_php_version
    required_php_version=$(get_required_php_version)

    echo -e "${CYAN}Upgrading PHP to ${required_php_version}+...${NC}"
    echo ""

    case "$os_type" in
        macos)
            if command_exists brew; then
                echo -e "${CYAN}Upgrading PHP via Homebrew...${NC}"
                # Unlink old PHP version if linked
                brew unlink php 2>/dev/null || true
                brew unlink php@8.2 2>/dev/null || true
                brew unlink php@8.3 2>/dev/null || true
                brew unlink php@8.4 2>/dev/null || true
                # Install PHP using version from versions.sh
                brew install "php@${required_php_version}" || brew install php
                brew link --overwrite --force "php@${required_php_version}" 2>/dev/null || {
                    echo -e "${YELLOW}Note:${NC} You may need to link PHP manually: ${CYAN}brew link php@${required_php_version}${NC}"
                }
            else
                echo -e "${RED}✗${NC} Homebrew required for PHP upgrade on macOS" >&2
                echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
                return 1
            fi
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                echo -e "${CYAN}Adding PHP repository...${NC}"
                sudo apt-get update -qq
                sudo apt-get install -y -qq software-properties-common
                sudo add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
                sudo apt-get update -qq

                # Check current PHP version to determine what to remove
                local current_php_version
                if command_exists php; then
                    current_php_version=$(php -r "$PHP_VERSION_COMMAND" 2>/dev/null || echo "0.0.0")
                    echo -e "${CYAN}Current PHP version: $current_php_version${NC}"

                    # Remove old PHP versions (only if version is less than required)
                    if ! check_php_version_meets_minimum "$current_php_version"; then
                        echo -e "${CYAN}Removing old PHP versions...${NC}"
                        # Remove specific version packages
                        sudo apt-get remove -y -qq php8.2* php8.3* php8.4* 2>/dev/null || true
                        # Also remove generic php package if it exists and is old
                        if dpkg -l | grep -q "^ii.*php[[:space:]]"; then
                            echo -e "${CYAN}Removing generic PHP package...${NC}"
                            sudo apt-get remove -y -qq php php-* 2>/dev/null || true
                        fi
                    fi
                else
                    # PHP not found, just remove any old version packages
                    echo -e "${CYAN}Removing old PHP versions...${NC}"
                    sudo apt-get remove -y -qq php8.2* php8.3* php8.4* 2>/dev/null || true
                fi

                # Install PHP using version from versions.sh
                echo -e "${CYAN}Installing PHP ${required_php_version}...${NC}"
                sudo apt-get install -y -qq "php${required_php_version}" "php${required_php_version}-cli" "php${required_php_version}-common" "php${required_php_version}-mbstring" "php${required_php_version}-xml" "php${required_php_version}-curl" "php${required_php_version}-zip" "php${required_php_version}-pgsql" "php${required_php_version}-bcmath" "php${required_php_version}-intl" || {
                    echo -e "${RED}✗${NC} Failed to install PHP ${required_php_version}" >&2
                    return 1
                }

                # Update alternatives to use PHP version from versions.sh
                echo -e "${CYAN}Setting PHP ${required_php_version} as default...${NC}"
                sudo update-alternatives --set php "/usr/bin/php${required_php_version}" 2>/dev/null || true
                # Also set phpize and phar if they exist
                sudo update-alternatives --set phpize "/usr/bin/phpize${required_php_version}" 2>/dev/null || true
                sudo update-alternatives --set phar "/usr/bin/phar${required_php_version}" 2>/dev/null || true
            elif command_exists yum; then
                echo -e "${CYAN}Upgrading PHP via yum...${NC}"
                # For RHEL/CentOS, we need to enable remi repository for PHP
                local php_repo="remi-php${required_php_version//./}"
                sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %{rhel}).rpm || true
                sudo yum-config-manager --enable "$php_repo" || true
                sudo yum update -y php php-cli php-common php-mbstring php-xml php-curl php-zip php-pgsql php-bcmath php-intl || {
                    echo -e "${RED}✗${NC} Failed to upgrade PHP" >&2
                    return 1
                }
            elif command_exists dnf; then
                echo -e "${CYAN}Upgrading PHP via dnf...${NC}"
                # For Fedora, enable remi repository for PHP
                local php_repo="remi-php${required_php_version//./}"
                sudo dnf install -y https://rpms.remirepo.net/fedora/remi-release-$(rpm -E %{fedora}).rpm || true
                sudo dnf config-manager --set-enabled "$php_repo" || true
                sudo dnf update -y php php-cli php-common php-mbstring php-xml php-curl php-zip php-pgsql php-bcmath php-intl || {
                    echo -e "${RED}✗${NC} Failed to upgrade PHP" >&2
                    return 1
                }
            else
                echo -e "${RED}✗${NC} Package manager not supported" >&2
                echo -e "  Please upgrade PHP to ${required_php_version}+ manually: ${CYAN}https://www.php.net/downloads${NC}" >&2
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for auto-upgrade" >&2
            echo -e "  Please upgrade PHP to ${required_php_version}+ manually: ${CYAN}https://www.php.net/downloads${NC}" >&2
            return 1
            ;;
    esac

    # Verify upgrade
    if check_php_version; then
        local php_version
        php_version=$(php -r "$PHP_VERSION_COMMAND")
        echo ""
        echo -e "${GREEN}✓${NC} PHP upgraded successfully: $php_version"
        return 0
    fi

    echo ""
    echo -e "${RED}✗${NC} PHP upgrade verification failed" >&2
    return 1
}

# Install PHP
install_php() {
    local os_type
    os_type=$(detect_os)

    local required_php_version
    required_php_version=$(get_required_php_version)

    echo -e "${CYAN}Installing PHP ${required_php_version}+...${NC}"
    echo ""

    case "$os_type" in
        macos)
            if command_exists brew; then
                echo -e "${CYAN}Installing PHP via Homebrew...${NC}"
                brew install "php@${required_php_version}" || brew install php
                brew link --overwrite --force "php@${required_php_version}" 2>/dev/null || {
                    echo -e "${YELLOW}Note:${NC} You may need to link PHP: ${CYAN}brew link php@${required_php_version}${NC}"
                }
            else
                echo -e "${RED}✗${NC} Homebrew required for PHP installation on macOS" >&2
                echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
                return 1
            fi
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                echo -e "${CYAN}Adding PHP repository...${NC}"
                sudo apt-get update -qq
                sudo apt-get install -y -qq software-properties-common
                sudo add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
                sudo apt-get update -qq
                echo -e "${CYAN}Installing PHP ${required_php_version}...${NC}"
                sudo apt-get install -y -qq "php${required_php_version}" "php${required_php_version}-cli" "php${required_php_version}-common" "php${required_php_version}-mbstring" "php${required_php_version}-xml" "php${required_php_version}-curl" "php${required_php_version}-zip" "php${required_php_version}-pgsql" "php${required_php_version}-bcmath" "php${required_php_version}-intl" || {
                    echo -e "${RED}✗${NC} Failed to install PHP" >&2
                    return 1
                }
                # Set PHP version from versions.sh as default
                sudo update-alternatives --set php "/usr/bin/php${required_php_version}" 2>/dev/null || true
            elif command_exists yum; then
                echo -e "${CYAN}Installing PHP via yum...${NC}"
                # Enable remi repository for PHP
                local php_repo="remi-php${required_php_version//./}"
                sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %{rhel}).rpm || true
                sudo yum-config-manager --enable "$php_repo" || true
                sudo yum install -y php php-cli php-common php-mbstring php-xml php-curl php-zip php-pgsql php-bcmath php-intl || {
                    echo -e "${RED}✗${NC} Failed to install PHP" >&2
                    return 1
                }
            elif command_exists dnf; then
                echo -e "${CYAN}Installing PHP via dnf...${NC}"
                # Enable remi repository for PHP
                local php_repo="remi-php${required_php_version//./}"
                sudo dnf install -y https://rpms.remirepo.net/fedora/remi-release-$(rpm -E %{fedora}).rpm || true
                sudo dnf config-manager --set-enabled "$php_repo" || true
                sudo dnf install -y php php-cli php-common php-mbstring php-xml php-curl php-zip php-pgsql php-bcmath php-intl || {
                    echo -e "${RED}✗${NC} Failed to install PHP" >&2
                    return 1
                }
            else
                echo -e "${RED}✗${NC} Package manager not supported" >&2
                echo -e "  Please install PHP ${required_php_version}+ manually: ${CYAN}https://www.php.net/downloads${NC}" >&2
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for auto-install" >&2
            echo -e "  Please install PHP ${required_php_version}+ manually: ${CYAN}https://www.php.net/downloads${NC}" >&2
            return 1
            ;;
    esac

    # Verify installation
    if check_php_version; then
        local php_version
        php_version=$(php -r "$PHP_VERSION_COMMAND")
        echo ""
        echo -e "${GREEN}✓${NC} PHP installed successfully: $php_version"
        return 0
    fi

    echo ""
    echo -e "${RED}✗${NC} PHP installation verification failed" >&2
    return 1
}

# Ensure required PHP extensions are installed
ensure_php_extensions_installed() {
    local required_php_version="$1"
    local required_extensions=(intl mbstring xml curl zip pgsql bcmath)
    local missing_extensions=()
    for ext in "${required_extensions[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            missing_extensions+=("$ext")
        fi
    done

    if [[ ${#missing_extensions[@]} -gt 0 ]]; then
        echo -e "${YELLOW}⚠${NC} Missing PHP extensions: ${missing_extensions[*]}"
        local os_type
        os_type=$(detect_os)
        case "$os_type" in
            linux|wsl2)
                if command_exists apt-get; then
                    for ext in "${missing_extensions[@]}"; do
                        sudo apt-get install -y -qq "php${required_php_version}-$ext"
                    done
                elif command_exists yum; then
                    for ext in "${missing_extensions[@]}"; do
                        sudo yum install -y "php-$ext"
                    done
                elif command_exists dnf; then
                    for ext in "${missing_extensions[@]}"; do
                        sudo dnf install -y "php-$ext"
                    done
                else
                    echo -e "${RED}✗${NC} Package manager not supported for extension install" >&2
                fi
                ;;
            macos)
                if command_exists brew; then
                    echo -e "${YELLOW}Note:${NC} For macOS, extensions are bundled or require pecl. Please install missing extensions manually if needed."
                else
                    echo -e "${RED}✗${NC} Homebrew required for extension install on macOS" >&2
                fi
                ;;
            *)
                echo -e "${RED}✗${NC} OS not supported for extension install" >&2
                ;;
        esac
        # Re-check extensions
        local still_missing=()
        for ext in "${missing_extensions[@]}"; do
            if ! php -m | grep -q "^$ext$"; then
                still_missing+=("$ext")
            fi
        done
        if [[ ${#still_missing[@]} -eq 0 ]]; then
            echo -e "${GREEN}✓${NC} All required PHP extensions installed"
        else
            echo -e "${RED}✗${NC} Still missing extensions: ${still_missing[*]}"
        fi
    else
        echo -e "${GREEN}✓${NC} All required PHP extensions present"
    fi
    return 0
}

# Install Composer
install_composer() {
    echo -e "${CYAN}Installing Composer...${NC}"
    echo ""

    # Download and install Composer
    local composer_installer
    composer_installer=$(mktemp)
    curl -sS https://getcomposer.org/installer -o "$composer_installer" || {
        echo -e "${RED}✗${NC} Failed to download Composer installer" >&2
        return 1
    }

    # Verify installer
    local expected_signature
    expected_signature=$(curl -sS https://composer.github.io/installer.sig)
    local actual_signature
    actual_signature=$(php -r "echo hash_file('sha384', '$composer_installer');")

    if [[ "$expected_signature" != "$actual_signature" ]]; then
        echo -e "${RED}✗${NC} Composer installer signature mismatch" >&2
        rm -f "$composer_installer"
        return 1
    fi

    # Install Composer globally
    php "$composer_installer" --install-dir=/usr/local/bin --filename=composer || {
        echo -e "${YELLOW}Installing to user directory...${NC}"
        php "$composer_installer" --install-dir="$HOME/.local/bin" --filename=composer || {
            echo -e "${RED}✗${NC} Failed to install Composer" >&2
            rm -f "$composer_installer"
            return 1
        }
        echo -e "${YELLOW}Note:${NC} Add ${CYAN}$HOME/.local/bin${NC} to your PATH"
    }

    rm -f "$composer_installer"

    # Verify installation
    if command_exists composer; then
        local composer_version
        composer_version=$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")
        echo ""
        echo -e "${GREEN}✓${NC} Composer installed successfully: $composer_version"

        # Self-update to ensure latest version
        echo -e "${CYAN}Ensuring latest Composer version...${NC}"
        composer self-update --quiet 2>/dev/null || sudo composer self-update --quiet 2>/dev/null || true
        return 0
    fi

    echo ""
    echo -e "${YELLOW}⚠${NC} Composer installed but not in PATH" >&2
    echo -e "  You may need to restart your shell or add the install directory to PATH" >&2
    return 1
}

# Main setup function
main() {
    print_section_banner "PHP & Composer Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Check PHP
    local required_php_version
    required_php_version=$(get_required_php_version)

    if check_php_version; then
        local php_version
        php_version=$(php -r "$PHP_VERSION_COMMAND")
        echo -e "${GREEN}✓${NC} PHP already installed: $php_version (meets requirement: ${required_php_version}+)"

        ensure_php_extensions_installed "$required_php_version"
    else
        if command_exists php; then
            local php_version
            php_version=$(php -r "$PHP_VERSION_COMMAND" 2>/dev/null || echo "$UNKNOWN_VERSION")
            echo -e "${YELLOW}⚠${NC} PHP version too old: $php_version (requires ${required_php_version}+)"

            if [[ -t 0 ]]; then
                if ask_yes_no "Upgrade PHP to ${required_php_version}+?" "y"; then
                    if ! upgrade_php; then
                        echo -e "${RED}✗${NC} PHP upgrade failed"
                        echo ""
                        echo -e "${YELLOW}Please upgrade PHP to ${required_php_version}+ manually:${NC}"
                        echo -e "  • macOS: ${CYAN}brew install php@${required_php_version} && brew link php@${required_php_version}${NC}"
                        echo -e "  • Linux: ${CYAN}sudo apt-get install php${required_php_version}${NC}"
                        echo -e "  • Manual: ${CYAN}https://www.php.net/downloads${NC}"
                        exit 1
                    fi
                else
                    echo -e "${YELLOW}Skipping PHP upgrade${NC}"
                    exit 1
                fi
            else
                # Non-interactive mode - auto-upgrade
                if ! upgrade_php; then
                    exit 1
                fi
            fi
        else
            echo -e "${YELLOW}ℹ${NC} PHP not found"

            if ! install_php; then
                echo -e "${RED}✗${NC} PHP installation failed"
                echo ""
                echo -e "${YELLOW}Please install PHP ${required_php_version}+ manually:${NC}"
                echo -e "  • macOS: ${CYAN}brew install php@${required_php_version}${NC}"
                echo -e "  • Linux: ${CYAN}sudo apt-get install php${required_php_version}${NC}"
                echo -e "  • Manual: ${CYAN}https://www.php.net/downloads${NC}"
                exit 1
            fi
        fi
    fi

    echo ""

    # Check Composer
    if command_exists composer; then
        local composer_version
        composer_version=$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")
        echo -e "${GREEN}✓${NC} Composer already installed: $composer_version"

        # Try to update Composer; may fail if installed system-wide without write access
        if composer self-update --quiet 2>/dev/null || sudo composer self-update --quiet 2>/dev/null; then
            local updated_version
            updated_version=$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")
            if [[ "$composer_version" != "$updated_version" ]]; then
                echo -e "${GREEN}✓${NC} Composer updated: $updated_version"
            fi
        fi
    else
        echo -e "${YELLOW}ℹ${NC} Composer not found"

        if ! install_composer; then
            echo -e "${RED}✗${NC} Composer installation failed"
            echo ""
            echo -e "${YELLOW}Please install Composer manually:${NC}"
            echo -e "  ${CYAN}https://getcomposer.org/download/${NC}"
            exit 1
        fi
    fi

    echo ""

    # Save state
    if command_exists php; then
        save_to_setup_state "PHP_VERSION" "$(php -r "$PHP_VERSION_COMMAND")"
    fi
    if command_exists composer; then
        save_to_setup_state "COMPOSER_VERSION" "$(composer --version 2>/dev/null | head -1 || echo "$UNKNOWN_VERSION")"
    fi

    echo ""
    echo -e "${GREEN}✓ PHP & Composer setup complete!${NC}"
    return 0
}

# Run main function
main "$@"
