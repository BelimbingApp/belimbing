#!/usr/bin/env bash
# scripts/setup-steps/25-laravel.sh
# Title: Laravel Application
# Purpose: Configure Laravel application after PHP and Composer are installed
# Usage: ./scripts/setup-steps/25-laravel.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Verifies PHP and Composer are installed
# - Installs Composer dependencies if needed
# - Generates Laravel APP_KEY if missing

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

# Check if .env file exists
check_env_file() {
    if [[ ! -f "$PROJECT_ROOT/.env" ]]; then
        echo -e "${RED}✗${NC} .env file not found" >&2
        echo -e "  Run ${CYAN}./scripts/setup-steps/05-environment.sh${NC} first" >&2
        return 1
    fi
    return 0
}

# Install Composer dependencies
install_dependencies() {
    if [[ -d "$PROJECT_ROOT/vendor" ]]; then
        echo -e "${GREEN}✓${NC} Composer dependencies already installed"
        return 0
    fi

    echo -e "${CYAN}Installing Composer dependencies...${NC}"
    echo ""

    if ! command_exists composer; then
        echo -e "${RED}✗${NC} Composer not found" >&2
        echo -e "  Run ${CYAN}./scripts/setup-steps/15-php.sh${NC} first" >&2
        return 1
    fi

    if composer install --no-interaction --prefer-dist --optimize-autoloader; then
        echo ""
        echo -e "${GREEN}✓${NC} Composer dependencies installed successfully"
        return 0
    else
        echo ""
        echo -e "${RED}✗${NC} Failed to install Composer dependencies" >&2
        return 1
    fi
}

# Generate Laravel APP_KEY
generate_app_key() {
    # Check if APP_KEY is already set and valid
    local app_key
    app_key=$(get_env_var "APP_KEY" "")
    if [[ -n "$app_key" ]] && [[ "$app_key" =~ ^base64:[A-Za-z0-9+/=]+$ ]]; then
        echo -e "${GREEN}✓${NC} APP_KEY already set"
        return 0
    fi

    # Check prerequisites
    if ! command_exists php; then
        echo -e "${RED}✗${NC} PHP not found" >&2
        echo -e "  Run ${CYAN}./scripts/setup-steps/15-php.sh${NC} first" >&2
        return 1
    fi

    if [[ ! -d "$PROJECT_ROOT/vendor" ]]; then
        echo -e "${YELLOW}⚠${NC} Composer dependencies not installed" >&2
        echo -e "  Installing dependencies first..." >&2
        if ! install_dependencies; then
            return 1
        fi
    fi

    echo -e "${CYAN}Generating Laravel APP_KEY...${NC}"
    if php artisan key:generate --force >/dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} APP_KEY generated successfully"
        return 0
    else
        echo -e "${RED}✗${NC} Failed to generate APP_KEY" >&2
        echo -e "  Run ${CYAN}php artisan key:generate${NC} manually" >&2
        return 1
    fi
}

# Main setup function
main() {
    print_section_banner "Laravel Application - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Check prerequisites (php/composer verified by 15-php.sh; guard for standalone runs)
    if ! command_exists php || ! command_exists composer; then
        echo -e "${RED}✗${NC} PHP and Composer are required" >&2
        echo -e "  Run ${CYAN}./scripts/setup-steps/15-php.sh${NC} first" >&2
        exit 1
    fi

    if ! check_env_file; then
        exit 1
    fi

    # Install Composer dependencies
    if ! install_dependencies; then
        exit 1
    fi
    echo ""

    # Generate APP_KEY
    if ! generate_app_key; then
        exit 1
    fi
    echo ""

    # Save state
    local app_key
    app_key=$(get_env_var "APP_KEY" "")
    if [[ -n "$app_key" ]]; then
        save_to_setup_state "APP_KEY_GENERATED" "true"
    fi
    save_to_setup_state "LARAVEL_CONFIGURED" "true"

    echo ""
    echo -e "${GREEN}✓ Laravel application configuration complete!${NC}"
    echo ""
    return 0
}

# Run main function
main "$@"
