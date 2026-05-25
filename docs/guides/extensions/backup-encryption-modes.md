# Extension Backup Encryption Modes

This guide is for extension authors who want to add a backup encryption mode to Belimbing.

## Registration

Core ships two modes: `none` and `app-key`. Extensions may register additional modes by calling `EncryptionModeRegistry::register()` from a service provider `boot()` method.

```php
// In your extension's ServiceProvider::boot()
public function boot(): void
{
    $this->app->make(\App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry::class)
        ->register('ext-acme-kms', function (array $config): \App\Base\Database\Services\Backup\Encryption\EncryptionMode {
            return new AcmeKmsEncryption($config['encryption']['kms_key'] ?? '');
        });
}
```

Register in `boot()`, not `register()`, so the singleton is resolved after it is bound.

## Contract

| Rule | Detail |
|------|--------|
| **Vendor-prefixed name** | Use `ext-{vendor}-{descriptor}` such as `ext-acme-kms`. Names `none`, `app-key`, and other unprefixed names are reserved for core. |
| **Stable manifest identifier** | The string passed to `register()` is written to `manifest.encryption_mode`. Do not rename it after artifacts exist. |
| **No plaintext on the storage disk** | `encryptFile()` must never leave plaintext on the configured backup disk. Short-lived temp files in `sys_get_temp_dir()` are acceptable when needed, but they must be `chmod 0600` and unlinked in a `finally`. |
| **Fail closed in `ensureReady()`** | Throw `BackupException::configurationInvalid()` or `BackupException::toolingMissing()` when configuration, key material, or tooling is missing. Never fall back to plaintext. |
| **Configuration ownership** | Extension-specific settings live under `backup.encryption.*` keys chosen by the extension author. Core does not interpret them. |
| **Restore and rotation docs** | Extension authors own the operator runbook for decrypt, rotation, and incident response. |

## When to use an extension mode

- Use `app-key` for most self-hosted installs that do not need an external key-management system.
- Use an extension mode when the deployment needs IAM-bound decrypt, operator escrow, HSM-backed keys, or another threat-model-specific workflow.

## Related Documentation

- [Database Migration Guidelines](../../../app/Base/Database/AGENTS.md)
- [Extension Database Migrations](./database-migrations.md)
