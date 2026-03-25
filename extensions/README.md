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

**Guides:**

- [Licensee Development Guide](docs/guides/licensee-development-guide.md) — fork model, directory boundaries, decision rubric
- [Database Migrations](docs/guides/extensions/database-migrations.md) — table naming, migration conventions
- [Config Overrides](docs/guides/extensions/config-overrides.md) — merging and overriding configuration
- [File Structure](docs/architecture/file-structure.md) — full directory layout reference
