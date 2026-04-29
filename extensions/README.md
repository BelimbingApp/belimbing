# Extensions

Licensee and third-party modules live here, following the `{owner}/{module}/` layout:

```
extensions/
├── sb-group/          # Licensee, prefer kebab-case naming convention
│   ├── quality/
│   └── logistics/
└── some-vendor/       # Third-party vendor
    └── reporting/
```

Each module mirrors BLB's internal structure — include only what's needed:

```
{owner}/{module}/
├── Config/
├── Database/
│   ├── Migrations/
│   └── Seeders/
├── Livewire/
├── Models/
├── Services/
├── Routes/
├── Tests/
└── ServiceProvider.php
```

## Tests

Extension tests live inside the extension module at `extensions/{owner}/{module}/Tests/`. This keeps licensee tests co-located with licensee code and outside BLB's core test suite.

```
extensions/sb-group/quality/
└── Tests/
    ├── Feature/
    └── Unit/
```

Run extension tests with:

```bash
php artisan test extensions/sb-group/quality/Tests
```

**Resources:** licensee views, assets, and theming under `resources/extensions/` are covered in [resources/extensions/README.md](../resources/extensions/README.md).

**Guides:**

- [Licensee Development Guide](../docs/guides/extensions/licensee-development-guide.md) — fork model, directory boundaries, decision rubric
- [Private Extension Repositories](../docs/guides/extensions/private-extension-repositories.md) — nested private git repos for licensee code that must not be pushed to the framework remote
- [Database Migrations](../docs/guides/extensions/database-migrations.md) — table naming, migration conventions
- [Config Overrides](../docs/guides/extensions/config-overrides.md) — merging and overriding configuration
- [File Structure](../docs/architecture/file-structure.md) — full directory layout reference
