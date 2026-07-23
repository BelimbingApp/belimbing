#!/usr/bin/env bash
# scripts/setup-steps/05-environment.sh
# Title: Environment & Prerequisites
# Purpose: Prepare storage directories and .env for Belimbing
# Usage: ./scripts/setup-steps/05-environment.sh [local|staging|production]
#
# This script:
# - Creates storage/ directories (logs, app/.devops, etc.)
# - Copies .env.example to .env as the canonical config baseline
# - Fills setup-time defaults for a small set of environment-specific values
# - Saves APP_ENV and setup date to state
#
# Note: APP_KEY generation happens in 25-laravel.sh after Composer install.

set -euo pipefail

SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"

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

APP_ENV="${1:-local}"

# Detect an installation name from git remote or directory name.
detect_instance_name() {
    if command_exists git && [[ -d "$PROJECT_ROOT/.git" ]]; then
        local repo_name
        repo_name=$(git remote get-url origin 2>/dev/null | sed 's/.*\///' | sed 's/\.git$//' || echo "")
        if [[ -n "$repo_name" ]]; then
            echo "$repo_name"
            return 0
        fi
    fi
    basename "$PROJECT_ROOT"
}

# Detect default APP_DEBUG based on environment.
detect_app_debug() {
    case "$APP_ENV" in
        production) echo "false" ;;
        *)          echo "true" ;;
    esac

    return 0
}

# Prepare .env and prompt user for configuration values.
# When .env already exists, current values are offered as defaults so the user
# can confirm or override them. When .env is absent, it is created from the
# template first and hardcoded defaults are used as the initial prompt values.
create_env_file() {
    if [[ ! -f "$PROJECT_ROOT/.env" ]]; then
        cp "$PROJECT_ROOT/.env.example" "$PROJECT_ROOT/.env"
        echo -e "${GREEN}✓${NC} Created .env from .env.example"
    else
        echo -e "${CYAN}ℹ${NC} .env already exists — reviewing configuration"
    fi
    echo ""

    local instance_name instance_name_default
    instance_name_default=$(get_env_var "BLB_INSTANCE_NAME" "$(detect_instance_name)")
    if [[ -t 0 ]]; then
        instance_name=$(ask_input "BLB_INSTANCE_NAME" "$instance_name_default")
    else
        instance_name="$instance_name_default"
    fi

    update_env_file "BLB_INSTANCE_NAME" "$instance_name"
    update_env_file "APP_ENV" "$APP_ENV"

    # APP_DEBUG is auto-derived for local; only prompt for staging/production.
    if [[ "$APP_ENV" != "local" ]]; then
        local app_debug app_debug_default
        app_debug_default=$(get_env_var "APP_DEBUG" "$(detect_app_debug)")
        if [[ -t 0 ]]; then
            app_debug=$(ask_input "APP_DEBUG" "$app_debug_default")
        else
            app_debug="$app_debug_default"
        fi
        update_env_file "APP_DEBUG" "$app_debug"
    fi

    # Domain configuration — drives APP_URL, Caddy routing, and /etc/hosts.
    local default_domains default_frontend default_backend
    default_domains=$(get_default_domains "$APP_ENV")
    default_frontend=$(echo "$default_domains" | cut -d'|' -f1)
    default_backend=$(echo "$default_domains" | cut -d'|' -f2)

    local frontend_domain frontend_domain_default
    frontend_domain_default=$(get_env_var "FRONTEND_DOMAIN" "$default_frontend")
    if [[ -t 0 ]]; then
        frontend_domain=$(ask_input "FRONTEND_DOMAIN" "$frontend_domain_default")
    else
        frontend_domain="$frontend_domain_default"
    fi

    local derived_backend
    derived_backend=$(derive_backend_domain "$frontend_domain")
    local backend_domain backend_domain_default
    backend_domain_default=$(get_env_var "BACKEND_DOMAIN" "$derived_backend")
    if [[ -t 0 ]]; then
        backend_domain=$(ask_input "BACKEND_DOMAIN" "$backend_domain_default")
    else
        backend_domain="$backend_domain_default"
    fi

    save_domains_to_env "$frontend_domain" "$backend_domain"

    update_env_file_if_missing "DB_HOST" "127.0.0.1"
    update_env_file_if_missing "DB_PORT" "5432"
    update_env_file_if_missing "REDIS_HOST" "127.0.0.1"
    update_env_file_if_missing "REDIS_PORT" "6379"

    return 0
}

# === Main ===

print_section_banner "Environment Setup ($APP_ENV)"

echo -e "${CYAN}Creating storage directories...${NC}"
ensure_storage_dirs "$PROJECT_ROOT"
echo -e "${GREEN}✓${NC} Storage directories ready"
echo ""

load_setup_state

create_env_file
echo ""

save_to_setup_state "APP_ENV" "$APP_ENV"
save_to_setup_state "SETUP_DATE" "$(date +"%Y-%m-%dT%H:%M:%S%z")"

echo -e "${GREEN}✓ Environment setup complete!${NC}"
