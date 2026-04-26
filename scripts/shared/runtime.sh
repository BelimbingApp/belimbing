#!/bin/bash
# Runtime directory management utilities for Belimbing scripts
# Manages script runtime files within Laravel's storage/ directory structure

# Source colors if not already loaded
RUNTIME_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${RED:-}" ]]; then
    source "$RUNTIME_SCRIPT_DIR/colors.sh"
fi

# detect_os is expected to be available from validation.sh
# If not loaded, define a minimal version
if ! command -v detect_os >/dev/null 2>&1; then
    detect_os() {
        if [[ "$OSTYPE" == "darwin"* ]]; then
            echo "macos"
        elif grep -qEi "(Microsoft|WSL)" /proc/version 2>/dev/null; then
            echo "wsl2"
        else
            echo "linux"
        fi
        return 0
    }
fi

# Predicate: pid is alive AND its working directory is exactly $2.
# Used to scope process-killing to a single BLB checkout so that, for
# example, stopping ../sbg/belimbing never touches ../laravel/blb.
# Linux uses /proc/<pid>/cwd; macOS/BSD fall back to lsof.  When neither
# resolves a cwd we conservatively return false (better to leave a
# foreign process alone than risk killing it).
pid_belongs_to_project() {
    local pid=$1
    local project_root=$2
    [[ -n "$pid" && "$pid" =~ ^[0-9]+$ ]] || return 1
    [[ -n "$project_root" ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1

    local cwd=""
    if [[ -r "/proc/$pid/cwd" ]]; then
        cwd=$(readlink "/proc/$pid/cwd" 2>/dev/null || true)
    elif command -v lsof >/dev/null 2>&1; then
        cwd=$(lsof -p "$pid" -d cwd -Fn 2>/dev/null | awk '/^n/{print substr($0,2); exit}')
    fi
    [[ -n "$cwd" && "$cwd" = "$project_root" ]]
}

# Ensure storage directory structure exists for script runtime files
# Uses Laravel's standard storage/ directory structure for consistency
# - storage/app/.devops/: Script runtime files (PIDs, setup state)
# - storage/logs/scripts/: Script logs (devops/deployment scripts)
# - storage/app/backups/: Database/backup files (script-managed)
# This simplifies drive mounting (one directory) and follows Laravel conventions
ensure_storage_dirs() {
    local project_root=$1

    if [[ -z "$project_root" ]]; then
        echo -e "${RED}${CROSS_MARK} Project root not provided${NC}" >&2
        return 1
    fi

    local storage_dir="$project_root/storage"
    # Script-specific subdirectories within Laravel storage/
    local dirs=(
        "app/.devops"          # PID files, setup state, script temp files
        "logs/scripts"         # Script/deployment logs
        "app/backups"          # Database/script-managed backups
    )

    for dir in "${dirs[@]}"; do
        mkdir -p "$storage_dir/$dir"
    done

    return 0
}

# Get Laravel storage directory path
get_storage_dir() {
    local project_root=$1
    echo "$project_root/storage"
    return 0
}

# Get script logs directory path (devops/script logs)
# Laravel application logs are in storage/logs/laravel.log
get_logs_dir() {
    local project_root=$1
    echo "$project_root/storage/logs/scripts"
    return 0
}

# Get backups directory path (script-managed backups)
get_backups_dir() {
    local project_root=$1
    echo "$project_root/storage/app/backups"
    return 0
}

# Get tmp directory path (script runtime files: PIDs, setup state)
get_tmp_dir() {
    local project_root=$1
    echo "$project_root/storage/app/.devops"
    return 0
}

# Get Laravel application logs directory path
get_laravel_logs_dir() {
    local project_root=$1
    echo "$project_root/storage/logs"
    return 0
}

# === Common Initialization for Setup Steps ===
# Initialize script environment for setup-step scripts
# Sets up: SETUP_STEPS_DIR, SCRIPTS_DIR, PROJECT_ROOT, and sources all utilities
# Usage: init_setup_step_script
# Note: Must be called before using any other functions
init_setup_step_script() {
    # Get script directory and project root
    SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"  # Points to scripts/setup-steps/
    SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"  # Points to scripts/
    PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"  # Points to project root

    # Source shared utilities (order matters: config.sh before validation.sh)
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
    return 0
}

# Clean script runtime directories (removes only script files, not Laravel app files)
clean_script_dirs() {
    local project_root=$1
    local storage_dir="$project_root/storage"

    if [[ ! -d "$storage_dir" ]]; then
        echo -e "${YELLOW}${INFO_MARK} storage/ directory does not exist${NC}"
        return 0
    fi

    echo -e "${YELLOW}${INFO_MARK} Cleaning script runtime directories...${NC}"

    # Remove only script-specific directories, preserve Laravel app files
    [[ -d "$storage_dir/app/.devops" ]] && rm -rf "${storage_dir}/app/.devops"/*
    [[ -d "$storage_dir/logs/scripts" ]] && rm -rf "${storage_dir}/logs/scripts"/*
    [[ -d "$storage_dir/app/backups" ]] && rm -rf "${storage_dir}/app/backups"/*

    echo -e "${GREEN}${CHECK_MARK}${NC} Cleaned"
    return 0
}

# Show Belimbing ASCII art banner
show_banner() {
    echo -e -n "${MAGENTA}"
    cat << 'EOF' | head -c -1
   ██████╗ ███████╗██╗     ██╗███╗   ███╗██████╗ ██╗███╗   ██╗ ██████╗
   ██╔══██╗██╔════╝██║     ██║████╗ ████║██╔══██╗██║████╗  ██║██╔════╝
   ██████╔╝█████╗  ██║     ██║██╔████╔██║██████╔╝██║██╔██╗ ██║██║  ███╗
   ██╔══██╗██╔══╝  ██║     ██║██║╚██╔╝██║██╔══██╗██║██║╚██╗██║██║   ██║
   ██████╔╝███████╗███████╗██║██║ ╚═╝ ██║██████╔╝██║██║ ╚████║╚██████╔╝
   ╚═════╝ ╚══════╝╚══════╝╚═╝╚═╝     ╚═╝╚═════╝ ╚═╝╚═╝  ╚═══╝ ╚═════╝
EOF
    echo -e "${NC}"
    return 0
}

# Print a section banner with magenta top and bottom lines
# Usage: print_section_banner "Title Text" [color]
#   or:  print_section_banner "${GREEN}✨ Ready!${NC} ${WHITE}Ctrl+C to stop"
#
# The title text supports inline color codes and formatting.
# Optional second parameter sets the line color (default: magenta)
print_section_banner() {
    local title="$1"
    local color="${2:-$MAGENTA}"  # Default to magenta if no color specified

    # Prepend step counter when run by setup.sh (exports BLB_STEP and BLB_STEP_TOTAL)
    if [[ -n "${BLB_STEP:-}" ]] && [[ -n "${BLB_STEP_TOTAL:-}" ]]; then
        title="[${BLB_STEP}/${BLB_STEP_TOTAL}] ${title}"
    fi

    echo ""
    echo -e "${color}════════════════════════════════════════════════════════════${NC}"
    echo -e "${color}${BOLD}  ${title}${NC}"
    echo -e "${color}════════════════════════════════════════════════════════════${NC}"
    echo ""
    return 0
}

# Print a subsection header with horizontal lines
# Usage: print_subsection_header "Subsection Title"
#   or:  print_subsection_header "${CYAN}Step 1/4:${NC} Environment Setup"
#
# The title supports inline color codes and formatting.
print_subsection_header() {
    local title="$1"

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "  ${title}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    return 0
}

# Print a simple horizontal divider line
# Usage: print_divider
print_divider() {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    return 0
}

# Launch browser helper (WSL2-friendly)
launch_browser() {
    local url=$1
    local os_type
    os_type="$(detect_os)"

    case "$os_type" in
        macos)
            if command -v open >/dev/null 2>&1; then
                open "$url" >/dev/null 2>&1 &
                return 0
            fi
            ;;
        linux)
            if command -v xdg-open >/dev/null 2>&1; then
                xdg-open "$url" >/dev/null 2>&1 &
                return 0
            fi
            ;;
        wsl2)
            if command -v chromium-browser >/dev/null 2>&1; then
                chromium-browser "$url" >/dev/null 2>&1 &
                return 0
            fi
            if command -v chromium >/dev/null 2>&1; then
                chromium "$url" >/dev/null 2>&1 &
                return 0
            fi
            if command -v google-chrome >/dev/null 2>&1; then
                google-chrome "$url" >/dev/null 2>&1 &
                return 0
            fi
            if command -v firefox >/dev/null 2>&1; then
                firefox "$url" >/dev/null 2>&1 &
                return 0
            fi
            if command -v xdg-open >/dev/null 2>&1; then
                xdg-open "$url" >/dev/null 2>&1 &
                return 0
            fi
            if command -v sensible-browser >/dev/null 2>&1; then
                sensible-browser "$url" >/dev/null 2>&1 &
                return 0
            fi
            ;;
        *) ;;
    esac

    echo -e "${YELLOW}${INFO_MARK} Couldn't auto-launch browser. Open manually: ${CYAN}$url${NC}"
    return 1
}

# Stop development services (Laravel, Vite, concurrently) for ONE project.
# Usage: stop_dev_services [environment] [backend_port] [frontend_port] [project_root]
#
# project_root scopes the kill: only `concurrently` processes whose cwd
# equals it are terminated.  Without that scoping, a stop in one BLB
# checkout would also terminate `concurrently` running in any sibling
# checkout (the previous behavior killed all concurrently processes
# system-wide).  The per-port kills are already project-specific because
# the caller passes the ports observed for *this* project.
#
# If project_root is not provided we try $PROJECT_ROOT, then `git
# rev-parse`, then `pwd`.  If port arguments are not provided we resolve
# them via get_backend_port / get_frontend_port (when available) or fall
# back to the env defaults.
stop_dev_services() {
    local environment="${1:-local}"
    local backend_port="${2:-}"
    local frontend_port="${3:-}"
    local project_root="${4:-${PROJECT_ROOT:-}}"

    if [[ -z "$project_root" ]]; then
        project_root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
    fi

    # Get ports if not provided
    if [[ -z "$backend_port" ]] || [[ -z "$frontend_port" ]]; then
        if command -v get_backend_port >/dev/null 2>&1 && command -v get_frontend_port >/dev/null 2>&1; then
            backend_port=$(get_backend_port "$environment" "$project_root" 2>/dev/null || echo "")
            frontend_port=$(get_frontend_port "$environment" "$project_root" 2>/dev/null || echo "")
        fi

        # Fallback to defaults
        case "$environment" in
            local)
                backend_port="${backend_port:-8000}"
                frontend_port="${frontend_port:-5173}"
                ;;
            staging)
                backend_port="${backend_port:-8001}"
                frontend_port="${frontend_port:-5174}"
                ;;
            production)
                backend_port="${backend_port:-8002}"
                frontend_port="${frontend_port:-5175}"
                ;;
            testing)
                backend_port="${backend_port:-8003}"
                frontend_port="${frontend_port:-5176}"
                ;;
            *)
                backend_port="${backend_port:-8000}"
                frontend_port="${frontend_port:-5173}"
                ;;
        esac
    fi

    # Stop concurrently processes that belong to THIS project only.
    local candidate_pids candidate
    local our_pids=()
    candidate_pids=$(pgrep -f "concurrently" 2>/dev/null || true)
    for candidate in $candidate_pids; do
        if pid_belongs_to_project "$candidate" "$project_root"; then
            our_pids+=("$candidate")
        fi
    done

    if [[ ${#our_pids[@]} -gt 0 ]]; then
        echo -e "${CYAN}Stopping concurrently processes for ${project_root} (${#our_pids[@]} pid(s))...${NC}"
        kill -TERM "${our_pids[@]}" 2>/dev/null || true
        sleep 1
        local survivors=()
        for candidate in "${our_pids[@]}"; do
            if kill -0 "$candidate" 2>/dev/null; then
                survivors+=("$candidate")
            fi
        done
        if [[ ${#survivors[@]} -gt 0 ]]; then
            kill -KILL "${survivors[@]}" 2>/dev/null || true
        fi
    fi

    # Stop processes by port (graceful then force).  Ports were chosen
    # for this project, so any holder of them is ours by construction.
    stop_port_by_number() {
        local port=$1
        local service_name=$2
        local port_pids

        port_pids=$(lsof -ti:"$port" 2>/dev/null || true)
        if [[ -n "$port_pids" ]]; then
            echo -e "${CYAN}Stopping $service_name (port $port)...${NC}"
            echo "$port_pids" | xargs kill -TERM 2>/dev/null || true
            sleep 0.5
            port_pids=$(lsof -ti:"$port" 2>/dev/null || true)
            if [[ -n "$port_pids" ]]; then
                echo "$port_pids" | xargs kill -9 2>/dev/null || true
            fi
        fi
        return 0
    }

    stop_port_by_number "$backend_port" "Laravel server"
    stop_port_by_number "$frontend_port" "Vite dev server"

    # Wait for output to flush
    sleep 0.5
    return 0
}
