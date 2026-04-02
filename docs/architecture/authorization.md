# Authorization (AuthZ) Architecture

**Document Type:** Architecture Specification
**Status:** Implemented
**Last Updated:** 2026-02-26
**Related:** `docs/architecture/user-employee-company.md`, `docs/architecture/ai/agent-model.md`, `docs/architecture/database.md`

---

## 1. Problem Essence

BLB needs one authorization system that consistently decides what both humans and Agents are allowed to do across UI, APIs, tools, and workflows.

### 1.1 Canonical Terms and Naming

| Term | Canonical Meaning |
|------|-------------------|
| Agent | Agent; the non-human employee actor type in BLB. |
| Human User | A human principal represented by `PrincipalType::HUMAN_USER` and stored as `'human_user'`. |
| Supervisor | The immediate principal responsible for a Agent (human or Agent). |
| Supervision Chain | Directed chain from a Agent to an accountable human. Must be acyclic. |
| Delegation Context | Execution context linking Agent actions to a human accountability chain (`actingForUserId` in current actor DTO; may evolve to richer supervision metadata). |

Naming rules locked for AuthZ v1:
1. Principal enum: `PrincipalType::AGENT` and `PrincipalType::HUMAN_USER`.
2. Persisted principal type values: `'agent'` and `'human_user'`.
3. Capability namespace for framework AI operations: `ai.agent.*`.

---

## 2. Why AuthZ, Not ACL-First

**AuthZ** is the complete decision system (principals, policies, scope, conditions, audit).

**ACL** is one mechanism (resource-level allow/deny lists).

Decision:
1. Build AuthZ core first.
2. Use RBAC + scoped policies as the baseline.
3. Add ACL-style overrides later only for concrete resource exceptions.

Rationale:
1. BLB rules are cross-cutting (company scope, role, workflow state, delegation).
2. Agent approvals and tool execution need policy evaluation, not menu-only checks.
3. ACL-first would couple implementation to resource lists too early and create rework.

---

## 3. Public Interface

All callers (web, API, Agent runtime, jobs, menu rendering) use one decision contract.

```php
interface AuthorizationService
{
    public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision;

    public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void;

    public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection;
}
```

**Location:** `app/Base/Authz/Contracts/AuthorizationService.php`

### 3.1 Actor Model

```php
final readonly class Actor
{
    public function __construct(
        public PrincipalType $type,  // PrincipalType::HUMAN_USER | PrincipalType::AGENT
        public int $id,
        public ?int $companyId,
        public ?int $actingForUserId = null,
        public array $attributes = [],
    ) {}
}
```

`$type` is a backed enum (`App\Base\Authz\Enums\PrincipalType`), not a raw string. The Actor carries a `validate()` method that encapsulates context validation rules (ID > 0, company required, agent delegation).

Rules:
1. A Agent is a delegated actor chained to a human (supervision chain).
2. Agent cannot exceed supervisor effective permissions.
3. Same capability vocabulary applies to human and Agent actors.
4. Every decision carries actor type for audit.

### 3.2 Resource Context

```php
final readonly class ResourceContext
{
    public function __construct(
        public string $type,           // e.g. 'employee', 'leave_request'
        public int|string|null $id,
        public ?int $companyId = null,
        public array $attributes = [],
    ) {}
}
```

### 3.3 Decision Contract

```php
final readonly class AuthorizationDecision
{
    public bool $allowed;
    public AuthorizationReasonCode $reasonCode;  // backed enum
    public array $appliedPolicies;               // trail of all consulted policies
    public array $auditMeta;
}
```

Reason codes (`App\Base\Authz\Enums\AuthorizationReasonCode`):
- `ALLOWED`
- `DENIED_UNKNOWN_CAAEBILITY`
- `DENIED_INVALID_ACTOR_CONTEXT`
- `DENIED_COMAENY_SCOPE`
- `DENIED_MISSING_CAAEBILITY`
- `DENIED_EXPLICITLY`
- `DENIED_POLICY_ENGINE_ERROR`

---

## 4. Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│  AuditingAuthorizationService (decorator)           │
│  ┌──────────────────────┐  ┌──────────────────────┐ │
│  │  AuthorizationEngine │  │ DatabaseDecisionLogger│ │
│  │  (pure, no side      │  │ (buffered batch       │ │
│  │   effects)           │  │  INSERT on terminate) │ │
│  └────────┬─────────────┘  └──────────────────────┘ │
└───────────┼─────────────────────────────────────────┘
            │ runs policy pipeline
            ▼
  ┌──────────────────┐
  │ ActorContextPolicy│ → deny if invalid actor
  ├──────────────────┤
  │ KnownCapability  │ → deny if capability not in registry
  │ Policy           │
  ├──────────────────┤
  │ CompanyScopePolicy│ → deny if cross-company resource
  ├──────────────────┤
  │ GrantPolicy      │ → allow/deny based on grants & roles
  │  └─ Effective    │
  │     Permissions   │    (pre-loaded, in-memory evaluation)
  └──────────────────┘
```

### 4.1 Engine / Decorator Split

**AuthorizationEngine** (`app/Base/Authz/Services/AuthorizationEngine.php`)
- Pure evaluation: no database writes, no side effects.
- Runs an ordered pipeline of `AuthorizationPolicy` implementations.
- Collects the trail of all consulted policies in `appliedPolicies`.
- Trivially testable in isolation.

**AuditingAuthorizationService** (`app/Base/Authz/Services/AuditingAuthorizationService.php`)
- Decorator implementing `AuthorizationService`.
- Delegates evaluation to the engine, then logs the decision via `DecisionLogger`.
- The logging strategy is swappable (swap the `DecisionLogger` binding for queue-based, sampling, no-op, etc.).

**Service provider wiring:**
```php
AuthorizationService::class → AuditingAuthorizationService
    ├── AuthorizationEngine (with policy pipeline)
    └── DecisionLogger → DatabaseDecisionLogger
```

### 4.2 Policy Pipeline

Each policy implements `AuthorizationPolicy`:

```php
interface AuthorizationPolicy
{
    public function key(): string;

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision;
    // null = abstain (continue), non-null = halt pipeline
}
```

**Location:** `app/Base/Authz/Contracts/AuthorizationPolicy.php`

**Default pipeline order:**

| Order | Policy | Key | Behavior |
|-------|--------|-----|----------|
| 1 | `ActorContextPolicy` | `actor_context` | Deny if actor fails validation (invalid ID, missing company, agent without delegation). Abstain on valid. |
| 2 | `KnownCapabilityPolicy` | `capability_registry` | Deny if capability key is not in the registry. Abstain on known. |
| 3 | `CompanyScopePolicy` | `company_scope` | Deny if resource company differs from actor company. Abstain when no resource or companies match. |
| 4 | `GrantPolicy` | `grant` | **Authoritative (final)**. Loads `EffectivePermissions` for the actor and evaluates: explicit deny > explicit allow > role grant > deny. Always returns a decision. |

**Adding new policies:** Create a class implementing `AuthorizationPolicy`, then add it to the pipeline array in `AuthzServiceProvider`. No existing code changes required.

### 4.3 EffectivePermissions

`app/Base/Authz/Services/EffectivePermissions.php`

Pre-loads all permission data for an actor in a **fixed number of queries** (not N), then evaluates checks in memory:

1. **Direct grants query:** `base_authz_principal_capabilities` → builds `$directDenies` and `$directAllows` hash maps keyed by `capability_key`.
2. **Role grants query:** `base_authz_principal_roles` JOIN `base_authz_role_capabilities` → builds `$roleGrants` hash map.

Evaluation priority: **explicit deny wins** > explicit allow > role grant > default deny.

The `GrantPolicy` caches `EffectivePermissions` per actor identity within a request, so `filterAllowed()` over N resources triggers only 2 queries total (not 2×N).

### 4.4 Decision Logging

`app/Base/Authz/Contracts/DecisionLogger.php` — swappable interface.
`app/Base/Authz/Services/DatabaseDecisionLogger.php` — default implementation.

Logging is **deferred and batched**:
1. Decisions are buffered in memory during the request.
2. A `terminating` callback flushes all buffered entries in a single batch `INSERT` (chunked at 500 rows) after the response is sent.
3. Log persistence failure is caught and reported via `logger()->error()` without affecting the authorization decision.

The `DecisionLog` model includes `MassPrunable` with a configurable retention period (`authz.decision_log_retention_days`, default 90 days). Run `php artisan model:prune --model=App\\Base\\Authz\\Models\\DecisionLog` to clean old entries.

---

## 5. Capability System

### 5.1 Capability Key Grammar

Format: `<domain>.<resource>.<action>`

Validated by `CapabilityKey` value object (`app/Base/Authz/Capability/CapabilityKey.php`):
- Pattern: `/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/`
- Always lowercase.
- Parseable into `{domain, resource, action}`.
- Agent-specific framework capabilities must use `ai.agent.<action>` or module-owned `*.agent.<action>` patterns.

### 5.2 Module-Owned Capabilities

Each module declares its own capabilities in `Config/authz.php`:

```php
// app/Modules/Core/User/Config/authz.php
return [
    'capabilities' => [
        'core.user.view',
        'core.user.list',
        'core.user.create',
        'core.user.update',
        'core.user.delete',
    ],
];
```

**Auto-discovery:** The `AuthzServiceProvider` scans these paths at boot and merges capabilities into the aggregated config:
- `app/Base/*/Config/authz.php`
- `app/Modules/*/*/Config/authz.php`

The base `Config/authz.php` holds only:
- Grammar rules (domains, verbs)
- Framework-level capabilities not yet owned by a module (e.g., `ai.agent.*`)
- System role definitions that aggregate capabilities across modules
- Decision log retention config

**Adding capabilities for a new module:** Create `Config/authz.php` in the module directory. No service provider changes needed — the file is auto-discovered. For **Agent** administration capabilities (e.g. `employee.agent.create`, `employee.agent.update`), the vocabulary is defined in [docs/architecture/ai/agent-model.md](ai/agent-model.md) §5.3; AuthZ owns registration and enforcement.

### 5.3 Catalog and Registry

**CapabilityCatalog** (`app/Base/Authz/Capability/CapabilityCatalog.php`)
- Built from the aggregated `config('authz')` after module discovery.
- Validates all capability keys against the grammar (domain must be registered, action must be a known verb).

**CapabilityRegistry** (`app/Base/Authz/Capability/CapabilityRegistry.php`)
- Built from a validated catalog.
- Provides `has()`, `assertKnown()`, `all()`, `forDomain()` lookups.
- Singleton — resolved once per application lifecycle.

### 5.4 Registered Domains and Verbs

**Domains** (configurable in `authz.php`):
- `core` — Core platform modules
- `workflow` — Workflow and state transitions
- `ai` — AI and agent capabilities
- `admin` — Administrative operations

**Verbs** (configurable in `authz.php`):
`view`, `list`, `create`, `update`, `delete`, `submit`, `approve`, `reject`, `execute`

---

## 6. Data Model

### 6.1 Schema

```
base_authz_roles
├── id (PK)
├── company_id (nullable, index) — null = system role
├── name
├── code (unique with company_id)
├── description (nullable)
├── is_system (boolean)
└── timestamps

base_authz_role_capabilities
├── id (PK)
├── role_id (FK → roles, cascade delete)
├── capability_key (string, indexed)
├── unique(role_id, capability_key)
└── timestamps

base_authz_principal_roles
├── id (PK)
├── company_id (nullable, index)
├── principal_type (varchar 40) — 'human_user' | 'agent'
├── principal_id (unsigned bigint)
├── role_id (FK → roles, cascade delete)
├── index(principal_type, principal_id)
├── unique(company_id, principal_type, principal_id, role_id)
└── timestamps

base_authz_principal_capabilities
├── id (PK)
├── company_id (nullable, index)
├── principal_type (varchar 40)
├── principal_id (unsigned bigint)
├── capability_key (string, indexed)
├── is_allowed (boolean, default true)
├── index(principal_type, principal_id)
├── unique(company_id, principal_type, principal_id, capability_key)
└── timestamps

base_authz_decision_logs
├── id (PK)
├── company_id, actor_type, actor_id (indexed)
├── acting_for_user_id (nullable, indexed)
├── capability, resource_type, resource_id (indexed)
├── allowed (boolean, indexed)
├── reason_code (indexed)
├── applied_policies (JSON)
├── context (JSON)
├── correlation_id (nullable, indexed)
├── occurred_at (timestamp, indexed)
├── composite indexes: (actor_type, actor_id, occurred_at), (capability, allowed)
└── timestamps
```

### 6.2 Design Decisions

**No `capabilities` table.** Capability keys are stored as strings directly in `role_capabilities` and `principal_capabilities`. The config-driven `CapabilityRegistry` is the authoritative source of valid capabilities. A separate DB table would be redundant normalization requiring seeder synchronization.

**String keys over FK joins.** Grant evaluation queries join on string columns instead of through a capabilities table. This removes an entity, two seeders, and all capability-table joins from the hot path.

**Validation at write time.** Since there's no FK constraint on `capability_key`, the `AuthzRoleCapabilitySeeder` validates keys against the `CapabilityRegistry` before inserting. Any admin API that assigns capabilities should do the same.

### 6.3 Migration Prefix

AuthZ migrations use prefix `0100_01_11` (Base layer, module 11). See `docs/architecture/database.md` for the migration registry.

---

## 7. Roles

### 7.1 System Roles

Defined in `app/Base/Authz/Config/authz.php` under the `roles` key. System roles have `company_id = null` and `is_system = true`. They are seeded by `AuthzRoleSeeder` and `AuthzRoleCapabilitySeeder`.

Current system roles:

| Code | Name | Capabilities |
|------|------|-------------|
| `core_admin` | Core Administrator | All `core.*` and `ai.agent.*` |
| `user_viewer` | User Viewer | `core.user.list`, `core.user.view` |
| `user_editor` | User Editor | All `core.user.*` |

### 7.2 Role Placement

- **Cross-module system roles** (like `core_admin` spanning User + Company + AI capabilities): defined in base `Config/authz.php`.
- **Module-scoped roles** (only referencing a single module's capabilities): may be defined in the module's own `Config/authz.php` under a `roles` key.

---

## 8. HTTP Middleware

`AuthorizeCapability` middleware (`app/Base/Authz/Middleware/AuthorizeCapability.php`):

```php
// In route definition
Route::get('/users', ...)->middleware('authz:core.user.list');
```

The middleware:
1. Resolves the authenticated user.
2. Derives principal type via `$user->principalType()` if the method exists, falling back to `HUMAN_USER`.
3. Constructs an `Actor` DTO.
4. Calls `AuthorizationService::authorize()`.
5. Returns 401 (unauthenticated) or 403 (denied).

---

## 9. Policy Model (v1)

### 9.1 Evaluation Order

Implemented as a composable policy pipeline (not hardcoded):

1. **Actor validity** — `ActorContextPolicy`
2. **Capability registry** — `KnownCapabilityPolicy`
3. **Company scope gate** — `CompanyScopePolicy`
4. **Grant evaluation** — `GrantPolicy` (RBAC + direct grants)

Future policies (resource ownership, workflow state, delegation constraints) can be inserted into the pipeline without modifying existing code.

### 9.2 Delegation Rules for Agent

1. Agent actor must include `actingForUserId` (or equivalent supervision context) so the chain to a human is explicit.
2. Effective permissions = intersection of:
   - supervisor effective permissions (Agent cannot exceed)
   - Agent safety policy (tool/channel limits)
3. High-risk actions may still require human approval even if allowed.

### 9.3 Delegation Invariant Test Requirement

The following invariant must be covered consistently in web, API, and Agent runtime integration tests:
1. If supervisor is denied capability `X`, Agent is denied `X`.
2. If supervisor is allowed `X` but Agent safety policy denies `X`, Agent is denied `X`.
3. If supervisor is allowed `X` and Agent safety policy allows `X`, Agent may be allowed `X` (subject to other policies).

### 9.4 Agents

Agents are first-class employees under the same org and AuthZ model as humans. **Full specification:** [docs/architecture/ai/agent-model.md](ai/agent-model.md).

**AuthZ contract for Agent:**

1. **Delegation constraint:** Agent effective permissions must be a strict subset of the supervisor’s effective permissions. Delegation cannot create new privileges. A policy (or pipeline stage) enforces this when the actor or resource is a Agent.
2. **Explicit deny wins:** Same as Agent and human; explicit deny always overrides role or delegated allow.
3. **Capability gates for Agent administration:** The Agent spec defines capability keys for managing Agents (e.g. `employee.agent.create`, `employee.agent.update`, `employee.agent.assign_role`, `employee.agent.assign_permission`, `employee.agent.disable`). The final vocabulary is owned by the AuthZ module and declared in `Config/authz.php` (or module configs) when implemented.
4. **Supervision chain:** Every Agent must have a supervision chain that resolves to a human accountable owner; the supervision graph must be acyclic. AuthZ may need to evaluate “can this supervisor delegate to this subordinate?” using the same engine.

For delegation invariants, supervisor model, and UI/audit rules, see [docs/architecture/ai/agent-model.md](ai/agent-model.md) §5–§7.

### 9.5 Menu Integration Rule

`menu.php` is a consumer, not source of truth.

Pattern:
1. Menu item declares required capability.
2. Menu renderer calls `AuthorizationService::can(...)`.
3. Hidden menu item does not imply denied backend access (backend enforces separately).

---

## 10. Module-Level Error Policy

1. Unknown capability → deny + `DENIED_UNKNOWN_CAAEBILITY` + warning log.
2. Missing actor context → deny + `DENIED_INVALID_ACTOR_CONTEXT`.
3. Policy evaluation exception → deny + `DENIED_POLICY_ENGINE_ERROR` + error log.
4. Audit logging failure → decision result stands, but emit high-severity operational alert.

---

## 11. Expected Call Patterns

1. **Web Controller/Livewire Action**
   - `authorize(actor, capability, resource)` before service call.
2. **Agent Tool Execution**
   - Evaluate as Agent actor with delegation/supervision context.
3. **Menu Rendering**
   - `can(...)` checks only for visibility hints.
4. **Batch Jobs/Queue Workers**
   - Use system actor or delegated actor explicitly; never implicit user context.

---

## 12. User Impersonation

Admin users with `admin.user.impersonate` capability can view the system as another user without logging out. This is a session-based mechanism for debugging and testing.

### 12.1 How It Works

`ImpersonationManager` (`app/Base/Authz/Services/ImpersonationManager.php`):
1. **Start:** Stores the admin's identity in session (`impersonation.original_user_id`), then calls `Auth::login($target)` to switch the authenticated user.
2. **Stop:** Reads the original admin ID from session, calls `Auth::loginUsingId()` to restore, then clears the session key.

Since `Auth::login()` swaps the session's authenticated user, all downstream code (`$request->user()`, `auth()->user()`, authz middleware, menu filtering) naturally operates under the impersonated user's identity and permissions.

### 12.2 Routes

| Method | Path | Middleware | Name |
|--------|------|-----------|------|
| POST | `admin/impersonate/{user}` | `auth`, `authz:admin.user.impersonate` | `admin.impersonate.start` |
| POST | `admin/impersonate/leave` | `auth` | `admin.impersonate.stop` |

Routes are auto-discovered from `app/Base/Authz/Routes/web.php`.

### 12.3 UI

- **Status Bar warning:** When impersonation is active, `resources/core/views/components/layouts/status-bar.blade.php` displays a `text-status-danger` warning with the impersonated user's name and a "Stop Impersonation" button.
- **Buttons:** Eye icon in the user list (index) and "Impersonate" button on the user show page. Hidden when viewing yourself or when already impersonating.

### 12.4 Constraints (v1)

- Cannot impersonate yourself.
- No nested impersonation (buttons hidden while impersonating).
- Impersonation guard is `admin.user.impersonate`, included in `core_admin` role.

---

## 13. File Structure

```
app/Base/Authz/
├── AuthzServiceProvider.php          # Wiring: discovery, pipeline, bindings
├── Config/
│   └── authz.php                     # Domains, verbs, base capabilities, roles, retention
├── Contracts/
│   ├── AuthorizationService.php      # Public API contract
│   ├── AuthorizationPolicy.php       # Policy pipeline stage interface
│   └── DecisionLogger.php            # Audit logging interface
├── Capability/
│   ├── CapabilityKey.php             # Grammar validation value object
│   ├── CapabilityCatalog.php         # Aggregated catalog from config
│   └── CapabilityRegistry.php        # Runtime lookup (singleton)
├── DTO/
│   ├── Actor.php                     # Principal DTO with validation
│   ├── AuthorizationDecision.php     # Decision DTO with reason code
│   └── ResourceContext.php           # Resource context DTO
├── Enums/
│   ├── PrincipalType.php             # human_user | agent
│   └── AuthorizationReasonCode.php   # Decision reason codes
├── Exceptions/
│   ├── AuthorizationDeniedException.php
│   └── UnknownCapabilityException.php
├── Middleware/
│   └── AuthorizeCapability.php       # Route middleware
├── Routes/
│   └── web.php                       # Impersonation routes (auto-discovered)
├── Models/
│   ├── Role.php
│   ├── PrincipalRole.php
│   ├── PrincipalCapability.php       # Uses capability_key string (no FK)
│   └── DecisionLog.php              # MassPrunable with retention policy
├── Policies/
│   ├── ActorContextPolicy.php
│   ├── KnownCapabilityPolicy.php
│   ├── CompanyScopePolicy.php
│   └── GrantPolicy.php
├── Services/
│   ├── AuthorizationEngine.php       # Pure evaluation engine
│   ├── AuditingAuthorizationService.php  # Logging decorator
│   ├── DatabaseDecisionLogger.php    # Buffered batch INSERT
│   ├── EffectivePermissions.php      # Pre-loaded permission set
│   └── ImpersonationManager.php      # Session-based user impersonation
└── Database/
    ├── Migrations/
    │   ├── 0100_01_11_000000_create_base_authz_roles_table.php
    │   ├── 0100_01_11_000002_create_base_authz_role_capabilities_table.php
    │   ├── 0100_01_11_000003_create_base_authz_principal_roles_table.php
    │   ├── 0100_01_11_000004_create_base_authz_principal_capabilities_table.php
    │   └── 0100_01_11_000005_create_base_authz_decision_logs_table.php
    └── Seeders/
        ├── AuthzRoleSeeder.php
        ├── AuthzRoleCapabilitySeeder.php
        └── Dev/
            └── DevAuthzCompanyAssignmentSeeder.php
```

Module capability configs:
```
app/Modules/Core/User/Config/authz.php      # core.user.*
app/Modules/Core/Company/Config/authz.php    # core.company.*
```

---

## 14. Complexity Hotspots

1. Multi-company users and company context switching.
2. Manager-subordinate conditional policies and **Agent delegation** (Agent permissions ≤ supervisor; see [ai/agent-model.md](ai/agent-model.md)).
3. Workflow-state-dependent permissions.
4. Consistency between synchronous UI checks and async job execution.
5. Future ACL overrides without policy ambiguity.
6. Provider ordering: module authz configs must be discovered before `CapabilityRegistry` is first resolved.

---

## 15. Non-Goals (v1)

1. Full visual policy builder UI.
2. Arbitrary ABAC DSL exposed to adopters.
3. Cross-company delegation for Agent.
4. External federated identity policy mapping.
5. Resource-level (row-level) ACL entries.

---

## 16. Acceptance Conditions

1. ✅ One API for all authorization decisions (`can/authorize/filterAllowed`).
2. ✅ Same capability key can be evaluated for both human and Agent actors.
3. ✅ Deny-by-default proven through tests.
4. ✅ UI, API, and Agent tool path all call the same policy engine.
5. ✅ Decision logs include actor type and reason code.
6. ✅ Policy pipeline is composable — new policies added without modifying engine.
7. ✅ Capabilities are module-owned and auto-discovered.
8. ✅ Decision logging is decoupled and swappable.
9. ✅ Grant evaluation uses fixed query count regardless of resource count.
