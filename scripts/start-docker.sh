#!/usr/bin/env bash
# scripts/start-docker.sh
# Title: Start Belimbing with Docker
# Purpose: Start Belimbing services using Docker and Docker Compose
# Usage: ./scripts/start-docker.sh [local|staging|production|testing]
#
# This script:
# - Checks for Docker and Docker Compose installation
# - Installs Docker if needed (Linux only)
# - Sets up Docker Compose configuration
# - Launches all services
# - Waits for services to be healthy
# - Runs migrations
# - Creates admin user if needed
# - Displays access URL

set -euo pipefail

# Get script directory and project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Source shared utilities
# shellcheck source=shared/colors.sh
source "$SCRIPT_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=shared/runtime.sh
source "$SCRIPT_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=shared/config.sh
source "$SCRIPT_DIR/shared/config.sh" 2>/dev/null || true
# shellcheck source=shared/validation.sh
source "$SCRIPT_DIR/shared/validation.sh" 2>/dev/null || true
# shellcheck source=shared/interactive.sh
source "$SCRIPT_DIR/shared/interactive.sh" 2>/dev/null || true
# shellcheck source=shared/caddy.sh
source "$SCRIPT_DIR/shared/caddy.sh" 2>/dev/null || true

# Environment (default to local if not provided)
APP_ENV="${1:-local}"
readonly PRODUCTION_ENV="production"

# Check if Docker Desktop is providing Docker (WSL2)
check_docker_desktop() {
    if ! is_wsl2; then
        return 1
    fi

    # Check if Docker Desktop socket exists (Docker Desktop uses this path)
    if [[ -S "/var/run/docker.sock" ]] || [[ -S "$HOME/.docker/run/docker.sock" ]]; then
        # Try to connect to Docker daemon
        if docker info >/dev/null 2>&1; then
            return 0
        fi
    fi

    return 1
}

# Check if Docker is installed and daemon is accessible
check_docker() {
    if ! command_exists docker; then
        return 1
    fi

    # Check if docker-compose or docker compose is available
    if ! command_exists docker-compose && ! docker compose version >/dev/null 2>&1; then
        return 1
    fi

    # Verify Docker daemon is accessible
    if ! docker info >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

# Install Docker (Linux only, not for Docker Desktop)
install_docker() {
    local os_type
    os_type=$(detect_os)

    if [[ "$os_type" != "linux" ]] && [[ "$os_type" != "wsl2" ]]; then
        echo -e "${RED}✗${NC} Docker auto-install only supported on Linux" >&2
        echo -e "  Please install Docker manually:" >&2
        echo -e "  ${CYAN}https://docs.docker.com/get-docker/${NC}" >&2
        return 1
    fi

    # On WSL2, if docker command exists, assume Docker Desktop (don't install Docker Engine)
    if is_wsl2 && command_exists docker; then
        echo -e "${YELLOW}⚠${NC} Docker command found on WSL2" >&2
        echo -e "  This likely means Docker Desktop is installed but not running." >&2
        echo -e "  Please start Docker Desktop from Windows, then run this script again." >&2
        echo -e "  ${CYAN}https://docs.docker.com/desktop/wsl/${NC}" >&2
        return 1
    fi

    echo -e "${CYAN}Installing Docker Engine...${NC}"
    echo ""

    if command_exists apt-get; then
        # Install Docker via official script
        curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
        sudo sh /tmp/get-docker.sh
        rm /tmp/get-docker.sh

        # Add current user to docker group
        sudo usermod -aG docker "$USER"
        echo -e "${YELLOW}Note:${NC} You may need to log out and back in for Docker group changes to take effect"
    elif command_exists yum; then
        # Install Docker via yum
        sudo yum install -y docker
        sudo systemctl start docker
        sudo systemctl enable docker
        sudo usermod -aG docker "$USER"
    elif command_exists dnf; then
        # Install Docker via dnf
        sudo dnf install -y docker
        sudo systemctl start docker
        sudo systemctl enable docker
        sudo usermod -aG docker "$USER"
    else
        echo -e "${RED}✗${NC} Package manager not supported" >&2
        return 1
    fi

    # Verify installation (check daemon is accessible)
    if check_docker; then
        echo -e "${GREEN}✓${NC} Docker Engine installed successfully"
        return 0
    fi

    echo -e "${RED}✗${NC} Docker installation verification failed" >&2
    echo -e "  Docker daemon may not be running. Try:" >&2
    if [[ "$os_type" = "wsl2" ]]; then
        echo -e "  ${CYAN}sudo service docker start${NC}" >&2
    else
        echo -e "  ${CYAN}sudo systemctl start docker${NC}" >&2
    fi
    return 1
}

# Check if port is reserved (system services, Windows/WSL reserved)
is_reserved_port() {
    local port=$1
    # Reserved ports: 0-1023 are privileged, plus Windows-specific
    # 445 = SMB, 135 = RPC, 139 = NetBIOS, 137-138 = NetBIOS
    case $port in
        445|135|139|137|138|3389) return 0 ;;  # Reserved
        *) return 1 ;;  # Not reserved
    esac
}

# Find next available port starting from given port
# Uses is_port_available from shared/validation.sh
next_free_port() {
    local port=$1
    local max_attempts=100
    local attempt=0

    while [[ $attempt -lt $max_attempts ]]; do
        # Skip reserved ports
        if ! is_reserved_port "$port" && is_port_available "$port"; then
            echo "$port"
            return 0
        fi
        port=$((port + 1))
        attempt=$((attempt + 1))
    done

    # Return original if no free port found
    echo "$1"
}

# Setup Docker Compose
setup_docker_compose() {
    local compose_file="$PROJECT_ROOT/docker/docker-compose.yml"
    local docker_env_file="$PROJECT_ROOT/docker/.env"

    if [[ ! -f "$compose_file" ]]; then
        echo -e "${RED}✗${NC} docker-compose.yml not found at $compose_file" >&2
        return 1
    fi
    echo -e "${GREEN}✓${NC} Found docker-compose.yml"

    # Create .env file for Docker if it doesn't exist
    if [[ ! -f "$docker_env_file" ]]; then
        echo -e "${CYAN}Creating .env file for Docker...${NC}"
        local docker_env_example="$PROJECT_ROOT/docker/.env.example"
        if [[ -f "$docker_env_example" ]]; then
            cp "$docker_env_example" "$docker_env_file"
            echo -e "${GREEN}✓${NC} Docker .env file created at $docker_env_file"
        else
            echo -e "${RED}✗${NC} docker/.env.example not found" >&2
            return 1
        fi
    else
        echo -e "${GREEN}✓${NC} Found Docker .env at $docker_env_file"
    fi

    # Find available ports (auto-detect if defaults are in use)
    DOCKER_DB_PORT=$(next_free_port 5432)
    DOCKER_REDIS_PORT=$(next_free_port 6379)
    DOCKER_APP_PORT=$(next_free_port 8000)
    DOCKER_VITE_PORT=$(next_free_port 5173)
    DOCKER_HTTP_PORT=$(next_free_port 80)
    DOCKER_HTTPS_PORT=$(next_free_port 443)

    # Export for use in run_compose
    export DOCKER_DB_PORT DOCKER_REDIS_PORT DOCKER_APP_PORT DOCKER_VITE_PORT DOCKER_HTTP_PORT DOCKER_HTTPS_PORT

    # Show port mappings if any changed from defaults
    local ports_changed=false
    if [[ "$DOCKER_DB_PORT" != "5432" ]] || [[ "$DOCKER_REDIS_PORT" != "6379" ]] || \
       [[ "$DOCKER_APP_PORT" != "8000" ]] || [[ "$DOCKER_VITE_PORT" != "5173" ]] || \
       [[ "$DOCKER_HTTP_PORT" != "80" ]] || [[ "$DOCKER_HTTPS_PORT" != "443" ]]; then
        ports_changed=true
        echo -e "${YELLOW}⚠${NC} Some default ports are in use, using alternatives:"
        [[ "$DOCKER_DB_PORT" != "5432" ]] && echo -e "  PostgreSQL: ${CYAN}$DOCKER_DB_PORT${NC} (default 5432 in use)"
        [[ "$DOCKER_REDIS_PORT" != "6379" ]] && echo -e "  Redis: ${CYAN}$DOCKER_REDIS_PORT${NC} (default 6379 in use)"
        [[ "$DOCKER_APP_PORT" != "8000" ]] && echo -e "  App: ${CYAN}$DOCKER_APP_PORT${NC} (default 8000 in use)"
        [[ "$DOCKER_VITE_PORT" != "5173" ]] && echo -e "  Vite: ${CYAN}$DOCKER_VITE_PORT${NC} (default 5173 in use)"
        [[ "$DOCKER_HTTP_PORT" != "80" ]] && echo -e "  HTTP: ${CYAN}$DOCKER_HTTP_PORT${NC} (default 80 in use)"
        [[ "$DOCKER_HTTPS_PORT" != "443" ]] && echo -e "  HTTPS: ${CYAN}$DOCKER_HTTPS_PORT${NC} (default 443 in use)"

        # Persist non-default ports to Docker .env for future restarts
        local docker_env_file="$PROJECT_ROOT/docker/.env"
        update_env_port() {
            local key=$1
            local value=$2
            local default=$3
            if [[ "$value" != "$default" ]]; then
                if grep -q "^${key}=" "$docker_env_file" 2>/dev/null; then
                    # Update existing entry
                    if [[ "$OSTYPE" == "darwin"* ]]; then
                        sed -i '' "s|^${key}=.*|${key}=${value}|" "$docker_env_file"
                    else
                        sed -i "s|^${key}=.*|${key}=${value}|" "$docker_env_file"
                    fi
                else
                    # Append new entry
                    echo "${key}=${value}" >> "$docker_env_file"
                fi
            fi
        }

        # Note: DB_PORT and REDIS_PORT are NOT persisted because they are internal
        # container ports (5432/6379). Only external-facing ports are persisted.
        update_env_port "APP_PORT" "$DOCKER_APP_PORT" "8000"
        update_env_port "VITE_PORT" "$DOCKER_VITE_PORT" "5173"
        update_env_port "HTTP_PORT" "$DOCKER_HTTP_PORT" "80"
        update_env_port "HTTPS_PORT" "$DOCKER_HTTPS_PORT" "443"

        # Update APP_URL to include the port (required for URL generation in Docker)
        local frontend_domain
        frontend_domain=$(get_frontend_domain)
        local new_app_url="https://${frontend_domain}:${DOCKER_HTTPS_PORT}"
        if grep -q "^APP_URL=" "$docker_env_file" 2>/dev/null; then
            if [[ "$OSTYPE" == "darwin"* ]]; then
                sed -i '' "s|^APP_URL=.*|APP_URL=${new_app_url}|" "$docker_env_file"
            else
                sed -i "s|^APP_URL=.*|APP_URL=${new_app_url}|" "$docker_env_file"
            fi
        else
            echo "APP_URL=${new_app_url}" >> "$docker_env_file"
        fi
    else
        echo -e "${GREEN}✓${NC} Using default ports"
    fi

    return 0
}

# Get Docker Compose profile based on environment
get_compose_profile() {
    if [[ "$APP_ENV" = "$PRODUCTION_ENV" ]]; then
        echo "prod"
    else
        echo "dev"
    fi
    return 0
}

# Run docker compose with correct profile and ports
# Usage: run_compose [args...]
run_compose() {
    local project_name
    project_name=$(get_compose_project_name)
    local profile
    profile=$(get_compose_profile)
    local compose_file="$PROJECT_ROOT/docker/docker-compose.yml"
    local env_file="$PROJECT_ROOT/docker/.env"

    # Build base command args
    local cmd_args=(-f "$compose_file" --env-file "$env_file" --profile "$profile" -p "$project_name")

    # Add --remove-orphans for 'up' command to clean up old containers
    if [[ "${1:-}" = "up" ]]; then
        cmd_args+=("$1" --remove-orphans)
        shift
    fi

    # Build APP_URL with port if HTTPS port is not 443
    local frontend_domain
    frontend_domain=$(get_frontend_domain)
    local app_url="https://${frontend_domain}"
    local https_port="${DOCKER_HTTPS_PORT:-443}"
    if [[ "$https_port" != "443" ]]; then
        app_url="https://${frontend_domain}:${https_port}"
    fi

    # Pass auto-detected ports (override .env if ports were in use)
    DB_PORT="${DOCKER_DB_PORT:-5432}" \
    REDIS_PORT="${DOCKER_REDIS_PORT:-6379}" \
    APP_PORT="${DOCKER_APP_PORT:-8000}" \
    VITE_PORT="${DOCKER_VITE_PORT:-5173}" \
    HTTP_PORT="${DOCKER_HTTP_PORT:-80}" \
    HTTPS_PORT="${https_port}" \
    APP_URL="${app_url}" \
    docker compose "${cmd_args[@]}" "$@"
}

# Wait for services to be healthy
wait_for_services() {
    local max_attempts=60
    local attempt=0
    local project_name
    project_name=$(get_compose_project_name)

    # Service name depends on profile (app-dev or app-prod)
    local app_service
    if [[ "$APP_ENV" = "$PRODUCTION_ENV" ]]; then
        app_service="app-prod"
    else
        app_service="app-dev"
    fi

    echo -e "${CYAN}Waiting for services to be healthy...${NC}"

    while [[ $attempt -lt $max_attempts ]]; do
        # Check if app container is running (container name is blb-app)
        if docker ps --format "{{.Names}}" | grep -q "^${project_name}-app$"; then
            # Try to check if app is responding
            if docker exec "${project_name}-app" php artisan --version >/dev/null 2>&1; then
                echo -e "${GREEN}✓${NC} Services are healthy"
                return 0
            fi
        fi

        attempt=$((attempt + 1))
        if [[ $((attempt % 10)) -eq 0 ]]; then
            echo -e "${YELLOW}  Still waiting... (${attempt}/${max_attempts})${NC}"
        fi
        sleep 1
    done

    echo -e "${RED}✗${NC} Services did not become healthy within timeout" >&2
    return 1
}

# Run migrations
run_migrations() {
    local project_name
    project_name=$(get_compose_project_name)

    echo -e "${CYAN}Running database migrations...${NC}"
    if docker exec "${project_name}-app" php artisan migrate --force >/dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} Migrations completed"
        return 0
    else
        echo -e "${YELLOW}⚠${NC} Migrations may have failed or already run" >&2
        return 1
    fi
}

# Create admin user if users table is empty
# The artisan command handles the check internally - it only creates if no users exist
create_admin_if_needed() {
    local project_name
    project_name=$(get_compose_project_name)
    local container_name="${project_name}-app"

    echo -e "${CYAN}Setting up admin user...${NC}"

    if [[ -t 0 ]]; then
        # Interactive mode: prompt for email, then run create-user (command will prompt for password)
        echo -e "${CYAN}Create admin user (email required; password will be prompted by the command).${NC}"
        read -r -p "Admin email: " admin_email
        if [[ -z "$admin_email" ]]; then
            echo -e "${YELLOW}⚠${NC} No email provided, skipping"
            return 1
        fi
        docker exec -it "$container_name" php artisan blb:user:create "$admin_email" --role=core_admin
        return $?
    else
        # Non-interactive mode: cannot create without email/password
        echo -e "${YELLOW}⚠${NC} No admin user (non-interactive mode)" >&2
        echo -e "  To create an admin account, run interactively:" >&2
        echo -e "  ${CYAN}docker exec -it $container_name php artisan blb:user:create <email> --role=core_admin${NC}" >&2
        return 1
    fi
}

# Get frontend domain from Docker .env or use default
get_frontend_domain() {
    local frontend_domain=""
    local docker_env_file="$PROJECT_ROOT/docker/.env"

    # Read FRONTEND_DOMAIN from Docker .env
    if [[ -f "$docker_env_file" ]]; then
        frontend_domain=$(grep -E "^FRONTEND_DOMAIN=" "$docker_env_file" | cut -d '=' -f2 | tr -d '[:space:]"'"'" || echo "")
    fi

    # Use defaults if not set
    if [[ -z "$frontend_domain" ]]; then
        if command -v get_default_domains >/dev/null 2>&1; then
            frontend_domain=$(get_default_domains "$APP_ENV" | cut -d'|' -f1)
        else
            frontend_domain="${APP_ENV}.blb.lara"
        fi
    fi

    echo "$frontend_domain"
    return 0
}

# Get compose file arguments based on environment
# Returns the -f arguments for docker compose
get_compose_args() {
    local base_file="$PROJECT_ROOT/docker/docker-compose.yml"
    local prod_file="$PROJECT_ROOT/docker/docker-compose.prod.yml"

    if [[ "$APP_ENV" = "$PRODUCTION_ENV" ]]; then
        # Production: base + prod override (no auto-load of override.yml)
        echo "-f $base_file -f $prod_file"
    else
        # Development: just base file, auto-loads docker-compose.override.yml
        echo "-f $base_file"
    fi
    return 0
}

# Get Docker Compose project name
get_compose_project_name() {
    # Use consistent project name matching codebase convention
    echo "blb"
    return 0
}

# Clean up problematic volumes (real issue we encountered)
cleanup_volumes() {
    local project_name
    project_name=$(get_compose_project_name)

    echo -e "${CYAN}Checking for volume issues...${NC}"

    # Stop containers first
    run_compose down 2>/dev/null || true

    # Check for volumes with naming conflicts (from different project names)
    # These are from previous installations of this same project using different project names
    local conflicting_volumes
    conflicting_volumes=$(docker volume ls --format "{{.Name}}" | grep -E "(^docker_(postgres|redis|caddy)_data|^belimbing_(postgres|redis|caddy)_data|^docker_(postgres|redis|caddy)_config|^belimbing_(postgres|redis|caddy)_config)" || true)

    if [[ -n "$conflicting_volumes" ]]; then
        echo -e "${YELLOW}⚠${NC} Found volumes from previous installations of this project"
        echo -e "${CYAN}These volumes are from old project names (docker/belimbing) and are not used by the current setup:${NC}"
        echo "$conflicting_volumes" | while read -r volume; do
            if [[ -n "$volume" ]]; then
                local created
                created=$(docker volume inspect "$volume" --format '{{.CreatedAt}}' 2>/dev/null | cut -d'T' -f1 || echo "unknown")
                echo -e "  • ${CYAN}${volume}${NC} (created: ${created})"
            fi
        done
        echo ""
        if [[ -t 0 ]]; then
            if ask_yes_no "Remove these old volumes? (This will delete data from previous installations)" "n"; then
                echo "$conflicting_volumes" | while read -r volume; do
                    if [[ -n "$volume" ]]; then
                        echo -e "  Removing: ${CYAN}${volume}${NC}"
                        docker volume rm "$volume" 2>/dev/null || true
                    fi
                done
                echo -e "${GREEN}✓${NC} Old volumes cleaned up"
            else
                echo -e "${CYAN}Keeping old volumes (they won't interfere with current setup)${NC}"
            fi
        fi
    fi

    # Check for permission issues with current postgres volume
    local postgres_volume="${project_name}_postgres_data"
    if docker volume inspect "$postgres_volume" >/dev/null 2>&1; then
        # Check if container failed due to permission issues (if container exists)
        local postgres_logs=""
        if docker ps -a --format "{{.Names}}" | grep -q "^${project_name}-postgres$"; then
            postgres_logs=$(docker logs "${project_name}-postgres" 2>&1 | tail -10 || true)
        fi

        # Also check if volume mountpoint has permission issues
        local mountpoint
        mountpoint=$(docker volume inspect "$postgres_volume" --format '{{.Mountpoint}}' 2>/dev/null || echo "")
        local has_permission_issue=false

        if echo "$postgres_logs" | grep -q "Permission denied\|can't create directory"; then
            has_permission_issue=true
        elif [[ -n "$mountpoint" ]] && [[ ! -w "$mountpoint" ]] 2>/dev/null; then
            # Check if mountpoint directory is writable (if accessible)
            has_permission_issue=true
        fi

        if [[ "$has_permission_issue" = true ]]; then
            echo -e "${YELLOW}⚠${NC} Current PostgreSQL volume has permission issues"
            echo -e "${CYAN}Volume: ${postgres_volume}${NC}"
            if [[ -t 0 ]]; then
                if ask_yes_no "Remove and recreate postgres volume? (This will delete current database data)" "n"; then
                    echo -e "  Removing: ${CYAN}${postgres_volume}${NC}"
                    docker volume rm "$postgres_volume" 2>/dev/null || true
                    echo -e "${GREEN}✓${NC} Volume will be recreated on next start"
                fi
            else
                # Non-interactive: auto-fix permission issues
                echo -e "  Auto-removing problematic volume: ${CYAN}${postgres_volume}${NC}"
                docker volume rm "$postgres_volume" 2>/dev/null || true
            fi
        fi
    fi
    return 0
}

# Clean up orphaned containers and networks
cleanup_orphans() {
    local project_name
    project_name=$(get_compose_project_name)

    echo -e "${CYAN}Cleaning up orphaned containers...${NC}"

    # Remove containers in bad states (created but not started, exited, etc.)
    local orphaned_containers
    orphaned_containers=$(docker ps -a --filter "name=${project_name}-" --format "{{.Names}}\t{{.Status}}" | \
        grep -E "(Created|Exited|Restarting)" | cut -f1 || true)

    if [[ -n "$orphaned_containers" ]]; then
        echo "$orphaned_containers" | while read -r container; do
            if [[ -n "$container" ]]; then
                echo -e "  Removing orphaned container: ${CYAN}${container}${NC}"
                docker rm -f "$container" 2>/dev/null || true
            fi
        done
    fi

    # Remove network if it exists but wasn't created by compose (fixes label mismatch)
    local network_name="${project_name}_belimbing-network"
    if docker network inspect "$network_name" >/dev/null 2>&1; then
        # Check if it has compose labels - if not, remove it so compose can recreate
        local compose_label
        compose_label=$(docker network inspect "$network_name" --format '{{index .Labels "com.docker.compose.network"}}' 2>/dev/null || echo "")
        if [[ -z "$compose_label" ]] || [[ "$compose_label" != "belimbing-network" ]]; then
            echo -e "  Removing network with incorrect labels: ${CYAN}${network_name}${NC}"
            docker network rm "$network_name" 2>/dev/null || true
        fi
    fi
    return 0
}

# Start Docker services
start_services() {
    local project_name
    project_name=$(get_compose_project_name)

    if [[ "$APP_ENV" = "$PRODUCTION_ENV" ]]; then
        echo -e "${CYAN}Starting production services...${NC}"
    else
        echo -e "${CYAN}Starting development services...${NC}"
    fi

    # Try to start services - docker compose handles existing containers smartly
    local error_output
    if ! error_output=$(run_compose up -d 2>&1); then
        # Check if error is related to volume mounting, permission issues, or network problems
        if echo "$error_output" | grep -qE "error mounting|no such file or directory|failed to create task|Permission denied|unhealthy|dependency failed|network.*not found|failed to set up container networking"; then
            echo -e "${YELLOW}⚠${NC} Service error detected, attempting fix..." >&2

            # Clean up orphans first (containers in bad states)
            cleanup_orphans

            # Then cleanup volumes if needed
            cleanup_volumes

            echo -e "${CYAN}Retrying...${NC}"
            if ! run_compose up -d; then
                echo -e "${RED}✗${NC} Failed to start services" >&2
                echo -e "${YELLOW}Debug info:${NC}" >&2
                run_compose ps >&2
                echo "" >&2
                echo -e "${CYAN}Check logs:${NC} docker logs ${project_name}-postgres" >&2
                return 1
            fi
        else
            echo -e "${RED}✗${NC} Failed to start services" >&2
            echo "$error_output" >&2
            return 1
        fi
    fi

    echo ""
    echo -e "${GREEN}✓${NC} Services started"
    echo ""
    echo -e "${CYAN}Service status:${NC}"
    run_compose ps
    return 0
}

# Main function
main() {
    print_section_banner "Start Belimbing with Docker ($APP_ENV)"

    # Check Docker
    print_subsection_header "Docker Check"
    if check_docker; then
        local docker_version compose_version docker_type
        docker_version=$(docker --version 2>/dev/null | head -1 || echo "unknown")
        if docker compose version >/dev/null 2>&1; then
            compose_version=$(docker compose version 2>/dev/null | head -1 || echo "unknown")
        else
            compose_version=$(docker-compose --version 2>/dev/null | head -1 || echo "unknown")
        fi

        # Detect Docker type
        if is_wsl2 && check_docker_desktop; then
            docker_type="Docker Desktop"
        else
            docker_type="Docker Engine"
        fi

        echo -e "${GREEN}✓${NC} Docker: $docker_version ($docker_type)"
        echo -e "${GREEN}✓${NC} Docker Compose: $compose_version"
    else
        # Check if Docker command exists but daemon is not accessible
        if command_exists docker; then
            echo -e "${YELLOW}⚠${NC} Docker command found but daemon is not accessible"
            if is_wsl2; then
                echo -e "${YELLOW}  Docker Desktop may not be running.${NC}"
                echo -e "${YELLOW}  Please start Docker Desktop from Windows, then run this script again.${NC}"
                exit 1
            else
                echo -e "${YELLOW}  Docker daemon may not be running. Try:${NC}"
                echo -e "${CYAN}    sudo systemctl start docker${NC}"
                exit 1
            fi
        fi

        echo -e "${YELLOW}⚠${NC} Docker not found"

        if [[ -t 0 ]]; then
            if ask_yes_no "Install Docker?" "y"; then
                if ! install_docker; then
                    echo -e "${RED}✗${NC} Docker installation failed"
                    echo ""
                    echo -e "${YELLOW}Please install Docker manually:${NC}"
                    echo -e "  ${CYAN}https://docs.docker.com/get-docker/${NC}"
                    exit 1
                fi
            else
                echo -e "${YELLOW}Skipping Docker installation${NC}"
                exit 1
            fi
        else
            # Non-interactive mode
            if ! install_docker; then
                exit 1
            fi
        fi
    fi

    echo ""

    # Setup Docker Compose
    print_subsection_header "Docker Compose Setup"
    if ! setup_docker_compose; then
        exit 1
    fi
    echo ""

    # Start services
    print_subsection_header "Starting Services"
    start_services
    echo ""

    # Wait for services to be healthy
    print_subsection_header "Waiting for Services"
    if ! wait_for_services; then
        echo -e "${YELLOW}⚠${NC} Continuing despite health check timeout..." >&2
    fi
    echo ""

    # Run migrations
    print_subsection_header "Database Setup"
    run_migrations
    echo ""

    # Create admin if needed
    print_subsection_header "Admin User"
    create_admin_if_needed
    echo ""

    # Setup SSL certificate trust (for self-signed certificates)
    print_subsection_header "SSL Certificate"
    local project_name
    project_name=$(get_compose_project_name)
    local container_name="${project_name}-caddy"

    # Use shared function from caddy.sh (sourced at top of script)
    if type setup_ssl_trust >/dev/null 2>&1; then
        setup_ssl_trust "$PROJECT_ROOT" "$container_name" || true  # Don't fail if SSL setup has issues
    else
        echo -e "${YELLOW}⚠${NC} SSL trust setup function not available" >&2
    fi
    echo ""

    # Get frontend domain and display URL
    print_subsection_header "Access Information"
    local frontend_domain
    frontend_domain=$(get_frontend_domain)
    local access_url="https://${frontend_domain}"

    # Include port in URL if HTTPS isn't on 443
    if [[ "${DOCKER_HTTPS_PORT:-443}" != "443" ]]; then
        access_url="https://${frontend_domain}:${DOCKER_HTTPS_PORT}"
    fi

    echo -e "${GREEN}✓${NC} Belimbing is ready!"
    echo ""
    echo -e "${CYAN}Access your application:${NC}"
    echo -e "  ${GREEN}${access_url}${NC}"
    echo ""

    # Show port info if non-default ports were used
    if [[ "${DOCKER_DB_PORT:-5432}" != "5432" ]] || \
       [[ "${DOCKER_REDIS_PORT:-6379}" != "6379" ]] || \
       [[ "${DOCKER_APP_PORT:-8000}" != "8000" ]] || \
       [[ "${DOCKER_HTTP_PORT:-80}" != "80" ]] || \
       [[ "${DOCKER_HTTPS_PORT:-443}" != "443" ]]; then
        echo -e "${CYAN}Service ports:${NC}"
        [[ "${DOCKER_HTTP_PORT:-80}" != "80" ]] && echo -e "  • HTTP: ${CYAN}${DOCKER_HTTP_PORT:-80}${NC}"
        [[ "${DOCKER_HTTPS_PORT:-443}" != "443" ]] && echo -e "  • HTTPS: ${CYAN}${DOCKER_HTTPS_PORT:-443}${NC}"
        [[ "${DOCKER_DB_PORT:-5432}" != "5432" ]] && echo -e "  • PostgreSQL: ${CYAN}${DOCKER_DB_PORT:-5432}${NC}"
        [[ "${DOCKER_REDIS_PORT:-6379}" != "6379" ]] && echo -e "  • Redis: ${CYAN}${DOCKER_REDIS_PORT:-6379}${NC}"
        [[ "${DOCKER_APP_PORT:-8000}" != "8000" ]] && echo -e "  • App: ${CYAN}${DOCKER_APP_PORT:-8000}${NC}"
        echo ""
    fi

    # Check if domain needs /etc/hosts entry
    if [[ "$frontend_domain" == *.blb.lara ]]; then
        if ! grep -q "$frontend_domain" /etc/hosts 2>/dev/null; then
            echo -e "${YELLOW}Note:${NC} You may need to add this to /etc/hosts:" >&2
            echo -e "  ${CYAN}127.0.0.1  ${frontend_domain}${NC}" >&2
            echo ""
        fi
    fi

    local project_name profile
    project_name=$(get_compose_project_name)
    profile=$(get_compose_profile)

    echo -e "${CYAN}Management commands (run from docker/ directory):${NC}"
    echo -e "  • View logs: ${CYAN}docker compose --profile $profile -p $project_name logs -f${NC}"
    echo -e "  • Stop:      ${CYAN}docker compose --profile $profile -p $project_name down${NC}"
    echo -e "  • Restart:   ${CYAN}docker compose --profile $profile -p $project_name restart${NC}"
    echo -e "  • Status:    ${CYAN}docker compose --profile $profile -p $project_name ps${NC}"
    echo ""
    return 0
}

# Run main function
main "$@"
