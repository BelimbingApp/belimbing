#!/bin/bash

# SPDX-License-Identifier: AGPL-3.0-only
# (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Source shared utilities
# shellcheck source=shared/colors.sh
source "$SCRIPT_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=shared/config.sh
source "$SCRIPT_DIR/shared/config.sh" 2>/dev/null || true
# shellcheck source=shared/validation.sh
source "$SCRIPT_DIR/shared/validation.sh" 2>/dev/null || true
# shellcheck source=shared/runtime.sh
source "$SCRIPT_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=shared/caddy.sh
source "$SCRIPT_DIR/shared/caddy.sh" 2>/dev/null || true

if ! command -v stop_dev_services >/dev/null 2>&1; then
    echo -e "${RED}✗${NC} stop_dev_services is not available (failed to load shared/runtime.sh)" >&2
    exit 1
fi

# Global variables
LOG_FILE=""
PID_FILE=""
DEV_PID=""
APP_ENV=""
APP_PORT=""
VITE_PORT=""
FRONTEND_DOMAIN=""
BACKEND_DOMAIN=""
HTTPS_PORT=""
APP_BIND_HOST=""
BLB_INGRESS_MODE=""
USE_NON_PRIVILEGED_PORT=0
PUBLIC_APP_URL=""
PUBLIC_BACKEND_URL=""

BLB_INGRESS_MODE_SHARED='shared'

# Logging function
log() {
    if [[ -n "$LOG_FILE" ]]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S %Z')] $*" >> "$LOG_FILE"
    fi
    return 0
}

now_epoch_s() {
    date +%s
    return 0
}

# Check for required dependencies (verification only, no installation)
check_dependencies() {
    local missing=()
    local has_bun=false

    if ! command -v composer &> /dev/null; then
        missing+=("composer")
    fi

    # Check for bun first (replaces both node and npm)
    if command -v bun &> /dev/null; then
        has_bun=true
        echo -e "${CYAN}ℹ${NC} Using Bun (replaces Node.js and npm)"
    else
        # Fall back to node/npm if bun is not available
        if ! command -v node &> /dev/null; then
            missing+=("node")
        fi
        if ! command -v npm &> /dev/null; then
            missing+=("npm")
        fi
    fi


    if [[ ${#missing[@]} -gt 0 ]]; then
        echo -e "${RED}✗${NC} Missing required dependencies:" >&2
        for dep in "${missing[@]}"; do
            echo -e "  ${BULLET} $dep" >&2
        done
        echo "" >&2
        echo -e "${YELLOW}Please run the setup script to install dependencies:${NC}" >&2
        echo -e "  ${CYAN}./scripts/setup.sh $APP_ENV${NC}" >&2
        echo "" >&2
        echo -e "${CYAN}Or install manually:${NC}" >&2
        if [[ " ${missing[*]} " =~ " composer " ]]; then
            echo -e "  • PHP/Composer: ${CYAN}./scripts/setup-steps/15-php.sh${NC}" >&2
        fi
        if [[ " ${missing[*]} " =~ " npm " ]] || [[ " ${missing[*]} " =~ " node " ]] || [[ " ${missing[*]} " =~ " bun " ]]; then
            echo -e "  • JavaScript runtime (Bun recommended): ${CYAN}./scripts/setup-steps/30-js.sh${NC}" >&2
        fi
        log "ERROR: Missing dependencies: ${missing[*]}"
        exit 1
    fi

    if [[ "$has_bun" = true ]]; then
        echo -e "${GREEN}✓${NC} All dependencies available (using Bun)"
    else
        echo -e "${GREEN}✓${NC} All dependencies available (using Node.js/npm)"
    fi
    return 0
}

# Ensure the FrankenPHP binary has cap_net_bind_service so it can bind to port 443 (Linux only).
ensure_frankenphp_bind_capability() {
    [[ "$(uname -s)" != "Linux" ]] && return 0

    local binary
    binary=$(command -v frankenphp 2>/dev/null || true)

    # Octane prefers ./frankenphp (project root) over PATH
    if [[ -x "$PROJECT_ROOT/frankenphp" ]]; then
        binary="$PROJECT_ROOT/frankenphp"
    fi

    [[ -z "$binary" || ! -f "$binary" ]] && return 0

    local caps
    caps=$(getcap "$binary" 2>/dev/null || true)

    if [[ "$caps" == *"cap_net_bind_service"* ]]; then
        return 0
    fi

    echo -e "${YELLOW}⚠${NC} FrankenPHP needs cap_net_bind_service to listen on port 443 (HTTPS)"
    echo -e "  Running: ${CYAN}sudo setcap cap_net_bind_service=+ep $binary${NC}"
    if sudo setcap cap_net_bind_service=+ep "$binary" 2>/dev/null; then
        echo -e "${GREEN}✓${NC} FrankenPHP port binding capability set"
        log "setcap cap_net_bind_service applied to $binary"
    else
        echo -e "${RED}✗${NC} Failed to set capability. Run manually:" >&2
        echo -e "  ${CYAN}sudo setcap cap_net_bind_service=+ep $binary${NC}" >&2
        log "ERROR: Failed to setcap on $binary"
        return 1
    fi
    return 0
}

# Read and validate APP_ENV
read_app_env() {
    # Read APP_ENV from .env file, default to 'local' if not found
    APP_ENV=$(get_env_var "APP_ENV" "local")

    # Validate APP_ENV using config.sh function
    if command -v normalize_and_validate_env >/dev/null 2>&1; then
        APP_ENV=$(normalize_and_validate_env "$APP_ENV")
    else
        # Fallback validation
        if [[ ! "$APP_ENV" =~ ^(local|staging|production|testing)$ ]]; then
            echo -e "${RED}✗${NC} Invalid APP_ENV: $APP_ENV" >&2
            echo -e "  Valid options: local, staging, production, testing" >&2
            log "ERROR: Invalid APP_ENV value: $APP_ENV"
            exit 1
        fi
    fi

    # Read domains from .env or use defaults
    FRONTEND_DOMAIN=$(get_env_var "FRONTEND_DOMAIN" "")
    BACKEND_DOMAIN=$(get_env_var "BACKEND_DOMAIN" "")
    BLB_INGRESS_MODE=$(caddy_normalize_ingress_mode "$(get_env_var "BLB_INGRESS_MODE" "direct")")

    # Use defaults if not set
    if [[ -z "$FRONTEND_DOMAIN" ]]; then
        if command -v get_default_domains >/dev/null 2>&1; then
            FRONTEND_DOMAIN=$(get_default_domains "$APP_ENV" | cut -d'|' -f1)
        else
            FRONTEND_DOMAIN="${APP_ENV}.blb.lara"
        fi
    fi
    if [[ -z "$BACKEND_DOMAIN" ]]; then
        if command -v get_default_domains >/dev/null 2>&1; then
            BACKEND_DOMAIN=$(get_default_domains "$APP_ENV" | cut -d'|' -f2)
        else
            BACKEND_DOMAIN="${APP_ENV}.api.blb.lara"
        fi
    fi

    # Log environment info (important for troubleshooting)
    log "Environment: $APP_ENV, Frontend: $FRONTEND_DOMAIN, Backend: $BACKEND_DOMAIN, Ingress: $BLB_INGRESS_MODE"

    echo -e "${GREEN}Using environment: ${APP_ENV}${NC}"
    return 0
}

# Check if domains are in /etc/hosts
check_hosts_entries() {
    local missing_hosts=()
    local result=0
    local hosts_note=""
    if is_wsl2; then
        hosts_note=" (WSL /etc/hosts — separate from Windows hosts)"
    fi

    # Check Linux /etc/hosts (uses shared domain_in_hosts: any IP, POSIX pattern)
    if ! domain_in_hosts "$FRONTEND_DOMAIN"; then
        missing_hosts+=("$FRONTEND_DOMAIN")
    fi

    if ! domain_in_hosts "$BACKEND_DOMAIN"; then
        missing_hosts+=("$BACKEND_DOMAIN")
    fi

    if [[ ${#missing_hosts[@]} -gt 0 ]]; then
        echo ""
        echo -e "${YELLOW}⚠${NC} The following domains are not in /etc/hosts${hosts_note}:"
        for domain in "${missing_hosts[@]}"; do
            echo -e "  ${BULLET} $domain"
        done
        echo ""
        echo -e "${CYAN}To add them, run:${NC}"
        echo -e "  ${YELLOW}sudo sh -c 'echo \"127.0.0.1 ${missing_hosts[*]}\" >> /etc/hosts'${NC}"
        echo ""
        echo -e "${CYAN}Or re-run native setup to add them automatically:${NC}"
        echo -e "  ${YELLOW}./scripts/setup.sh $APP_ENV${NC}"
        echo ""
        log "WARNING: Missing hosts entries: ${missing_hosts[*]}"
        result=1
    else
        echo -e "${GREEN}✓${NC} Domains configured in /etc/hosts"
    fi

    # Check Windows hosts file if running in WSL2 and we're likely to use a Windows browser.
    # If a local Linux browser (chromium, firefox, etc.) is available, we skip this check
    # because Windows hosts entries are not required for that workflow.
    if is_wsl2; then
        if command -v chromium-browser >/dev/null 2>&1 || \
           command -v chromium >/dev/null 2>&1 || \
           command -v google-chrome >/dev/null 2>&1 || \
           command -v firefox >/dev/null 2>&1 || \
           command -v xdg-open >/dev/null 2>&1 || \
           command -v sensible-browser >/dev/null 2>&1; then
            log "INFO: Skipping Windows hosts check (local Linux browser available on WSL2)"
            return $result
        fi

        local win_hosts
        win_hosts=$(get_windows_hosts_path)
        local wsl_ip
        wsl_ip=$(get_wsl2_ip)
        local win_missing=()
        local win_wrong_ip=()

        if [[ -z "$wsl_ip" ]]; then
            echo -e "${YELLOW}⚠${NC} Could not determine WSL2 IP address for Windows hosts file check"
            log "WARNING: Could not determine WSL2 IP address"
            return $result
        fi

        # Check if domains exist in Windows hosts file
        if ! domain_in_windows_hosts "$FRONTEND_DOMAIN"; then
            win_missing+=("$FRONTEND_DOMAIN")
        elif grep -E "^[[:space:]]*127\.0\.0\.1[[:space:]]+.*${FRONTEND_DOMAIN//./\\.}" "$win_hosts" 2>/dev/null | grep -v "^#" > /dev/null; then
            win_wrong_ip+=("$FRONTEND_DOMAIN")
        fi

        if ! domain_in_windows_hosts "$BACKEND_DOMAIN"; then
            win_missing+=("$BACKEND_DOMAIN")
        elif grep -E "^[[:space:]]*127\.0\.0\.1[[:space:]]+.*${BACKEND_DOMAIN//./\\.}" "$win_hosts" 2>/dev/null | grep -v "^#" > /dev/null; then
            win_wrong_ip+=("$BACKEND_DOMAIN")
        fi

        if [[ ${#win_missing[@]} -gt 0 ]] || [[ ${#win_wrong_ip[@]} -gt 0 ]]; then
            echo ""
            echo -e "${YELLOW}⚠${NC} Windows hosts file may need configuration (WSL2 detected):"

            if [[ ${#win_missing[@]} -gt 0 ]]; then
                echo -e "  ${YELLOW}Missing domains:${NC} ${win_missing[*]}"
            fi

            if [[ ${#win_wrong_ip[@]} -gt 0 ]]; then
                echo -e "  ${YELLOW}Wrong IP address (using 127.0.0.1 instead of WSL2 IP):${NC} ${win_wrong_ip[*]}"
            fi

            echo ""
            echo -e "${CYAN}WSL2 IP address: ${YELLOW}$wsl_ip${NC}"
            echo ""
            echo -e "${CYAN}Add/update this line in Windows hosts file:${NC}"
            echo -e "  ${YELLOW}$wsl_ip $FRONTEND_DOMAIN $BACKEND_DOMAIN${NC}"
            echo ""
            echo -e "${CYAN}Windows hosts file location:${NC}"
            echo -e "  ${YELLOW}C:\\Windows\\System32\\drivers\\etc\\hosts${NC}"
            echo ""
            echo -e "${CYAN}To fix:${NC}"
            echo -e "  1. Open Notepad as Administrator (Win+R → ${YELLOW}notepad${NC} → Ctrl+Shift+Enter)"
            echo -e "  2. Open: ${YELLOW}C:\\Windows\\System32\\drivers\\etc\\hosts${NC}"
            if [[ ${#win_wrong_ip[@]} -gt 0 ]]; then
                echo -e "  3. Remove/comment lines with ${YELLOW}127.0.0.1${NC} for these domains"
            fi
            echo -e "  4. Add: ${YELLOW}$wsl_ip $FRONTEND_DOMAIN $BACKEND_DOMAIN${NC}"
            echo -e "  5. Save and close"
            echo ""
            echo -e "${CYAN}Or use PowerShell (Run as Administrator):${NC}"
            if [[ ${#win_wrong_ip[@]} -gt 0 ]]; then
                echo -e "  ${YELLOW}\$content = Get-Content \"C:\\Windows\\System32\\drivers\\etc\\hosts\"; \$content = \$content | Where-Object { \$_ -notmatch \"127\\.0\\.0\\.1.*local\\.blb\\.lara\" -and \$_ -notmatch \"127\\.0\\.0\\.1.*local\\.api\\.blb\\.lara\" }; \$content | Set-Content \"C:\\Windows\\System32\\drivers\\etc\\hosts\"${NC}"
            fi
            echo -e "  ${YELLOW}Add-Content -Path \"C:\\Windows\\System32\\drivers\\etc\\hosts\" -Value \"$wsl_ip $FRONTEND_DOMAIN $BACKEND_DOMAIN\"${NC}"
            echo ""
            log "WARNING: Windows hosts file may need configuration. WSL2 IP: $wsl_ip"
            result=1
        else
            echo -e "${GREEN}✓${NC} Windows hosts file configured correctly (WSL2 IP: $wsl_ip)"
        fi
    fi

    return $result
}

# Resolve ports: prefer .env pin, otherwise find a free port. Write actual ports to runtime file for stop-app.
get_ports() {
    local preferred

    # APP_PORT: In shared ingress mode the port must match the Caddy site
    # fragment, so a pinned value that is busy is a hard conflict.  In direct
    # mode (or when unpinned) we can auto-find a free port.
    preferred=$(get_env_var "APP_PORT" "")
    if [[ -n "$preferred" ]] && [[ "$preferred" =~ ^[0-9]+$ ]]; then
        if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
            APP_PORT="$preferred"
        else
            APP_PORT=$(resolve_port "$preferred" 8000)
        fi
    else
        APP_PORT=$(next_free_port 8000)
    fi

    # VITE_PORT: .env pin (if available) → free port from preferred or 5173
    preferred=$(get_env_var "VITE_PORT" "")
    if [[ -n "$preferred" ]] && [[ "$preferred" =~ ^[0-9]+$ ]]; then
        VITE_PORT=$(resolve_port "$preferred" 5173)
    else
        VITE_PORT=$(next_free_port 5173)
    fi

    export APP_ENV APP_PORT VITE_PORT

    # Write runtime ports so stop-app and cleanup know what to stop
    local runtime_dir="$PROJECT_ROOT/storage/app/.devops"
    mkdir -p "$runtime_dir"
    cat > "$runtime_dir/ports.env" <<EOF
APP_PORT=$APP_PORT
VITE_PORT=$VITE_PORT
EOF

    echo -e "${CYAN}ℹ${NC} Ports: Laravel ${APP_PORT}, Vite ${VITE_PORT}, HTTPS ${HTTPS_PORT}"
    return 0
}

# Check if the app port is available.  In shared mode, when the pinned port
# is busy, re-run the ingress setup step to auto-assign a free port and
# regenerate the Caddy site fragment so they stay in sync.
check_and_stop_services() {
    local port=$1

    if ! lsof -Pi :"$port" -sTCP:LISTEN -t >/dev/null 2>&1; then
        return 0
    fi

    if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
        echo -e "${YELLOW}⚠${NC} Port ${CYAN}${port}${NC} is busy — reassigning port and updating Caddy..." >&2
        log "Port $port busy; re-running ingress setup to reassign"

        # Re-use the ingress setup step to pick a free port, update .env,
        # and regenerate + install the Caddy site fragment.
        local ingress_script="$SCRIPT_DIR/setup-steps/72-caddy-ingress.sh"
        if ! bash "$ingress_script" "$APP_ENV" </dev/null; then
            echo -e "${RED}✗${NC} Ingress setup failed — resolve the port conflict manually" >&2
            exit 1
        fi

        # Reload the newly pinned port
        APP_PORT=$(get_env_var 'APP_PORT' "$port")
        export APP_PORT
        return 0
    fi

    echo -e "${RED}✗${NC} Port ${CYAN}${port}${NC} is already in use." >&2
    log "ERROR: Port $port is in use"
    exit 1
}

# Set up cleanup handler
cleanup() {
    local exit_code="${1:-0}"
    local failure_reason="${2:-}"

    echo ""
    echo -e "${YELLOW}Stopping services...${NC}"

    local stop_user
    stop_user=$(whoami 2>/dev/null || echo "${USER:-unknown}")
    log "[$stop_user] Stopping services"

    if [[ -n "${APP_ENV:-}" ]]; then
        stop_dev_services "$APP_ENV" "$APP_PORT" "$VITE_PORT"
    else
        stop_dev_services "local"
    fi

    [[ -f "$PID_FILE" ]] && rm -f "$PID_FILE"
    rm -f "$PROJECT_ROOT/storage/app/.devops/ports.env"

    log "Services stopped"

    if [[ "$exit_code" -ne 0 ]]; then
        echo ""
        echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        echo -e "${RED}✗ Failed to start app${NC}"
        echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        if [[ -n "$failure_reason" ]]; then
            echo ""
            echo -e "${YELLOW}Reason:${NC} $failure_reason"
        fi
        echo ""
        echo -e "${CYAN}Troubleshooting:${NC}"
        if [[ -n "$LOG_FILE" ]]; then
            echo -e "  ${BULLET} Log file:          ${YELLOW}$LOG_FILE${NC}"
        fi
    fi

    exit "$exit_code"
}

# Wait for services to start (with health check)
wait_for_service() {
    local url=$1
    local service_name=$2
    local max_attempts=30
    local attempt=1
    local start_ts
    start_ts=$(now_epoch_s)

    echo -e "${CYAN}Waiting for $service_name to be ready...${NC}"
    while [[ $attempt -le $max_attempts ]]; do
        if curl -s -f -k --connect-timeout 1 --max-time 2 "$url" >/dev/null 2>&1; then
            echo -e "${GREEN}✓${NC} $service_name is ready"
            log "$service_name ready in $(( $(now_epoch_s) - start_ts ))s"
            return 0
        fi
        sleep 1
        attempt=$((attempt + 1))
    done

    echo -e "${YELLOW}⚠${NC} $service_name may not be fully ready, continuing anyway..."
    log "WARNING: $service_name may not be fully ready after $max_attempts attempts (elapsed $(( $(now_epoch_s) - start_ts ))s)"
    return 1
}

# Export environment variables that FrankenPHP's embedded Caddy reads from the Caddyfile.
# The project Caddyfile uses {$VAR:default} syntax, resolved by Caddy at startup.
export_caddy_env() {
    local system_caddy_running=false

    if caddy_system_is_running; then
        system_caddy_running=true
    fi

    export APP_DOMAIN="$FRONTEND_DOMAIN"
    export BACKEND_DOMAIN="$BACKEND_DOMAIN"
    export APP_PORT="$APP_PORT"
    export VITE_PORT="$VITE_PORT"
    export VITE_HOST="127.0.0.1"
    local mkcert_cert="$PROJECT_ROOT/certs/${FRONTEND_DOMAIN}.pem"
    local mkcert_key="$PROJECT_ROOT/certs/${FRONTEND_DOMAIN}-key.pem"

    if [[ "$APP_ENV" = "local" ]] || [[ "$APP_ENV" = "testing" ]]; then
        if command_exists mkcert && [[ -f "$mkcert_cert" ]] && [[ -f "$mkcert_key" ]]; then
            export TLS_DIRECTIVE="tls $mkcert_cert $mkcert_key"
            mkcert -install > /dev/null 2>&1 || true
        else
            export TLS_DIRECTIVE="tls internal"
            echo -e "${YELLOW}⚠${NC} mkcert certs not found — falling back to internal CA (browser warnings expected)"
            echo -e "  Run ${CYAN}./scripts/setup-steps/70-domains.sh${NC} to generate mkcert certs"
        fi
    else
        local tls_mode
        tls_mode=$(get_env_var "TLS_MODE" "internal")
        if [[ "$tls_mode" = "mkcert" ]] && [[ -f "$mkcert_cert" ]] && [[ -f "$mkcert_key" ]]; then
            export TLS_DIRECTIVE="tls $mkcert_cert $mkcert_key"
            mkcert -install > /dev/null 2>&1 || true
        else
            export TLS_DIRECTIVE="tls internal"
        fi
    fi

    export CADDY_LOG_DIR="$PROJECT_ROOT/.caddy/logs"
    mkdir -p "$CADDY_LOG_DIR"

    # Use a different admin port to avoid conflict with system Caddy (default 2019)
    local caddy_admin_port
    caddy_admin_port=$(next_free_port 2020)
    export CADDY_SERVER_ADMIN_PORT="$caddy_admin_port"

    if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] || [[ "$system_caddy_running" = true ]]; then
        export HTTPS_PORT="$APP_PORT"
        export USE_NON_PRIVILEGED_PORT=1
        export TLS_DIRECTIVE=""
        export CADDY_SCHEME="http"

        if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] && [[ "$system_caddy_running" = true ]]; then
            PUBLIC_APP_URL="https://${FRONTEND_DOMAIN}"
            PUBLIC_BACKEND_URL="https://${BACKEND_DOMAIN}"
        else
            PUBLIC_APP_URL="http://${FRONTEND_DOMAIN}:${APP_PORT}"
            PUBLIC_BACKEND_URL="http://${BACKEND_DOMAIN}:${APP_PORT}"
        fi
    else
        export HTTPS_PORT="443"
        export USE_NON_PRIVILEGED_PORT=0
        export TLS_DIRECTIVE="${TLS_DIRECTIVE:-tls internal}"
        export CADDY_SCHEME="https"

        PUBLIC_APP_URL="https://${FRONTEND_DOMAIN}"
        PUBLIC_BACKEND_URL="https://${BACKEND_DOMAIN}"
    fi

    APP_BIND_HOST=$(caddy_resolve_app_bind_host "${USE_NON_PRIVILEGED_PORT:-0}" "$(get_env_var "APP_BIND_HOST" "")")

    export APP_BIND_HOST HTTPS_PORT PUBLIC_APP_URL PUBLIC_BACKEND_URL
    log "Caddy env exported (TLS_DIRECTIVE=$TLS_DIRECTIVE, ADMIN_PORT=$CADDY_SERVER_ADMIN_PORT, HTTPS_PORT=$HTTPS_PORT, APP_BIND_HOST=$APP_BIND_HOST)"
    return 0
}

# launch_browser is provided by shared/runtime.sh

# Start development services
start_services() {
    echo -e "${GREEN}Starting FrankenPHP (Octane), Vite, and queue worker...${NC}"

    # Create a separate log file for dev services output
    local dev_log_file
    dev_log_file="$(get_logs_dir "$PROJECT_ROOT")/dev-services.log"

    # Override CI=1 (set by some IDEs/editors) so laravel-vite-plugin starts the HMR server
    export LARAVEL_BYPASS_ENV_CHECK=1

    composer run dev >> "$dev_log_file" 2>&1 &
    DEV_PID=$!

    # Store PID for cleanup
    echo "$DEV_PID" > "$PID_FILE"

    echo -e "${CYAN}ℹ${NC} Dev services output: ${dev_log_file}"
    echo -e "${CYAN}ℹ${NC} To watch: ${YELLOW}tail -f ${dev_log_file}${NC}"
    return 0
}

print_runtime_guidance() {
    if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
        echo ""
        echo -e "${CYAN}ℹ${NC} Shared ingress mode selected. FrankenPHP will listen on ${YELLOW}127.0.0.1:${APP_PORT}${NC}."
        echo ""
        if caddy_system_is_running; then
            echo -e "${GREEN}✓${NC} System Caddy detected — public URLs should resolve through your configured hostnames"
            log "Shared ingress mode active with system Caddy"
        else
            echo -e "${YELLOW}⚠${NC} Shared ingress mode is configured, but system Caddy is not running."
            echo -e "  ${CYAN}The app will still start on a local HTTP listener for verification:${NC} ${YELLOW}${PUBLIC_APP_URL}${NC}"
            echo -e "  ${CYAN}Run setup again to provision Caddy, or install this site block manually:${NC}"
            echo ""
            caddy_render_system_site_snippet "$PROJECT_ROOT" "$FRONTEND_DOMAIN" "$BACKEND_DOMAIN" "$APP_PORT" "$APP_ENV" | while IFS= read -r line; do
                echo -e "    ${YELLOW}${line}${NC}"
            done
            echo ""
            log "Shared ingress configured but system Caddy is not running"
        fi
        return 0
    fi

    if caddy_system_is_running; then
        echo ""
        echo -e "${YELLOW}⚠${NC} System Caddy is already active, so BLB will avoid :443 and use a local HTTP listener instead."
        echo -e "  ${CYAN}If you want this to be permanent, set ${YELLOW}BLB_INGRESS_MODE=shared${NC} and run setup.${NC}"
        echo -e "  ${CYAN}Until then, access the app through:${NC} ${YELLOW}${PUBLIC_APP_URL}${NC}"
        echo ""
        echo -e "  ${CYAN}Suggested system Caddy site block:${NC}"
        echo ""
        caddy_render_system_site_snippet "$PROJECT_ROOT" "$FRONTEND_DOMAIN" "$BACKEND_DOMAIN" "$APP_PORT" "$APP_ENV" | while IFS= read -r line; do
            echo -e "    ${YELLOW}${line}${NC}"
        done
        echo ""
        log "System Caddy detected while in direct mode; falling back to local HTTP listener"
        return 0
    fi

    ensure_frankenphp_bind_capability
    echo ""
    echo -e "${CYAN}ℹ${NC} Direct mode selected. FrankenPHP will bind to ${YELLOW}${APP_BIND_HOST}:${HTTPS_PORT}${NC}."
    return 0
}

get_healthcheck_url() {
    if [[ "${USE_NON_PRIVILEGED_PORT:-0}" = "1" ]]; then
        printf '%s\n' "http://127.0.0.1:${APP_PORT}"
    else
        printf '%s\n' "https://${FRONTEND_DOMAIN}"
    fi

    return 0
}

print_runtime_summary() {
    echo ""
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}✓ Belimbing is ready!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${CYAN}Access your application:${NC}"
    echo -e "  ${GREEN}Frontend:${NC} ${YELLOW}${PUBLIC_APP_URL}${NC}"
    echo -e "  ${GREEN}Backend:${NC}  ${YELLOW}${PUBLIC_BACKEND_URL}${NC}"
    echo ""
    echo -e "${CYAN}Services:${NC}"

    if [[ "${USE_NON_PRIVILEGED_PORT:-0}" = "1" ]]; then
        echo -e "  ${BULLET} FrankenPHP (Octane): http://127.0.0.1:${APP_PORT}"
        echo -e "  ${BULLET} Vite:                http://127.0.0.1:$VITE_PORT"
        echo ""
        if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] && caddy_system_is_running; then
            echo -e "${GREEN}✓ System Caddy is active for shared ingress${NC}"
        else
            echo -e "${YELLOW}⚠ Public HTTPS is not being served by BLB directly in this mode${NC}"
        fi
    else
        echo -e "  ${BULLET} FrankenPHP (Octane): https://${FRONTEND_DOMAIN} (:443, bind ${APP_BIND_HOST})"
        echo -e "  ${BULLET} Vite:                http://127.0.0.1:$VITE_PORT"
    fi
    echo ""
    echo -e "${CYAN}Log file:${NC} ${LOG_FILE}"
    echo ""
    echo -e "Press ${YELLOW}Ctrl+C${NC} to stop all services"
    return 0
}

# Main orchestration function
main() {
    cd "$PROJECT_ROOT"
    local t0
    t0=$(now_epoch_s)

    # Ensure storage directory structure exists
    ensure_storage_dirs "$PROJECT_ROOT"

    # Setup logging
    local log_dir
    log_dir=$(get_logs_dir "$PROJECT_ROOT")
    LOG_FILE="$log_dir/start-app.log"
    mkdir -p "$log_dir"

    local start_user
    start_user=$(whoami 2>/dev/null || echo "${USER:-unknown}")
    log "[$start_user] Starting BLB Development Environment..."
    echo -e "${GREEN}Starting BLB Development Environment...${NC}"

    # Initialize environment
    local t_init
    t_init=$(now_epoch_s)
    read_app_env
    get_ports
    export_caddy_env
    log "Init completed in $(( $(now_epoch_s) - t_init ))s"

    # Check hosts entries (warn but don't block)
    local t_hosts
    t_hosts=$(now_epoch_s)
    check_hosts_entries || true
    log "Hosts check completed in $(( $(now_epoch_s) - t_hosts ))s"

    # Check dependencies
    local t_deps
    t_deps=$(now_epoch_s)
    check_dependencies
    log "Dependency check completed in $(( $(now_epoch_s) - t_deps ))s"

    print_runtime_guidance

    # Check and stop services if needed
    local t_ports
    t_ports=$(now_epoch_s)
    check_and_stop_services "$APP_PORT"
    log "Port availability check completed in $(( $(now_epoch_s) - t_ports ))s"

    # Store PID file path for cleanup
    PID_FILE="$PROJECT_ROOT/storage/app/.devops/start-app.pid"
    mkdir -p "$(dirname "$PID_FILE")"

    # Set up cleanup handler (before starting processes)
    trap cleanup INT TERM

    # Start services
    local t_start
    t_start=$(now_epoch_s)
    start_services
    log "Dev services started in $(( $(now_epoch_s) - t_start ))s (PID $DEV_PID)"

    # Wait for FrankenPHP/Octane to be ready on the actual local listener.
    local healthcheck_url
    healthcheck_url=$(get_healthcheck_url)
    wait_for_service "$healthcheck_url" "FrankenPHP (Octane)" || true
    log "Start-app total time so far: $(( $(now_epoch_s) - t0 ))s"

    print_runtime_summary

    # Launch browser if available
    launch_browser "$PUBLIC_APP_URL" || true

    # Wait for background process
    wait "$DEV_PID" 2>/dev/null || true
    return 0
}

# Run main function
main "$@"
