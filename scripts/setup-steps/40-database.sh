#!/usr/bin/env bash
# scripts/setup-steps/40-database.sh
# Title: Database & Redis
# Purpose: Install and configure PostgreSQL and Redis for Belimbing
# Usage: ./scripts/setup-steps/40-database.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Auto-detects existing PostgreSQL/Redis installations
# - Installs PostgreSQL/Redis if needed
# - Creates database and user automatically when local admin access is available
# - Saves credentials to .env automatically
# - Supports SQLite fallback for minimal deployments

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

# Constants for .env keys
readonly ENV_KEY_DB_DATABASE="DB_DATABASE"
readonly ENV_KEY_DB_CONNECTION="DB_CONNECTION"
readonly LOCAL_DB_HOST="127.0.0.1"
readonly POSTGRES_ADMIN_MODE_NONE="none"
readonly POSTGRES_ADMIN_MODE_CURRENT_OS_USER="current-os-user"
readonly POSTGRES_ADMIN_MODE_POSTGRES_OS_USER="postgres-os-user"

POSTGRES_ADMIN_MODE="$POSTGRES_ADMIN_MODE_NONE"

escape_postgresql_literal() {
    printf "%s" "$1" | sed "s/'/''/g"
}

escape_postgresql_identifier() {
    printf "%s" "$1" | sed 's/"/""/g'
}

has_existing_postgresql_config() {
    local db_port db_name db_user db_password
    db_port=$(get_env_var "DB_PORT" "")
    db_name=$(get_env_var "$ENV_KEY_DB_DATABASE" "")
    db_user=$(get_env_var "DB_USERNAME" "")
    db_password=$(get_env_var "DB_PASSWORD" "")

    [[ -n "$db_port" ]] && [[ -n "$db_name" ]] && [[ -n "$db_user" ]] && [[ -n "$db_password" ]]
}

prompt_database_password() {
    local prompt=$1
    local saved_password=${2:-}
    local response

    while true; do
        if [[ -n "$saved_password" ]]; then
            response=$(ask_password "$prompt (press Enter to keep saved value)")
            if [[ -n "$response" ]]; then
                echo "$response"
                return 0
            fi

            echo "$saved_password"
            return 0
        fi

        response=$(ask_password "$prompt")
        if [[ -n "$response" ]]; then
            echo "$response"
            return 0
        fi

        echo -e "${YELLOW}This field is required${NC}" >&2
    done
}

describe_postgresql_admin_mode() {
    case "$POSTGRES_ADMIN_MODE" in
        "$POSTGRES_ADMIN_MODE_CURRENT_OS_USER") echo 'your current OS user via local socket auth' ;;
        "$POSTGRES_ADMIN_MODE_POSTGRES_OS_USER") echo 'the local postgres OS user via sudo' ;;
        *) echo 'no local PostgreSQL admin path detected' ;;
    esac
}

detect_postgresql_admin_mode() {
    POSTGRES_ADMIN_MODE="$POSTGRES_ADMIN_MODE_NONE"

    if psql -w -d postgres -c "SELECT 1" >/dev/null 2>&1; then
        POSTGRES_ADMIN_MODE="$POSTGRES_ADMIN_MODE_CURRENT_OS_USER"
        return 0
    fi

    if ! command_exists sudo; then
        return 1
    fi

    if [[ -t 0 ]]; then
        if sudo -u postgres psql -d postgres -c "SELECT 1" >/dev/null 2>&1; then
            POSTGRES_ADMIN_MODE="$POSTGRES_ADMIN_MODE_POSTGRES_OS_USER"
            return 0
        fi
    elif sudo -n -u postgres psql -d postgres -c "SELECT 1" >/dev/null 2>&1; then
        POSTGRES_ADMIN_MODE="$POSTGRES_ADMIN_MODE_POSTGRES_OS_USER"
        return 0
    fi

    return 1
}

run_postgresql_admin_sql() {
    local sql=$1

    case "$POSTGRES_ADMIN_MODE" in
        "$POSTGRES_ADMIN_MODE_CURRENT_OS_USER")
            psql -v ON_ERROR_STOP=1 -w -d postgres -c "$sql"
            ;;
        "$POSTGRES_ADMIN_MODE_POSTGRES_OS_USER")
            if [[ -t 0 ]]; then
                sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres -c "$sql"
            else
                sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d postgres -c "$sql"
            fi
            ;;
        *)
            return 1
            ;;
    esac
}

save_postgresql_credentials() {
    local db_host=$1
    local db_port=$2
    local db_name=$3
    local db_user=$4
    local db_password=$5

    if [[ ! -f "$PROJECT_ROOT/.env" ]]; then
        return 0
    fi

    update_env_file "DB_CONNECTION" "pgsql"
    update_env_file "DB_HOST" "$db_host"
    update_env_file "DB_PORT" "$db_port"
    update_env_file "$ENV_KEY_DB_DATABASE" "$db_name"
    update_env_file "DB_USERNAME" "$db_user"
    update_env_file "DB_PASSWORD" "$db_password"
}

reuse_existing_postgresql_config_if_working() {
    if ! has_existing_postgresql_config; then
        return 1
    fi

    if ! command_exists psql; then
        return 1
    fi

    echo -e "${CYAN}Checking PostgreSQL configuration from .env...${NC}"
    if verify_postgresql_connection; then
        echo -e "${GREEN}✓${NC} Reusing existing PostgreSQL configuration"
        return 0
    fi

    echo -e "${YELLOW}⚠${NC} Existing PostgreSQL configuration in .env could not be verified" >&2
    diagnose_postgresql_connection || true
    return 1
}

setup_existing_postgresql_connection() {
    local default_db_name
    default_db_name=$(get_default_database_name "$APP_ENV")

    echo ""
    echo -e "${CYAN}Belimbing could not get local PostgreSQL admin access.${NC}"
    echo -e "${CYAN}Provide credentials for an existing PostgreSQL database and user.${NC}"
    echo -e "${CYAN}Belimbing will verify them, then save them to .env.${NC}"
    echo ""

    local db_host db_port db_name db_user existing_password db_password
    db_host=$(ask_input "DB_HOST" "$(get_env_var "DB_HOST" "$LOCAL_DB_HOST")")
    db_port=$(ask_input "DB_PORT" "$(get_env_var "DB_PORT" "$DEFAULT_DB_PORT")")
    db_name=$(ask_input "DB_DATABASE" "$(get_env_var "$ENV_KEY_DB_DATABASE" "$default_db_name")")
    db_user=$(ask_input "DB_USERNAME" "$(get_env_var "DB_USERNAME" "$DEFAULT_DB_USER")")
    existing_password=$(get_env_var "DB_PASSWORD" "")
    db_password=$(prompt_database_password "DB_PASSWORD" "$existing_password")

    echo ""
    echo -e "${CYAN}Verifying database connection...${NC}"

    if ! verify_postgresql_connection "$db_host" "$db_port" "$db_name" "$db_user" "$db_password"; then
        echo -e "${RED}✗${NC} Could not verify the provided PostgreSQL credentials" >&2
        return 1
    fi

    save_postgresql_credentials "$db_host" "$db_port" "$db_name" "$db_user" "$db_password"
    echo -e "${GREEN}✓${NC} Database connection verified successfully"
    echo -e "${GREEN}✓${NC} Database credentials saved to .env"
    return 0
}

configure_postgresql_database() {
    if reuse_existing_postgresql_config_if_working; then
        return 0
    fi

    if detect_postgresql_admin_mode; then
        setup_postgresql_database
        return $?
    fi

    setup_existing_postgresql_connection
}

# Check if PostgreSQL is installed and running
check_postgresql() {
    if command_exists psql; then
        # Check if service is running
        if command_exists pg_isready && pg_isready -h localhost -p 5432 >/dev/null 2>&1; then
            return 0
        fi
        # psql exists but service might not be running
        return 1
    fi
    return 1
}

# Fix PostgreSQL repository GPG key (migrate from legacy to modern method)
fix_postgresql_repo_key() {
    local pg_key_file="/etc/apt/trusted.gpg.d/postgresql-repo.gpg"

    # Check if legacy key exists in trusted.gpg
    if [[ -f "/etc/apt/trusted.gpg" ]] && command_exists apt-key && apt-key list 2>/dev/null | grep -q "PostgreSQL"; then
        echo -e "${CYAN}Migrating PostgreSQL GPG key from legacy storage...${NC}"
        # Export the key using apt-key and convert to modern format
        apt-key export ACCC4CF8 2>/dev/null | sudo gpg --dearmor -o "$pg_key_file" 2>/dev/null || {
            # Fallback: download fresh key
            sudo wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | \
                sudo gpg --dearmor -o "$pg_key_file" 2>/dev/null || true
        }
        sudo chmod 644 "$pg_key_file" 2>/dev/null || true

        # Remove from legacy keyring (safe, we have it in modern location now)
        sudo apt-key del ACCC4CF8 2>/dev/null || true
    fi

    # Ensure key file exists in modern location
    if ! [[ -f "$pg_key_file" ]]; then
        echo -e "${CYAN}Downloading PostgreSQL repository GPG key (modern method)...${NC}"
        sudo wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | \
            sudo gpg --dearmor -o "$pg_key_file" 2>/dev/null || {
            # Fallback for systems without gpg command
            sudo wget --quiet -O "$pg_key_file" https://www.postgresql.org/media/keys/ACCC4CF8.asc || true
        }
        sudo chmod 644 "$pg_key_file" 2>/dev/null || true
    fi
    return 0
}

# Install PostgreSQL
install_postgresql() {
    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Installing PostgreSQL...${NC}"
    echo ""

    local postgresql_brew_version
    postgresql_brew_version=$(get_postgresql_brew_version)

    case "$os_type" in
        macos)
            if command_exists brew; then
                echo -e "${CYAN}Installing PostgreSQL via Homebrew...${NC}"
                brew install "$postgresql_brew_version" || brew install postgresql
                brew services start "$postgresql_brew_version" 2>/dev/null || brew services start postgresql
            else
                echo -e "${RED}✗${NC} Homebrew required for PostgreSQL installation on macOS" >&2
                echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
                return 1
            fi
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                local postgresql_version
                postgresql_version=$(get_postgresql_version)

                # Check if PostgreSQL official repository is needed (for specific versions like 18)
                # Ubuntu's default repos might only have older versions
                local needs_official_repo=false

                # Check if we have the version from default repos
                if command_exists psql; then
                    local current_pg_version
                    current_pg_version=$(psql --version 2>/dev/null | awk '{print $3}' | cut -d. -f1 || echo "0")
                    if [[ "$current_pg_version" -lt "$postgresql_version" ]]; then
                        needs_official_repo=true
                    fi
                else
                    # If not installed, try default repos first, but prepare for official repo if needed
                    needs_official_repo=true
                fi

                # Add PostgreSQL official repository using modern GPG key method
                if [[ "$needs_official_repo" = true ]]; then
                    echo -e "${CYAN}Adding PostgreSQL official repository (modern method)...${NC}"

                    # Install prerequisites
                    sudo apt-get update -qq
                    sudo apt-get install -y -qq wget ca-certificates lsb-release || true

                    # Detect Ubuntu/Debian codename
                    local distro_codename
                    distro_codename=$(lsb_release -cs 2>/dev/null || echo "")

                    if [[ -z "$distro_codename" ]]; then
                        # Fallback: try to detect from /etc/os-release
                        distro_codename=$(grep -E "^VERSION_CODENAME=" /etc/os-release 2>/dev/null | cut -d= -f2 || echo "")
                    fi

                    if [[ -n "$distro_codename" ]]; then
                        # Fix/migrate GPG key using modern method
                        fix_postgresql_repo_key

                        local pg_key_file="/etc/apt/trusted.gpg.d/postgresql-repo.gpg"

                        # Add/update repository using modern signed-by method
                        local pg_sources_file="/etc/apt/sources.list.d/pgdg.list"
                        if [[ -f "$pg_sources_file" ]]; then
                            # Update existing file to use signed-by if it doesn't already
                            if ! grep -q "signed-by" "$pg_sources_file"; then
                                echo -e "${CYAN}Updating repository file to use modern signed-by method...${NC}"
                                sudo sed -i "s|deb http://apt.postgresql.org|deb [signed-by=$pg_key_file] http://apt.postgresql.org|g" "$pg_sources_file"
                            fi
                        else
                            # Create new repository file with modern method
                            echo "deb [signed-by=$pg_key_file] http://apt.postgresql.org/pub/repos/apt $distro_codename-pgdg main" | \
                                sudo tee "$pg_sources_file" >/dev/null
                        fi

                        sudo apt-get update -qq
                    else
                        echo -e "${YELLOW}⚠${NC} Could not detect distribution codename, trying default repositories...${NC}"
                    fi
                fi

                echo -e "${CYAN}Installing PostgreSQL ${postgresql_version}...${NC}"

                # Try to install specific version from official repo, fallback to default
                if [[ "$needs_official_repo" = true ]]; then
                    sudo apt-get install -y -qq "postgresql-${postgresql_version}" "postgresql-client-${postgresql_version}" "postgresql-contrib-${postgresql_version}" || \
                    sudo apt-get install -y -qq "postgresql-${postgresql_version}" || \
                    sudo apt-get install -y -qq postgresql postgresql-contrib || {
                        echo -e "${RED}✗${NC} Failed to install PostgreSQL" >&2
                        return 1
                    }
                else
                    sudo apt-get install -y -qq postgresql postgresql-contrib || {
                        echo -e "${RED}✗${NC} Failed to install PostgreSQL" >&2
                        return 1
                    }
                fi
                # Start and enable PostgreSQL service
                sudo systemctl start postgresql 2>/dev/null || sudo service postgresql start
                sudo systemctl enable postgresql 2>/dev/null || true
            elif command_exists yum; then
                echo -e "${CYAN}Installing PostgreSQL via yum...${NC}"
                sudo yum install -y postgresql-server postgresql-contrib || {
                    echo -e "${RED}✗${NC} Failed to install PostgreSQL" >&2
                    return 1
                }
                sudo postgresql-setup --initdb 2>/dev/null || true
                sudo systemctl start postgresql
                sudo systemctl enable postgresql
            elif command_exists dnf; then
                echo -e "${CYAN}Installing PostgreSQL via dnf...${NC}"
                sudo dnf install -y postgresql-server postgresql-contrib || {
                    echo -e "${RED}✗${NC} Failed to install PostgreSQL" >&2
                    return 1
                }
                sudo postgresql-setup --initdb 2>/dev/null || true
                sudo systemctl start postgresql
                sudo systemctl enable postgresql
            else
                echo -e "${RED}✗${NC} Package manager not supported" >&2
                echo -e "  Please install PostgreSQL manually: ${CYAN}https://www.postgresql.org/download/${NC}" >&2
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for auto-install" >&2
            echo -e "  Please install PostgreSQL manually: ${CYAN}https://www.postgresql.org/download/${NC}" >&2
            return 1
            ;;
    esac

    # Wait for PostgreSQL to be ready
    echo -e "${CYAN}Waiting for PostgreSQL to start...${NC}"
    local max_attempts=30
    local attempt=0
    while [[ $attempt -lt $max_attempts ]]; do
        if check_postgresql; then
            echo -e "${GREEN}✓${NC} PostgreSQL is ready"
            return 0
        fi
        sleep 1
        ((attempt++))
    done

    echo -e "${RED}✗${NC} PostgreSQL failed to start" >&2
    return 1
}

# Verify PostgreSQL connection using credentials from .env or provided parameters
verify_postgresql_connection() {
    local db_host="${1:-127.0.0.1}"
    local db_port="${2:-5432}"
    local db_name="${3:-}"
    local db_user="${4:-}"
    local db_password="${5:-}"

    # If credentials not provided, try to read from .env
    if [[ (-z "$db_name" || -z "$db_user" || -z "$db_password") && -f "$PROJECT_ROOT/.env" ]]; then
        db_host=$(get_env_var "DB_HOST" "127.0.0.1")
        db_port=$(get_env_var "DB_PORT" "5432")
        db_name=$(get_env_var "$ENV_KEY_DB_DATABASE" "")
        db_user=$(get_env_var "DB_USERNAME" "")
        db_password=$(get_env_var "DB_PASSWORD" "")
    fi

    # Check if we have all required credentials
    if [[ -z "$db_name" ]] || [[ -z "$db_user" ]] || [[ -z "$db_password" ]]; then
        echo -e "${YELLOW}⚠${NC} Cannot verify connection: missing database credentials" >&2
        return 1
    fi

    # Test connection
    if PGPASSWORD="$db_password" psql -h "$db_host" -p "$db_port" -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

# Use local admin access to create/update a PostgreSQL database and role for Laravel.
# Shows defaults in a summary block, asks one Y/n. Expands to individual prompts on "n".
# Auto-generates a password when the default (postgres) user is kept.
setup_postgresql_database() {
    local default_db_name
    default_db_name=$(get_default_database_name "$APP_ENV")

    local admin_description
    admin_description=$(describe_postgresql_admin_mode)

    local db_host="$LOCAL_DB_HOST"
    local db_port db_name db_user db_password
    db_port=$(get_env_var "DB_PORT" "$DEFAULT_DB_PORT")
    db_name=$(get_env_var "$ENV_KEY_DB_DATABASE" "$default_db_name")
    db_user=$(get_env_var "DB_USERNAME" "$DEFAULT_DB_USER")
    db_password=$(get_env_var "DB_PASSWORD" "")

    # Auto-generate password when empty (admin mode can ALTER USER to set it)
    local password_auto_generated=false
    if [[ -z "$db_password" ]]; then
        db_password=$(generate_random_token 24)
        password_auto_generated=true
    fi

    echo ""
    echo -e "${CYAN}Belimbing detected local PostgreSQL admin access via ${admin_description}.${NC}"
    echo -e "${CYAN}It will create or update the database and role for Laravel automatically.${NC}"
    echo ""
    echo -e "  DB_PORT      = ${CYAN}${db_port}${NC}"
    echo -e "  DB_DATABASE   = ${CYAN}${db_name}${NC}"
    echo -e "  DB_USERNAME   = ${CYAN}${db_user}${NC}"
    if [[ "$password_auto_generated" = true ]]; then
        echo -e "  DB_PASSWORD   = ${DIM}(auto-generated)${NC}"
    else
        echo -e "  DB_PASSWORD   = ${DIM}(from .env)${NC}"
    fi
    echo ""

    if [[ -t 0 ]] && ! ask_yes_no "Use these settings?" "y"; then
        # Expand to individual prompts
        db_port=$(ask_input "DB_PORT" "$db_port")
        db_name=$(ask_input "DB_DATABASE" "$db_name")
        db_user=$(ask_input "DB_USERNAME" "$db_user")

        echo -e "${CYAN}This password is for Laravel's TCP connection to PostgreSQL, not for the OS install.${NC}"
        local existing_password
        existing_password=$(get_env_var "DB_PASSWORD" "")
        db_password=$(prompt_database_password "DB_PASSWORD" "$existing_password")
    fi

    local escaped_db_user escaped_db_name escaped_db_password
    escaped_db_user=$(escape_postgresql_identifier "$db_user")
    escaped_db_name=$(escape_postgresql_identifier "$db_name")
    escaped_db_password=$(escape_postgresql_literal "$db_password")

    echo -e "${CYAN}Setting up PostgreSQL database...${NC}"

    # CREATE USER fails if the role exists; fall back to ALTER to update the password.
    run_postgresql_admin_sql "CREATE USER \"$escaped_db_user\" WITH PASSWORD '$escaped_db_password';" 2>/dev/null || \
    run_postgresql_admin_sql "ALTER USER \"$escaped_db_user\" WITH PASSWORD '$escaped_db_password';" 2>/dev/null || true

    # CREATE DATABASE is best-effort — may already exist from a previous run.
    run_postgresql_admin_sql "CREATE DATABASE \"$escaped_db_name\" OWNER \"$escaped_db_user\";" 2>/dev/null || true
    run_postgresql_admin_sql "GRANT ALL PRIVILEGES ON DATABASE \"$escaped_db_name\" TO \"$escaped_db_user\";" 2>/dev/null || true

    echo -e "${GREEN}✓${NC} Database '$db_name' and user '$db_user' configured"

    save_postgresql_credentials "$db_host" "$db_port" "$db_name" "$db_user" "$db_password"
    echo -e "${GREEN}✓${NC} Database credentials saved to .env"

    echo -e "${CYAN}Verifying database connection...${NC}"
    if verify_postgresql_connection "$db_host" "$db_port" "$db_name" "$db_user" "$db_password"; then
        echo -e "${GREEN}✓${NC} Database connection verified successfully"
        return 0
    fi

    echo -e "${YELLOW}⚠${NC} Could not verify database connection with saved credentials" >&2
    echo -e "  ${YELLOW}Note:${NC} Connection may require additional PostgreSQL authentication configuration" >&2
    return 0
}

# Check if Redis is installed and running
check_redis() {
    if command_exists redis-cli && redis-cli ping >/dev/null 2>&1; then
        return 0
    fi
    return 1
}

# Ensure .env and filesystem are ready for SQLite (no server install)
setup_sqlite() {
    local db_file="${1:-database/database.sqlite}"

    echo -e "${CYAN}Configuring SQLite...${NC}"

    # Ensure .env has DB_CONNECTION=sqlite and DB_DATABASE
    if [[ -f "$PROJECT_ROOT/.env" ]]; then
        update_env_file "$ENV_KEY_DB_CONNECTION" "sqlite"
        update_env_file_if_missing "$ENV_KEY_DB_DATABASE" "$db_file"
        db_file=$(get_env_var "$ENV_KEY_DB_DATABASE" "$db_file")
    else
        echo -e "${YELLOW}⚠${NC} .env not found; create it and set DB_CONNECTION=sqlite, $ENV_KEY_DB_DATABASE=$db_file"
        return 0
    fi

    # Skip filesystem setup for in-memory
    if [[ "$db_file" = ":memory:" ]]; then
        echo -e "${GREEN}✓${NC} SQLite configured (in-memory)"
        return 0
    fi

    local full_path
    case "$db_file" in
        /*) full_path="$db_file" ;;
        *)  full_path="$PROJECT_ROOT/$db_file" ;;
    esac
    local dir_path
    dir_path=$(dirname "$full_path")

    if [[ ! -d "$dir_path" ]]; then
        mkdir -p "$dir_path"
        echo -e "${GREEN}✓${NC} Created directory $dir_path"
    fi

    if [[ ! -f "$full_path" ]]; then
        touch "$full_path"
        echo -e "${GREEN}✓${NC} Created database file $db_file"
    else
        echo -e "${GREEN}✓${NC} Database file $db_file exists"
    fi

    if [[ -w "$full_path" ]] && [[ -w "$dir_path" ]]; then
        echo -e "${GREEN}✓${NC} SQLite path is writable"
    else
        echo -e "${YELLOW}⚠${NC} Ensure $dir_path and $db_file are writable by the app" >&2
    fi

    # Optional: check PHP has pdo_sqlite
    if command_exists php; then
        if php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" 2>/dev/null; then
            echo -e "${GREEN}✓${NC} PHP pdo_sqlite extension is loaded"
        else
            echo -e "${YELLOW}⚠${NC} PHP pdo_sqlite extension not loaded; install php-sqlite3 or enable pdo_sqlite in php.ini" >&2
        fi
    fi

    echo -e "${GREEN}✓${NC} SQLite setup complete"
    return 0
}

# Install Redis
install_redis() {
    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Installing Redis...${NC}"
    echo ""

    case "$os_type" in
        macos)
            if command_exists brew; then
                echo -e "${CYAN}Installing Redis via Homebrew...${NC}"
                brew install redis
                brew services start redis
            else
                echo -e "${RED}✗${NC} Homebrew required for Redis installation on macOS" >&2
                echo -e "  Install Homebrew: ${CYAN}https://brew.sh${NC}" >&2
                return 1
            fi
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                echo -e "${CYAN}Installing Redis via apt...${NC}"
                sudo apt-get update -qq
                sudo apt-get install -y -qq redis-server || {
                    echo -e "${RED}✗${NC} Failed to install Redis" >&2
                    return 1
                }
                # Start and enable Redis service
                sudo systemctl start redis-server 2>/dev/null || sudo service redis-server start
                sudo systemctl enable redis-server 2>/dev/null || true
            elif command_exists yum; then
                echo -e "${CYAN}Installing Redis via yum...${NC}"
                sudo yum install -y redis || {
                    echo -e "${RED}✗${NC} Failed to install Redis" >&2
                    return 1
                }
                sudo systemctl start redis
                sudo systemctl enable redis
            elif command_exists dnf; then
                echo -e "${CYAN}Installing Redis via dnf...${NC}"
                sudo dnf install -y redis || {
                    echo -e "${RED}✗${NC} Failed to install Redis" >&2
                    return 1
                }
                sudo systemctl start redis
                sudo systemctl enable redis
            else
                echo -e "${RED}✗${NC} Package manager not supported" >&2
                echo -e "  Please install Redis manually: ${CYAN}https://redis.io/download${NC}" >&2
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗${NC} OS not supported for auto-install" >&2
            echo -e "  Please install Redis manually: ${CYAN}https://redis.io/download${NC}" >&2
            return 1
            ;;
    esac

    # Wait for Redis to be ready
    echo -e "${CYAN}Waiting for Redis to start...${NC}"
    local max_attempts=10
    local attempt=0
    while [[ $attempt -lt $max_attempts ]]; do
        if check_redis; then
            echo -e "${GREEN}✓${NC} Redis is ready"
            return 0
        fi
        sleep 1
        ((attempt++))
    done

    echo -e "${RED}✗${NC} Redis failed to start" >&2
    return 1
}

# Diagnose why a PostgreSQL connection failed and print actionable guidance.
# Checks service, credentials, and database existence individually.
diagnose_postgresql_connection() {
    local db_host db_port db_name db_user db_password
    db_host=$(get_env_var "DB_HOST" "127.0.0.1")
    db_port=$(get_env_var "DB_PORT" "5432")
    db_name=$(get_env_var "$ENV_KEY_DB_DATABASE" "")
    db_user=$(get_env_var "DB_USERNAME" "")
    db_password=$(get_env_var "DB_PASSWORD" "")

    echo ""
    echo -e "${RED}✗${NC} Database connection failed. Diagnosing..." >&2
    echo ""

    # 1. Is PostgreSQL reachable at all?
    if ! pg_isready -h "$db_host" -p "$db_port" >/dev/null 2>&1; then
        echo -e "  ${RED}✗${NC} PostgreSQL is not reachable at ${CYAN}${db_host}:${db_port}${NC}" >&2
        echo -e "    ${YELLOW}Fix:${NC} Start the service: ${CYAN}sudo systemctl start postgresql${NC}" >&2
        return 1
    fi
    echo -e "  ${GREEN}✓${NC} PostgreSQL is reachable at ${db_host}:${db_port}"

    # 2. Can we authenticate with these credentials?
    if ! PGPASSWORD="$db_password" psql -h "$db_host" -p "$db_port" -U "$db_user" -d "postgres" -c "SELECT 1;" >/dev/null 2>&1; then
        echo -e "  ${RED}✗${NC} Authentication failed for user ${CYAN}${db_user}${NC}" >&2
        echo -e "    ${YELLOW}Fix:${NC} Check DB_USERNAME and DB_PASSWORD in ${CYAN}.env${NC}" >&2
        echo -e "    ${YELLOW}Or reset:${NC} ${CYAN}sudo -u postgres psql -c \"ALTER USER ${db_user} WITH PASSWORD 'new_password';\"${NC}" >&2
        return 1
    fi
    echo -e "  ${GREEN}✓${NC} Authentication successful for user ${db_user}"

    # 3. Does the database exist?
    if ! PGPASSWORD="$db_password" psql -h "$db_host" -p "$db_port" -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
        echo -e "  ${RED}✗${NC} Database ${CYAN}${db_name}${NC} does not exist or is not accessible" >&2
        echo -e "    ${YELLOW}Fix:${NC} ${CYAN}sudo -u postgres psql -c \"CREATE DATABASE ${db_name} OWNER ${db_user};\"${NC}" >&2
        return 1
    fi
    echo -e "  ${GREEN}✓${NC} Database ${db_name} is accessible"

    # If we got here, individual checks passed but verify_postgresql_connection failed — unexpected
    echo -e "  ${YELLOW}⚠${NC} Individual checks passed but combined verification failed" >&2
    return 1
}

# PostgreSQL is running: prompt for credentials, create database if needed.
ensure_postgresql_database() {
    echo -e "${GREEN}✓${NC} PostgreSQL is installed and running"
    echo ""
    configure_postgresql_database
}

# PostgreSQL is installed but not running: start service then create/verify database.
start_postgresql_service_then_setup() {
    echo -e "${YELLOW}⚠${NC} PostgreSQL installed but not running"
    echo -e "${CYAN}Starting PostgreSQL service...${NC}"
    local os_type
    os_type=$(detect_os)
    local postgresql_brew_version
    postgresql_brew_version=$(get_postgresql_brew_version)

    case "$os_type" in
        linux|wsl2)
            sudo systemctl start postgresql 2>/dev/null || sudo service postgresql start
            sleep 2
            ;;
        macos)
            brew services start "$postgresql_brew_version" 2>/dev/null || brew services start postgresql
            sleep 2
            ;;
        *) ;;
    esac

    if check_postgresql; then
        configure_postgresql_database
    else
        echo -e "${RED}✗${NC} Failed to start PostgreSQL" >&2
        exit 1
    fi
    return 0
}

# PostgreSQL not installed: prompt (interactive) or install (non-interactive), then setup.
install_postgresql_if_needed() {
    echo -e "${YELLOW}ℹ${NC} PostgreSQL not found"

    if [[ -t 0 ]]; then
        if ask_yes_no "Install PostgreSQL?" "y"; then
            if install_postgresql; then
                configure_postgresql_database
            else
                echo -e "${RED}✗${NC} PostgreSQL installation failed"
                echo ""
                echo -e "${YELLOW}Please install PostgreSQL manually:${NC}"
                echo -e "  • macOS: ${CYAN}brew install postgresql${NC}"
                echo -e "  • Linux: ${CYAN}sudo apt-get install postgresql${NC}"
                echo -e "  • Manual: ${CYAN}https://www.postgresql.org/download/${NC}"
                exit 1
            fi
        else
            echo -e "${YELLOW}Skipping PostgreSQL installation${NC}"
            exit 1
        fi
    else
        if install_postgresql; then
            configure_postgresql_database
        else
            exit 1
        fi
    fi
    return 0
}

# Redis is installed but not running: start service then verify.
start_redis_service_then_check() {
    echo -e "${YELLOW}⚠${NC} Redis installed but not running"
    echo -e "${CYAN}Starting Redis service...${NC}"
    local os_type
    os_type=$(detect_os)
    case "$os_type" in
        linux|wsl2)
            sudo systemctl start redis-server 2>/dev/null || sudo service redis-server start
            sleep 1
            ;;
        macos)
            brew services start redis
            sleep 1
            ;;
        *) ;;
    esac

    if check_redis; then
        echo -e "${GREEN}✓${NC} Redis is now running"
    else
        echo -e "${RED}✗${NC} Failed to start Redis" >&2
        exit 1
    fi
    return 0
}

# Redis not installed: prompt (interactive) or install (non-interactive).
install_redis_if_needed() {
    echo -e "${YELLOW}ℹ${NC} Redis not found"

    if [[ -t 0 ]]; then
        if ask_yes_no "Install Redis?" "y"; then
            if ! install_redis; then
                echo -e "${RED}✗${NC} Redis installation failed"
                echo ""
                echo -e "${YELLOW}Please install Redis manually:${NC}"
                echo -e "  • macOS: ${CYAN}brew install redis${NC}"
                echo -e "  • Linux: ${CYAN}sudo apt-get install redis-server${NC}"
                echo -e "  • Manual: ${CYAN}https://redis.io/download${NC}"
                exit 1
            fi
        else
            echo -e "${YELLOW}Skipping Redis installation${NC}"
            exit 1
        fi
    else
        if ! install_redis; then
            exit 1
        fi
    fi
    return 0
}

# Main setup function
main() {
    print_section_banner "Database & Redis Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    local db_connection
    db_connection=$(get_db_connection)

    if [[ "$db_connection" = "sqlite" ]]; then
        # SQLite: no server install, just .env and file path
        setup_sqlite "database/database.sqlite"
    else
        # PostgreSQL Setup
        if check_postgresql; then
            ensure_postgresql_database
        elif command_exists psql; then
            start_postgresql_service_then_setup
        else
            install_postgresql_if_needed
        fi
    fi

    echo ""

    # Redis Setup
    if check_redis; then
        echo -e "${GREEN}✓${NC} Redis is installed and running"
    elif command_exists redis-cli; then
        start_redis_service_then_check
    else
        install_redis_if_needed
    fi

    echo ""

    # Save state
    save_to_setup_state "REDIS_INSTALLED" "true"
    if [[ "$db_connection" = "pgsql" ]]; then
        save_to_setup_state "POSTGRESQL_INSTALLED" "true"
    fi

    echo ""
    echo -e "${GREEN}✓ Database & Redis setup complete!${NC}"
    echo ""
    if [[ -f "$PROJECT_ROOT/.env" ]]; then
        echo -e "${CYAN}Database configuration saved to .env${NC}"
    fi
    echo ""
    return 0
}

# Run main function
main "$@"
