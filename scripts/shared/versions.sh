#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-only
# (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
#
# Belimbing Version Management
# Single source of truth for all software versions used across setup scripts
#
# Purpose:
#   Centralize version definitions to ensure consistency across all setup and
#   requirements checking scripts. This file provides both minimum required
#   versions and latest recommended versions for all dependencies.
#
# Usage:
#   Source this file in your script:
#     source "$SCRIPT_DIR/shared/versions.sh"
#
#   Or via config.sh (automatically sourced):
#     source "$SCRIPT_DIR/shared/config.sh"
#
# How to Update Versions:
#   1. Update the version constants in this file
#   2. All scripts using these constants will automatically use the new versions
#   3. Test the affected scripts to ensure compatibility
#
# Example:
#   REQUIRED_PHP_VERSION=$(get_required_php_version)
#   if check_php_version_meets_minimum "$current_version"; then
#       echo "PHP version is sufficient"
#   fi

# === Minimum Required Versions ===
# These define the minimum versions that must be installed

# PHP: Minimum required major.minor version
REQUIRED_PHP_MAJOR=8
REQUIRED_PHP_MINOR=5

# === Latest Recommended Versions ===
# These define the latest stable versions recommended for installation

# Git: Latest stable version
LATEST_GIT_VERSION="2.53.0"

# Bun: Latest stable version (without 'v' prefix for consistency)
LATEST_BUN_VERSION="1.3.11"

# === Service Versions ===
# Versions for services that are installed

# PostgreSQL: Version to install
POSTGRESQL_VERSION="18"

# Redis: Version to install
REDIS_VERSION="8"

# sqlite-vec: Pinned fallback version (used when GitHub API is unreachable)
SQLITE_VEC_VERSION="v0.1.7"

# === Package Manager Specific Versions ===
# These are generated from the base versions above to maintain single source of truth

# === Helper Functions ===

# Get required PHP version string (e.g., "8.5")
# Usage: version=$(get_required_php_version)
get_required_php_version() {
    echo "${REQUIRED_PHP_MAJOR}.${REQUIRED_PHP_MINOR}"
    return 0
}

# Get latest Git version (pinned fallback)
# Usage: version=$(get_latest_git_version)
get_latest_git_version() {
    echo "$LATEST_GIT_VERSION"
    return 0
}

# Resolve latest Git version from the GitHub tags API.
# Falls back to the pinned LATEST_GIT_VERSION if the API is unreachable.
# Usage: version=$(resolve_latest_git_version)
resolve_latest_git_version() {
    local fallback
    fallback=$(get_latest_git_version)

    local latest
    latest=$(curl -fsSL --max-time 5 \
        'https://api.github.com/repos/git/git/tags?per_page=10' 2>/dev/null \
        | grep '"name"' \
        | grep -v '\-rc' \
        | head -1 \
        | sed 's/.*"name": *"v\([^"]*\)".*/\1/')

    if [[ -n "$latest" ]]; then
        echo "$latest"
    else
        echo "$fallback"
    fi
}

# Resolve latest Bun version from the GitHub releases API.
# Falls back to the pinned LATEST_BUN_VERSION if the API is unreachable.
# Usage: version=$(resolve_latest_bun_version)
resolve_latest_bun_version() {
    local fallback
    fallback="$LATEST_BUN_VERSION"

    local latest
    latest=$(curl -fsSL --max-time 5 \
        'https://api.github.com/repos/oven-sh/bun/releases/latest' 2>/dev/null \
        | grep '"tag_name"' \
        | sed 's/.*"tag_name": *"\(bun-\)\{0,1\}v\{0,1\}\([^"]*\)".*/\2/')

    if [[ -n "$latest" ]]; then
        echo "$latest"
    else
        echo "$fallback"
    fi
}

# Get latest Bun version (resolved via API with pinned fallback)
# Usage: version=$(get_latest_bun_version)
get_latest_bun_version() {
    resolve_latest_bun_version
    return 0
}

# Get Bun version with 'v' prefix for display
# Usage: display_version=$(get_latest_bun_version_with_prefix)
get_latest_bun_version_with_prefix() {
    echo "v$(get_latest_bun_version)"
    return 0
}

# Get PostgreSQL version
# Usage: version=$(get_postgresql_version)
get_postgresql_version() {
    echo "$POSTGRESQL_VERSION"
    return 0
}

# Get PostgreSQL version for Homebrew (with @ prefix)
# Usage: brew_version=$(get_postgresql_brew_version)
get_postgresql_brew_version() {
    echo "postgresql@$(get_postgresql_version)"
    return 0
}

# Get Redis version
# Usage: version=$(get_redis_version)
get_redis_version() {
    echo "$REDIS_VERSION"
    return 0
}

# Get sqlite-vec pinned fallback version
# Usage: version=$(get_sqlite_vec_version)
get_sqlite_vec_version() {
    echo "$SQLITE_VEC_VERSION"
    return 0
}

# Compare two version strings (semantic versioning)
# Returns: 0 if v1 == v2, 1 if v1 > v2, 2 if v1 < v2
# Usage: result=$(compare_version "1.2.3" "1.2.4")
#        if [ "$result" -eq 2 ]; then echo "v1 is older"; fi
compare_version() {
    local v1=$1
    local v2=$2

    # Remove 'v' prefix if present
    v1=${v1#v}
    v2=${v2#v}

    # Split versions into major.minor.patch arrays
    local v1_parts v2_parts
    IFS='.' read -ra v1_parts <<< "$v1"
    IFS='.' read -ra v2_parts <<< "$v2"

    # Compare major, minor, patch in order
    local i=0
    while [[ $i -lt 3 ]]; do
        local part1=${v1_parts[$i]:-0}
        local part2=${v2_parts[$i]:-0}

        # Remove non-numeric suffixes (e.g., "8.5.0-dev" -> "8.5.0")
        part1=${part1%%[^0-9]*}
        part2=${part2%%[^0-9]*}

        # Default to 0 if empty
        part1=${part1:-0}
        part2=${part2:-0}

        if [[ "$part1" -gt "$part2" ]]; then
            return 1  # v1 > v2
        elif [[ "$part1" -lt "$part2" ]]; then
            return 2  # v1 < v2
        fi

        ((i++))
    done

    return 0  # v1 == v2
}

# Check if a version string meets minimum requirement
# Usage: if version_meets_minimum "8.6.0" "$REQUIRED_PHP_MAJOR" "$REQUIRED_PHP_MINOR"; then
#           echo "Version is sufficient"
#        fi
version_meets_minimum() {
    local current_version=$1
    local required_major=$2
    local required_minor=$3

    # Remove 'v' prefix if present
    current_version=${current_version#v}

    # Extract major and minor from current version
    local current_major current_minor
    current_major=$(echo "$current_version" | cut -d. -f1)
    current_minor=$(echo "$current_version" | cut -d. -f2)

    # Remove non-numeric suffixes
    current_major=${current_major%%[^0-9]*}
    current_minor=${current_minor%%[^0-9]*}

    # Default to 0 if empty
    current_major=${current_major:-0}
    current_minor=${current_minor:-0}

    # Compare: major version must be greater, or equal with minor >= required
    if [[ "$current_major" -gt "$required_major" ]]; then
        return 0  # Meets requirement
    elif [[ "$current_major" -eq "$required_major" ]] && [[ "$current_minor" -ge "$required_minor" ]]; then
        return 0  # Meets requirement
    else
        return 1  # Does not meet requirement
    fi
}

# Check if PHP version meets minimum requirement (8.5+)
# Usage: if check_php_version_meets_minimum "8.6.0"; then
#           echo "PHP version is sufficient"
#        fi
check_php_version_meets_minimum() {
    local current_version=$1
    version_meets_minimum "$current_version" "$REQUIRED_PHP_MAJOR" "$REQUIRED_PHP_MINOR"
}

# Check if Git version is at least the latest recommended version
# Usage: if check_git_version_meets_latest "2.52.0"; then
#           echo "Git is up to date"
#        fi
check_git_version_meets_latest() {
    local current_version=$1
    compare_version "$current_version" "$LATEST_GIT_VERSION"
    local result=$?
    # Returns 0 if current >= latest, non-zero otherwise
    [[ $result -eq 0 ]] || [[ $result -eq 1 ]]
}

# Parse version string into major.minor.patch components
# Usage: parse_version "8.5.3" major minor patch
#        echo "Major: $major, Minor: $minor, Patch: $patch"
parse_version() {
    local version=$1
    # Remove 'v' prefix if present
    version=${version#v}

    # Assign positional params to named locals before using them in evals
    local major_var=$2
    local minor_var=$3
    local patch_var=$4

    # Export variables (using eval since we're setting caller's variables)
    eval "$major_var=$(echo "$version" | cut -d. -f1)"
    eval "$minor_var=$(echo "$version" | cut -d. -f2)"
    eval "$patch_var=$(echo "$version" | cut -d. -f3)"

    # Clean up non-numeric suffixes
    eval "$major_var=\${$major_var%%[^0-9]*}"
    eval "$minor_var=\${$minor_var%%[^0-9]*}"
    eval "$patch_var=\${$patch_var%%[^0-9]*}"

    # Default to 0 if empty
    eval "if [ -z \"\$$major_var\" ]; then $major_var=0; fi"
    eval "if [ -z \"\$$minor_var\" ]; then $minor_var=0; fi"
    eval "if [ -z \"\$$patch_var\" ]; then $patch_var=0; fi"
    return 0
}