#!/usr/bin/env bash
# scripts/setup-steps/60-migrations.sh
# Title: Database Migrations
# Purpose: Run Laravel migrations with environment-appropriate seeding
# Usage: ./scripts/setup-steps/60-migrations.sh [local|staging|production]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Prompts for framework primitives (licensee company, admin user)
# - Passes values as transient env vars to php artisan migrate
# - local: migrate --seed --dev (production + dev seeders)
# - staging/production: migrate --seed --force (production seeders only)
# - Clears and rebuilds application caches
#
# Framework primitives (licensee company, admin user, Lara) are created by
# MigrateCommand::ensureFrameworkPrimitives() in all environments.
# Values are NOT persisted to .env — the users table is stable (is_stable=true)
# so the admin row survives migrate:fresh runs.
#
# Prerequisites:
# - PHP and Composer installed (20-php.sh)
# - Laravel configured with APP_KEY (25-laravel.sh)
# - Database configured and accessible (40-database.sh)

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

# Print database connection troubleshooting guidance.
# Accepts optional captured output as $1 to extract error details.
print_db_troubleshoot() {
    local output="${1:-}"

    echo -e "${RED}✗${NC} Database connection failed" >&2
    if [[ -n "$output" ]]; then
        echo "$output" | grep -i "SQLSTATE\|FATAL\|refused\|password\|authentication" | head -3 | while IFS= read -r line; do
            echo -e "  ${RED}→${NC} $line" >&2
        done
    fi
    echo "" >&2
    echo -e "  ${YELLOW}Troubleshoot:${NC}" >&2
    echo -e "    1. Is PostgreSQL running?  ${CYAN}pg_isready${NC}" >&2
    echo -e "    2. Are credentials correct? Check ${CYAN}.env${NC} (DB_USERNAME, DB_PASSWORD)" >&2
    echo -e "    3. Re-run database setup:   ${CYAN}./scripts/setup-steps/40-database.sh${NC}" >&2

    return 0
}

# Detect default admin email from git config.
detect_admin_email() {
    git config user.email 2>/dev/null || echo "admin@example.com"
    return 0
}

# Rebuild application caches
rebuild_caches() {
    echo -e "${CYAN}Rebuilding application caches...${NC}"

    if [[ "$APP_ENV" = "production" ]] || [[ "$APP_ENV" = "staging" ]]; then
        php artisan config:cache 2>/dev/null || true
        php artisan route:cache 2>/dev/null || true
        php artisan view:cache 2>/dev/null || true
        echo -e "${GREEN}✓${NC} Caches rebuilt"
    else
        php artisan config:clear 2>/dev/null || true
        php artisan route:clear 2>/dev/null || true
        php artisan view:clear 2>/dev/null || true
        echo -e "${GREEN}✓${NC} Caches cleared (development mode)"
    fi

    return 0
}

# Main setup function
main() {
    print_section_banner "Database Migrations - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Prerequisites guard (for standalone runs)
    if ! command_exists php || [[ ! -f "$PROJECT_ROOT/artisan" ]]; then
        echo -e "${RED}✗${NC} PHP and Laravel are required" >&2
        echo -e "  Run ${CYAN}./scripts/setup-steps/25-laravel.sh${NC} first" >&2
        exit 1
    fi

    # Verify database connection before attempting migrations.
    # Catches credential/service issues early with a clear message,
    # instead of letting php artisan migrate dump a raw QueryException.
    echo -e "${CYAN}Verifying database connection...${NC}"
    local db_check_output
    if db_check_output=$(php artisan tinker --execute="DB::connection()->getPdo(); echo 'ok';" 2>&1); then
        if echo "$db_check_output" | grep -q "ok"; then
            echo -e "${GREEN}✓${NC} Database connection verified"
        else
            print_db_troubleshoot "$db_check_output"
            exit 1
        fi
    else
        print_db_troubleshoot "$db_check_output"
        exit 1
    fi
    echo ""

    # Prompt for framework primitives (licensee company, admin user).
    # These are passed as transient env vars to php artisan migrate.
    local company_name admin_name admin_email admin_password
    company_name=$(ask_input "Licensee company name" "My Company")
    admin_name=$(ask_input "Admin name" "Administrator")
    admin_email=$(ask_input "Admin email" "$(detect_admin_email)")
    admin_password=$(ask_password "Admin password (min 8 chars)")
    if [[ -z "$admin_password" ]]; then
        admin_password="password"
        echo -e "  ${YELLOW}ℹ${NC} Using default password: ${CYAN}password${NC}"
    fi
    echo ""

    # Run migrations with framework primitive env vars
    local migrate_args=()
    if [[ "$APP_ENV" = "local" ]]; then
        migrate_args=(--seed --dev)
    else
        migrate_args=(--seed --force)
    fi

    echo -e "${CYAN}Running migrations...${NC}"
    echo -e "${CYAN}ℹ${NC} migrate ${migrate_args[*]}"

    if ! LICENSEE_COMPANY_NAME="$company_name" \
         ADMIN_NAME="$admin_name" \
         ADMIN_EMAIL="$admin_email" \
         ADMIN_PASSWORD="$admin_password" \
         php artisan migrate "${migrate_args[@]}"; then
        echo -e "${RED}✗${NC} Migration failed" >&2
        echo -e "  Run ${CYAN}php artisan migrate ${migrate_args[*]}${NC} manually" >&2
        exit 1
    fi
    echo ""

    # Rebuild caches
    rebuild_caches
    echo ""

    save_to_setup_state "MIGRATIONS_RUN" "true"

    echo -e "${GREEN}✓ Database migrations complete!${NC}"
    return 0
}

# Run main function
main "$@"
