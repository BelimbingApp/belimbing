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

print_ingress_mode_guidance() {
    local default_mode=$1
    local frontend_domain
    frontend_domain=$(get_env_var 'FRONTEND_DOMAIN' '')
    local backend_domain
    backend_domain=$(get_env_var 'BACKEND_DOMAIN' '')
    local current_app_port
    current_app_port=$(get_env_var 'APP_PORT' '')
    local caddy_status='not installed'

    if command_exists caddy; then
        if system_caddy_is_running; then
            caddy_status='installed and running'
        else
            caddy_status='installed but not running'
        fi
    fi

    echo -e "${CYAN}How this choice works${NC}" >&2
    echo -e "  ${GREEN}shared${NC} ${DIM}(Recommended)${NC}" \
        "System Caddy owns ${CYAN}:80${NC}/${CYAN}:443${NC} and forwards traffic to BLB on an internal app port." >&2
    echo -e "           Choose this if the machine may host multiple sites or you want the most conventional local setup." >&2
    echo -e "  ${GREEN}direct${NC}              BLB serves HTTPS itself on ${CYAN}:443${NC} without writing a system Caddy site block." >&2
    echo -e "           Choose this only when BLB is the only app that should bind the public HTTPS port." >&2
    echo "" >&2
    echo -e "${CYAN}Current context${NC}" >&2
    echo -e "  Default mode: ${GREEN}${default_mode}${NC}" >&2
    echo -e "  System Caddy: ${GREEN}${caddy_status}${NC}" >&2

    if [[ -n "$current_app_port" ]]; then
        echo -e "  Current APP_PORT: ${GREEN}${current_app_port}${NC}" >&2
    fi

    if [[ -n "$frontend_domain" ]] || [[ -n "$backend_domain" ]]; then
        echo -e "  Domains: ${GREEN}${frontend_domain:-<unset>}${NC} / ${GREEN}${backend_domain:-<unset>}${NC}" >&2
    fi

    echo "" >&2
    echo -e "${CYAN}If you're unsure:${NC} choose ${GREEN}shared${NC}. The setup can install Caddy and manage a BLB block in the system Caddyfile." >&2
    echo "" >&2
    return 0
}

prompt_for_ingress_mode() {
    local default_mode=$1

    if [[ ! -t 0 ]]; then
        printf '%s\n' "$default_mode"
        return 0
    fi

    local default_choice='1'
    if [[ "$default_mode" = "$DIRECT_MODE" ]]; then
        default_choice='2'
    fi

    echo -e "${CYAN}Native ingress mode${NC}" >&2
    echo "" >&2
    print_ingress_mode_guidance "$default_mode"
    echo -e "  1. ${GREEN}shared${NC} ${DIM}(Recommended)${NC}" \
        "— system Caddy owns ${CYAN}:80${NC}/${CYAN}:443${NC}; BLB listens on an internal app port" >&2
    echo -e "  2. ${GREEN}direct${NC}" \
        "             — BLB owns ${CYAN}:443${NC} directly; no managed system Caddy site block" >&2
    echo "" >&2

    local ingress_choice
    while true; do
        read -r -p "Choose [${default_choice}]: " ingress_choice
        ingress_choice="${ingress_choice:-$default_choice}"

        case "$ingress_choice" in
            1|shared)
                printf '%s\n' "$SHARED_MODE"
                return 0
                ;;
            2|direct)
                printf '%s\n' "$DIRECT_MODE"
                return 0
                ;;
            *)
                echo -e "${YELLOW}⚠${NC} Enter ${CYAN}1${NC} for ${GREEN}shared${NC} or ${CYAN}2${NC} for ${GREEN}direct${NC}." >&2
                ;;
        esac
    done

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

get_system_caddy_include_dir() {
    local caddyfile_path=$1
    printf '%s\n' "$(dirname "$caddyfile_path")/blb"
    return 0
}

get_installed_site_fragment_path() {
    local caddyfile_path=$1
    local frontend_domain=$2
    local include_dir
    include_dir=$(get_system_caddy_include_dir "$caddyfile_path")
    printf '%s\n' "${include_dir}/${frontend_domain}.caddy"
    return 0
}

# Copy project cert files into the system Caddy include directory so the
# caddy service user can read them (the project tree is often inside a home
# directory that is not traversable by system daemons).
# Prints the destination cert directory path on stdout.
provision_certs_for_system_caddy() {
    local caddyfile_path=$1
    local frontend_domain=$2
    local src_cert="$PROJECT_ROOT/certs/${frontend_domain}.pem"
    local src_key="$PROJECT_ROOT/certs/${frontend_domain}-key.pem"
    local include_dir
    include_dir=$(get_system_caddy_include_dir "$caddyfile_path")
    local dest_dir="${include_dir}/certs"
    local dest_cert="${dest_dir}/${frontend_domain}.pem"
    local dest_key="${dest_dir}/${frontend_domain}-key.pem"

    copy_file_with_elevation_if_needed "$src_cert" "$dest_cert"
    copy_file_with_elevation_if_needed "$src_key" "$dest_key"

    # Ensure the caddy service user can read the copied files
    if id caddy >/dev/null 2>&1; then
        sudo chown caddy:caddy "$dest_cert" "$dest_key" 2>/dev/null || true
    fi
    chmod 644 "$dest_cert" 2>/dev/null || sudo chmod 644 "$dest_cert"
    chmod 640 "$dest_key" 2>/dev/null || sudo chmod 640 "$dest_key"

    printf '%s\n' "$dest_dir"
    return 0
}

render_tls_directive() {
    local frontend_domain=$1
    local cert_dir="${2:-$PROJECT_ROOT/certs}"
    local cert_file="${cert_dir}/${frontend_domain}.pem"
    local key_file="${cert_dir}/${frontend_domain}-key.pem"

    if [[ -f "$cert_file" ]] && [[ -f "$key_file" ]]; then
        printf 'tls %s %s\n' "$cert_file" "$key_file"
        return 0
    fi

    if [[ "$APP_ENV" = 'local' || "$APP_ENV" = 'testing' ]]; then
        printf 'tls internal\n'
        return 0
    fi

    printf '\n'

    return 0
}

generate_site_fragment() {
    local frontend_domain=$1
    local backend_domain=$2
    local app_port=$3
    local cert_dir="${4:-$PROJECT_ROOT/certs}"
    local fragment_path
    fragment_path=$(get_generated_site_fragment_path "$frontend_domain")
    local tls_directive
    tls_directive=$(render_tls_directive "$frontend_domain" "$cert_dir")

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

copy_file_with_elevation_if_needed() {
    local source_path=$1
    local target_path=$2

    mkdir -p "$(dirname "$source_path")"

    if [[ -w "$target_path" ]] || [[ ! -e "$target_path" && -w "$(dirname "$target_path")" ]]; then
        mkdir -p "$(dirname "$target_path")"
        cp "$source_path" "$target_path"
    else
        sudo mkdir -p "$(dirname "$target_path")"
        sudo cp "$source_path" "$target_path"
    fi

    return 0
}

install_site_fragment_into_include_dir() {
    local fragment_path=$1
    local caddyfile_path=$2
    local frontend_domain=$3
    local installed_fragment_path
    installed_fragment_path=$(get_installed_site_fragment_path "$caddyfile_path" "$frontend_domain")

    copy_file_with_elevation_if_needed "$fragment_path" "$installed_fragment_path"

    save_to_setup_state 'SYSTEM_CADDY_SITE_FRAGMENT' "$installed_fragment_path"
    echo -e "${GREEN}✓${NC} Installed BLB site file: ${CYAN}${installed_fragment_path}${NC}"
    return 0
}

install_caddy_import_into_caddyfile() {
    local caddyfile_path=$1
    local managed_block_name
    managed_block_name="BLB ${PROJECT_ROOT}"
    local include_dir
    include_dir=$(get_system_caddy_include_dir "$caddyfile_path")
    local import_file
    import_file=$(mktemp)

    cat > "$import_file" <<EOF
import ${include_dir}/*.caddy
EOF

    local rendered_file
    rendered_file=$(replace_managed_block "$caddyfile_path" "$managed_block_name" "$import_file")

    copy_file_with_elevation_if_needed "$rendered_file" "$caddyfile_path"

    rm -f "$import_file"
    rm -f "$rendered_file"
    save_to_setup_state 'SYSTEM_CADDY_CONFIG_PATH' "$caddyfile_path"
    echo -e "${GREEN}✓${NC} Installed BLB import block into ${CYAN}${caddyfile_path}${NC}"
    return 0
}

validate_and_reload_caddy() {
    local caddyfile_path=$1
    local reload_output
    local formatted_file

    echo -e "${CYAN}Formatting Caddy configuration...${NC}"
    formatted_file=$(mktemp)
    cp "$caddyfile_path" "$formatted_file"
    caddy fmt --overwrite "$formatted_file" >/dev/null

    if [[ -w "$caddyfile_path" ]]; then
        cp "$formatted_file" "$caddyfile_path"
    else
        sudo cp "$formatted_file" "$caddyfile_path"
    fi

    rm -f "$formatted_file"

    echo -e "${CYAN}Validating Caddy configuration...${NC}"
    caddy validate --config "$caddyfile_path"

    echo -e "${CYAN}Reloading Caddy configuration...${NC}"

    if system_caddy_is_running; then
        if ! reload_output=$(caddy reload --config "$caddyfile_path" 2>&1); then
            echo -e "${RED}✗${NC} Failed to reload system Caddy" >&2
            echo "$reload_output" >&2
            echo "" >&2
            echo -e "${YELLOW}Likely cause:${NC} the running Caddy service cannot read one of the files referenced by the config." >&2
            echo -e "Inspect the paths in ${CYAN}${caddyfile_path}${NC} and ensure the ${CYAN}caddy${NC} service user can access them." >&2
            return 1
        fi

        echo -e "${GREEN}✓${NC} Caddy configuration reloaded"
        return 0
    else
        ensure_caddy_service_running || true
        if ! reload_output=$(caddy reload --config "$caddyfile_path" 2>&1); then
            echo -e "${RED}✗${NC} Failed to reload system Caddy after attempting to start it" >&2
            echo "$reload_output" >&2
            return 1
        fi

        echo -e "${GREEN}✓${NC} Caddy configuration reloaded"
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

    local caddyfile_path
    caddyfile_path=$(get_system_caddyfile_path)
    echo -e "${CYAN}System Caddyfile:${NC} ${caddyfile_path}"
    echo -e "${CYAN}BLB site include directory:${NC} $(get_system_caddy_include_dir "$caddyfile_path")"

    # If mkcert certs exist, copy them into the system Caddy include dir so the
    # caddy service user can read them (project tree may be inside a restricted home dir).
    local system_cert_dir="$PROJECT_ROOT/certs"
    local src_cert="$PROJECT_ROOT/certs/${frontend_domain}.pem"
    local src_key="$PROJECT_ROOT/certs/${frontend_domain}-key.pem"
    if [[ -f "$src_cert" ]] && [[ -f "$src_key" ]]; then
        system_cert_dir=$(provision_certs_for_system_caddy "$caddyfile_path" "$frontend_domain")
        echo -e "${GREEN}✓${NC} Provisioned certs for system Caddy: ${CYAN}${system_cert_dir}${NC}"
    fi

    local fragment_path
    fragment_path=$(generate_site_fragment "$frontend_domain" "$backend_domain" "$app_port" "$system_cert_dir")

    local install_block=true
    if [[ -t 0 ]] && ! ask_yes_no "Install or update the BLB include import and site file?" "y"; then
        install_block=false
    fi

    if [[ "$install_block" = true ]]; then
        install_site_fragment_into_include_dir "$fragment_path" "$caddyfile_path" "$frontend_domain"
        install_caddy_import_into_caddyfile "$caddyfile_path"
        validate_and_reload_caddy "$caddyfile_path"
    else
        echo -e "${YELLOW}⚠${NC} Skipped installing the system Caddy include file and import"
        echo -e "  Generated BLB site file: ${CYAN}${fragment_path}${NC}"
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
