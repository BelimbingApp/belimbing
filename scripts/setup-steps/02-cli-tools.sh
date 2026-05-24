#!/usr/bin/env bash
# scripts/setup-steps/02-cli-tools.sh
# Title: CLI Tools (rg, jq)
# Purpose: Install ripgrep and jq used by AI-assisted development workflows
# Usage: ./scripts/setup-steps/02-cli-tools.sh [local|staging|production]

set -euo pipefail

SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"

# shellcheck source=../shared/colors.sh
source "$SCRIPTS_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=../shared/runtime.sh
source "$SCRIPTS_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=../shared/validation.sh
source "$SCRIPTS_DIR/shared/validation.sh" 2>/dev/null || true

APP_ENV="${1:-local}"

os_type=$(detect_os 2>/dev/null || echo "unknown")

# Install a tool if absent. Tries each install_cmd in order; skips gracefully on failure.
ensure_tool() {
    local name="$1"
    local binary="$2"
    shift 2

    if command -v "$binary" >/dev/null 2>&1; then
        echo -e "${GREEN}✓${NC} $name already installed"
        return 0
    fi

    echo -e "${CYAN}→${NC} Installing $name..."
    local cmd
    for cmd in "$@"; do
        if eval "$cmd" 2>/dev/null; then
            if command -v "$binary" >/dev/null 2>&1; then
                echo -e "${GREEN}✓${NC} $name installed"
                return 0
            fi
        fi
    done

    echo -e "${YELLOW}⚠${NC} $name could not be installed automatically — install it manually"
}

# Build install commands based on the available package manager
rg_cmds=()
jq_cmds=()

case "$os_type" in
    macos)
        rg_cmds=("brew install ripgrep")
        jq_cmds=("brew install jq")
        ;;
    linux|wsl2)
        if command -v apt-get >/dev/null 2>&1; then
            rg_cmds=("sudo apt-get install -y ripgrep")
            jq_cmds=("sudo apt-get install -y jq")
        elif command -v dnf >/dev/null 2>&1; then
            rg_cmds=("sudo dnf install -y ripgrep")
            jq_cmds=("sudo dnf install -y jq")
        elif command -v pacman >/dev/null 2>&1; then
            rg_cmds=("sudo pacman -S --noconfirm ripgrep" "sudo pacman -S --noconfirm jq")
            jq_cmds=("sudo pacman -S --noconfirm jq")
        fi
        ;;
esac

print_section_banner "CLI Tools ($APP_ENV)"

ensure_tool "ripgrep (rg)" "rg" "${rg_cmds[@]+"${rg_cmds[@]}"}"
ensure_tool "jq" "jq" "${jq_cmds[@]+"${jq_cmds[@]}"}"

echo ""
echo -e "${GREEN}✓ CLI tools ready${NC}"
