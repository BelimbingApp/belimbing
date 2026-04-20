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
# FrameworkPrimitivesProvisioner (called from MigrateCommand) in all environments.
# The licensee company is upserted onto id=1 so row 1 remains the canonical
# licensee across repeated setup runs.
# Values are NOT persisted to .env — the users table is stable (is_stable=true)
# so the admin row survives migrate:fresh runs.
#
# Prerequisites:
# - PHP and Composer installed (15-php.sh)
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

# Extract a single key from a JSON string using PHP.
# Usage: json_extract "$json_string" "key_name"
json_extract() {
    local json=$1
    local key=$2
    echo "$json" | php -r "\$data = json_decode(file_get_contents('php://stdin'), true) ?: []; echo \$data['${key}'] ?? '';" 2>/dev/null || echo ""

    return 0
}

# Resolve the preferred admin user id from setup state when available.
preferred_admin_user_id() {
    get_setup_state_var "ADMIN_USER_ID" ""
    return 0
}

# Create a transient admin bootstrap payload file for the migrate command.
create_admin_bootstrap_file() {
    local admin_name=$1
    local admin_email=$2
    local admin_password=$3
    local tmp_file
    mkdir -p "$PROJECT_ROOT/storage/app/.devops"
    tmp_file=$(mktemp "$PROJECT_ROOT/storage/app/.devops/admin-bootstrap.XXXXXX")
    chmod 600 "$tmp_file"
    printf '%s\n%s\n%s\n' "$admin_name" "$admin_email" "$admin_password" > "$tmp_file"
    echo "$tmp_file"
    return 0
}

# Load any existing framework primitive values from the database.
# Returns a JSON object with empty-string values when records are absent.
load_existing_framework_primitives() {
    local preferred_user_id="${1:-}"

    BLB_PREFERRED_ADMIN_USER_ID="$preferred_user_id" php artisan tinker --execute='
        $company = App\Modules\Core\Company\Models\Company::query()->find(App\Modules\Core\Company\Models\Company::LICENSEE_ID);
        $preferredAdminUserId = getenv("BLB_PREFERRED_ADMIN_USER_ID") ?: null;
        $adminQuery = $company ? App\Modules\Core\User\Models\User::query()->where("company_id", $company->id) : null;
        $admin = $company ? $company->resolveAdminUser() : null;

        if ($admin === null && $adminQuery !== null && is_string($preferredAdminUserId) && $preferredAdminUserId !== "") {
            $admin = (clone $adminQuery)->whereKey((int) $preferredAdminUserId)->first();
        }

        if ($admin === null && $adminQuery !== null) {
            $admin = $adminQuery->orderBy("id")->first();
        }

        echo json_encode([
            "company_name" => $company?->name ?? "",
            "company_code" => $company?->code ?? "",
            "admin_name" => $admin?->name ?? "",
            "admin_email" => $admin?->email ?? "",
        ]);
    ' 2>/dev/null || echo '{}'

    return 0
}

# Derive a default company code from company name (snake_case).
default_company_code_from_name() {
    local company_name="${1:-}"

    echo "$company_name" \
        | tr '[:upper:]' '[:lower:]' \
        | sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//'

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

    # Check if framework primitives (licensee company + admin user) already exist in the DB.
    # If they do, inform the user and skip prompting. Otherwise, prompt with the
    # best available defaults from the DB or setup state.
    local company_name="" company_code="" admin_name="" admin_email="" admin_password=""
    local primitives_exist=false

    local existing
    existing=$(load_existing_framework_primitives "$(preferred_admin_user_id)")

    if command_exists php; then
        # Strip any leading/trailing whitespace or Tinker decoration
        existing=$(echo "$existing" | grep -o '{.*}' 2>/dev/null || echo '{}')

        # Parse existing values from JSON
        company_name=$(json_extract "$existing" "company_name")
        company_code=$(json_extract "$existing" "company_code")
        admin_name=$(json_extract "$existing" "admin_name")
        admin_email=$(json_extract "$existing" "admin_email")

        if [[ -n "$company_name" && -n "$admin_email" ]]; then
            primitives_exist=true
            echo -e "${GREEN}✓${NC} Framework primitives already set up:"
            echo -e "  Company: ${CYAN}${company_name}${NC} (${company_code})"
            echo -e "  Admin:   ${CYAN}${admin_name}${NC} <${admin_email}>"
            echo ""
        fi
    fi

    if [[ "$primitives_exist" = false ]]; then
        # Prompt for framework primitives (licensee company, admin user).
        # These are passed as transient env vars to php artisan migrate.
        local company_name_default company_code_default admin_name_default admin_email_default
        company_name_default=$(get_setup_state_var "LICENSEE_COMPANY_NAME" "${company_name:-My Company}")
        company_name=$(ask_input "Licensee company name" "$company_name_default")

        local default_code
        default_code=$(default_company_code_from_name "$company_name")
        company_code_default=$(get_setup_state_var "LICENSEE_COMPANY_CODE" "${company_code:-$default_code}")
        company_code=$(ask_input "Licensee company code" "$company_code_default")

        admin_name_default="${admin_name:-Administrator}"
        admin_name=$(ask_input "Admin name" "$admin_name_default")

        admin_email_default="${admin_email:-$(detect_admin_email)}"
        admin_email=$(ask_input "Admin email" "$admin_email_default")
        admin_password=$(ask_password "Admin password (min 8 chars)")
        if [[ -z "$admin_password" ]]; then
            admin_password="password"
            echo -e "  ${YELLOW}ℹ${NC} Using default password: ${CYAN}password${NC}"
        fi
        echo ""
    fi

    # Run migrations with framework primitive env vars
    local migrate_args=()
    if [[ "$APP_ENV" = "local" ]]; then
        migrate_args=(--seed --dev)
    else
        migrate_args=(--seed --force)
    fi

    echo -e "${CYAN}Running migrations...${NC}"
    echo -e "${CYAN}ℹ${NC} migrate ${migrate_args[*]}"

    local admin_bootstrap_file=""
    if [[ "$primitives_exist" = false ]]; then
        admin_bootstrap_file=$(create_admin_bootstrap_file "$admin_name" "$admin_email" "$admin_password")
        trap '[[ -n "$admin_bootstrap_file" && -f "$admin_bootstrap_file" ]] && rm -f "$admin_bootstrap_file"' EXIT
    fi

    if ! LICENSEE_COMPANY_NAME="$company_name" \
            LICENSEE_COMPANY_CODE="$company_code" \
         BLB_BOOTSTRAP_ADMIN_FILE="$admin_bootstrap_file" \
         php artisan migrate "${migrate_args[@]}"; then
        echo -e "${RED}✗${NC} Migration failed" >&2
        echo -e "  Run ${CYAN}php artisan migrate ${migrate_args[*]}${NC} manually" >&2
        exit 1
    fi
    echo ""

    if [[ -n "$admin_bootstrap_file" && -f "$admin_bootstrap_file" ]]; then
        rm -f "$admin_bootstrap_file"
        trap - EXIT
    fi

    # Rebuild caches
    rebuild_caches
    echo ""

    local persisted
    persisted=$(load_existing_framework_primitives "")
    persisted=$(echo "$persisted" | grep -o '{.*}' 2>/dev/null || echo '{}')
    local persisted_admin_name persisted_admin_email persisted_company_name persisted_company_code persisted_admin_user_id
    persisted_company_name=$(json_extract "$persisted" "company_name")
    persisted_company_code=$(json_extract "$persisted" "company_code")
    persisted_admin_name=$(json_extract "$persisted" "admin_name")
    persisted_admin_email=$(json_extract "$persisted" "admin_email")
    persisted_admin_user_id=$(php artisan tinker --execute='
        $company = App\Modules\Core\Company\Models\Company::query()->find(App\Modules\Core\Company\Models\Company::LICENSEE_ID);
        echo $company?->adminUserId() ?? "";
    ' 2>/dev/null || echo "")

    company_name=${persisted_company_name:-$company_name}
    company_code=${persisted_company_code:-$company_code}
    admin_name=${persisted_admin_name:-$admin_name}
    admin_email=${persisted_admin_email:-$admin_email}

    save_to_setup_state "LICENSEE_COMPANY_NAME" "$company_name"
    save_to_setup_state "LICENSEE_COMPANY_CODE" "$company_code"
    save_to_setup_state "ADMIN_USER_ID" "$persisted_admin_user_id"
    remove_from_setup_state "ADMIN_NAME"
    remove_from_setup_state "ADMIN_EMAIL"
    save_to_setup_state "MIGRATIONS_RUN" "true"

    echo -e "${GREEN}✓ Database migrations complete!${NC}"
    return 0
}

# Run main function
main "$@"
