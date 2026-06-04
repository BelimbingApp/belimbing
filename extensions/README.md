# Extensions

Licensee and third-party modules live here, following the `{owner}/{module}/` layout:

```
extensions/
├── sb-group/          # Licensee, prefer kebab-case naming convention
│   ├── qac/
│   └── ibp/
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
├── Views/
├── Tests/
└── ServiceProvider.php
```

Module-owned Blade views live in `Views/` and are registered by the module's
`ServiceProvider` with a view namespace. Do not create a companion
`resources/extensions/{owner}/` tree.

## Tests

Extension tests live inside the extension module at `extensions/{owner}/{module}/Tests/`. This keeps licensee tests co-located with licensee code and outside BLB's core test suite.

```
extensions/sb-group/qac/
└── Tests/
    ├── Feature/
    └── Unit/
```

Run extension tests with:

```bash
php artisan test extensions/sb-group/qac/Tests
```

**Guides:**

- [Private Extension Repositories](../docs/guides/extensions/private-extension-repositories.md) — nested private git repos for licensee code that must not be pushed to the framework remote
- [Database Migrations](../docs/guides/extensions/database-migrations.md) — table naming, migration conventions
- [Config Overrides](../docs/guides/extensions/config-overrides.md) — merging and overriding configuration
- [File Structure](../docs/architecture/file-structure.md) — full directory layout reference
