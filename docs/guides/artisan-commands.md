# Artisan Command Examples

**Document Type:** Developer Guide
**Last Updated:** 2026-08-05

## Run Specific Migration Files

Pass `--path` once per migration file. Explicit paths do not bypass BLB's global Module dependency preflight.

```bash
php artisan migrate --path=app/Core/AI/Database/Migrations/0200_02_01_000017_rename_max_tool_iterations_setting.php --path=app/Domains/Commerce/Inventory/Database/Migrations/0310_01_01_000003_repair_commerce_inventory_item_photo_state.php
```
