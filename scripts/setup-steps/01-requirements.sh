#!/usr/bin/env bash
# scripts/setup-steps/01-requirements.sh
# Title: System Requirements
# Purpose: Validate system-level requirements that later steps cannot recover from
# Usage: ./scripts/setup-steps/01-requirements.sh [local|staging|production]
#
# Checks: OS compatibility, disk space, RAM, network connectivity.
# Tool-level checks (PHP, Git, PostgreSQL, etc.) are handled by their own steps.

set -euo pipefail

SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"

# shellcheck source=../shared/colors.sh
source "$SCRIPTS_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=../shared/runtime.sh
source "$SCRIPTS_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=../shared/validation.sh
source "$SCRIPTS_DIR/shared/validation.sh" 2>/dev/null || true

APP_ENV="${1:-local}"

readonly MIN_DISK_GB=2
readonly MIN_RAM_GB=2

has_failure=false

check_os() {
    local os_type
    os_type=$(detect_os 2>/dev/null || echo "unknown")

    case "$os_type" in
        linux|wsl2|macos)
            echo -e "${GREEN}✓${NC} OS: $os_type"
            ;;
        *)
            echo -e "${RED}✗${NC} OS: $os_type (not supported)"
            has_failure=true
            ;;
    esac

    return 0
}

check_disk_space() {
    local available_gb
    available_gb=$(df -BG "$PROJECT_ROOT" 2>/dev/null | awk 'NR==2 {print $4}' | sed 's/G//' || echo "0")

    # Fallback for systems without -BG
    if [[ -z "$available_gb" ]] || [[ "$available_gb" = "0" ]]; then
        available_gb=$(df "$PROJECT_ROOT" 2>/dev/null | awk 'NR==2 {print int($4/1024/1024)}' || echo "0")
    fi

    if [[ "$available_gb" -ge "$MIN_DISK_GB" ]]; then
        echo -e "${GREEN}✓${NC} Disk: ${available_gb}GB available (${MIN_DISK_GB}GB required)"
    else
        echo -e "${RED}✗${NC} Disk: ${available_gb}GB available (${MIN_DISK_GB}GB required)"
        has_failure=true
    fi

    return 0
}

check_ram() {
    local total_ram_gb=0
    local os_type
    os_type=$(detect_os 2>/dev/null || echo "unknown")

    case "$os_type" in
        linux|wsl2)
            if [[ -f /proc/meminfo ]]; then
                total_ram_gb=$(awk '/MemTotal/ {print int($2/1024/1024)}' /proc/meminfo 2>/dev/null || echo "0")
            fi
            ;;
        macos)
            total_ram_gb=$(sysctl -n hw.memsize 2>/dev/null | awk '{print int($1/1024/1024/1024)}' || echo "0")
            ;;
        *) ;;
    esac

    if [[ "$total_ram_gb" -ge "$MIN_RAM_GB" ]]; then
        echo -e "${GREEN}✓${NC} RAM: ${total_ram_gb}GB available (${MIN_RAM_GB}GB required)"
    elif [[ "$total_ram_gb" -gt 0 ]]; then
        echo -e "${YELLOW}⚠${NC} RAM: ${total_ram_gb}GB available (${MIN_RAM_GB}GB recommended)"
    else
        echo -e "${YELLOW}⚠${NC} RAM: Cannot detect"
    fi

    return 0
}

check_network() {
    if curl -s --max-time 5 https://packagist.org >/dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} Network: Internet available"
    else
        echo -e "${YELLOW}⚠${NC} Network: Cannot reach internet (packages may fail to download)"
    fi

    return 0
}

# === Main ===

print_section_banner "System Requirements ($APP_ENV)"

check_os
check_disk_space
check_ram
check_network

echo ""

if [[ "$has_failure" = true ]]; then
    echo -e "${RED}✗ System requirements not met${NC}"
    exit 1
fi

echo -e "${GREEN}✓ System requirements met${NC}"
