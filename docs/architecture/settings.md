# Settings Architecture

**Document Type:** Architecture Specification
**Status:** Approved target; implementation in progress
**Last Updated:** 2026-07-23
**Related:** `docs/architecture/database.md`, `docs/architecture/authorization.md`, `docs/architecture/module-system.md`, `docs/plans/settings-model-evolution.md`

---

## 1. Problem Essence

Belimbing needs one understandable contract for application settings without treating `.env`, Laravel config, database rows, model preference blobs, and browser storage as interchangeable parameter stores. Every value must have one owner, one resolution path, and a UI when an operator or user is expected to change it.

The settings system is a deep module: callers ask for a declared setting, while the module hides database scoping, defaults, caching, encryption, and authorization.

---

## 2. Configuration Classification

Every configurable value belongs to exactly one category:

| Category | Source of truth | Examples |
|----------|-----------------|----------|
| **Belimbing runtime parameter** | `base_settings`, then its declared code default | AI limits, tool paths, retention, company timezone, user theme |
| **Belimbing runtime state** | `base_settings` or a purpose-built table | Verification timestamps, OAuth state, sync cursors |
| **Environment-owned application input** | `.env` / process environment through Laravel config | `APP_KEY`, database connection, cache driver, app URL, server ports |
| **External tooling input** | `.env`, CI/deployment secrets, or the tool's own secret store | Sonar token, GitHub token, deployment credentials |
| **Structural definition** | Versioned code or module config | Migration prefixes, provider types, capabilities, supported algorithms |

A runtime parameter must not also have a deployment override in `.env` or a Laravel config fallback. Environment-owned and external-tooling values are not read through `SettingsService`.

The runtime parameter mental model is deliberately small:

```text
base_settings → declared code default
```

`base_settings` may contain a value at an allowed user, company, or global scope. The definition supplies the final default when no applicable row exists.

---

## 3. Setting Definitions Are the Contract

Every runtime parameter has one module-owned definition. The definition is the single source of truth for:

- stable dot-notation key;
- value type and nullability;
- allowed scopes and their fallback order;
- declared code default;
- validation and normalization;
- whether the value is encrypted;
- whether and where it is editable in the UI;
- owning module and authorization capability;
- help text needed to make the setting operable.

Editable fields and runtime parameter definitions are the same concept. UI metadata extends a setting definition; it does not create a second default or validation source.

Internal runtime state written through `SettingsService` must still be claimed by its module for residue ownership, but it need not be exposed in a settings UI. High-volume, relational, or independently queryable state belongs in a purpose-built table rather than `base_settings`.

---

## 4. Public Interface

All runtime-parameter callers resolve values through `SettingsService` or a typed domain wrapper that delegates to it. Callers do not query `base_settings`, call `config()`, inspect `.env`, or repeat defaults.

The target read contract is:

```php
interface SettingsService
{
    public function get(string $key, ?Scope $scope = null): mixed;

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys, ?Scope $scope = null): array;

    public function set(
        string $key,
        mixed $value,
        ?Scope $scope = null,
    ): void;

    public function forget(string $key, ?Scope $scope = null): void;

    public function has(string $key, ?Scope $scope = null): bool;
}
```

`get()` obtains the type, allowed scopes, and default from the setting definition. Typed wrappers remain appropriate when a domain needs additional coercion or invariants:

```php
final readonly class AiRuntimeSettings
{
    public function maxToolRounds(): int
    {
        return max(1, (int) $this->settings->get(
            'ai.llm.agentic.max_tool_rounds',
        ));
    }
}
```

`has()` answers whether an explicit row exists at the requested database scope. It does not report whether a declared default exists.

“Restore default” calls `forget()`. It must not copy the default into a database row.

---

## 5. Scope Model

Belimbing supports three runtime-setting scopes:

| Scope | Identity | Intended ownership |
|-------|----------|--------------------|
| **User** | `users.id` | Durable preference of the authenticated account |
| **Company** | `companies.id` | Company policy, shared behavior, or company-owned integration |
| **Global** | `scope_type` and `scope_id` are null | Installation-wide parameter or internal state |

Employee is not a settings scope. Employees are business-domain records; a user is the authenticated principal who owns account preferences. Users without an employee record must still have complete settings behavior.

The target scope types are:

```php
enum ScopeType: string
{
    case USER = 'user';
    case COMPANY = 'company';
}
```

A setting definition declares which scopes are meaningful. Resolution only visits those scopes, from most specific to least specific, before using the declared default.

Common shapes:

| Declared scopes | Resolution |
|-----------------|------------|
| Global | global row → declared default |
| Company, global | company row → global row → declared default |
| User | user row → declared default |
| User, company, global | user row → company row → global row → declared default |

Scope fallback is therefore explicit per setting, not an accidental universal cascade. A personal theme should not silently inherit an unrelated company or installation value unless its definition intentionally allows that behavior.

For a user lookup that permits company fallback, the scope context contains both the authenticated `user_id` and the active `company_id`. The persisted user row is keyed by `users.id`; it does not depend on an employee link.

---

## 6. User Preferences

Durable authenticated-user preferences belong in user-scoped `base_settings`.

| Preference | Scope | Declared default | Notes |
|------------|-------|------------------|-------|
| `ui.theme` | User | `system` | `light`, `dark`, or `system`; follows the account across browsers |
| `ui.timezone.mode` | User | `company` | Chooses company, browser-local, or UTC display |
| `ui.locale` | User | Installation or negotiated locale | User choice overrides the general locale policy |
| Landing page | User | Product-defined landing page | Durable navigation preference |
| Dashboard layout | User | Visible default widget order | Small JSON value is acceptable when read and written as one preference |
| Last-used AI model hints | User | No hint | User interaction preference, optionally keyed by agent |

Timezone separates personal display choice from company data:

- `ui.timezone.mode` is a user preference.
- `localization.timezone` is a company setting containing an IANA timezone.
- Browser-local mode reads the current browser timezone at display time; the device timezone is not copied into `base_settings`.
- With no user row, the declared mode default is `company`. With no company timezone row, `localization.timezone` uses its declared `UTC` default.

Theme follows the same ownership rule:

- An authenticated user’s durable theme is stored in user-scoped `base_settings`.
- `localStorage` may mirror the value to avoid a flash before authentication state is hydrated, but it is not the authenticated source of truth.
- Anonymous or pre-login device preference may remain browser-local.

The existing `users.prefs` JSON column is transitional storage for preferences created before user-scoped settings existed. Preferences that match this contract should migrate to `base_settings`; unrelated relational collections or high-volume user data should use purpose-built tables.

---

## 7. Resolution Algorithm

```text
resolve(key, scope context):
  1. Load the module-owned setting definition.
  2. Build the definition's allowed scope chain from the supplied context.
  3. Return the first explicit base_settings value in that chain.
  4. Return the definition's declared code default.
```

Examples:

| Setting | Applicable rows | Result |
|---------|-----------------|--------|
| AI maximum tool rounds | Global `160` | `160` |
| AI maximum tool rounds | No global row | declared default `100` |
| User theme | User `dark` | `dark` |
| User theme | No user row | declared default `system` |
| User timezone mode | User `local` | `local` |
| User timezone mode | No user row | declared default `company` |
| Company timezone | Company `Asia/Kuala_Lumpur` | `Asia/Kuala_Lumpur` |
| Company timezone | No company row | declared default `UTC` |

Laravel config is not a runtime-parameter layer. Config files remain valid for structural definitions and environment-owned bootstrap inputs, but an operator-editable setting does not fall back to `config()` or `.env`.

---

## 8. Schema

### 8.1 Table: `base_settings`

`base_settings` belongs to Base.

| Column | Type | Description |
|--------|------|-------------|
| `id` | `bigint unsigned` PK | Auto-increment |
| `key` | `varchar(255)` | Stable dot-notation setting key |
| `value` | `json` | JSON-encoded typed value |
| `is_encrypted` | `boolean` | Whether the value is encrypted at rest |
| `scope_type` | `varchar(50)` nullable | `user`, `company`, or null for global |
| `scope_id` | `bigint unsigned` nullable | ID of the scoped entity |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Indexes:

- Unique `(key, scope_type, scope_id)`.
- Index `(scope_type, scope_id)`.
- Prefix ownership and residue queries may use `key LIKE 'namespace.%'`.

Constraints:

- `scope_type` and `scope_id` are both null or both non-null.
- `scope_type` must be an implemented `ScopeType`.
- The application validates that the selected scope is allowed by the setting definition.
- `value` is never null; clearing an override deletes its row.

### 8.2 Installation and Machine-Specific Values

A global row is global to one Belimbing installation database. Machine-specific runtime values, including executable paths, may be stored globally when that installation has one runtime environment or every application node shares the same path contract.

If one database serves heterogeneous application nodes, a machine-specific absolute path cannot honestly be global. Before supporting that deployment shape, Belimbing must either add an installation/node scope or require a portable shared path. The current architecture does not silently invent per-node behavior.

Data Share does not transfer `base_settings` as domain data. Backup and restore may carry installation settings to another machine, so readiness checks and settings UIs must surface invalid paths and provide a safe reset to the declared default.

---

## 9. Module Placement and Discovery

Framework infrastructure lives under `app/Base/Settings`. Modules contribute setting definitions through discovered `Config/settings.php` files.

Target declaration responsibilities:

- `definitions`: exact runtime parameter definitions, including default and scopes;
- `editable`: UI grouping and presentation metadata referring to definitions;
- `runtime`: claims for internal state keys or wildcard namespaces that are not parameters.

Every persisted key must be claimed by its owning module. The Database Residue screen flags unclaimed `base_settings` rows.

Definitions are discovered from Base, modules, and extensions using the module discovery contract. Extensions may add their own namespaced settings but must not mutate another owner’s definition implicitly.

---

## 10. Authorization

Settings authorization follows ownership:

| Capability | Intended actor | Description |
|------------|----------------|-------------|
| `base.settings.manage_global` | Platform administrator | Manage installation-wide settings |
| `base.settings.manage_company` | Authorized company administrator | Manage settings owned by the active company |
| `base.settings.manage_user` | Authenticated user | Manage the user’s own preferences |

Managing personal preferences is not an employee-supervision capability. A company administrator does not automatically gain permission to change a user’s theme, locale, landing page, or timezone mode. Support impersonation or administrative preference repair requires an explicit capability and audit trail.

Reads follow the setting definition and current context. Secrets and write-only values remain masked in UI payloads.

---

## 11. Caching and Encryption

Database rows are cached per key and scope. Because resolved fallback chains are
not cached as composite values, `set()` and `forget()` only need to invalidate
the exact row cache they affect.

The default cache TTL is six hours. TTL is a recovery bound for bypassed or missed invalidation; normal settings changes become visible through explicit cache invalidation.

Cache infrastructure is environment-owned because the settings service depends on it; its connection and driver cannot themselves be runtime settings.

Sensitive values are encrypted with Laravel `Crypt` before database storage, using `APP_KEY`. `APP_KEY` remains environment-owned because it is required to decrypt `base_settings`.

The setting definition declares encryption. Callers and UI components must not independently decide whether a known parameter is encrypted.

---

## 12. What Stays Out of `base_settings`

| Category | Reason |
|----------|--------|
| Application bootstrap inputs | Required before the settings database is available |
| Settings infrastructure inputs | Database, cache, and encryption are dependencies of the resolver |
| External tooling inputs | Sonar, CI, deployment, and repository tools run outside Belimbing |
| Structural definitions | Versioned product behavior, not mutable runtime parameters |
| Relational or high-volume data | Needs purpose-built schema, querying, and lifecycle |
| Anonymous/device-only UI state | No authenticated owner; may remain in browser storage |

`.env` is therefore not “bootstrap only.” It contains environment-owned application inputs and may also be consumed by external development, build, deployment, or CI tooling. It is not a general-purpose fallback for Belimbing runtime parameters.

---

## 13. Current Implementation Divergence

This document records the approved target before implementation, by request.

Current code still differs in material ways:

- Canonical definitions and definition-aware resolution are implemented for the AI tool-round limit, `pdftotext` path, all five Performance instrumentation controls, company timezone, and user timezone display mode.
- System → Diagnostics → Performance provides authorized global editing and restore-default behavior; instrumentation and pruning no longer read `PERF_*` environment or config fallbacks.
- User scope is implemented. Employee scope remains temporarily available only for undeclared legacy settings while their account preferences are migrated.
- Undeclared legacy keys still fall back to Laravel config and caller-supplied defaults.
- The legacy method signatures still expose caller-supplied read defaults and
  caller-selected encryption for undeclared keys; definitions own both
  concerns for migrated parameters.
- Callers may supply repeated defaults instead of resolving a declared definition.
- Some durable user preferences live in `users.prefs`.
- Theme is stored only in browser `localStorage`.
- Company timezone and user timezone mode use their canonical company- and user-scoped definitions.
- Most runtime parameters have not yet migrated to canonical definitions and may still read from config or `.env`.

`docs/plans/settings-model-evolution.md` is the implementation and migration source of truth. Until its phases land, code behavior—not this target contract—describes production behavior.

---

## 14. Related Documentation

- `docs/plans/settings-model-evolution.md` — migration plan and status
- `docs/architecture/module-system.md` — configuration ownership and module discovery
- `docs/architecture/database.md` — Base table and migration conventions
- `docs/architecture/authorization.md` — capability and principal model
- `.env.example` — concise environment-owned configuration guidance
