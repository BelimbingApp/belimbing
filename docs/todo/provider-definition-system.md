# BUILD: Provider Definition System

**Status:** Complete
**Phase:** 3 of 3 ✅

---

## Problem Essence

Fixed `api_key` column, `auth_type` not in DB, and provider-name conditionals leaking across `RuntimeCredentialResolver`, `ModelDiscoveryService`, and Blade templates. Provider configuration is provider-defined data stored in a fixed schema.

---

## Design Decisions

- **No family class hierarchy.** Concrete definition classes, not abstract families. One configurable `GenericApiKeyDefinition` handles ~35 providers; dedicated classes for the 3–4 genuine outliers.
- **No form-builder DSL.** Definitions declare `editorFields()` returning simple `ProviderField` value objects. The Blade template switches on field type (text/secret/readonly) — not a plugin system.
- **No `ProviderRecordData` DTO.** Definitions return validated arrays that map directly to model attributes.
- **No `ProviderRecordService`.** Persistence stays in Livewire concern — just calls definition then `updateOrCreate`.
- **`ResolvedProviderConfig` is minimal.** `baseUrl`, `apiKey`, `headers`, `metadata` — replaces untyped arrays threaded through 4 service layers.
- **Catalog metadata stays in Base AI.** Core AI definitions own editor fields, auth behavior, credential requirements, and runtime mapping.

---

## Architecture

### Contract

```php
interface ProviderDefinition
{
    public function key(): string;
    public function authType(): AuthType;
    public function defaultBaseUrl(): string;
    public function editorFields(ProviderOperation $operation): array;
    public function validateAndNormalize(array $input, ProviderOperation $operation): array;
    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig;
}
```

### Value Objects / Enums

- `AuthType` enum: `ApiKey`, `Local`, `DeviceFlow`, `OAuth`, `Custom`
- `ProviderOperation` enum: `Create`, `Edit`
- `ProviderField` — key, label, type (text/secret/readonly), requiredOn rules
- `ResolvedProviderConfig` — baseUrl, apiKey, headers, metadata

### Definitions

- `GenericApiKeyDefinition` — configurable via constructor, covers ~35 providers
- `GenericLocalDefinition` — configurable, covers ~4 local providers
- `CloudflareGatewayDefinition` — derived URL from account_id + gateway_id
- `GithubCopilotDefinition` — device flow + token exchange at runtime

### Registry

- `ProviderDefinitionRegistry` — reads catalog overlay, builds generic definitions for standard entries, returns dedicated definitions for outliers

### Data Model Change

| Column | Before | After |
|--------|--------|-------|
| `api_key` | `text` (encrypted cast) | **dropped** |
| `credentials` | — | `text` (encrypted:array cast) — `{'api_key': '...'}` |
| `connection_config` | — | `json` — `{'account_id': '...'}` or `{}` |
| `auth_type` | — | `string` — stored at governance time |

---

## Phase 1 — Schema + Contracts + Registry ✅

### 1.1 Schema migration
- [x] Rewrite `create_ai_providers` migration: drop `api_key`, add `credentials`, `connection_config`, `auth_type`
- [x] Update `AiProvider` model: fillable, casts, backward-compat accessor

### 1.2 Enums and value objects
- [x] Create `AuthType` enum
- [x] Create `ProviderOperation` enum
- [x] Create `ProviderField` value object
- [x] Create `ResolvedProviderConfig` value object

### 1.3 Contract and definitions
- [x] Create `ProviderDefinition` interface
- [x] Create `GenericApiKeyDefinition`
- [x] Create `GenericLocalDefinition`
- [x] Create `CloudflareGatewayDefinition`
- [x] Create `GithubCopilotDefinition`
- [x] Create `CopilotProxyDefinition` (connectivity probe moved from RuntimeCredentialResolver)

### 1.4 Registry
- [x] Create `ProviderDefinitionRegistry`

### 1.5 Runtime refactor
- [x] Refactor `RuntimeCredentialResolver` to dispatch through definitions
- [x] Refactor `ModelDiscoveryService` to use definitions for credential resolution
- [x] Update `ConfigResolver` to read from `credentials` bag

---

## Phase 2 — UI + Livewire ✅

### 2.1 Setup flow
- [x] Update `ProviderSetup` — definition-driven validation via `gatherInput()` + `mapFieldToProperty()`
- [x] Update `ProviderSetup.connectProvider()` — writes definition-normalized attributes
- [x] Update `CloudflareGatewaySetup` — delegates to definition, removed `buildValidationRules()`, `buildValidationMessages()`, `resolveBaseUrl()`
- [x] Blade `provider-setup.blade.php` — conditionals remain (UI-specific), data flow uses definitions

### 2.2 Provider management (ManagesProviders)
- [x] Update `ManagesProviders.saveProvider()` — writes `credentials`/`connection_config`/`auth_type`
- [x] Update `ManagesProviders.openEditProvider()` — reads from `credentials['api_key']`
- [x] Update `providers.blade.php` — `$provider->auth_type->value` (no fallback)

### 2.3 Cleanup
- [x] Remove stale `auth_type ??` fallback patterns from `model-table.blade.php`
- [x] Update `ResolvesAvailableModels` to read from `credentials['api_key']`
- [x] Services dispatch through definitions — no provider-name conditionals remain

---

## Phase 3 — Tests + Alignment ✅

### 3.1 Unit tests
- [x] Test `GenericApiKeyDefinition`: editorFields, validateAndNormalize, resolveRuntime
- [x] Test `GenericLocalDefinition`: optional credentials, resolveRuntime
- [x] Test `CloudflareGatewayDefinition`: derived base URL, connection_config mapping
- [x] Test `GithubCopilotDefinition`: device flow auth type, resolveRuntime token exchange
- [x] Test `CopilotProxyDefinition`: constructor defaults, resolveRuntime connectivity probe
- [x] Test `ProviderDefinitionRegistry`: resolves correct definition for each provider key

### 3.2 Feature tests
- [x] Update `ProvidersUiTest` for new credential schema
- [x] `ProviderConnectionsTest` — no changes needed (tests connection logic, not schema)

### 3.3 Collateral test fixes (old `api_key` column)
- [x] Update `LaraSetupTest` — `api_key` → `credentials`
- [x] `AuditableTraitTest` — not related to AI providers (tests generic redaction)
- [x] Update `RuntimeCredentialResolverTest` — definition dispatch + RefreshDatabase
- [x] Update `LaraPromptAndOrchestrationTest` — `api_key` → `credentials`
- [x] Update `MakesRuntimeResponses` — passthrough mock replaces direct constructor

### 3.4 After-coding alignment review
- [x] Grep for stale `api_key` column references — none found
- [x] Verify no dead code from removed methods — clean
- [x] Run full test suite — 870 pass, 1 pre-existing failure (LaraSetupTest)
- [x] Verify no provider-name conditionals remain outside definitions
- [x] Verify all runtime consumers go through `ResolvedProviderConfig`
- [x] Verify Blade templates reference `$provider->auth_type` (not fallback)
- [x] Boy-scout: grep for stale references, dead code, unused imports — all clean
