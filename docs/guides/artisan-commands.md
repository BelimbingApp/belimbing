## Run specific migration files (idempotent)

Example that run 2 migration files:

```bash
php artisan migrate --path=app/Modules/Core/AI/Database/Migrations/0200_02_01_000017_add_family_to_ai_providers_and_migrate_image_credentials.php --path=app/Modules/Commerce/Inventory/Database/Migrations/0310_01_01_000003_add_use_cleaned_photo_to_commerce_inventory_item_photos_table.php
```