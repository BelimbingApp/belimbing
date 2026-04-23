#!/bin/bash
# Belimbing Configuration Variables
# Single source of truth for configuration defaults and helper functions
#
# === Configuration Variables ===
# These are loaded from .env file at runtime by start-app.sh
# and from storage/app/.devops/setup.env during setup steps.
#
# Expected variables:
# - APP_ENV: local|staging|production|testing
# - DATABASE_URL: PostgreSQL connection string
# - JWT_SECRET, JWT_EXPIRATION_HOURS, JWT_REFRESH_EXPIRATION_DAYS
# - FRONTEND_DOMAIN, BACKEND_DOMAIN
# - BACKEND_PORT, FRONTEND_PORT
# - BACKEND_URL

# Source version constants (must be sourced before using version-related functions)
# Determine script directory from this file's location
_CONFIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Source versions.sh if it exists
# shellcheck source=versions.sh
if [[ -f "$_CONFIG_DIR/versions.sh" ]]; then
    source "$_CONFIG_DIR/versions.sh" 2>/dev/null || true
fi
unset _CONFIG_DIR

# === Setup Defaults ===
# shellcheck disable=SC2034
# Used by setup scripts to provide sensible defaults during interactive setup
# Use a dedicated application role by default so setup never modifies the
# PostgreSQL superuser account unless the operator explicitly opts in.
DEFAULT_DB_USER="belimbing_app"
DEFAULT_DB_PORT="5432"

# Generate a random token for per-installation secrets (DB passwords, API keys, etc.).
# Uses openssl if available, falls back to /dev/urandom.
generate_random_token() {
    local length="${1:-32}"

    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 48 | tr -d '=+/' | cut -c1-"$length"
        return 0
    fi

    head -c 48 /dev/urandom 2>/dev/null | base64 | tr -d '=+/' | cut -c1-"$length"
}

# Default preferred ports (VITE_PORT|APP_PORT).
# Only a starting hint — start-app auto-assigns free ports via next_free_port at runtime,
# so multiple instances (main + worktrees) never collide.
# HTTPS is always 443 (shared Caddy with host-based routing).
get_default_ports() {
    echo "5173|8000"
    return 0
}

# Get backend port for environment (reads from .env or uses default)
get_backend_port() {
    local env=$1
    local project_root=${2:-$PROJECT_ROOT}

    # Try to read from .env file
    if [[ -n "$project_root" ]]; then
        local port
        port=$(get_env_var "APP_PORT" "" "$project_root/.env")
        if [[ -n "$port" ]] && [[ "$port" =~ ^[0-9]+$ ]]; then
            echo "$port"
            return 0
        fi
    fi

    # Use default based on environment
    local defaults
    defaults=$(get_default_ports "$env")
    echo "$defaults" | cut -d'|' -f2
    return 0
}

# Get frontend port for environment (reads from .env or uses default)
get_frontend_port() {
    local env=$1
    local project_root=${2:-$PROJECT_ROOT}

    # Try to read from .env file
    if [[ -n "$project_root" ]]; then
        local port
        port=$(get_env_var "VITE_PORT" "" "$project_root/.env")
        if [[ -n "$port" ]] && [[ "$port" =~ ^[0-9]+$ ]]; then
            echo "$port"
            return 0
        fi
    fi

    # Use default based on environment
    local defaults
    defaults=$(get_default_ports "$env")
    echo "$defaults" | cut -d'|' -f1
    return 0
}


# Get default domains for an environment
# Returns: frontend_domain|backend_domain
# Pattern: ${env}.blb.lara for frontend, ${env}.api.blb.lara for backend
get_default_domains() {
    local env=$1
    case "$env" in
        production)
            echo "app.blb.lara|api.blb.lara"
            ;;
        *)
            echo "${env}.blb.lara|${env}.api.blb.lara"
            ;;
    esac
    return 0
}

# Derive the backend (API) domain from a frontend domain.
# Convention: insert 'api.' after the first segment.
# Example: local.blb.lara -> local.api.blb.lara
derive_backend_domain() {
    local frontend=$1
    local first_segment="${frontend%%.*}"
    local rest="${frontend#*.}"
    echo "${first_segment}.api.${rest}"
    return 0
}

# Save frontend and backend domains to .env, and derive APP_URL.
# Keeps APP_URL in sync whenever domains change.
# Usage: save_domains_to_env "frontend_domain" "backend_domain"
save_domains_to_env() {
    local frontend_domain=$1
    local backend_domain=$2
    local app_scheme
    app_scheme=$(get_env_var "APP_SCHEME" "https")

    update_env_file "FRONTEND_DOMAIN" "$frontend_domain"
    update_env_file "BACKEND_DOMAIN" "$backend_domain"
    update_env_file "APP_URL" "${app_scheme}://${frontend_domain}"
    return 0
}

# Get default JWT expiration values
get_default_jwt_expiration() {
    echo "24|30"
    return 0
}

# Get default database name for an environment
get_default_database_name() {
    local env=$1
    echo "belimbing_${env}"
    return 0
}

# Get DB_CONNECTION from .env (pgsql, sqlite, mysql, etc.)
# Uses PROJECT_ROOT; defaults to pgsql if unset or .env missing.
get_db_connection() {
    if [[ -z "${PROJECT_ROOT:-}" ]]; then
        echo "pgsql"
        return 0
    fi
    local val
    val=$(get_env_var "DB_CONNECTION" "pgsql")
    echo "$val"
    return 0
}

# === Environment Validation ===
# Normalize and validate APP_ENV variable
# Usage: normalize_and_validate_env "$APP_ENV"
# Returns: Normalized environment name (Laravel standard: local, staging, production, testing)
# Exits with code 1 if invalid
normalize_and_validate_env() {
    local env=$1

    # Normalize environment name (support old values for backward compatibility)
    case "$env" in
        dev|development) env="local" ;;
        stage) env="staging" ;;
        prod) env="production" ;;
        test) env="testing" ;;
        *) ;;
    esac

    # Validate environment type (Laravel standard values)
    if [[ ! "$env" =~ ^(local|staging|production|testing)$ ]]; then
        echo -e "${RED}✗${NC} Invalid environment: $env" >&2
        echo -e "  Valid options: local, staging, production, testing" >&2
        exit 1
    fi

    echo "$env"
    return 0
}

# === State Management ===
# Setup state file location
get_setup_state_file() {
    if [[ -z "${PROJECT_ROOT:-}" ]]; then
        echo "Error: PROJECT_ROOT not set" >&2
        return 1
    fi
    echo "$PROJECT_ROOT/storage/app/.devops/setup.env"
    return 0
}

# Initialize setup state file with header if it doesn't exist
init_setup_state_file() {
    local state_file
    state_file=$(get_setup_state_file)

    if [[ ! -f "$state_file" ]]; then
        mkdir -p "$(dirname "$state_file")"
        cat > "$state_file" << 'EOF'
# Belimbing Setup State
# This file is generated by the setup scripts
# Do not edit manually - changes may be overwritten
EOF
    fi
    return 0
}

# Load setup state from storage/app/.devops/setup.env
# Preserves CLI-provided APP_ENV if set before calling this function
# Automatically normalizes and validates APP_ENV after loading
load_setup_state() {
    local state_file
    state_file=$(get_setup_state_file)

    # Preserve CLI-provided APP_ENV (takes precedence over state files)
    local cli_app_env="${APP_ENV:-}"

    if [[ -f "$state_file" ]]; then
        # shellcheck disable=SC1090
        source "$state_file" 2>/dev/null || true
    fi

    # Also try loading from .env, make .env variables available
    if [[ -f "$PROJECT_ROOT/.env" ]]; then
        set -a
        source "$PROJECT_ROOT/.env" 2>/dev/null || true
        set +a
    fi

    # Restore CLI argument if it was set (CLI takes precedence)
    if [[ -n "$cli_app_env" ]]; then
        APP_ENV="$cli_app_env"
    fi

    # Normalize and validate APP_ENV (from CLI, state file, or .env)
    APP_ENV=$(normalize_and_validate_env "$APP_ENV")
    return 0
}

# Read a single variable from the setup state file.
# Usage: get_setup_state_var KEY [default]
get_setup_state_var() {
    local key=$1
    local default_value=${2:-}
    local state_file
    state_file=$(get_setup_state_file) || {
        echo "$default_value"
        return 0
    }

    if [[ ! -f "$state_file" ]]; then
        echo "$default_value"
        return 0
    fi

    local value
    value=$(sed -n "s/^${key}=\"\\(.*\\)\"$/\\1/p" "$state_file" | tail -1)

    if [[ -n "$value" ]]; then
        echo "$value"
    else
        echo "$default_value"
    fi

    return 0
}

# Save a key-value pair to setup state file
# Usage: save_to_setup_state "KEY" "value"
save_to_setup_state() {
    local key=$1
    local value=$2
    local state_file
    state_file=$(get_setup_state_file)

    # Ensure state file exists with header
    init_setup_state_file

    # Remove existing key if present
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "/^${key}=/d" "$state_file" 2>/dev/null || true
    else
        sed -i "/^${key}=/d" "$state_file" 2>/dev/null || true
    fi

    # Append new value
    echo "${key}=\"${value}\"" >> "$state_file"
    return 0
}

# Remove a key from the setup state file if it exists.
# Usage: remove_from_setup_state "KEY"
remove_from_setup_state() {
    local key=$1
    local state_file
    state_file=$(get_setup_state_file)

    if [[ ! -f "$state_file" ]]; then
        return 0
    fi

    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "/^${key}=/d" "$state_file" 2>/dev/null || true
    else
        sed -i "/^${key}=/d" "$state_file" 2>/dev/null || true
    fi

    return 0
}

# Escape special characters for sed replacement string
# In sed replacement, we need to escape: &, \, and the delimiter #
escape_sed_replacement() {
    local value="$1"
    # Escape backslashes first (must be first)
    value="${value//\\/\\\\}"
    # Escape ampersands
    value="${value//&/\\&}"
    # Escape the delimiter # (we use # instead of | for better compatibility)
    value="${value//#/\\#}"
    printf '%s' "$value"
    return 0
}

# Read a single variable from .env.
# Usage: get_env_var KEY [default]
# - KEY: variable name (literal, no regex).
# - default: value if KEY missing or empty (default: "").
# Reads from $PROJECT_ROOT/.env. Optional third arg env_file overrides path (used internally by port getters).
# Output: value with leading/trailing space and surrounding quotes stripped.
# Values containing = are supported (everything after first =).
get_env_var() {
    local key="$1"
    local default="${2:-}"
    local env_file="${3:-${PROJECT_ROOT:-}/.env}"

    [[ -f "$env_file" ]] || { echo "$default"; return 0; }
    [[ -n "$key" ]] || { echo "$default"; return 0; }

    local key_escaped
    key_escaped=$(printf '%s' "$key" | sed 's/[.[\*^$()+?{|]/\\&/g')
    local line
    line=$(grep -E "^[[:space:]]*${key_escaped}[[:space:]]*=" "$env_file" 2>/dev/null | head -1)
    [[ -n "$line" ]] || { echo "$default"; return 0; }

    local val="${line#*=}"
    val=$(echo "$val" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    val=$(echo "$val" | tr -d '"' | tr -d "'")
    if [[ -n "$val" ]]; then
        echo "$val"
    else
        echo "$default"
    fi
}

# Update or add a variable to the .env file.
# Usage: update_env_file "VARIABLE_NAME" "value" [env_file]
# - env_file defaults to $PROJECT_ROOT/.env when omitted.
update_env_file() {
    local var_name=$1
    local var_value=$2
    local env_file="${3:-${PROJECT_ROOT:-}/.env}"

    if [[ ! -f "$env_file" ]]; then
        echo "$var_name=\"$var_value\"" > "$env_file"
        return 0
    fi

    # Check if variable exists in file
    if grep -q "^${var_name}=" "$env_file"; then
        # Escape special characters in the replacement string
        local escaped_value
        escaped_value=$(escape_sed_replacement "$var_value")

        # Escape variable name for use in sed pattern (escape special regex chars: . * ^ $ [ ] ( ) + ? { |)
        local escaped_var_name="${var_name}"
        escaped_var_name="${escaped_var_name//\./\\.}"
        escaped_var_name="${escaped_var_name//\*/\\*}"
        escaped_var_name="${escaped_var_name//\^/\\^}"
        escaped_var_name="${escaped_var_name//\$/\\$}"
        escaped_var_name="${escaped_var_name//\[/\\[}"
        escaped_var_name="${escaped_var_name//\]/\\]}"
        escaped_var_name="${escaped_var_name//\(/\\(}"
        escaped_var_name="${escaped_var_name//\)/\\)}"
        escaped_var_name="${escaped_var_name//\+/\\+}"
        escaped_var_name="${escaped_var_name//\?/\\?}"
        escaped_var_name="${escaped_var_name//\{/\\{}"
        escaped_var_name="${escaped_var_name//\|/\\|}"

        # Use # as delimiter (unlikely to appear in variable names or values)
        local sed_pattern="s#^${escaped_var_name}=.*#${var_name}=\"${escaped_value}\"#"

        # Update existing variable (handle both quoted and unquoted values)
        if [[ "$OSTYPE" == "darwin"* ]]; then
            # macOS sed syntax
            sed -i '' "$sed_pattern" "$env_file" || return 1
        else
            # Linux sed syntax
            sed -i "$sed_pattern" "$env_file" || return 1
        fi
    else
        # Append new variable
        echo "$var_name=\"$var_value\"" >> "$env_file"
    fi
}

# Set a variable in .env only when it is missing or empty (upsert-if-missing).
# Usage: update_env_file_if_missing "VARIABLE_NAME" "default_value" [env_file]
# - env_file defaults to $PROJECT_ROOT/.env when omitted.
update_env_file_if_missing() {
    local var_name=$1
    local default_value=$2
    local env_file="${3:-${PROJECT_ROOT:-}/.env}"

    local current
    current=$(get_env_var "$var_name" "" "$env_file")
    if [[ -z "$current" ]]; then
        update_env_file "$var_name" "$default_value" "$env_file"
    fi

    return 0
}
