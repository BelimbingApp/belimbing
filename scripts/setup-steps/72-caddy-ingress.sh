#!/usr/bin/env bash
# scripts/setup-steps/72-caddy-ingress.sh
# Title: Ingress Mode & System Caddy
# Purpose: Configure native ingress mode and optional system Caddy integration
# Usage: ./scripts/setup-steps/72-caddy-ingress.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh

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
readonly INGRESS_MODE_KEY='BLB_INGRESS_MODE'
readonly DIRECT_MODE='direct'
readonly SHARED_MODE='shared'
readonly DEFAULT_SHARED_APP_PORT='8000'

current_ingress_mode() {
    local mode
    mode=$(get_env_var "$INGRESS_MODE_KEY" "$SHARED_MODE")

    case "$mode" in
        "$DIRECT_MODE"|"$SHARED_MODE")
            printf '%s\n' "$mode"
            ;;
        *)
            printf '%s\n' "$SHARED_MODE"
            ;;
    esac

    return 0
}

prompt_for_ingress_mode() {
    local default_mode=$1

    if [[ ! -t 0 ]]; then
        printf '%s\n' "$default_mode"
        return 0
    fi

    echo -e "${CYAN}Native ingress mode${NC}"
    echo ""
    echo -e "  1. ${GREEN}shared${NC} ${DIM}(Recommended)${NC} — system Caddy owns :80/:443 and proxies to BLB on a pinned app port"
    echo -e "  2. direct  — single-instance fallback where BLB serves HTTPS itself on :443"
    echo ""

    local default_choice='1'
    if [[ "$default_mode" = "$DIRECT_MODE" ]]; then
        default_choice='2'
    fi

    read -r -p "Choose [${default_choice}]: " ingress_choice
    ingress_choice="${ingress_choice:-$default_choice}"

    case "$ingress_choice" in
        1|shared)
            printf '%s\n' "$SHARED_MODE"
            ;;
        2|direct)
            printf '%s\n' "$DIRECT_MODE"
            ;;
        *)
            echo -e "${YELLOW}⚠${NC} Invalid choice '${ingress_choice}', defaulting to ${default_mode}"
            printf '%s\n' "$default_mode"
            ;;
    esac

    return 0
}

system_caddy_is_running() {
    if systemctl is-active --quiet caddy 2>/dev/null || pgrep -x caddy >/dev/null 2>&1; then
        return 0
    fi

    return 1
}

ensure_caddy_installed() {
    if command_exists caddy; then
        echo -e "${GREEN}✓${NC} Caddy already installed: ${CYAN}$(caddy version 2>/dev/null | head -1)${NC}"
        return 0
    fi

    local os_type
    os_type=$(detect_os)

    echo -e "${CYAN}Installing Caddy...${NC}"

    case "$os_type" in
        macos)
            if ! command_exists brew; then
                echo -e "${RED}✗${NC} Homebrew is required to install Caddy on macOS" >&2
                return 1
            fi

            brew install caddy
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                sudo apt-get update -qq
                sudo apt-get install -y -qq caddy
            elif command_exists dnf; then
                sudo dnf install -y caddy
            elif command_exists yum; then
                sudo yum install -y caddy
            else
                echo -e "${RED}✗${NC} Unsupported package manager for automatic Caddy installation" >&2
                echo -e "  Install Caddy manually, then re-run this step." >&2
                return 1
            fi
            ;;
        *)
            echo -e "${RED}✗${NC} Unsupported OS for automatic Caddy installation" >&2
            echo -e "  Install Caddy manually, then re-run this step." >&2
            return 1
            ;;
    esac

    if ! command_exists caddy; then
        echo -e "${RED}✗${NC} Caddy installation completed but executable was not found in PATH" >&2
        return 1
    fi

    echo -e "${GREEN}✓${NC} Caddy installed: ${CYAN}$(caddy version 2>/dev/null | head -1)${NC}"
    return 0
}

get_system_caddyfile_path() {
    local candidates=(
        '/etc/caddy/Caddyfile'
        '/opt/homebrew/etc/Caddyfile'
        '/usr/local/etc/Caddyfile'
    )

    local candidate
    for candidate in "${candidates[@]}"; do
        if [[ -f "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    local os_type
    os_type=$(detect_os)

    case "$os_type" in
        macos)
            if [[ -d '/opt/homebrew/etc' ]]; then
                printf '%s\n' '/opt/homebrew/etc/Caddyfile'
            else
                printf '%s\n' '/usr/local/etc/Caddyfile'
            fi
            ;;
        *)
            printf '%s\n' '/etc/caddy/Caddyfile'
            ;;
    esac

    return 0
}

ensure_caddy_service_running() {
    local os_type
    os_type=$(detect_os)

    case "$os_type" in
        macos)
            if command_exists brew; then
                brew services start caddy >/dev/null 2>&1 || true
            fi
            ;;
        linux|wsl2)
            sudo systemctl enable caddy >/dev/null 2>&1 || true
            sudo systemctl start caddy >/dev/null 2>&1 || true
            ;;
        *)
            ;;
    esac

    if system_caddy_is_running; then
        echo -e "${GREEN}✓${NC} System Caddy is running"
        return 0
    fi

    echo -e "${YELLOW}⚠${NC} Could not confirm that system Caddy is running"
    echo -e "  Start it manually after setup if needed."
    return 1
}

ensure_shared_app_port_is_pinned() {
    local app_port
    app_port=$(get_env_var 'APP_PORT' '')

    if [[ -n "$app_port" ]]; then
        echo -e "${GREEN}✓${NC} Shared-ingress app port pinned: ${CYAN}${app_port}${NC}" >&2
        printf '%s\n' "$app_port"
        return 0
    fi

    update_env_file 'APP_PORT' "$DEFAULT_SHARED_APP_PORT"
    echo -e "${GREEN}✓${NC} Pinned APP_PORT for shared ingress: ${CYAN}${DEFAULT_SHARED_APP_PORT}${NC}" >&2
    printf '%s\n' "$DEFAULT_SHARED_APP_PORT"
    return 0
}

get_generated_site_fragment_path() {
    local frontend_domain=$1
    printf '%s\n' "$PROJECT_ROOT/.caddy/system/${frontend_domain}.caddy"
    return 0
}

render_tls_directive() {
    local frontend_domain=$1
    local cert_file="$PROJECT_ROOT/certs/${frontend_domain}.pem"
    local key_file="$PROJECT_ROOT/certs/${frontend_domain}-key.pem"

    if [[ -f "$cert_file" ]] && [[ -f "$key_file" ]]; then
        printf 'tls %s %s\n' "$cert_file" "$key_file"
        return 0
    fi

    case "$APP_ENV" in
        local|testing)
            printf 'tls internal\n'
            ;;
        *)
            printf '\n'
            ;;
    esac

    return 0
}

generate_site_fragment() {
    local frontend_domain=$1
    local backend_domain=$2
    local app_port=$3
    local fragment_path
    fragment_path=$(get_generated_site_fragment_path "$frontend_domain")
    local tls_directive
    tls_directive=$(render_tls_directive "$frontend_domain")

    mkdir -p "$(dirname "$fragment_path")"

    cat > "$fragment_path" <<EOF
# Generated by scripts/setup-steps/72-caddy-ingress.sh for ${PROJECT_ROOT}
# BLB system Caddy integration for ${APP_ENV}.

${frontend_domain} {
$( [[ -n "$tls_directive" ]] && printf '    %s\n' "$tls_directive" )
    reverse_proxy 127.0.0.1:${app_port}
}

${backend_domain} {
$( [[ -n "$tls_directive" ]] && printf '    %s\n' "$tls_directive" )
    reverse_proxy 127.0.0.1:${app_port}
}
EOF

    save_to_setup_state 'SYSTEM_CADDY_SITE_FRAGMENT' "$fragment_path"
    echo -e "${GREEN}✓${NC} Generated BLB Caddy site fragment: ${CYAN}${fragment_path}${NC}" >&2
    printf '%s\n' "$fragment_path"
    return 0
}

replace_managed_block() {
    local target_file=$1
    local block_name=$2
    local replacement_file=$3
    local temp_file
    temp_file=$(mktemp)

    if [[ -f "$target_file" ]]; then
        awk -v start="# BEGIN ${block_name}" -v end="# END ${block_name}" '
            $0 == start { skip = 1; next }
            $0 == end { skip = 0; next }
            skip != 1 { print }
        ' "$target_file" > "$temp_file"
    fi

    {
        if [[ -s "$temp_file" ]]; then
            cat "$temp_file"
            echo ""
        fi
        echo "# BEGIN ${block_name}"
        cat "$replacement_file"
        echo "# END ${block_name}"
    } > "${temp_file}.new"

    mv "${temp_file}.new" "$temp_file"
    printf '%s\n' "$temp_file"
    return 0
}

install_site_fragment_into_caddyfile() {
    local fragment_path=$1
    local caddyfile_path=$2
    local managed_block_name
    managed_block_name="BLB ${PROJECT_ROOT}"
    local rendered_file
    rendered_file=$(replace_managed_block "$caddyfile_path" "$managed_block_name" "$fragment_path")

    mkdir -p "$(dirname "$caddyfile_path")"

    if [[ -w "$caddyfile_path" ]] || [[ ! -e "$caddyfile_path" && -w "$(dirname "$caddyfile_path")" ]]; then
        cp "$rendered_file" "$caddyfile_path"
    else
        sudo cp "$rendered_file" "$caddyfile_path"
    fi

    rm -f "$rendered_file"
    save_to_setup_state 'SYSTEM_CADDY_CONFIG_PATH' "$caddyfile_path"
    echo -e "${GREEN}✓${NC} Installed BLB site block into ${CYAN}${caddyfile_path}${NC}"
    return 0
}

validate_and_reload_caddy() {
    local caddyfile_path=$1

    echo -e "${CYAN}Validating Caddy configuration...${NC}"
    caddy validate --config "$caddyfile_path"

    echo -e "${CYAN}Reloading Caddy configuration...${NC}"

    if system_caddy_is_running; then
        if ! caddy reload --config "$caddyfile_path" >/dev/null 2>&1; then
            local os_type
            os_type=$(detect_os)
            case "$os_type" in
                macos)
                    brew services restart caddy >/dev/null 2>&1 || true
                    ;;
                linux|wsl2)
                    sudo systemctl reload caddy >/dev/null 2>&1 || sudo systemctl restart caddy >/dev/null 2>&1 || true
                    ;;
                *)
                    ;;
            esac
        fi
    else
        ensure_caddy_service_running || true
        caddy reload --config "$caddyfile_path" >/dev/null 2>&1 || true
    fi

    return 0
}

configure_shared_ingress() {
    ensure_caddy_installed

    local app_port
    app_port=$(ensure_shared_app_port_is_pinned)

    local frontend_domain
    frontend_domain=$(get_env_var 'FRONTEND_DOMAIN' '')
    local backend_domain
    backend_domain=$(get_env_var 'BACKEND_DOMAIN' '')

    if [[ -z "$frontend_domain" ]] || [[ -z "$backend_domain" ]]; then
        echo -e "${RED}✗${NC} FRONTEND_DOMAIN and BACKEND_DOMAIN must be configured before shared ingress setup" >&2
        return 1
    fi

    local fragment_path
    fragment_path=$(generate_site_fragment "$frontend_domain" "$backend_domain" "$app_port")

    local caddyfile_path
    caddyfile_path=$(get_system_caddyfile_path)
    echo -e "${CYAN}System Caddyfile:${NC} ${caddyfile_path}"

    local install_block=true
    if [[ -t 0 ]] && ! ask_yes_no "Install or update the BLB site block in ${caddyfile_path}?" "y"; then
        install_block=false
    fi

    if [[ "$install_block" = true ]]; then
        install_site_fragment_into_caddyfile "$fragment_path" "$caddyfile_path"
        validate_and_reload_caddy "$caddyfile_path"
    else
        echo -e "${YELLOW}⚠${NC} Skipped installing the system Caddy block"
        echo -e "  Install the generated fragment manually: ${CYAN}${fragment_path}${NC}"
    fi

    ensure_caddy_service_running || true
    return 0
}

main() {
    print_section_banner "Ingress Mode & System Caddy - Belimbing ($APP_ENV)"

    load_setup_state

    local default_mode
    default_mode=$(current_ingress_mode)
    local selected_mode
    selected_mode=$(prompt_for_ingress_mode "$default_mode")

    update_env_file "$INGRESS_MODE_KEY" "$selected_mode"
    save_to_setup_state "$INGRESS_MODE_KEY" "$selected_mode"

    echo ""
    echo -e "${GREEN}✓${NC} Ingress mode: ${CYAN}${selected_mode}${NC}"

    if [[ "$selected_mode" = "$SHARED_MODE" ]]; then
        echo ""
        configure_shared_ingress
    else
        echo -e "${CYAN}ℹ${NC} Direct mode selected — BLB will serve HTTPS itself when no system Caddy is active"
        echo -e "${CYAN}ℹ${NC} Shared ingress remains the recommended topology for multi-site or long-lived hosts"
    fi

    echo ""
    echo -e "${GREEN}✓ Ingress setup complete!${NC}"
    return 0
}

main "$@"
