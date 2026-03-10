#!/usr/bin/env bash
# scripts/setup-steps/70-caddy.sh
# Title: Reverse Proxy (Caddy)
# Purpose: Install and configure Caddy reverse proxy for Belimbing
# Usage: ./scripts/setup-steps/70-caddy.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# This script:
# - Detects existing reverse proxies
# - Installs Caddy if selected
# - Configures Caddyfile
# - Sets PROXY_TYPE variable

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
# shellcheck source=../shared/caddy.sh
source "$SCRIPTS_DIR/shared/caddy.sh"

# Environment (default to local if not provided, using Laravel standard)
APP_ENV="${1:-local}"
readonly FRONTEND_DOMAIN_KEY="FRONTEND_DOMAIN"
readonly BACKEND_DOMAIN_KEY="BACKEND_DOMAIN"

# Prompt user for custom domains with defaults
# Returns: frontend_domain|backend_domain (only this goes to stdout)
# Defaults: from .env ($FRONTEND_DOMAIN_KEY, $BACKEND_DOMAIN_KEY) if set, else from get_default_domains.
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

# Extract domains from existing Belimbing config in Caddyfile
# Returns: frontend_domain|backend_domain or empty if not found
extract_belimbing_domains() {
    local caddyfile=$1

    if [[ ! -f "$caddyfile" ]]; then
        return 1
    fi

    # Check if Belimbing block exists
    if ! grep -q "# Belimbing configuration" "$caddyfile" 2>/dev/null; then
        return 1
    fi

    # Extract domains from site address lines in Belimbing section
    # Support both:
    # - https://domain:port {
    # - https://domain {
    # - domain {
    local in_belimbing_block=false
    local found_first_https=false
    local frontend_domain=""
    local backend_domain=""

    while IFS= read -r line; do
        # Check if we're entering Belimbing block
        if [[ "$line" =~ "# Belimbing configuration" ]]; then
            in_belimbing_block=true
            continue
        fi

        # Only check for end of block after we've seen at least one https:// line
        # This allows comments like "# Environment: local" to be part of the block header
        if [[ "$in_belimbing_block" = true ]] && [[ "$found_first_https" = true ]] && [[ "$line" =~ ^# ]]; then
            break
        fi

        # Extract domain from site address lines (top-level, non-indented) in Belimbing block
        if [[ "$in_belimbing_block" = true ]] && [[ "$line" =~ ^[^[:space:]] ]] && [[ "$line" == *"{"* ]]; then
            found_first_https=true
            # Extract first token, then strip scheme, port, and trailing "{"
            local addr
            addr=$(echo "$line" | awk '{print $1}')
            addr="${addr#https://}"
            addr="${addr%%\{}"
            addr="${addr%%:*}"
            if [[ -n "$addr" ]]; then
                if [[ -z "$frontend_domain" ]]; then
                    frontend_domain="$addr"
                elif [[ -z "$backend_domain" ]]; then
                    backend_domain="$addr"
                    break
                fi
            fi
        fi
    done < "$caddyfile"

    if [[ -n "$frontend_domain" ]] && [[ -n "$backend_domain" ]]; then
        echo "${frontend_domain}|${backend_domain}"
        return 0
    fi

    return 1
}

# Auto-configure existing Caddy by adding Belimbing config block
# Adds configuration to existing Caddyfile or creates new one
configure_existing_caddy() {
    local frontend_domain=$1
    local backend_domain=$2
    local frontend_port=$3
    local backend_port=$4
    local https_port=$5

    echo -e "${CYAN}Auto-configuring existing Caddy installation...${NC}"

    # Find Caddyfile location (common locations)
    local caddyfile_locations=(
        "/etc/caddy/Caddyfile"
        "$HOME/.config/caddy/Caddyfile"
        "$PROJECT_ROOT/Caddyfile"
        "/usr/local/etc/caddy/Caddyfile"
    )

    local caddyfile=""
    local is_system_file=false
    local is_project_file=false

    # Find Caddyfile location
    for location in "${caddyfile_locations[@]}"; do
        if [[ -f "$location" ]]; then
            caddyfile="$location"
            # Check if it's a system file (never touch these)
            if [[ "$caddyfile" =~ ^/(etc|usr/local/etc)/ ]]; then
                is_system_file=true
            elif [[ "$caddyfile" = "$PROJECT_ROOT/Caddyfile" ]]; then
                is_project_file=true
            fi
            break
        fi
    done

    # If no Caddyfile found, try to detect from Caddy process
    if [[ -z "$caddyfile" ]] && command_exists caddy; then
        local caddy_pid
        caddy_pid=$(pgrep -x caddy | head -1)
        if [[ -n "$caddy_pid" ]]; then
            echo -e "${YELLOW}⚠${NC} Caddy is running but Caddyfile location not detected"
        fi
    fi

    # Belimbing's canonical configuration is the repo-root Caddyfile.
    # We never modify system Caddyfiles. If a system Caddyfile exists, we still rely on
    # running a project-specific Caddy instance using $PROJECT_ROOT/Caddyfile (via start-app.sh).
    caddyfile="$PROJECT_ROOT/Caddyfile"
    if [[ "$is_system_file" = true ]]; then
        echo -e "${CYAN}ℹ${NC} System Caddyfile detected: ${CYAN}$caddyfile${NC}"
        echo -e "${CYAN}ℹ${NC} Belimbing will use the project Caddyfile: ${CYAN}$PROJECT_ROOT/Caddyfile${NC}"
    fi

    if [[ ! -f "$caddyfile" ]]; then
        echo -e "${RED}✗${NC} Missing project Caddyfile: ${CYAN}$caddyfile${NC}" >&2
        echo -e "${CYAN}ℹ${NC} Restore it from the repo, then re-run this step." >&2
        return 1
    fi

    # We no longer generate/patch a project-specific Caddyfile.
    # The canonical `Caddyfile` is variable-driven and used by start-app.sh.

    # Create certs directory if it doesn't exist
    local certs_dir="$PROJECT_ROOT/certs"
    mkdir -p "$certs_dir"

    # Generate self-signed certificates if they don't exist
    if [[ ! -f "$certs_dir/${frontend_domain}.pem" ]]; then
        echo -e "${CYAN}Generating self-signed certificates...${NC}"
        if command_exists mkcert; then
            # Use mkcert for trusted local certificates
            if [[ ! -f "$certs_dir/${frontend_domain}.pem" ]]; then
                mkcert -cert-file "$certs_dir/${frontend_domain}.pem" \
                       -key-file "$certs_dir/${frontend_domain}-key.pem" \
                       "$frontend_domain" "$backend_domain" 2>/dev/null || true
            fi
        else
            # Fallback: generate basic self-signed cert (will show browser warning)
            echo -e "${YELLOW}⚠${NC} mkcert not found, generating basic self-signed certificate"
            echo -e "${CYAN}ℹ${NC} Install mkcert for trusted local certificates: ${CYAN}https://github.com/FiloSottile/mkcert${NC}"
        fi
    fi

    # Provide instructions
    echo ""
    echo -e "${CYAN}ℹ${NC} Project Caddyfile: ${CYAN}$PROJECT_ROOT/Caddyfile${NC}"
    echo -e "${CYAN}ℹ${NC} Start via: ${CYAN}./scripts/start-app.sh${NC}"

    return 0
}

# Main setup function
main() {
    print_section_banner "Reverse Proxy Setup - Belimbing ($APP_ENV)"

    # Load existing configuration
    load_setup_state

    # Detect existing proxy first
    local existing_proxy
    existing_proxy=$(detect_proxy)

    # Check if already configured AND proxy is still running/valid
    if [[ -n "${PROXY_TYPE:-}" ]]; then
        # Only prompt if the previously chosen proxy is still active/valid
        local should_prompt=false

        if [[ "$PROXY_TYPE" = "caddy" ]] && [[ "$existing_proxy" = "caddy" ]]; then
            # Caddy was chosen AND is currently running
            should_prompt=true
        elif [[ "$PROXY_TYPE" = "nginx" ]] || [[ "$PROXY_TYPE" = "apache" ]] || [[ "$PROXY_TYPE" = "traefik" ]]; then
            # Manual proxy was chosen AND matches what's running
            if [[ "$existing_proxy" = "$PROXY_TYPE" ]]; then
                should_prompt=true
            fi
        elif [[ "$PROXY_TYPE" = "manual" ]] && [[ "$existing_proxy" != "none" ]]; then
            # Generic manual choice AND some proxy is running
            should_prompt=true
        elif [[ "$PROXY_TYPE" = "none" ]]; then
            # "None" is always valid (no proxy needed)
            should_prompt=true
        fi

        if [[ "$should_prompt" = true ]]; then
            echo -e "${CYAN}ℹ${NC} You previously chose: ${YELLOW}$PROXY_TYPE${NC} as your reverse proxy"
            echo ""

            if [[ -t 0 ]]; then
                if ask_yes_no "Use the same choice again?" "y"; then
                    echo -e "${GREEN}✓${NC} Keeping your choice: ${CYAN}$PROXY_TYPE${NC}"

                    # Even when keeping the previous choice, ensure hosts are configured
                    local defaults frontend_domain backend_domain
                    defaults=$(get_default_domains "$APP_ENV")
                    frontend_domain=$(get_env_var "$FRONTEND_DOMAIN_KEY" "$(echo "$defaults" | cut -d'|' -f1)")
                    backend_domain=$(get_env_var "$BACKEND_DOMAIN_KEY" "$(echo "$defaults" | cut -d'|' -f2)")

                    # Add hosts entries if missing
                    if [[ "$PROXY_TYPE" != "none" ]]; then
                        echo ""
                        ensure_domains_in_hosts "$frontend_domain" "$backend_domain"
                    fi

                    exit 0
                fi
                echo ""
                echo -e "${YELLOW}OK, let's choose a new option...${NC}"
                echo ""
            else
                echo -e "${GREEN}✓${NC} Using your previous choice: ${CYAN}$PROXY_TYPE${NC}"

                # Even when keeping the previous choice, ensure hosts are configured
                local defaults frontend_domain backend_domain
                defaults=$(get_default_domains "$APP_ENV")
                frontend_domain=$(get_env_var "$FRONTEND_DOMAIN_KEY" "$(echo "$defaults" | cut -d'|' -f1)")
                backend_domain=$(get_env_var "$BACKEND_DOMAIN_KEY" "$(echo "$defaults" | cut -d'|' -f2)")

                # Add hosts entries if missing
                if [[ "$PROXY_TYPE" != "none" ]]; then
                    echo ""
                    ensure_domains_in_hosts "$frontend_domain" "$backend_domain"
                fi

                exit 0
            fi
        fi
    fi

    # Display detection results
    echo -e "${CYAN}Detecting existing reverse proxies...${NC}"

    if [[ "$existing_proxy" != "none" ]]; then
        echo -e "${YELLOW}⚠${NC} Detected existing reverse proxy: ${YELLOW}$existing_proxy${NC}"
        echo ""

        # Special handling for Caddy: auto-configure it
        if [[ "$existing_proxy" = "caddy" ]]; then
            echo -e "${GREEN}✓${NC} Caddy detected - will auto-configure for Belimbing"
            echo ""
            PROXY_TYPE="caddy"

            # Prompt for domains (or use defaults)
            echo ""
            local domains
            domains=$(prompt_for_domains)
            local frontend_domain backend_domain
            frontend_domain=$(echo "$domains" | cut -d'|' -f1)
            backend_domain=$(echo "$domains" | cut -d'|' -f2)

            # Save domains to setup state and .env
            save_to_setup_state "$FRONTEND_DOMAIN_KEY" "$frontend_domain"
            save_to_setup_state "$BACKEND_DOMAIN_KEY" "$backend_domain"
            update_env_file "$FRONTEND_DOMAIN_KEY" "$frontend_domain"
            update_env_file "$BACKEND_DOMAIN_KEY" "$backend_domain"

            # Add domains to /etc/hosts if missing
            echo ""
            ensure_domains_in_hosts "$frontend_domain" "$backend_domain"

            local default_ports
            default_ports=$(get_default_ports "$APP_ENV")
            local frontend_port backend_port
            frontend_port=$(echo "$default_ports" | cut -d'|' -f1)
            backend_port=$(echo "$default_ports" | cut -d'|' -f2)

            configure_existing_caddy "$frontend_domain" "$backend_domain" "$frontend_port" "$backend_port" "443"

        else
            # Non-Caddy proxy detected - show options since user needs to choose
            cat << EOF
${CYAN}Reverse Proxy Configuration${NC}

A reverse proxy handles HTTPS and forwards requests to your backend/frontend.

Since you already have $existing_proxy running, you have options:

${CYAN}Options:${NC}

  ${GREEN}1. Use Caddy anyway${NC} (will auto-handle port conflicts)
     • Automatic setup, zero configuration
     • Runs as a child process of start-app.sh
     • Stops when you stop the app
     • Best for development and testing

  ${YELLOW}2. Use my existing $existing_proxy${NC} (manual configuration required)
     • We'll generate a config snippet for you
     • You manage the proxy yourself

  ${RED}3. No HTTPS${NC} (HTTP only)
     • No HTTPS, access via http://localhost
     • Some browser features won't work without HTTPS
     • Not recommended for production

EOF

            if [[ -t 0 ]]; then
                echo -e "${CYAN}What would you like to do?${NC}"
                echo -e "  ${CYAN}1${NC} - Use Caddy anyway (will auto-handle port conflicts)"
                echo -e "  ${CYAN}2${NC} - Use my existing $existing_proxy (manual configuration required)"
                echo -e "  ${CYAN}3${NC} - No HTTPS (HTTP only)"
                echo ""

                local choice
                while true; do
                    choice=$(ask_input "Choice" "1")

                    case "$choice" in
                        1)
                            # Use Caddy anyway - auto-handle conflicts
                            PROXY_TYPE="caddy"

                            # Prompt for domains
                            echo ""
                            local domains
                            domains=$(prompt_for_domains)
                            FRONTEND_DOMAIN=$(echo "$domains" | cut -d'|' -f1)
                            BACKEND_DOMAIN=$(echo "$domains" | cut -d'|' -f2)

                            # Save domains to setup state and .env
                            save_to_setup_state "$FRONTEND_DOMAIN_KEY" "$FRONTEND_DOMAIN"
                            save_to_setup_state "$BACKEND_DOMAIN_KEY" "$BACKEND_DOMAIN"
                            update_env_file "$FRONTEND_DOMAIN_KEY" "$FRONTEND_DOMAIN"
                            update_env_file "$BACKEND_DOMAIN_KEY" "$BACKEND_DOMAIN"

                            # Add domains to /etc/hosts if missing
                            echo ""
                            ensure_domains_in_hosts "$FRONTEND_DOMAIN" "$BACKEND_DOMAIN"

                            echo -e "${CYAN}ℹ${NC} Caddy will use shared instance on port 443"
                            break
                            ;;
                        2)
                            PROXY_TYPE="$existing_proxy"
                            echo ""
                            echo -e "${YELLOW}ℹ${NC} Using $existing_proxy - you'll need to configure it manually"
                            echo -e "${CYAN}ℹ${NC} Configuration snippets will be generated during final setup"
                            break
                            ;;
                        3)
                            PROXY_TYPE="none"
                            echo ""
                            echo -e "${YELLOW}⚠${NC} Running without HTTPS"
                            if ask_yes_no "Continue without HTTPS?" "n"; then
                                break
                            fi
                            ;;
                        *)
                            echo -e "${YELLOW}Invalid choice. Please enter 1, 2, or 3${NC}"
                            ;;
                    esac
                done
            else
                # Non-interactive: default to Caddy with auto-conflict handling
                PROXY_TYPE="caddy"
            fi
        fi
    else
        # No existing proxy detected - be opinionated, use Caddy automatically!
        echo -e "${GREEN}✓${NC} No existing reverse proxy detected"
        echo -e "${CYAN}→${NC} Automatically choosing Caddy for HTTPS support"
        echo ""
        PROXY_TYPE="caddy"
    fi

    echo ""

    # Handle Caddy installation if selected
    if [[ "$PROXY_TYPE" = "caddy" ]]; then
        echo -e "${CYAN}Setting up Caddy...${NC}"
        echo ""

        # Prompt for domains if not already set (e.g., from existing Caddy auto-config)
        if [[ -z "${FRONTEND_DOMAIN:-}" ]] || [[ -z "${BACKEND_DOMAIN:-}" ]]; then
            echo ""
            local domains
            domains=$(prompt_for_domains)
            FRONTEND_DOMAIN=$(echo "$domains" | cut -d'|' -f1)
            BACKEND_DOMAIN=$(echo "$domains" | cut -d'|' -f2)

            # Save domains to setup state and .env
            save_to_setup_state "$FRONTEND_DOMAIN_KEY" "$FRONTEND_DOMAIN"
            save_to_setup_state "$BACKEND_DOMAIN_KEY" "$BACKEND_DOMAIN"
            update_env_file "$FRONTEND_DOMAIN_KEY" "$FRONTEND_DOMAIN"
            update_env_file "$BACKEND_DOMAIN_KEY" "$BACKEND_DOMAIN"
        fi

        # Always ensure domains are in hosts file (even if domains were already set)
        echo ""
        ensure_domains_in_hosts "$FRONTEND_DOMAIN" "$BACKEND_DOMAIN"

        # Check if Caddy is already installed
        if command -v caddy &> /dev/null; then
            local caddy_version
            caddy_version=$(caddy version 2>/dev/null | head -1 || echo "unknown")
            echo -e "${GREEN}✓${NC} Caddy already installed: $caddy_version"
        else
            echo -e "${YELLOW}ℹ${NC} Caddy not found, installing..."
            if install_caddy; then
                echo -e "${GREEN}✓${NC} Caddy installed successfully"
            else
                echo -e "${RED}✗${NC} Failed to install Caddy"
                echo ""
                echo -e "${YELLOW}Options:${NC}"
                echo -e "  1. Install Caddy manually: ${CYAN}https://caddyserver.com/docs/install${NC}"
                echo -e "  2. Re-run this script and choose Manual or None"
                echo ""
                exit 1
            fi
        fi

        # Verify Caddy installation
        if ! command -v caddy &> /dev/null; then
            echo -e "${RED}✗${NC} Caddy installation verification failed"
            exit 1
        fi

        echo ""
        echo -e "${GREEN}✓${NC} Caddy is ready"
        echo -e "  ${CYAN}Frontend: ${FRONTEND_DOMAIN:-$(echo "$(get_default_domains "$APP_ENV")" | cut -d'|' -f1)}${NC}"
        echo -e "  ${CYAN}Backend: ${BACKEND_DOMAIN:-$(echo "$(get_default_domains "$APP_ENV")" | cut -d'|' -f2)}${NC}"
        echo -e "  ${CYAN}Caddyfile will be generated during final setup${NC}"
    elif [[ "$PROXY_TYPE" = "manual" ]] || [[ "$PROXY_TYPE" = "nginx" ]] || [[ "$PROXY_TYPE" = "apache" ]] || [[ "$PROXY_TYPE" = "traefik" ]]; then
        echo -e "${CYAN}ℹ${NC} Using manual proxy configuration: $PROXY_TYPE"
        echo -e "  ${CYAN}Configuration snippets will be generated during final setup${NC}"
    else
        echo -e "${YELLOW}⚠${NC} No reverse proxy configured (HTTP only mode)"
    fi

    echo ""
    echo -e "${GREEN}✓${NC} Your choice saved: ${CYAN}$PROXY_TYPE${NC}"

    # Save state
    save_to_setup_state "PROXY_TYPE" "$PROXY_TYPE" "$PROJECT_ROOT"

    # Update .env file with proxy configuration
    echo -n "Updating .env file with proxy settings... "
    update_env_file "PROXY_TYPE" "$PROXY_TYPE"
    echo -e "${GREEN}✓${NC}"

    echo ""
    echo -e "Configuration saved to: ${CYAN}$(get_setup_state_file "$PROJECT_ROOT")${NC}"
    echo -e "Proxy settings saved to: ${CYAN}$PROJECT_ROOT/.env${NC}"
    echo ""
    echo -e "${GREEN}✓ Reverse proxy setup complete!${NC}"
    return 0
}

# Run main function
main "$@"
