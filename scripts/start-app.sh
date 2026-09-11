#!/bin/bash
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
# shellcheck source=shared/diagnostics.sh
source "$SCRIPT_DIR/shared/diagnostics.sh" 2>/dev/null || true

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
HTTPS_PORT=""
APP_BIND_HOST=""
BLB_INGRESS_MODE=""
USE_NON_PRIVILEGED_PORT=0
PUBLIC_APP_URL=""
INTENDED_APP_URL=""
PUBLIC_URL_REACHABLE=1

BLB_INGRESS_MODE_SHARED='shared'

prepend_path_if_dir() {
    local dir=$1

    if [[ -d "$dir" ]] && [[ ":$PATH:" != *":$dir:"* ]]; then
        export PATH="$dir:$PATH"
    fi

    return 0
}

bootstrap_tool_paths() {
    prepend_path_if_dir "$HOME/.local/bin"
    prepend_path_if_dir "$HOME/.bun/bin"
    return 0
}

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

# Best-effort diagnosis for startup timeout by reading recent app/server logs.
detect_startup_failure_reason() {
    local laravel_log="$PROJECT_ROOT/storage/logs/laravel.log"
    local dev_log
    dev_log="$(get_logs_dir "$PROJECT_ROOT")/dev-services.log"
    local reason=""

    if [[ -f "$dev_log" ]]; then
        reason=$(tail -n 250 "$dev_log" | grep -E "not found|ERROR|Error|Fatal|exception|returned with error code|exited with code|script \"dev:all\" exited" | tail -n1 || true)
        if [[ -n "$reason" ]]; then
            printf '%s\n' "$reason"
            return 0
        fi
    fi

    if [[ -f "$laravel_log" ]]; then
        reason=$(tail -n 400 "$laravel_log" | grep -E "\.ERROR:" | tail -n1 || true)
        if [[ -n "$reason" ]]; then
            reason=$(printf '%s' "$reason" | sed -E 's/^\[[^]]+\] [^ ]+\.ERROR: //')
            reason=$(printf '%s' "$reason" | sed -E 's/ \{"exception".*$//')
            printf '%s\n' "$reason"
            return 0
        fi
    fi

    printf '%s\n' ""
    return 0
}

# Check for required dependencies (verification only, no installation)
check_dependencies() {
    local missing=()

    if ! command -v composer &> /dev/null; then
        missing+=("composer")
    fi

    if ! command -v bun &> /dev/null; then
        missing+=("bun")
    fi

    if ! command -v bunx &> /dev/null; then
        missing+=("bunx")
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
            echo -e "  • PHP runtime/Composer: ${CYAN}./scripts/setup-steps/15-runtime.sh${NC}" >&2
        fi
        if [[ " ${missing[*]} " =~ " bun " ]] || [[ " ${missing[*]} " =~ " bunx " ]]; then
            echo -e "  • JavaScript runtime (Bun): ${CYAN}./scripts/setup-steps/30-js.sh${NC}" >&2
        fi
        log "ERROR: Missing dependencies: ${missing[*]}"
        exit 1
    fi

    echo -e "${GREEN}✓${NC} All dependencies available (using Bun)"
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

# pid_belongs_to_project is provided by shared/runtime.sh and used here
# to scope reclamation to the current $PROJECT_ROOT.

# Best-effort SIGTERM-then-SIGKILL on a process and its descendants.
# Always succeeds; this is a cleanup helper, not a control-flow predicate.
kill_process_tree() {
    local root=$1
    [[ -n "$root" && "$root" =~ ^[0-9]+$ ]] || return 0
    local descendants
    descendants=$(pgrep -P "$root" 2>/dev/null || true)
    local child
    for child in $descendants; do
        kill_process_tree "$child"
    done
    if kill -0 "$root" 2>/dev/null; then
        kill -TERM "$root" 2>/dev/null || true
        sleep 0.3
        if kill -0 "$root" 2>/dev/null; then
            kill -KILL "$root" 2>/dev/null || true
        fi
    fi
    return 0
}

# Reclaim a stale dev session left behind by an earlier crash of *this*
# project (e.g. terminal closed without Ctrl+C, kill -9, system reboot).
# Without this, the next start-app.sh run hits two failure modes:
#   1. The pinned APP_PORT is busy → ensure_app_port_available reassigns
#      to a new port and rewrites the Caddy site fragment, but the existing
#      FrankenPHP keeps the old port and Octane refuses to start with
#      "FrankenPHP server is already running" → 502 at the public URL.
#   2. octane-server-state.json points at a still-alive FrankenPHP PID
#      from the previous run, which makes Octane abort.
# We only reclaim processes whose cwd matches $PROJECT_ROOT; any other
# BLB checkout running in parallel is left untouched.
reclaim_stale_dev_session() {
    local devops_dir="$PROJECT_ROOT/storage/app/.devops"
    local stale_pid_file="$devops_dir/start-app.pid"
    local octane_state="$PROJECT_ROOT/storage/logs/octane-server-state.json"
    local reclaimed=0

    if [[ -f "$stale_pid_file" ]]; then
        local stale_pid
        stale_pid=$(tr -d '[:space:]' < "$stale_pid_file" 2>/dev/null || true)
        if pid_belongs_to_project "$stale_pid" "$PROJECT_ROOT"; then
            echo -e "${YELLOW}⚠${NC} Found stale BLB dev session for this project (PID ${stale_pid}) — stopping it"
            log "Reclaiming stale start-app session PID=$stale_pid"
            kill_process_tree "$stale_pid"
            reclaimed=1
        fi
        rm -f "$stale_pid_file"
    fi

    if [[ -f "$octane_state" ]]; then
        local octane_pid=""
        if command -v python3 >/dev/null 2>&1; then
            octane_pid=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("masterProcessId",""))' "$octane_state" 2>/dev/null || true)
        fi
        if [[ -z "$octane_pid" ]]; then
            octane_pid=$(grep -oE '"masterProcessId"[[:space:]]*:[[:space:]]*[0-9]+' "$octane_state" | grep -oE '[0-9]+' | head -n1 || true)
        fi
        if pid_belongs_to_project "$octane_pid" "$PROJECT_ROOT"; then
            echo -e "${YELLOW}⚠${NC} Found stale FrankenPHP master for this project (PID ${octane_pid}) — stopping it"
            log "Reclaiming stale FrankenPHP master PID=$octane_pid"
            kill_process_tree "$octane_pid"
            rm -f "$octane_state"
            reclaimed=1
        elif [[ -z "$octane_pid" ]] || ! kill -0 "$octane_pid" 2>/dev/null; then
            # PID is gone, or unparseable; the state file is misleading garbage.
            rm -f "$octane_state"
            log "Removed stale octane-server-state.json (PID '$octane_pid' not alive)"
        fi
        # Otherwise the PID is alive but belongs to a different project — leave it.
    fi

    if [[ "$reclaimed" -eq 1 ]]; then
        # Give the kernel a moment to release the listening sockets so the
        # next port probe sees them free.
        sleep 1
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

    # Read the domain from .env or use the default
    FRONTEND_DOMAIN=$(get_env_var "FRONTEND_DOMAIN" "")
    BLB_INGRESS_MODE=$(caddy_normalize_ingress_mode "$(get_env_var "BLB_INGRESS_MODE" "direct")")

    # Use the default if not set
    if [[ -z "$FRONTEND_DOMAIN" ]]; then
        if command -v get_default_domain >/dev/null 2>&1; then
            FRONTEND_DOMAIN=$(get_default_domain "$APP_ENV")
        else
            FRONTEND_DOMAIN="${APP_ENV}.blb.lara"
        fi
    fi

    # Log environment info (important for troubleshooting)
    log "Environment: $APP_ENV, Domain: $FRONTEND_DOMAIN, Ingress: $BLB_INGRESS_MODE"

    echo -e "${GREEN}Using environment: ${APP_ENV}${NC}"
    return 0
}

# Check if the app domain is in /etc/hosts
check_hosts_entries() {
    local result=0
    local hosts_note=""
    if is_wsl2; then
        hosts_note=" (WSL /etc/hosts — separate from Windows hosts)"
    fi

    # Check Linux /etc/hosts (uses shared domain_in_hosts: any IP, POSIX pattern)
    if ! domain_in_hosts "$FRONTEND_DOMAIN"; then
        echo ""
        echo -e "${YELLOW}⚠${NC} ${FRONTEND_DOMAIN} is not in /etc/hosts${hosts_note}"
        echo ""
        echo -e "${CYAN}To add it, run:${NC}"
        echo -e "  ${YELLOW}sudo sh -c 'echo \"127.0.0.1 ${FRONTEND_DOMAIN}\" >> /etc/hosts'${NC}"
        echo ""
        echo -e "${CYAN}Or re-run native setup to add it automatically:${NC}"
        echo -e "  ${YELLOW}./scripts/setup.sh $APP_ENV${NC}"
        echo ""
        log "WARNING: Missing hosts entry: $FRONTEND_DOMAIN"
        result=1
    else
        echo -e "${GREEN}✓${NC} Domain configured in /etc/hosts"
    fi

    # Check Windows hosts file if running in WSL2 and we're likely to use a Windows browser.
    # Only a GUI browser on a real Linux display makes the Windows hosts file
    # irrelevant; xdg-open and sensible-browser ship on stock Ubuntu WSL images
    # and resolve to the Windows browser (or to nothing), so counting them as a
    # local browser skipped this check for exactly the users who needed it.
    if is_wsl2; then
        if linux_gui_browser_available; then
            log "INFO: Skipping Windows hosts check (GUI browser on a Linux display)"
            return $result
        fi

        local win_hosts
        win_hosts=$(get_windows_hosts_path)
        local net_mode
        net_mode=$(wsl2_networking_mode)
        local win_missing=false
        local win_wrong_ip=false
        local expected_ip=""

        # Mirrored networking shares the Windows network namespace, so
        # 127.0.0.1 is the correct Windows hosts entry there. Under NAT the
        # loopback address does not reach a listener inside WSL.
        if [[ "$net_mode" = "mirrored" ]]; then
            expected_ip="127.0.0.1"
        else
            expected_ip=$(get_wsl2_ip)

            if [[ -z "$expected_ip" ]]; then
                echo -e "${YELLOW}⚠${NC} Could not determine WSL2 IP address for Windows hosts file check"
                log "WARNING: Could not determine WSL2 IP address"
                return $result
            fi
        fi

        # Check if the domain exists in the Windows hosts file
        if ! domain_in_windows_hosts "$FRONTEND_DOMAIN"; then
            win_missing=true
        elif [[ "$net_mode" != "mirrored" ]] && grep -E "^[[:space:]]*127\.0\.0\.1[[:space:]]+.*${FRONTEND_DOMAIN//./\\.}" "$win_hosts" 2>/dev/null | grep -v "^#" > /dev/null; then
            win_wrong_ip=true
        fi

        if [[ "$win_missing" = true ]] || [[ "$win_wrong_ip" = true ]]; then
            echo ""
            echo -e "${YELLOW}⚠${NC} Windows hosts file may need configuration (WSL2 detected):"

            if [[ "$win_missing" = true ]]; then
                echo -e "  ${YELLOW}Missing domain:${NC} $FRONTEND_DOMAIN"
            fi

            if [[ "$win_wrong_ip" = true ]]; then
                echo -e "  ${YELLOW}Wrong IP address (using 127.0.0.1 instead of WSL2 IP):${NC} $FRONTEND_DOMAIN"
            fi

            echo ""
            echo -e "${CYAN}Address this domain must resolve to on Windows: ${YELLOW}$expected_ip${NC} (WSL2 networking: ${net_mode})"
            echo ""
            echo -e "${CYAN}Add/update this line in Windows hosts file:${NC}"
            echo -e "  ${YELLOW}$expected_ip $FRONTEND_DOMAIN${NC}"
            echo ""
            echo -e "${CYAN}Windows hosts file location:${NC}"
            echo -e "  ${YELLOW}C:\\Windows\\System32\\drivers\\\\etc\\hosts${NC}"
            echo ""
            echo -e "${CYAN}To fix:${NC}"
            echo -e "  1. Open Notepad as Administrator (Win+R → ${YELLOW}notepad${NC} → Ctrl+Shift+Enter)"
            echo -e "  2. Open: ${YELLOW}C:\\Windows\\System32\\drivers\\\\etc\\hosts${NC}"
            if [[ "$win_wrong_ip" = true ]]; then
                echo -e "  3. Remove/comment the line with ${YELLOW}127.0.0.1${NC} for this domain"
            fi
            echo -e "  4. Add: ${YELLOW}$expected_ip $FRONTEND_DOMAIN${NC}"
            echo -e "  5. Save and close"
            echo ""
            echo -e "${CYAN}Or use PowerShell (Run as Administrator):${NC}"
            if [[ "$win_wrong_ip" = true ]]; then
                echo -e "  ${YELLOW}\$content = Get-Content \"C:\\Windows\\System32\\drivers\\\\etc\\hosts\"; \$content = \$content | Where-Object { \$_ -notmatch \"127\\.0\\.0\\.1.*local\\.blb\\.lara\" }; \$content | Set-Content \"C:\\Windows\\System32\\drivers\\\\etc\\hosts\"${NC}"
            fi
            echo -e "  ${YELLOW}Add-Content -Path \"C:\\Windows\\System32\\drivers\\\\etc\\hosts\" -Value \"$expected_ip $FRONTEND_DOMAIN\"${NC}"
            echo ""
            log "WARNING: Windows hosts file may need configuration. Expected IP: $expected_ip (networking: $net_mode)"
            result=1
        else
            echo -e "${GREEN}✓${NC} Windows hosts file configured correctly (resolves to $expected_ip)"
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

# Ensure the app port is usable.  Does NOT stop anything — by the time we
# get here, reclaim_stale_dev_session has already cleared our own corpses,
# so any remaining listener on this port belongs to a foreign process.
# In shared mode we recover by re-running the ingress setup step to pick a
# new free port and regenerate the Caddy site fragment so they stay in
# sync; in direct mode the conflict is fatal.
ensure_app_port_available() {
    local port=$1

    if ! lsof -Pi :"$port" -sTCP:LISTEN -t >/dev/null 2>&1; then
        return 0
    fi

    if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
        echo -e "${YELLOW}⚠${NC} Port ${CYAN}${port}${NC} is busy (held by a foreign process) — reassigning port and updating Caddy..." >&2
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
        stop_dev_services "$APP_ENV" "$APP_PORT" "$VITE_PORT" "$PROJECT_ROOT"
    else
        stop_dev_services "local" "" "" "$PROJECT_ROOT"
    fi

    [[ -f "$PID_FILE" ]] && rm -f "$PID_FILE"
    rm -f "$PROJECT_ROOT/storage/app/.devops/ports.env"
    # Octane writes this on start and never removes it on its own; if we
    # leave it, the next start-app run can mistake it for a live server.
    rm -f "$PROJECT_ROOT/storage/logs/octane-server-state.json"

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
        if [[ -n "${DEV_PID:-}" ]] && ! kill -0 "$DEV_PID" 2>/dev/null; then
            echo -e "${RED}✗${NC} $service_name process exited before becoming ready" >&2
            break
        fi
        sleep 1
        attempt=$((attempt + 1))
    done

    echo -e "${RED}✗${NC} $service_name did not respond at $url after ${max_attempts}s" >&2
    local startup_reason
    startup_reason=$(detect_startup_failure_reason)
    if [[ -n "$startup_reason" ]]; then
        echo -e "${YELLOW}Likely startup error:${NC} $startup_reason" >&2
        log "Likely startup error: $startup_reason"
    fi
    log "ERROR: $service_name not ready after $max_attempts attempts (elapsed $(( $(now_epoch_s) - start_ts ))s)"
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
            if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
                echo -e "  Run ${CYAN}./scripts/setup-steps/70-domains.sh${NC}, then ${CYAN}./scripts/setup-steps/72-caddy-ingress.sh${NC}"
            else
                echo -e "  Run ${CYAN}./scripts/setup-steps/70-domains.sh${NC} to generate mkcert certs"
            fi
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
    export CADDY_SERVER_ADMIN_HOST='127.0.0.1'
    export CADDY_SERVER_ADMIN_PORT="$caddy_admin_port"

    if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] || [[ "$system_caddy_running" = true ]]; then
        export HTTPS_PORT="$APP_PORT"
        export USE_NON_PRIVILEGED_PORT=1
        export TLS_DIRECTIVE=""
        export CADDY_SCHEME="http"

        # Shared ingress always *intends* to be reached over HTTPS through
        # system Caddy. Keep that URL even when Caddy is currently down: it is
        # what the user was told to open, and what start-up verification must
        # probe. PUBLIC_* stays on the local HTTP listener until the HTTPS URL
        # is confirmed, so a degraded run still prints something that works.
        if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
            INTENDED_APP_URL="https://${FRONTEND_DOMAIN}"
        fi

        if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] && [[ "$system_caddy_running" = true ]]; then
            PUBLIC_APP_URL="https://${FRONTEND_DOMAIN}"
        else
            PUBLIC_APP_URL="http://${FRONTEND_DOMAIN}:${APP_PORT}"
        fi
    else
        export HTTPS_PORT="443"
        export USE_NON_PRIVILEGED_PORT=0
        export TLS_DIRECTIVE="${TLS_DIRECTIVE:-tls internal}"
        export CADDY_SCHEME="https"

        PUBLIC_APP_URL="https://${FRONTEND_DOMAIN}"
    fi

    INTENDED_APP_URL="${INTENDED_APP_URL:-$PUBLIC_APP_URL}"

    APP_BIND_HOST=$(caddy_resolve_app_bind_host "${USE_NON_PRIVILEGED_PORT:-0}" "$(get_env_var "APP_BIND_HOST" "")")
    CADDY_BIND_ADDRESS="$APP_BIND_HOST"
    CADDY_VITE_SNIPPET="${CADDY_VITE_SNIPPET:-scripts/caddy-snippets/vite-enabled.caddy}"

    export APP_BIND_HOST CADDY_BIND_ADDRESS CADDY_VITE_SNIPPET HTTPS_PORT PUBLIC_APP_URL
    log "Caddy env exported (TLS_DIRECTIVE=$TLS_DIRECTIVE, ADMIN_PORT=$CADDY_SERVER_ADMIN_PORT, HTTPS_PORT=$HTTPS_PORT, APP_BIND_HOST=$APP_BIND_HOST, CADDY_BIND_ADDRESS=$CADDY_BIND_ADDRESS)"
    return 0
}

# launch_browser is provided by shared/runtime.sh

# Start development services
start_services() {
    echo -e "${GREEN}Starting FrankenPHP (Octane), Vite, and queue worker...${NC}"

    # Create a separate log file for dev services output
    local dev_log_file
    dev_log_file="$(get_logs_dir "$PROJECT_ROOT")/dev-services.log"
    : > "$dev_log_file"

    # Override CI=1 (set by some IDEs/editors) so laravel-vite-plugin starts the HMR server
    export LARAVEL_BYPASS_ENV_CHECK=1

    composer run dev > >(awk '{ print strftime("[%Y-%m-%d %H:%M:%S]"), $0; fflush() }' >> "$dev_log_file") 2>&1 &
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
            caddy_render_system_site_snippet "$PROJECT_ROOT" "$FRONTEND_DOMAIN" "$APP_PORT" "$APP_ENV" | while IFS= read -r line; do
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
        caddy_render_system_site_snippet "$PROJECT_ROOT" "$FRONTEND_DOMAIN" "$APP_PORT" "$APP_ENV" | while IFS= read -r line; do
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

# Probe Laravel's health route rather than the site root. /up is exempt from
# maintenance mode, so readiness still passes when a previous update left the
# site down — otherwise startup would abort before heal_stale_maintenance gets
# to clear it. It is also a real check: the site root answers 200 with an empty
# body when the request matches no Caddy vhost, which passes `curl -f` without
# the application having served anything.
get_healthcheck_url() {
    if [[ "${USE_NON_PRIVILEGED_PORT:-0}" = "1" ]]; then
        printf '%s\n' "http://127.0.0.1:${APP_PORT}/up"
    else
        printf '%s\n' "https://${FRONTEND_DOMAIN}/up"
    fi

    return 0
}

# An update that could not confirm its worker reload leaves the site in
# maintenance mode on purpose, to keep old in-memory workers from serving newly
# pulled files. Booting fresh workers closes that window by construction, so a
# hold owned by a run that is no longer active is now just downtime. The command
# leaves a genuinely running update alone.
heal_stale_maintenance() {
    if ! command -v php >/dev/null 2>&1; then
        log "Skipped maintenance heal: php not on PATH"
        return 0
    fi

    local output
    if output=$(cd "$PROJECT_ROOT" && php artisan blb:software:maintenance-heal 2>&1); then
        if [[ -n "${output//[[:space:]]/}" ]]; then
            echo -e "${CYAN}ℹ${NC} ${output}"
        fi
        log "Maintenance heal check: ${output:-no action needed}"
    else
        echo -e "${YELLOW}⚠${NC} Could not check for leftover maintenance mode: ${output}" >&2
        log "WARNING: maintenance heal failed: ${output}"
    fi

    return 0
}

# The loopback healthcheck only proves FrankenPHP answers on its own port. In
# shared ingress mode the URL the user was told to open is served by a
# *different* process, so "ready" can still mean "connection refused" in the
# browser. Probe that URL — not the local fallback, which answers either way —
# and name the cause when it does not respond.
verify_public_reachability() {
    if url_is_reachable "${INTENDED_APP_URL%/}/up"; then
        promote_intended_urls
        echo -e "${GREEN}✓${NC} ${PUBLIC_APP_URL} answers from this host"
        log "Public URL reachable: $PUBLIC_APP_URL"
        return 0
    fi

    if repair_public_ingress && url_is_reachable "${INTENDED_APP_URL%/}/up"; then
        promote_intended_urls
        echo -e "${GREEN}✓${NC} ${PUBLIC_APP_URL} answers from this host"
        log "Public URL reachable after repair: $PUBLIC_APP_URL"
        return 0
    fi

    PUBLIC_URL_REACHABLE=0
    report_public_url_unreachable
    return 1
}

# The intended URL answered, so it is safe to advertise it. In direct mode this
# is a no-op; in shared mode it upgrades the local HTTP fallback to the HTTPS
# URL that system Caddy is now serving.
promote_intended_urls() {
    PUBLIC_APP_URL="$INTENDED_APP_URL"
    return 0
}

# The one repair that is safe to perform unasked: setup installed system Caddy
# for shared ingress and the service simply is not running. Switching ingress
# modes is deliberately NOT automated — direct mode binds 0.0.0.0:443 and would
# expose the dev app to the LAN without the user asking for it.
repair_public_ingress() {
    [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] || return 1
    caddy_system_is_running && return 1
    command_exists caddy || return 1
    systemd_is_available || return 1

    echo -e "${CYAN}→${NC} System Caddy is installed but not running — starting it..."
    log "Attempting to start system Caddy for shared ingress"

    if sudo -n systemctl start caddy 2>/dev/null || { [[ -t 0 ]] && sudo systemctl start caddy; }; then
        # Caddy returns from `start` before the listener is bound.
        local attempt
        for attempt in 1 2 3 4 5; do
            caddy_system_is_running && break
            sleep 1
        done
        echo -e "${GREEN}✓${NC} System Caddy started"
        log "System Caddy started"
        return 0
    fi

    echo -e "${YELLOW}⚠${NC} Could not start system Caddy automatically"
    log "WARNING: could not start system Caddy"
    return 1
}

report_public_url_unreachable() {
    local net_mode="unknown"
    net_mode=$(wsl2_networking_mode)
    local cause_reported=false

    echo ""
    echo -e "${YELLOW}⚠${NC} ${INTENDED_APP_URL} did not answer, even though FrankenPHP is healthy."
    echo -e "  ${CYAN}The app is running — something between the browser and it is missing.${NC}"
    echo ""

    if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]] && ! caddy_system_is_running; then
        cause_reported=true
        echo -e "  ${YELLOW}Cause:${NC} ingress mode is ${CYAN}shared${NC}, so BLB never binds :443 — system"
        echo -e "         Caddy is meant to own it and proxy to ${CYAN}127.0.0.1:${APP_PORT}${NC}. It is not running."
        echo ""

        if is_wsl2 && ! systemd_is_available; then
            echo -e "  ${CYAN}On WSL2 systemd is off by default, so the caddy service never starts.${NC}"
            echo -e "  ${CYAN}Add these two lines to ${YELLOW}/etc/wsl.conf${CYAN}, then run ${YELLOW}wsl --shutdown${CYAN} from Windows:${NC}"
            echo -e "    ${YELLOW}[boot]${NC}"
            echo -e "    ${YELLOW}systemd=true${NC}"
        elif command_exists caddy; then
            echo -e "  ${CYAN}Start it with:${NC} ${YELLOW}sudo systemctl start caddy${NC}"
        else
            echo -e "  ${CYAN}Caddy is not installed. Run:${NC} ${YELLOW}./scripts/setup-steps/72-caddy-ingress.sh $APP_ENV${NC}"
        fi

        echo ""
        echo -e "  ${CYAN}Or let BLB own :443 itself (binds ${YELLOW}0.0.0.0:443${CYAN}, reachable on your LAN):${NC}"
        echo -e "    ${YELLOW}BLB_INGRESS_MODE=direct${NC} in .env, then restart"
        echo ""
    elif [[ "$BLB_INGRESS_MODE" != "$BLB_INGRESS_MODE_SHARED" ]] && ! port_has_listener "$HTTPS_PORT"; then
        cause_reported=true
        echo -e "  ${YELLOW}Cause:${NC} nothing is listening on port ${CYAN}${HTTPS_PORT}${NC}."
        echo ""

        local binary
        binary=$(command -v frankenphp 2>/dev/null || true)
        [[ -x "$PROJECT_ROOT/frankenphp" ]] && binary="$PROJECT_ROOT/frankenphp"

        if [[ "$HTTPS_PORT" = "443" ]] && ! frankenphp_can_bind_privileged_ports "$binary"; then
            echo -e "  ${CYAN}FrankenPHP cannot bind a privileged port without the capability:${NC}"
            echo -e "    ${YELLOW}sudo setcap cap_net_bind_service=+ep ${binary:-\$(command -v frankenphp)}${NC}"
        else
            echo -e "  ${CYAN}Check the dev log:${NC} ${YELLOW}$(get_logs_dir "$PROJECT_ROOT")/dev-services.log${NC}"
        fi
        echo ""
    fi

    if [[ "$cause_reported" != true ]]; then
        # Something answers on the port but not for this host or scheme —
        # a proxy that does not know the vhost, or a certificate the probe
        # rejected.
        echo -e "  ${CYAN}The port is served, so the request is being refused or misrouted"
        echo -e "  rather than unserved. Worth checking:${NC}"
        echo -e "    ${BULLET} ${YELLOW}curl -kv ${INTENDED_APP_URL}/up${NC}"
        echo -e "    ${BULLET} dev services log: ${YELLOW}$(get_logs_dir "$PROJECT_ROOT")/dev-services.log${NC}"
        if [[ "$BLB_INGRESS_MODE" = "$BLB_INGRESS_MODE_SHARED" ]]; then
            echo -e "    ${BULLET} system Caddy is running but may not proxy this host:"
            echo -e "      ${YELLOW}sudo journalctl -u caddy -n 50${NC}"
        fi
        echo ""
    fi

    # A degraded shared-ingress run still has a working local listener. Say so,
    # so the user is not left with only a URL that refuses connections.
    if [[ "$PUBLIC_APP_URL" != "$INTENDED_APP_URL" ]] && url_is_reachable "${PUBLIC_APP_URL%/}/up"; then
        echo -e "  ${GREEN}✓${NC} In the meantime the app is reachable directly at ${YELLOW}${PUBLIC_APP_URL}${NC}"
        echo ""
    fi

    if is_wsl2 && [[ "$net_mode" != "mirrored" ]]; then
        echo -e "  ${CYAN}Also note (WSL2, ${net_mode} networking):${NC} your Windows browser cannot reach"
        echo -e "  this listener through ${YELLOW}127.0.0.1${NC}. The Windows hosts file needs the WSL2 IP"
        echo -e "  (${YELLOW}$(get_wsl2_ip)${NC}), which changes on every reboot — or switch WSL to mirrored"
        echo -e "  networking by adding to ${YELLOW}%USERPROFILE%\.wslconfig${NC}:"
        echo -e "    ${YELLOW}[wsl2]${NC}"
        echo -e "    ${YELLOW}networkingMode=mirrored${NC}"
        echo ""
    fi

    log "WARNING: public URL $INTENDED_APP_URL unreachable (mode=$BLB_INGRESS_MODE, net=$net_mode, fallback=$PUBLIC_APP_URL)"
    return 0
}

print_runtime_summary() {
    local rule="━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    echo ""
    if [[ "$PUBLIC_URL_REACHABLE" = "1" ]]; then
        echo -e "${GREEN}${rule}${NC}"
        echo -e "${GREEN}✓ Belimbing is ready!${NC}"
        echo -e "${GREEN}${rule}${NC}"
    else
        # Claiming "ready" while the printed URL refuses connections is what
        # sends people hunting through logs instead of at the cause above.
        echo -e "${YELLOW}${rule}${NC}"
        echo -e "${YELLOW}⚠ Belimbing started, but its public URL is not reachable${NC}"
        echo -e "${YELLOW}${rule}${NC}"
    fi
    echo ""
    echo -e "${CYAN}Access your application:${NC}"
    echo -e "  ${GREEN}URL:${NC} ${YELLOW}${PUBLIC_APP_URL}${NC}"
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
    bootstrap_tool_paths
    local t0
    t0=$(now_epoch_s)

    # Ensure storage directory structure exists
    ensure_storage_dirs "$PROJECT_ROOT"
    export_project_php_ini_scan_dir "$PROJECT_ROOT"

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
    # Reclaim any stale dev session for THIS project before resolving ports,
    # so a pinned APP_PORT isn't bumped just because our own corpse is on it.
    reclaim_stale_dev_session
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

    # Make sure $APP_PORT is actually usable; in shared mode this may
    # reassign the port and regenerate the Caddy site fragment.
    local t_ports
    t_ports=$(now_epoch_s)
    ensure_app_port_available "$APP_PORT"
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
    # Failure here is fatal: the public Caddy site has already been pointed
    # at $APP_PORT, so continuing would silently serve 502s to the user.
    local healthcheck_url
    healthcheck_url=$(get_healthcheck_url)
    if ! wait_for_service "$healthcheck_url" "FrankenPHP (Octane)"; then
        local dev_log
        dev_log="$(get_logs_dir "$PROJECT_ROOT")/dev-services.log"
        cleanup 1 "FrankenPHP (Octane) failed to start on ${healthcheck_url}. See: ${dev_log}"
    fi
    log "Start-app total time so far: $(( $(now_epoch_s) - t0 ))s"

    # Workers are up and serving the current code, so any maintenance hold left
    # by an earlier update has nothing left to protect.
    heal_stale_maintenance

    # The healthcheck above only proved the loopback listener answers. Confirm
    # the URL we are about to print actually serves, and diagnose it if not.
    verify_public_reachability || true

    print_runtime_summary

    # Launch browser if available
    launch_browser "$PUBLIC_APP_URL" || true

    # Wait for background process
    wait "$DEV_PID" 2>/dev/null || true
    return 0
}

# Run main function
main "$@"
