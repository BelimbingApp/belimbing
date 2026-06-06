#!/usr/bin/env bash

# Refresh BLB after dependency manifest changes with one command.
#
# Usage:
#   ./scripts/update.sh
#
# Run this after dependency manifest or lockfile changes, including after
# pulling remote updates. It uses Composer's lock validation to choose install
# versus update, refreshes Bun dependencies, republishes PHP-managed assets, and
# rebuilds frontend assets.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=shared/colors.sh
source "$SCRIPT_DIR/shared/colors.sh" 2>/dev/null || true

usage() {
    echo "Usage: $0" >&2
    exit 1
}

should_run_composer_update() {
    local composer_lock="$PROJECT_ROOT/composer.lock"
    local validation_output

    if [[ ! -f "$composer_lock" ]]; then
        return 0
    fi

    if validation_output="$(cd "$PROJECT_ROOT" && composer validate --check-lock --no-check-publish 2>&1)"; then
        return 1
    fi

    if grep -Eiq 'lock file .* not up to date|lock file is not up to date' <<< "$validation_output"; then
        return 0
    fi

    printf '%s\n' "$validation_output" >&2
    exit 1
}

run_composer_refresh() {
    if ! command -v composer >/dev/null 2>&1; then
        echo -e "${RED}✗${NC} Composer is not available" >&2
        exit 1
    fi

    if should_run_composer_update; then
        echo -e "${CYAN}Composer manifest changed; updating PHP dependencies...${NC}"
        (cd "$PROJECT_ROOT" && composer update --no-interaction --prefer-dist --optimize-autoloader)
    else
        echo -e "${CYAN}Composer lockfile is current; installing PHP dependencies...${NC}"
        (cd "$PROJECT_ROOT" && composer install --no-interaction --prefer-dist --optimize-autoloader)
    fi

    echo -e "${CYAN}Publishing Composer-managed assets...${NC}"
    (cd "$PROJECT_ROOT" && php artisan vendor:publish --tag=laravel-assets --ansi --force)
    (cd "$PROJECT_ROOT" && php artisan vendor:publish --tag=livewire:assets --ansi --force)
}

run_package_refresh() {
    if ! command -v bun >/dev/null 2>&1; then
        echo -e "${RED}✗${NC} Bun is not available" >&2
        exit 1
    fi

    echo -e "${CYAN}Refreshing Bun dependencies...${NC}"
    (cd "$PROJECT_ROOT" && bun install)

    echo -e "${CYAN}Building frontend assets...${NC}"
    (cd "$PROJECT_ROOT" && bun run build)
}

main() {
    if [[ $# -ne 0 ]]; then
        usage
    fi

    run_composer_refresh
    run_package_refresh
}

main "$@"