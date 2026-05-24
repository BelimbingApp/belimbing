#!/usr/bin/env bash
set -euo pipefail

# Deprecated compatibility bridge.
# Keep this list only while migrating existing under-development tables toward
# migration-local `use IncubatingSchema;` declarations. `php artisan migrate --dev`
# still reads these patterns so other installations can rebuild the same schema.
# Long term, move each entry into the owning migration file and delete it here.

readonly SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
readonly PROJECT_ROOT=$(cd -- "${SCRIPT_DIR}/.." && pwd)
readonly BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS=(
  base_audit_mutations
  base_audit_actions
  base_authz_decision_logs
  base_authz_principal_capabilities
  base_integration_oauth_tokens
  commerce_catalog_attributes
  commerce_inventory_item_fitments
  commerce_inventory_items
  commerce_marketplace_*
  ai_browser_sessions
  ai_channel_conversations
  ai_channel_conversation_messages
  company_relationships
  sbg_quality_*
  sbg_ibp_*
  people_*
  payroll_*
)

cd "${PROJECT_ROOT}"

printf '%s\n' 'Deprecated: scripts/unstable-table-list.sh is now a compatibility bridge.' >&2
printf '%s\n' 'Move each table pattern into the owning migration file with use IncubatingSchema;' >&2
printf '%s\n' 'migrate --dev already reads this script while the transition is in progress.' >&2
printf '%s\n' 'Use the patterns below to locate affected tables and update their owning migrations on other installations.' >&2
printf '\n' >&2

for pattern in "${BLB_DEPRECATED_UNSTABLE_TABLE_PATTERNS[@]}"; do
  printf '%s\n' "${pattern}"
done
