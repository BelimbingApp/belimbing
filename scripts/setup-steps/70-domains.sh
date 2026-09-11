#!/usr/bin/env bash
# scripts/setup-steps/70-domains.sh
# Title: Domains & TLS
# Purpose: Configure the app domain, its /etc/hosts entry, and mkcert TLS certificates
# Usage: ./scripts/setup-steps/70-domains.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Prompts for (or reuses) the app domain
# - Adds the domain to /etc/hosts (and Windows hosts on WSL2)
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
readonly TLS_MODE_KEY="TLS_MODE"
readonly MKCERT_MODE="mkcert"

# Prompt user for a custom domain with a default
# Returns: frontend_domain (only this goes to stdout)
# Default: from .env ($FRONTEND_DOMAIN_KEY) if set, else from get_default_domain.
# When the domain is already set in .env, returns it without prompting.
prompt_for_domain() {
    local default_frontend
    default_frontend=$(get_default_domain "$APP_ENV")

    # Prefer .env / setup state if present
    default_frontend=$(get_env_var "$FRONTEND_DOMAIN_KEY" "$default_frontend")

    # If the domain is already configured (e.g., by 05-environment.sh), reuse silently.
    local existing_frontend
    existing_frontend=$(get_env_var "$FRONTEND_DOMAIN_KEY" "")
    if [[ -n "$existing_frontend" ]]; then
        echo -e "${GREEN}✓${NC} Using domain from .env: ${CYAN}${existing_frontend}${NC}" >&2
        echo "$existing_frontend"
        return 0
    fi

    if [[ -t 0 ]]; then
        # All informational output goes to stderr so only the result goes to stdout
        echo -e "${CYAN}Domain Configuration${NC}" >&2
        echo "" >&2
        local custom_frontend
        custom_frontend=$(ask_input "Domain" "$default_frontend")
        # Use default if empty (shouldn't happen since default is provided, but safety check)
        [[ -z "$custom_frontend" ]] && custom_frontend="$default_frontend"

        # Validate domain (output to stderr)
        if ! is_valid_domain "$custom_frontend"; then
            echo -e "${YELLOW}⚠${NC} Domain format may be invalid: ${CYAN}$custom_frontend${NC}" >&2
        fi

        # Only the result goes to stdout
        echo "$custom_frontend"
    else
        # Non-interactive: use the default
        echo "$default_frontend"
    fi
    return 0
}

# Generate mkcert certificates for the given domain.
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

    echo -e "${YELLOW}⚠${NC} $MKCERT_MODE root CA trust installation failed — browser warnings may remain"
    return 1
}

ensure_tls_certs() {
    local frontend_domain=$1
    local certs_dir="$PROJECT_ROOT/certs"
    mkdir -p "$certs_dir"

    if ! ensure_mkcert_available; then
        echo -e "${YELLOW}⚠${NC} $MKCERT_MODE not found — FrankenPHP/Caddy will use an internal CA (browser warnings expected)"
        echo -e "${CYAN}ℹ${NC} Install $MKCERT_MODE for trusted local HTTPS: ${CYAN}https://github.com/FiloSottile/mkcert${NC}"
        return 0
    fi

    trust_mkcert_root || true

    if [[ -f "$certs_dir/${frontend_domain}.pem" ]] && [[ -f "$certs_dir/${frontend_domain}-key.pem" ]]; then
        echo -e "${GREEN}✓${NC} TLS certificates already exist"
        update_env_file "$TLS_MODE_KEY" "$MKCERT_MODE"
        save_to_setup_state "$TLS_MODE_KEY" "$MKCERT_MODE"
        return 0
    fi

    echo -e "${CYAN}Generating $MKCERT_MODE certificates...${NC}"
    if "$MKCERT_MODE" -cert-file "$certs_dir/${frontend_domain}.pem" \
           -key-file "$certs_dir/${frontend_domain}-key.pem" \
           "$frontend_domain"; then
        echo -e "${GREEN}✓${NC} TLS certificates generated (trusted by $MKCERT_MODE)"
        update_env_file "$TLS_MODE_KEY" "$MKCERT_MODE"
        save_to_setup_state "$TLS_MODE_KEY" "$MKCERT_MODE"
    else
        echo -e "${YELLOW}⚠${NC} $MKCERT_MODE failed — FrankenPHP/Caddy will use an internal CA (browser warnings expected)"
    fi
    return 0
}

# Main setup function
main() {
    print_section_banner "Domains & TLS - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Prompt for the domain (or reuse from .env)
    local frontend_domain
    frontend_domain=$(prompt_for_domain)

    # Save the domain to setup state and .env (also derives APP_URL)
    save_to_setup_state "$FRONTEND_DOMAIN_KEY" "$frontend_domain"
    save_domain_to_env "$frontend_domain"

    # Add the domain to /etc/hosts if missing
    echo ""
    ensure_domain_in_hosts "$frontend_domain"

    # Generate TLS certificates
    echo ""
    ensure_tls_certs "$frontend_domain"

    echo ""
    echo -e "${GREEN}✓${NC} Domain configured"
    echo -e "  ${CYAN}${frontend_domain}${NC}"
    echo ""
    echo -e "${GREEN}✓ Domain & TLS setup complete!${NC}"
    return 0
}

# Run main function
main "$@"
