# Extension Configuration and Runtime Settings

**Status:** Approved target; settings migration in progress
**Last Updated:** 2026-07-23
**Canonical contract:** `docs/architecture/settings.md`

Extensions contribute two different kinds of configuration. Keep them separate:

1. Structural definitions live in versioned module or extension code.
2. Runtime parameters live in `base_settings` with declared code defaults and authorized UI.

Environment variables are reserved for inputs required before database-backed settings are available and values consumed by external development, build, deployment, or CI tools. They are not extension runtime-parameter overrides.

## Structural Definitions

Structural configuration describes what the extension is, not how an operator has configured one installation. Examples include:

- supported types and adapters;
- capability definitions;
- route or discovery metadata;
- immutable algorithm choices;
- module-owned registries.

Keep structural definitions in the extension’s `Config/` directory and merge them under a stable, namespaced Laravel config key from the extension provider:

```php
final class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/quality.php',
            'quality',
        );
    }
}
```

```php
<?php

return [
    'relationship_types' => [
        [
            'code' => 'vendor',
            'label' => 'Vendor',
        ],
    ],
];
```

Structural config is versioned and deployed with the extension. Do not publish it as an operator settings surface and do not derive mutable runtime behavior from arbitrary `config()->set()` calls.

## Runtime Parameters

An extension runtime parameter is a value an authorized operator or user may change after Belimbing can access its database. Examples include:

- integration credentials;
- timeouts and retention;
- executable or storage paths;
- limits and policy knobs;
- user preferences.

Declare runtime parameters in the extension’s `Config/settings.php`. Each definition owns its key, type, allowed scopes, default, validation, encryption, authorization, and UI metadata.

The settings-model migration tracked in `docs/plans/settings-model-evolution.md` will make these definitions the canonical resolver input. The target shape is:

```php
<?php

return [
    'definitions' => [
        'quality.vendor.timeout_seconds' => [
            'type' => 'integer',
            'scopes' => ['company'],
            'default' => 30,
            'rules' => ['required', 'integer', 'min:1', 'max:300'],
            'encrypted' => false,
        ],
    ],
    'editable' => [
        // UI grouping that refers to the definition above.
    ],
    'runtime' => [
        // Internal state claims, not parameter definitions.
        'quality.vendor.last_verified_at',
    ],
];
```

Consumers resolve the parameter through `SettingsService` or a typed extension-owned wrapper:

```php
$timeout = app(SettingsService::class)->get(
    'quality.vendor.timeout_seconds',
    scope: Scope::company($companyId),
);
```

Do not call `env()` or `config()` for a declared runtime parameter. Do not repeat its default or validation in consumers.

## User, Company, and Global Scope

Choose scope according to ownership:

| Scope | Use for |
|-------|---------|
| User | Durable preference of an authenticated account |
| Company | Company policy, integration, or shared behavior |
| Global | Installation-wide parameter or internal state |

Employee is not a settings scope. A user may exist without an employee record.

Definitions declare the allowed fallback chain. A personal preference may allow only user scope, while an organizational parameter may permit company then global fallback.

## Environment-Owned Inputs

An extension may use Laravel config backed by `.env` only when the value is not a Belimbing runtime parameter:

- it is required before the settings database can be reached;
- it configures the database, cache, encryption, server, or another resolver dependency;
- it is consumed by an external build, deployment, CI, or development tool.

Example of an extension bootstrap input:

```php
return [
    'bootstrap_transport' => env('QUALITY_BOOTSTRAP_TRANSPORT', 'local'),
];
```

This exception must be real. An API key, timeout, path, or limit used after application boot normally belongs in `base_settings` and a UI, even when it differs between installations.

## Secrets

Secrets managed by Belimbing belong in encrypted `base_settings` rows. Their definitions declare encryption, and their UI is write-only after save.

Never commit a secret, place it in a structural config file, return it in a settings payload, or log its plaintext. External-tool secrets such as Sonar or deployment tokens remain in that tool’s secret store or the environment because the tool runs outside Belimbing.

## Extension Ownership and Overrides

- Namespace keys to the owning extension or domain.
- Do not override another module’s runtime setting definition implicitly.
- Use an explicit extension point when another owner permits contribution.
- Structural registry extension follows the owning registry’s documented merge or discovery semantics.
- A disabled or removed extension leaves its database rows discoverable as residue; it does not silently transfer ownership.

## Defaults and Restore Behavior

A runtime parameter has one declared code default. A fresh installation works without seeded setting rows.

Saving creates or updates an explicit `base_settings` row. Restoring the default deletes that row; it does not write the default into the database.

This keeps rows meaningful: every row represents an intentional override.

## Testing

Extension tests should prove:

- every consumed runtime parameter has a discovered definition;
- the definition has the intended type, scopes, default, validation, and encryption;
- the authorized UI can save and restore the setting;
- restore removes the row and reveals the declared default;
- secrets are encrypted and masked;
- consumers use `SettingsService`, not `env()` or `config()`;
- structural config still merges without mutating runtime parameters.

## Current Migration Note

The resolver requires a discovered definition for every runtime parameter and a
module-owned runtime claim for internal state. Unknown keys fail closed; there
is no config, `.env`, caller-default, or caller-encryption compatibility path.
Follow the canonical contract here and in `docs/architecture/settings.md`.
