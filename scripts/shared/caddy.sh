#!/bin/bash

# SPDX-License-Identifier: AGPL-3.0-only
# (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

# Source colors if not already loaded
CADDY_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "$RED" ]]; then
    source "$CADDY_SCRIPT_DIR/colors.sh"
fi

# Source validation utilities
if ! command -v command_exists &> /dev/null; then
    source "$CADDY_SCRIPT_DIR/validation.sh"
fi

# ── Shared Caddy ──────────────────────────────────────────────────────────
# All BLB instances share one Caddy process on :443 via host-based routing.
# Each instance writes a resolved site fragment to BLB_CADDY_HOME/sites/.
# start-app adds its fragment and reloads; stop-app removes it and reloads.
BLB_CADDY_HOME="${BLB_CADDY_HOME:-$HOME/.blb/caddy}"
BLB_CADDY_SITES="$BLB_CADDY_HOME/sites"
BLB_CADDY_MAIN="$BLB_CADDY_HOME/Caddyfile"
BLB_CADDY_ADMIN_SOCK="/tmp/caddy-blb-admin.sock"
BLB_CADDY_LAST_ERROR=""
BLB_CADDY_LAST_OUTPUT=""

# Diagnose a Caddy failure from its captured output and set BLB_CADDY_LAST_ERROR
# with a human-readable reason and actionable hint.
# Usage: diagnose_caddy_failure <rc> <captured_output> <context>
#   rc:              exit code from caddy command
#   captured_output: combined stdout+stderr from the failed command
#   context:         short label like "start" or "reload" for the error message
diagnose_caddy_failure() {
    local rc=$1
    local output=$2
    local context=$3

    BLB_CADDY_LAST_OUTPUT="$output"

    local reason="Failed to ${context} Caddy (rc=$rc)"
    local hint=""

    if [[ "$output" == *"address already in use"* ]]; then
        reason="Port 443 is already in use by another process"
        hint="Stop the process occupying port 443 (sudo lsof -i :443) or change HTTPS_PORT"
    elif [[ "$output" == *"permission denied"* ]] || [[ "$output" == *"operation not permitted"* ]]; then
        reason="Caddy lacks permission to bind port 443"
        hint="Run: sudo setcap 'cap_net_bind_service=+ep' \$(command -v caddy)"
    elif [[ "$output" == *"parsing caddyfile"* ]] || [[ "$output" == *"Caddyfile:"* ]] || [[ "$output" == *"unexpected"* ]]; then
        reason="Invalid Caddyfile configuration"
        hint="Check generated config: $BLB_CADDY_MAIN and site fragments in $BLB_CADDY_SITES/"
    elif [[ "$output" == *"no such file or directory"* ]] && [[ "$output" == *"admin"* || "$output" == *"sock"* ]]; then
        reason="Stale Caddy admin socket"
        hint="Remove the socket and retry: rm -f $BLB_CADDY_ADMIN_SOCK"
    elif [[ "$output" == *"no such host"* ]] || [[ "$output" == *"dial"* ]]; then
        reason="Caddy cannot reach upstream (DNS or network issue)"
        hint="Verify FRONTEND_DOMAIN / BACKEND_DOMAIN resolve and upstream ports are correct"
    elif [[ "$output" == *"loading"* ]] && [[ "$output" == *"module"* ]]; then
        reason="Caddy module/plugin loading error"
        hint="Reinstall Caddy or check if required plugins are available"
    fi

    if [[ -n "$hint" ]]; then
        BLB_CADDY_LAST_ERROR="${reason}. ${hint}"
    else
        BLB_CADDY_LAST_ERROR="$reason"
    fi
}

ensure_blb_caddy_dirs() {
    mkdir -p "$BLB_CADDY_SITES"
    return 0
}

# Create the main Caddyfile that imports all site fragments.
create_main_caddyfile() {
    ensure_blb_caddy_dirs
    if [[ ! -f "$BLB_CADDY_MAIN" ]]; then
        cat > "$BLB_CADDY_MAIN" <<EOF
{
	admin unix/$BLB_CADDY_ADMIN_SOCK
}

import $BLB_CADDY_SITES/*.caddy
EOF
    fi
    return 0
}

# Resolve Caddy {$VAR:default} placeholders using exported env vars.
# Reads a Caddyfile template and writes the resolved result to stdout.
# Uses pure bash string ops to avoid sed escaping issues with {$...} syntax.
resolve_caddyfile_vars() {
    local input_file=$1
    local vars=(APP_DOMAIN BACKEND_DOMAIN APP_HOST APP_PORT VITE_HOST VITE_PORT HTTPS_PORT TLS_MODE CADDY_LOG_DIR)
    local content
    content=$(<"$input_file")

    for var in "${vars[@]}"; do
        local val="${!var:-}"
        local token_prefix='{$'"${var}"':'
        local token_bare='{$'"${var}"'}'

        if [[ -n "$val" ]]; then
            # {$VAR:default} → val
            while [[ "$content" == *"${token_prefix}"* ]]; do
                local before="${content%%"${token_prefix}"*}"
                local rest="${content#*"${token_prefix}"}"
                rest="${rest#*\}}"
                content="${before}${val}${rest}"
            done
            # {$VAR} → val
            content="${content//"${token_bare}"/"${val}"}"
        else
            # {$VAR:default} → default
            while [[ "$content" == *"${token_prefix}"* ]]; do
                local before="${content%%"${token_prefix}"*}"
                local rest="${content#*"${token_prefix}"}"
                local default_val="${rest%%\}*}"
                rest="${rest#*\}}"
                content="${before}${default_val}${rest}"
            done
            # {$VAR} → empty
            content="${content//"${token_bare}"/}"
        fi
    done

    printf '%s\n' "$content"
    return 0
}

# Derive a slug from FRONTEND_DOMAIN (safe for filenames).
site_fragment_slug() {
    local domain="${1:-${FRONTEND_DOMAIN:-blb}}"
    echo "$domain" | tr '.' '-'
    return 0
}

# Write resolved site fragment for this instance.
# Uses the project Caddyfile as template, resolves vars, writes to shared sites dir.
write_site_fragment() {
    local project_root=$1
    local slug
    slug=$(site_fragment_slug "$FRONTEND_DOMAIN")
    local fragment_file="$BLB_CADDY_SITES/${slug}.caddy"

    ensure_blb_caddy_dirs

    if [[ ! -f "$project_root/Caddyfile" ]]; then
        echo -e "${RED}✗${NC} No Caddyfile template found in project root" >&2
        return 1
    fi

    resolve_caddyfile_vars "$project_root/Caddyfile" > "$fragment_file"
    echo "$fragment_file"
    return 0
}

# Remove this instance's site fragment.
remove_site_fragment() {
    local slug
    slug=$(site_fragment_slug "${FRONTEND_DOMAIN:-${1:-}}")
    local fragment_file="$BLB_CADDY_SITES/${slug}.caddy"
    rm -f "$fragment_file"
    return 0
}

# Start the shared Caddy if not running, or reload if already running.
ensure_shared_caddy() {
    BLB_CADDY_LAST_ERROR=""
    BLB_CADDY_LAST_OUTPUT=""
    create_main_caddyfile

    if ! command_exists caddy; then
        install_caddy || {
            BLB_CADDY_LAST_ERROR="Caddy is not available after installation attempt"
            return 1
        }
    fi

    ensure_caddy_privileges || {
        BLB_CADDY_LAST_ERROR="Caddy cannot bind privileged ports (need cap_net_bind_service). Run: sudo setcap 'cap_net_bind_service=+ep' \$(command -v caddy)"
        return 1
    }

    local caddy_output rc

    if pgrep -x "caddy" > /dev/null; then
        caddy_output=$(caddy reload --config "$BLB_CADDY_MAIN" --adapter caddyfile 2>&1)
        rc=$?
        if [[ $rc -eq 0 ]]; then
            echo -e "${GREEN}✓${NC} Caddy reloaded with updated sites"
        else
            echo -e "${YELLOW}⚠${NC} Caddy reload failed (rc=$rc); attempting restart..." >&2
            caddy stop 2>/dev/null || true
            sleep 1
            caddy_output=$(caddy start --config "$BLB_CADDY_MAIN" --adapter caddyfile 2>&1)
            rc=$?
            if [[ $rc -eq 0 ]]; then
                echo -e "${GREEN}✓${NC} Caddy restarted with updated sites"
            else
                echo -e "${RED}✗${NC} Failed to restart Caddy (rc=$rc)" >&2
                diagnose_caddy_failure "$rc" "$caddy_output" "restart"
                return 1
            fi
        fi
    else
        echo -e "${GREEN}Starting shared Caddy on :443...${NC}"
        caddy_output=$(caddy start --config "$BLB_CADDY_MAIN" --adapter caddyfile 2>&1)
        rc=$?
        if [[ $rc -eq 0 ]]; then
            echo -e "${GREEN}✓${NC} Caddy started"
        else
            echo -e "${RED}✗${NC} Failed to start Caddy (rc=$rc)" >&2
            diagnose_caddy_failure "$rc" "$caddy_output" "start"
            return 1
        fi
    fi
    return 0
}

# Stop the shared Caddy only if no site fragments remain.
maybe_stop_shared_caddy() {
    local remaining
    remaining=$(find "$BLB_CADDY_SITES" -name '*.caddy' 2>/dev/null | wc -l)
    if [[ "$remaining" -eq 0 ]] && pgrep -x "caddy" > /dev/null; then
        echo -e "${CYAN}No BLB sites remaining; stopping Caddy...${NC}"
        caddy stop 2>/dev/null || true
    fi
    return 0
}

# Install Caddy if needed
install_caddy() {
    echo -e "${YELLOW}${INFO_MARK} Installing Caddy...${NC}"

    local os_type
    os_type=$(detect_os)

    case "$os_type" in
        macos)
            if command_exists brew; then
                brew install caddy
            else
                echo -e "${RED}${CROSS_MARK} Homebrew required${NC}"
                return 1
            fi
            ;;
        linux|wsl2)
            if command_exists apt-get; then
                sudo apt-get update -qq
                sudo apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl
                curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' 2>/dev/null | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
                curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' 2>/dev/null | sudo tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null
                sudo apt-get update -qq
                sudo apt-get install -y -qq caddy
            else
                echo -e "${YELLOW}Installing from binary...${NC}"
                curl -o caddy.tar.gz "https://caddyserver.com/api/download?os=linux&arch=amd64" 2>/dev/null
                tar -xzf caddy.tar.gz caddy
                sudo mv caddy /usr/local/bin/
                sudo chmod +x /usr/local/bin/caddy
                rm caddy.tar.gz
            fi
            ;;
        *)
            echo -e "${RED}${CROSS_MARK} OS not supported${NC}"
            return 1
            ;;
    esac

    echo -e "${GREEN}${CHECK_MARK}${NC} Caddy installed"
    return 0
}

# Ensure Caddy binary can bind to privileged ports (e.g., 443)
ensure_caddy_privileges() {
    if ! command -v caddy >/dev/null 2>&1; then
        echo -e "${RED}${CROSS_MARK}${NC} Caddy binary not found"
        return 1
    fi

    local caddy_path
    caddy_path="$(command -v caddy)"

    if command -v getcap >/dev/null 2>&1; then
        local current_caps
        current_caps="$(getcap "$caddy_path" 2>/dev/null || true)"
        if [[ "$current_caps" == *cap_net_bind_service* ]]; then
            return 0
        fi
    fi

    echo -e "${CYAN}${INFO_MARK} Granting Caddy permission to bind privileged ports...${NC}"
    if sudo setcap 'cap_net_bind_service=+ep' "$caddy_path"; then
        echo -e "${GREEN}${CHECK_MARK}${NC} Caddy can now bind to port 443"
        return 0
    fi

    echo -e "${RED}${CROSS_MARK}${NC} Failed to grant Caddy permission. Run:"
    echo -e "  ${YELLOW}sudo setcap 'cap_net_bind_service=+ep' $caddy_path${NC}"
    return 1
}

# Check if Caddy proxy is enabled (PROXY_TYPE=caddy). Start-app uses this to decide start/skip from runtime state.
# Usage: is_caddy_enabled ["proxy_type_value"]
is_caddy_enabled() {
    local proxy_type=${1:-"${PROXY_TYPE:-}"}
    [[ "$proxy_type" = "caddy" ]]
}

# Setup SSL certificate trust for self-signed certificates (TLS_MODE=internal)
# Use in any environment: local, staging, or production behind proxy/internal network
# Works with both native Caddy and Docker Caddy
# Usage: setup_ssl_trust [project_root] [container_name]
#   project_root: Project root directory (default: current directory)
#   container_name: Docker container name if using Docker (optional)
setup_ssl_trust() {
    local project_root="${1:-$(pwd)}"
    local container_name="${2:-}"
    local cert_path="$project_root/storage/app/ssl"
    local root_ca_file="$cert_path/caddy-root-ca.crt"

    echo -e "${CYAN}Setting up SSL certificate trust...${NC}"

    # Create directory for certificates
    mkdir -p "$cert_path"

    local root_ca_source=""
    local is_docker=false

    # Detect if Caddy is in Docker or native
    if [[ -n "$container_name" ]] && docker ps --format "{{.Names}}" | grep -q "^${container_name}$"; then
        # Docker Caddy
        is_docker=true
        root_ca_source="/data/caddy/pki/authorities/local/root.crt"

        # Wait for Caddy to generate certificates (up to 10 seconds)
        local attempts=0
        local max_attempts=10
        while [[ $attempts -lt $max_attempts ]]; do
            if docker exec "$container_name" test -f "$root_ca_source" 2>/dev/null; then
                break
            fi
            sleep 1
            attempts=$((attempts + 1))
        done

        # Export root CA from Docker container
        if ! docker exec "$container_name" cat "$root_ca_source" > "$root_ca_file" 2>/dev/null; then
            echo -e "${YELLOW}⚠${NC} Could not export Caddy root CA from container (certificate may not be generated yet)"
            echo -e "  You can manually accept the certificate warning in your browser"
            return 1
        fi
    else
        # Native Caddy - try common locations
        local native_locations=(
            "$HOME/.local/share/caddy/pki/authorities/local/root.crt"
            "$HOME/.config/caddy/pki/authorities/local/root.crt"
            "/root/.local/share/caddy/pki/authorities/local/root.crt"
        )

        for location in "${native_locations[@]}"; do
            if [[ -f "$location" ]]; then
                root_ca_source="$location"
                break
            fi
        done

        if [[ -z "$root_ca_source" ]] || [[ ! -f "$root_ca_source" ]]; then
            echo -e "${YELLOW}⚠${NC} Could not find Caddy root CA (certificate may not be generated yet)"
            echo -e "  Expected locations:"
            for location in "${native_locations[@]}"; do
                echo -e "    ${CYAN}$location${NC}"
            done
            echo -e "  You can manually accept the certificate warning in your browser"
            return 1
        fi

        # Copy from native location
        cp "$root_ca_source" "$root_ca_file" 2>/dev/null || {
            echo -e "${YELLOW}⚠${NC} Could not copy Caddy root CA"
            return 1
        }
    fi

    # Check if certificate was exported successfully
    if [[ ! -s "$root_ca_file" ]]; then
        echo -e "${YELLOW}⚠${NC} Root CA file is empty"
        return 1
    fi

    echo -e "${GREEN}✓${NC} Exported Caddy root CA to ${CYAN}storage/app/ssl/caddy-root-ca.crt${NC}"

    # Try to install to system trust store (Linux/WSL2)
    local installed=false
    if command_exists update-ca-certificates; then
        # Debian/Ubuntu
        local system_cert_path="/usr/local/share/ca-certificates/caddy-blb-root.crt"

        # Check if already installed and matches current certificate
        if [[ -f "$system_cert_path" ]]; then
            if diff -q "$root_ca_file" "$system_cert_path" >/dev/null 2>&1; then
                echo -e "${GREEN}✓${NC} Certificate already installed in system trust store"
                installed=true
            elif [[ -t 0 ]]; then
                echo -e "${YELLOW}ℹ${NC} Certificate changed, updating system trust store..."
                if sudo cp "$root_ca_file" "$system_cert_path" 2>/dev/null && \
                   sudo update-ca-certificates 2>/dev/null; then
                    echo -e "${GREEN}✓${NC} Certificate updated in system trust store"
                    installed=true
                fi
            fi
        elif [[ -t 0 ]]; then
            # Not installed yet - install it
            echo -e "${CYAN}Installing certificate to system trust store...${NC}"
            if sudo cp "$root_ca_file" "$system_cert_path" 2>/dev/null && \
               sudo update-ca-certificates 2>/dev/null; then
                echo -e "${GREEN}✓${NC} Certificate installed to system trust store"
                installed=true
            fi
        fi
    elif command_exists trust; then
        # Fedora/RHEL
        # Check if already in trust store
        if trust list | grep -q "caddy-blb-root" 2>/dev/null; then
            echo -e "${GREEN}✓${NC} Certificate already installed in system trust store"
            installed=true
        elif [[ -t 0 ]]; then
            echo -e "${CYAN}Installing certificate to system trust store...${NC}"
            if sudo trust anchor --store "$root_ca_file" 2>/dev/null; then
                echo -e "${GREEN}✓${NC} Certificate installed to system trust store"
                installed=true
            fi
        fi
    fi

    # On WSL2, provide instructions for Windows trust
    if is_wsl2; then
        echo ""
        echo -e "${CYAN}For Windows browser support:${NC}"
        echo -e "  Certificate location: ${CYAN}storage/app/ssl/caddy-root-ca.crt${NC}"
        echo -e "  To install:"
        echo -e "    1. Open Windows Explorer and navigate to: ${CYAN}storage/app/ssl${NC}"
        echo -e "       (In WSL2, access via: ${CYAN}\\\\wsl$\\\\<distro>\\\\<project_path>\\\\storage\\\\app\\\\ssl${NC})"
        echo -e "    2. Double-click ${CYAN}caddy-root-ca.crt${NC}"
        echo -e "    3. Click 'Install Certificate' → 'Local Machine' → Next"
        echo -e "    4. Select 'Place all certificates in the following store'"
        echo -e "    5. Browse → 'Trusted Root Certification Authorities' → OK → Next → Finish"
        echo -e "    6. Restart your browser"
        echo ""
    fi

    if [[ "$installed" = false ]]; then
        echo -e "${YELLOW}Note:${NC} Certificate not auto-installed to system trust store"
        echo -e "  You can manually install: ${CYAN}$root_ca_file${NC}"
        echo -e "  Or accept the browser warning (safe for self-signed development certificates)"
    fi

    return 0
}

# High-level orchestration to ensure SSL trust is set up if needed
# Checks TLS_MODE and whether cert is already installed before attempting setup
# Usage: ensure_ssl_trust [project_root] [tls_mode] [container_name]
ensure_ssl_trust() {
    local project_root="${1:-$(pwd)}"
    local tls_mode="${2:-internal}"
    local container_name="${3:-}"

    # Production environments with real certificates (TLS_MODE != "internal") don't need this
    if [[ "${tls_mode}" != "internal" ]]; then
        if command -v log >/dev/null 2>&1; then
            log "Using TLS mode: $tls_mode (real certificates, skipping SSL trust setup)"
        fi
        return 0
    fi

    # Check if we can even run the setup
    if ! command -v setup_ssl_trust >/dev/null 2>&1; then
        return 1
    fi

    # Check if certificate is already in system trust store
    local ssl_already_installed=false

    if command -v update-ca-certificates >/dev/null 2>&1; then
        # Debian/Ubuntu - check if already installed
        if [[ -f "/usr/local/share/ca-certificates/caddy-blb-root.crt" ]]; then
            ssl_already_installed=true
        fi
    elif command -v trust >/dev/null 2>&1; then
        # Fedora/RHEL - check if already in trust store
        if trust list | grep -q "caddy-blb-root" 2>/dev/null; then
            ssl_already_installed=true
        fi
    fi

    if [[ "$ssl_already_installed" = true ]]; then
        # Already installed - skip setup
        return 0
    fi

    # Not installed yet - try to set up
    # We wait briefly for Caddy to generate certificates if they don't exist yet
    sleep 2

    echo ""
    echo -e "${CYAN}Setting up SSL certificate trust (one-time, for development)...${NC}"

    if setup_ssl_trust "$project_root" "$container_name"; then
        return 0
    else
        # Setup failed - provide helpful guidance
        echo ""
        echo -e "${YELLOW}Note:${NC} SSL trust setup was skipped or failed (not critical for development)"
        echo -e "To set up SSL trust later, run: ${CYAN}./scripts/setup-steps/75-ssl-trust.sh${NC}"
        echo -e "Or accept the browser warning when accessing your app (safe for self-signed certs)"
        return 1
    fi
}

