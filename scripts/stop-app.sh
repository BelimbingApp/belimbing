#!/bin/bash

# SPDX-License-Identifier: AGPL-3.0-only
# (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

# Stop Belimbing Development Environment Services
# Usage: ./scripts/stop-app.sh [ENVIRONMENT]
#
# Why this script exists (when Ctrl+C in start-app.sh should be enough):
#
# 1. Recovery from crashes: If start-app.sh was killed (SIGKILL, terminal crash,
#    system crash), cleanup() won't run, leaving orphaned processes. This script
#    allows manual cleanup of stuck services.
#
# 2. Stopping from different terminal: If start-app.sh is running in another
#    terminal, Ctrl+C won't work. This script can stop services from any terminal.
#
# 3. Explicit stopping: Better UX than relying on start-app.sh's auto-cleanup
#    when restarting. Users can explicitly stop without starting.
#
# 4. Debugging/cleanup: Useful for force-cleaning stuck processes during
#    development and troubleshooting.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$SCRIPT_DIR/shared/colors.sh" 2>/dev/null || true
source "$SCRIPT_DIR/shared/config.sh" 2>/dev/null || true
source "$SCRIPT_DIR/shared/validation.sh" 2>/dev/null || true
source "$SCRIPT_DIR/shared/runtime.sh" 2>/dev/null || true

APP_ENV="${1:-local}"
PORTS_FILE="$PROJECT_ROOT/storage/app/.devops/ports.env"

# Read runtime ports written by start-app; fall back to .env or defaults
if [[ -f "$PORTS_FILE" ]]; then
    APP_PORT=$(grep -E "^APP_PORT=" "$PORTS_FILE" 2>/dev/null | cut -d= -f2 || echo "")
    VITE_PORT=$(grep -E "^VITE_PORT=" "$PORTS_FILE" 2>/dev/null | cut -d= -f2 || echo "")
else
    APP_PORT=$(get_env_var "APP_PORT" "")
    VITE_PORT=$(get_env_var "VITE_PORT" "")
fi
APP_PORT="${APP_PORT:-8000}"
VITE_PORT="${VITE_PORT:-5173}"

FRONTEND_DOMAIN=$(get_env_var "FRONTEND_DOMAIN" "")

echo -e "${YELLOW}Stopping ${APP_ENV} environment services (Laravel ${APP_PORT}, Vite ${VITE_PORT})...${NC}"
stop_dev_services "$APP_ENV" "$APP_PORT" "$VITE_PORT"

rm -f "$PORTS_FILE"
echo -e "\n${GREEN}✓ Services stopped.${NC}"
