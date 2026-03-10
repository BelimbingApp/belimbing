# Settings Architecture

**Document Type:** Architecture Specification
**Status:** Phase 1 Implemented
**Last Updated:** 2026-03-10
**Related:** `docs/architecture/database.md`, `docs/architecture/authorization.md`

---

## 1. Problem Essence

BLB needs a general-purpose, multi-tenant configuration system that resolves settings through a layered cascade — from framework defaults to per-scope overrides — without proliferating per-module config tables.

---

## 2. Public Interface

All callers resolve settings through one service contract. The service hides the resolution chain behind a simple read/write interface.

```php
interface SettingsService
{
    /**
     * Resolve a setting value through the cascade.
     *
     * @param  string  $key  Dot-notation key (e.g., 'ai.tools.web_search.cache_ttl_minutes')
     * @param  mixed  $default  Fallback if no layer provides a value
     * @param  Scope|null  $scope  Target scope; null = global
     */
    public function get(string $key, mixed $default = null, ?Scope $scope = null): mixed;

    /**
     * Write a setting to the DB layer at the given scope.
     *
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value  Value to store (must be JSON-serializable)
     * @param  Scope|null  $scope  Target scope; null = global
     */
    public function set(string $key, mixed $value, ?Scope $scope = null): void;

    /**
     * Remove a DB-layer override, falling back to the next layer.
     */
    public function forget(string $key, ?Scope $scope = null): void;

    /**
     * Check whether a key has an explicit value at the given scope (DB only).
     */
    public function has(string $key, ?Scope $scope = null): bool;
}
```

### 2.1 Scope Model

```php
final readonly class Scope
{
    public function __construct(
        public ScopeType $type,  // ScopeType::COMPANY | ScopeType::EMPLOYEE
        public int $id,
    ) {}
}

enum ScopeType: string
{
    case COMPANY = 'company';
    case EMPLOYEE = 'employee';
}
```

---

## 3. Resolution Order

Settings resolve **bottom-up** — the most specific layer that provides a value wins.

```
Priority (highest → lowest)
━━━━━━━━━━━━━━━━━━━━━━━━━━━
4. Scoped override   (employee → company)   ← final say if exists
3. Global DB         (scope = null)
2. Config file       (config('group.key'))
1. .env              (env defaults)         ← base default
```

### 3.1 Resolution Algorithm

```
resolve(key, scope):
  1. If scope is EMPLOYEE → look up employee override in DB
     → found? return it
  2. If scope has a company → look up company override in DB
     → found? return it
  3. Look up global override in DB (scope_type = null)
     → found? return it
  4. Return config(key) ?? default
     (config() already merges .env via env() calls)
```

Employee scope implies company scope — an employee always belongs to a company, so the chain is: employee → company → global DB → config file.

### 3.2 Example

Setting: `ai.tools.web_search.cache_ttl_minutes`

| Layer | Value | Wins? |
|-------|-------|-------|
| `.env` | (not set) | — |
| `Config/ai.php` | `15` | ← default |
| Global DB | `30` | ← admin raised it framework-wide |
| Company A override | `60` | ← Company A wants longer cache |
| Employee X override | `5` | ← Employee X wants fast refresh |

**Result for Employee X (Company A):** `5`
**Result for Employee Y (Company A, no employee override):** `60`
**Result for Company B (no overrides):** `30`

---

## 4. Schema

### 4.1 Table: `base_settings`

Belongs to Base layer. Migration prefix: `0100_01_13_*` (Base, new module slot).

| Column | Type | Description |
|--------|------|-------------|
| `id` | `bigint unsigned` PK | Auto-increment |
| `key` | `varchar(255)` | Dot-notation key (e.g., `ai.tools.web_search.cache_ttl_minutes`) |
| `value` | `json` | JSON-encoded value (supports any serializable type) |
| `is_encrypted` | `boolean` | Whether the value is encrypted at rest (default false) |
| `scope_type` | `varchar(50)` nullable | `company`, `employee`, or null (global) |
| `scope_id` | `bigint unsigned` nullable | FK to scoped entity; null when scope_type is null |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

**Indexes:**
- Unique: `(key, scope_type, scope_id)` — one value per key per scope
- Index: `(scope_type, scope_id)` — efficient scope lookups
- Prefix queries (e.g., all `ai.tools.*` settings) use `WHERE key LIKE 'ai.tools.%'`

**Constraints:**
- `scope_type` and `scope_id` are both null (global) or both non-null
- `value` column stores JSON; the application layer handles type coercion

### 4.2 Why One Table

Alternatives considered:

| Approach | Rejected Because |
|----------|-----------------|
| Per-module tables (`ai_tool_configs`, `mail_configs`) | Proliferates tables; each module reinvents scoping, caching, resolution |
| EAV with string values | Loses type info; casting scattered across consumers |
| Separate scope tables | Over-normalized for a key-value store |

One table with JSON values + a composite unique index on `(key, scope_type, scope_id)` is simple, queryable, and framework-owned.

---

## 5. Module Placement

```
app/Base/Settings/
├── Config/
│   └── settings.php              # Meta-config (cache TTL, allowed scope types)
├── Contracts/
│   └── SettingsService.php       # Interface (§2)
├── Database/
│   └── Migrations/
│       └── 0100_01_13_000000_create_base_settings_table.php
├── Models/
│   └── Setting.php               # Eloquent model for base_settings
├── Services/
│   └── DatabaseSettingsService.php  # Implementation
├── ServiceProvider.php           # Binds contract, merges config, registers cache
└── Facades/
    └── Settings.php              # Optional facade (prefer DI)
```

Layer: **Base** (`app/Base/Settings`) — framework infrastructure, no business logic dependency.

---

## 6. Caching Strategy

Every resolved value is cached to avoid per-request DB hits.

| Concern | Approach |
|---------|----------|
| Cache key format | `blb:settings:{scope_type}:{scope_id}:{key}` |
| Global cache key | `blb:settings:global:{key}` |
| TTL | Configurable, default 1 hour |
| Invalidation | Bust on `set()` and `forget()` |
| Warm-up | Optional `blb:settings:cache` artisan command loads all DB settings into cache |
| Driver | Uses default cache driver (Redis recommended in production) |

### 6.1 Scope-Aware Cache

The cache stores the **resolved value per scope**, not the raw DB row. This avoids re-running the cascade on every read:

```
set('ai.tools.web_search.cache_ttl_minutes', 60, Scope(COMPANY, 1))
  → bust cache for key at scope (company:1)
  → bust cache for all employees of company 1 who don't have their own override
```

---

## 7. Encryption

Settings supports transparent encryption for sensitive values (API keys, tokens). The caller specifies `encrypted: true` on `set()`:

```php
$settings->set('ai.tools.web_search.parallel.api_key', $key, encrypted: true);
$settings->get('ai.tools.web_search.parallel.api_key'); // returns plaintext
```

**Implementation:** Values are encrypted with `Crypt::encryptString()` (AES-256-CBC via `APP_KEY`) before DB storage and decrypted on read. The `is_encrypted` column tracks which rows need decryption. This follows the same pattern as `AiProvider->api_key` (`'encrypted'` cast).

---

## 8. What Stays Out of DB

| Category | Reason |
|----------|--------|
| **Boot-time config** (DB host, cache driver, app key) | DB not available during boot. Must remain in `.env` / config files. |
| **Immutable framework defaults** (migration prefixes, layer conventions) | Structural — not user-configurable. Stay in code/docs. |
| **Feature flags** | Different lifecycle (gradual rollout, A/B). Separate system if needed. |

---

## 9. Authorization

Settings writes are gated by AuthZ capabilities:

| Capability | Who | Description |
|------------|-----|-------------|
| `base.settings.manage_global` | Admin | Read/write global DB settings |
| `base.settings.manage_company` | Company Admin | Read/write settings scoped to own company |
| `base.settings.manage_employee` | Self / Supervisor | Read/write employee-scoped settings |

Read access follows the same scope rules — a company admin can read company and global settings but not other companies' overrides.

---

## 10. Migration of Existing Config

Once implemented, existing per-module config like `ai.tools.web_search.*` continues to work unchanged. The settings system sits **above** config files — it only adds DB-layer overrides. No migration of existing `.env` or config values is required.

Modules that want tenant-specific overrides simply call `Settings::get()` instead of `config()`. The fallback chain ensures `config()` values are returned when no DB override exists.

---

## 11. Implementation Phases

### Phase 1: Foundation ✅
- [x] Create `app/Base/Settings/` module structure
- [x] Define `SettingsService` contract
- [x] Create `base_settings` migration
- [x] Implement `DatabaseSettingsService` with resolution chain
- [x] Register in `ServiceProvider`
- [x] Unit tests (resolution order, scope cascade, cache invalidation)
- [x] Encryption support (`is_encrypted` column, `Crypt` encrypt/decrypt)

### Phase 2: Tool Workspace Integration ✅
- [x] `ToolConfigField` DTO for declaring tool configuration fields
- [x] Config fields on `ToolMetadata` for web_search, web_fetch, browser
- [x] Workspace Setup form — saves config via `SettingsService` (encrypted for secrets)
- [x] Try It console — direct tool execution with sample inputs
- [x] Replaced passive health checks with verification from Try It results
- [x] Updated catalog to show Verified/Failed/— instead of health state

### Phase 3: Remaining
- [ ] Wire AuthZ capabilities for settings management
- [ ] Add `blb:settings:cache` warm-up command
- [ ] Per-setting type hints / validation rules (schema registry)
- [ ] Audit log for setting changes

---

## 12. Related Documentation

- **`docs/architecture/database.md`** — Migration registry (register `0100_01_13_*` prefix)
- **`docs/architecture/authorization.md`** — AuthZ capability pattern
- **`docs/architecture/file-structure.md`** — Base module placement conventions
