# Authorization Module (app/Base/Authz)

This module manages the framework's authorization system, including roles, capabilities, and permission evaluation.

## Configuration & State Synchronization

The authorization system is **hybrid**:
- **Config (Authority for Existence)**: The `CapabilityRegistry` (built from `authz.php`) defines the "vocabulary" of the system. If a capability is not in the config, it is denied immediately by the `KnownCapabilityPolicy` on every request.
- **Database (Authority for Assignments)**: The database stores role-to-capability mappings and user-specific grants. This is where the actual "who can do what" state lives.

### ⚠️ CRITICAL: Synchronizing Database with Config

Whenever you modify any `authz.php` configuration file (adding/removing capabilities or updating role assignments), you **MUST** synchronize the database to reflect these changes in the UI and permission checks. This is because the database acts as the runtime source for grants, even though the config defines their existence.

#### Production / Staging (Non-destructive)
Run the specialized seeder to update role-capability mappings without wiping other data:

```bash
php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder"
```

#### Development (Destructive)
If you are doing a fresh install or a full reseed:

```bash
php artisan migrate:fresh --seed --dev
```

### Key Principles

1. **Explicit Synchronization**: The system does not automatically sync database roles with config on every request for performance reasons. Manual seeding is required after config edits.
2. **Capability Grammar**: All capability keys must follow the `<domain>.<resource>.<action>` format (e.g., `admin.system.log.list`).
3. **Effective Permissions**: The `EffectivePermissions` service combines role grants, direct allows, and explicit denies. Direct denies always win.

## Reference Files

- **Base Config**: [authz.php](Config/authz.php) — System roles and framework-level capabilities.
- **Role Seeder**: [AuthzRoleCapabilitySeeder.php](Database/Seeders/AuthzRoleCapabilitySeeder.php) — The authoritative sync tool.
- **Documentation**: [docs/architecture/authorization.md](../../../docs/architecture/authorization.md) — Full architecture details.
