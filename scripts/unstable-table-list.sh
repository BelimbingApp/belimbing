#!/usr/bin/env bash
set -euo pipefail

readonly SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
readonly PROJECT_ROOT=$(cd -- "${SCRIPT_DIR}/.." && pwd)

cd "${PROJECT_ROOT}"

php artisan blb:table:unstable \
  base_audit_mutations \
  base_authz_principal_capabilities \
  base_integration_oauth_tokens \
  commerce_catalog_attributes \
  commerce_inventory_items \
  commerce_marketplace_listing_drafts \
  commerce_marketplace_metadata \
  commerce_marketplace_listings \
  ai_browser_sessions \
  ai_channel_conversations \
  ai_channel_conversation_messages \
  company_relationships \
  sbg_quality_* \
  'people_*' \
  'payroll_*'
