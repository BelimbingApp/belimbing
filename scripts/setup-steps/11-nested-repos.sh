#!/usr/bin/env bash
# scripts/setup-steps/11-nested-repos.sh
# Title: Nested Domain Repos
# Purpose: Clone the non-Core domain repos into app/Modules/
# Usage: ./scripts/setup-steps/11-nested-repos.sh [local|staging|production|testing]
# Can be run standalone or called by main setup.sh
#
# The framework repo tracks only app/Base and app/Modules/Core. Every other
# domain is a nested-git checkout (see docs/architecture/module-system.md,
# "Nested-Git Distribution"). This script clones any missing domain repo into
# its mount path and leaves existing checkouts untouched.
#
# Private licensee extension repos (extensions/{licensee}) are deliberately
# not handled here; clone those manually per
# docs/guides/extensions/private-extension-repositories.md.

set -euo pipefail

# Get script directory and project root
SETUP_STEPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"  # Points to scripts/setup-steps/
SCRIPTS_DIR="$(cd "$SETUP_STEPS_DIR/.." && pwd)"  # Points to scripts/
PROJECT_ROOT="$(cd "$SCRIPTS_DIR/.." && pwd)"  # Points to project root

# Source shared utilities
# shellcheck source=../shared/colors.sh
source "$SCRIPTS_DIR/shared/colors.sh" 2>/dev/null || true

# Domain repos: "<mount path relative to project root>=<clone URL>"
readonly -a DOMAIN_REPOS=(
    "app/Modules/Commerce=https://github.com/BelimbingApp/blb-commerce.git"
    "app/Modules/Operation=https://github.com/BelimbingApp/blb-operation.git"
    "app/Modules/People=https://github.com/BelimbingApp/blb-people.git"
)

echo "Nested domain repos"

for entry in "${DOMAIN_REPOS[@]}"; do
    mount_path="${entry%%=*}"
    url="${entry##*=}"
    target="$PROJECT_ROOT/$mount_path"

    if [[ -d "$target/.git" ]]; then
        echo -e "${GREEN:-}✓${NC:-} $mount_path already checked out"
        continue
    fi

    if [[ -d "$target" ]] && [[ -n "$(ls -A "$target" 2>/dev/null)" ]]; then
        echo -e "${YELLOW:-}!${NC:-} $mount_path exists with files but no .git — skipping (resolve manually)"
        continue
    fi

    echo "Cloning $url into $mount_path ..."
    git clone "$url" "$target"
    echo -e "${GREEN:-}✓${NC:-} $mount_path cloned"
done

echo "Nested domain repos done. Discovery integrates them automatically; no registration step."
