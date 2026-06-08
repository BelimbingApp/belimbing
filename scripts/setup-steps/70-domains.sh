#!/usr/bin/env bash
# scripts/setup-steps/70-domains.sh
# Title: Domains & TLS
# Purpose: Configure custom domains, /etc/hosts entries, and mkcert TLS certificates
# Usage: ./scripts/setup-steps/70-domains.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Prompts for (or reuses) frontend and backend domains
# - Adds domains to /etc/hosts (and Windows hosts on WSL2)
# - Generates mkcert certificates for local HTTPS

set -euo pipefail

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
source "$SCRIPTS_DIR/shared/config.sh"
# shellcheck source=../shared/validation.sh
source "$SCRIPTS_DIR/shared/validation.sh"
# shellcheck source=../shared/interactive.sh
source "$SCRIPTS_DIR/shared/interactive.sh"

# Environment (default to local if not provided, using Laravel standard)
APP_ENV="${1:-local}"
readonly FRONTEND_DOMAIN_KEY="FRONTEND_DOMAIN"
readonly BACKEND_DOMAIN_KEY="BACKEND_DOMAIN"
readonly TLS_MODE_KEY="TLS_MODE"
readonly MKCERT_MODE="mkcert"

# Prompt user for custom domains with defaults
# Returns: frontend_domain|backend_domain (only this goes to stdout)
# Defaults: from .env ($FRONTEND_DOMAIN_KEY, $BACKEND_DOMAIN_KEY) if set, else from get_default_domains.
# When both domains are already set in .env, returns them without prompting.
prompt_for_domains() {
    local default_domains
    default_domains=$(get_default_domains "$APP_ENV")
    local default_frontend
    local default_backend
    default_frontend=$(echo "$default_domains" | cut -d'|' -f1)
    default_backend=$(echo "$default_domains" | cut -d'|' -f2)

    # Prefer .env / setup state if present
    default_frontend=$(get_env_var "$FRONTEND_DOMAIN_KEY" "$default_frontend")
    default_backend=$(get_env_var "$BACKEND_DOMAIN_KEY" "$default_backend")

    # If both domains are already configured (e.g., by 05-environment.sh), reuse silently.
    local existing_frontend existing_backend
    existing_frontend=$(get_env_var "$FRONTEND_DOMAIN_KEY" "")
    existing_backend=$(get_env_var "$BACKEND_DOMAIN_KEY" "")
    if [[ -n "$existing_frontend" ]] && [[ -n "$existing_backend" ]]; then
        echo -e "${GREEN}✓${NC} Using domains from .env: ${CYAN}${existing_frontend}${NC} / ${CYAN}${existing_backend}${NC}" >&2
        echo "${existing_frontend}|${existing_backend}"
        return 0
    fi

    if [[ -t 0 ]]; then
        # All informational output goes to stderr so only the result goes to stdout
        echo -e "${CYAN}Domain Configuration${NC}" >&2
        echo "" >&2
        local custom_frontend
        custom_frontend=$(ask_input "Frontend domain" "$default_frontend")
        # Use default if empty (shouldn't happen since default is provided, but safety check)
        [[ -z "$custom_frontend" ]] && custom_frontend="$default_frontend"

        echo "" >&2
        local custom_backend
        custom_backend=$(ask_input "Backend domain" "$default_backend")
        # Use default if empty (shouldn't happen since default is provided, but safety check)
        [[ -z "$custom_backend" ]] && custom_backend="$default_backend"

        # Validate domains (output to stderr)
        if ! is_valid_domain "$custom_frontend"; then
            echo -e "${YELLOW}⚠${NC} Frontend domain format may be invalid: ${CYAN}$custom_frontend${NC}" >&2
        fi
        if ! is_valid_domain "$custom_backend"; then
            echo -e "${YELLOW}⚠${NC} Backend domain format may be invalid: ${CYAN}$custom_backend${NC}" >&2
        fi

        # Only the result goes to stdout
        echo "${custom_frontend}|${custom_backend}"
    else
        # Non-interactive: use defaults
        echo "${default_frontend}|${default_backend}"
    fi
    return 0
}

# Generate mkcert certificates for the given domains.
# Installs mkcert for local/test where possible; skips gracefully if unavailable.
should_use_mkcert() {
    local tls_mode
    tls_mode=$(get_env_var "$TLS_MODE_KEY" "")

    [[ "$APP_ENV" = "local" || "$APP_ENV" = "testing" || "$tls_mode" = "$MKCERT_MODE" ]]
}

ensure_mkcert_available() {
    if command_exists "$MKCERT_MODE"; then
        return 0
    fi

    if ! should_use_mkcert; then
        return 1
    fi

    local os_type
    os_type=$(detect_os)

    if install_mkcert "$os_type"; then
        return 0
    fi

    return 1
}

trust_mkcert_root() {
    if ! command_exists "$MKCERT_MODE"; then
        return 1
    fi

    if "$MKCERT_MODE" -install; then
        return 0
    fi

    echo -e "${YELLOW}⚠${NC} ${MKCERT_MODE} root CA trust installation failed — browser warnings may remain"
    return 1
}

ensure_tls_certs() {
    local frontend_domain=$1
    local backend_domain=$2
    local certs_dir="$PROJECT_ROOT/certs"
    mkdir -p "$certs_dir"

    if ! ensure_mkcert_available; then
        echo -e "${YELLOW}⚠${NC} ${MKCERT_MODE} not found — FrankenPHP/Caddy will use an internal CA (browser warnings expected)"
        echo -e "${CYAN}ℹ${NC} Install ${MKCERT_MODE} for trusted local HTTPS: ${CYAN}https://github.com/FiloSottile/${MKCERT_MODE}${NC}"
        return 0
    fi

    trust_mkcert_root || true

    if [[ -f "$certs_dir/${frontend_domain}.pem" ]] && [[ -f "$certs_dir/${frontend_domain}-key.pem" ]]; then
        echo -e "${GREEN}✓${NC} TLS certificates already exist"
        update_env_file "$TLS_MODE_KEY" "$MKCERT_MODE"
        save_to_setup_state "$TLS_MODE_KEY" "$MKCERT_MODE"
        return 0
    fi

    echo -e "${CYAN}Generating ${MKCERT_MODE} certificates...${NC}"
    if "$MKCERT_MODE" -cert-file "$certs_dir/${frontend_domain}.pem" \
           -key-file "$certs_dir/${frontend_domain}-key.pem" \
           "$frontend_domain" "$backend_domain"; then
        echo -e "${GREEN}✓${NC} TLS certificates generated (trusted by ${MKCERT_MODE})"
        update_env_file "$TLS_MODE_KEY" "$MKCERT_MODE"
        save_to_setup_state "$TLS_MODE_KEY" "$MKCERT_MODE"
    else
        echo -e "${YELLOW}⚠${NC} ${MKCERT_MODE} failed — FrankenPHP/Caddy will use an internal CA (browser warnings expected)"
    fi
    return 0
}

# Main setup function
main() {
    print_section_banner "Domains & TLS - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Prompt for domains (or reuse from .env)
    local domains
    domains=$(prompt_for_domains)
    local frontend_domain backend_domain
    frontend_domain=$(echo "$domains" | cut -d'|' -f1)
    backend_domain=$(echo "$domains" | cut -d'|' -f2)

    # Save domains to setup state and .env (also derives APP_URL)
    save_to_setup_state "$FRONTEND_DOMAIN_KEY" "$frontend_domain"
    save_to_setup_state "$BACKEND_DOMAIN_KEY" "$backend_domain"
    save_domains_to_env "$frontend_domain" "$backend_domain"

    # Add domains to /etc/hosts if missing
    echo ""
    ensure_domains_in_hosts "$frontend_domain" "$backend_domain"

    # Generate TLS certificates
    echo ""
    ensure_tls_certs "$frontend_domain" "$backend_domain"

    echo ""
    echo -e "${GREEN}✓${NC} Domains configured"
    echo -e "  ${CYAN}Frontend: ${frontend_domain}${NC}"
    echo -e "  ${CYAN}Backend:  ${backend_domain}${NC}"
    echo ""
    echo -e "${GREEN}✓ Domains & TLS setup complete!${NC}"
    return 0
}

# Run main function
main "$@"
