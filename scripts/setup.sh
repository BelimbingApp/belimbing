#!/usr/bin/env bash
# Belimbing Environment Setup
#
# Orchestrates modular setup steps for the Belimbing (BLB) project.
# Each component (PostgreSQL, Caddy, etc.) has its own independent script
# in setup-steps/ that can be run standalone or called by this orchestrator.
#
# Usage: ./scripts/setup.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=shared/colors.sh
source "$SCRIPT_DIR/shared/colors.sh" 2>/dev/null || true
# shellcheck source=shared/runtime.sh
source "$SCRIPT_DIR/shared/runtime.sh" 2>/dev/null || true
# shellcheck source=shared/config.sh
source "$SCRIPT_DIR/shared/config.sh" 2>/dev/null || true
# shellcheck source=shared/validation.sh
source "$SCRIPT_DIR/shared/validation.sh" 2>/dev/null || true
# shellcheck source=shared/interactive.sh
source "$SCRIPT_DIR/shared/interactive.sh" 2>/dev/null || true

# === Main logic ===

clear
print_section_banner "Belimbing Environment Setup"

# Ask which environment to set up
echo -e "${CYAN}Which environment do you want to set up?${NC}"
echo ""
echo -e "  1. ${GREEN}local${NC}      — Development with sample data"
echo -e "  2. staging    — Pre-production testing"
echo -e "  3. production — Live deployment"
echo ""

read -r -p "Choose [1]: " env_choice
env_choice="${env_choice:-1}"

case "$env_choice" in
    1|local)      APP_ENV="local" ;;
    2|staging)    APP_ENV="staging" ;;
    3|production) APP_ENV="production" ;;
    *)
        echo -e "${RED}✗ Invalid choice: '$env_choice'${NC}" >&2
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}✓${NC} Environment: ${CYAN}${APP_ENV}${NC}"
echo ""

# Auto-discover setup steps from setup-steps/ directory.
# Each step file must have a "# Title: ..." line in its header.
declare -a STEPS=()

while IFS= read -r step_file; do
    script_name=$(basename "$step_file")
    title=$(grep "^# Title:" "$step_file" | head -1 | sed 's/^# Title: //')
    STEPS+=("${script_name}:${title:-Setup Step}")
done < <(find "$SCRIPT_DIR/setup-steps" -name "*.sh" -type f | sort)

# Display setup plan
echo -e "${CYAN}Setup Steps:${NC}"
for i in "${!STEPS[@]}"; do
    IFS=':' read -r _ description <<< "${STEPS[$i]}"
    echo -e "  $((i + 1)). ${description}"
done
echo ""

# Run each step
INSTALL_START_TIME=$(date +%s)
declare -a FAILED=()
completed_count=0
total_time=0
interrupted=false

step_num=1
for step in "${STEPS[@]}"; do
    if [[ "$interrupted" = true ]]; then
        break
    fi

    IFS=':' read -r script description <<< "$step"
    step_script="$SCRIPT_DIR/setup-steps/$script"

    if [[ ! -f "$step_script" ]]; then
        echo -e "${RED}✗${NC} Step script not found: $step_script"
        FAILED+=("$script")
        ((step_num++))
        continue
    fi

    export BLB_STEP="$step_num"
    export BLB_STEP_TOTAL="${#STEPS[@]}"

    if bash "$step_script" "$APP_ENV"; then
        ((++completed_count))
    else
        echo ""
        echo -e "${RED}✗${NC} ${description} — Failed"
        FAILED+=("$script")
    fi

    # Confirm before next step (skip after last)
    if [[ "$step_num" -lt ${#STEPS[@]} ]]; then
        echo ""
        if ! ask_yes_no "Continue?" "y"; then
            echo ""
            echo -e "${YELLOW}Setup interrupted${NC}"
            interrupted=true
        fi
    fi

    echo ""
    ((step_num++))
done

# === Finishing ===

total_time=$(($(date +%s) - INSTALL_START_TIME))

if [[ ${#FAILED[@]} -eq 0 ]] && [[ "$completed_count" -eq ${#STEPS[@]} ]]; then
    echo ""
    echo -e "${GREEN}✓ Setup Complete! 🎉${NC} (${total_time}s)"
    echo ""

    # Cleanup temporary setup state file
    rm -f "$PROJECT_ROOT/storage/app/.devops/setup.env"

    if read -r -p "Start Belimbing now? (y/n) [y]: " -n 1; then
        echo ""
        if [[ $REPLY =~ ^[Yy]$ ]] || [[ -z $REPLY ]]; then
            echo ""
            exec "$SCRIPT_DIR/start-app.sh"
        fi
    fi
    echo ""
    exit 0
else
    echo -e "${YELLOW}⚠ Setup Incomplete${NC} (${completed_count}/${#STEPS[@]} steps, ${total_time}s)"
    echo ""
    echo -e "${CYAN}Retry failed steps:${NC}"
    for script in "${FAILED[@]}"; do
        echo -e "  ${CYAN}./scripts/setup-steps/$script $APP_ENV${NC}"
    done
    echo ""
    echo -e "${CYAN}Or re-run full setup:${NC}"
    echo -e "  ${CYAN}./scripts/setup.sh${NC}"
    echo ""
    exit 1
fi
