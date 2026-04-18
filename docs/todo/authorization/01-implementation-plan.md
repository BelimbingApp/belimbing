# Authorization (AuthZ) Implementation Plan

**Status:** Partially Implemented
**Last Updated:** 2026-02-26
**Prerequisites:** `docs/architecture/authorization.md`, `docs/todo/authorization/00-prd.md`

**Implementation status:** See [00-prd.md](00-prd.md) §0. Stages A–D mostly done; E partial (assignment validation + cascade revocation remaining); F partial (no decision log query tooling). Agent model: same RBAC, assignment-only, cascade revocation — see [00-prd.md](00-prd.md) §3.1.

---

## 1. Objective
Implement a company-scoped, deny-by-default AuthZ foundation used consistently by web, API, menu visibility, and Agent delegation.

## 2. Scope Decisions Locked

1. Capability key format: `<domain>.<resource>.<action>`
2. Scope model now: `company_id` only (`group_id`, as in Group of Companies as an entity, is intentionally deferred)
3. `menu.php` is consumer-only of AuthZ decisions
4. Agent uses same AuthZ engine via delegated actor model
5. Naming is canonical: `Agent`, principal values `user | agent`, and AI capability prefix `ai.agent.*`
6. Delegation context uses `actingForUserId` in current DTO (extendable to richer supervision metadata later)
7. **Agent permission model:** Same RBAC as human (`principal_id = employee_id`). Assignment-only: validate at assign time that supervisor can only assign what they have. Cascade revocation: when supervisor loses role/capability, cascade to all subordinates (Employee.supervisor_id). No runtime policy that loads supervisor effective permissions.

## 3. Stage Plan

## Stage A - Capability Spine ✅

### Tasks
- [x] 1. Finalize allowed domain list and owner mapping
- [x] 2. Finalize action verb list (`view`, `list`, `create`, `update`, `delete`, `submit`, `approve`, `reject`, `execute`)
- [x] 3. Implement capability registry (code-first)
- [x] 4. Add capability lookup helper for app consumption
- [x] 5. Add CI/test validator for unknown capability usage

### Target Files (proposed)
1. `app/Base/Authz/Capability/CapabilityKey.php`
2. `app/Base/Authz/Capability/CapabilityRegistry.php`
3. `app/Base/Authz/Capability/CapabilityCatalog.php`
4. `tests/Unit/Base/Authz/CapabilityRegistryTest.php`

### Done Criteria
1. Unknown capability fails tests
2. Capability grammar is enforced by code
3. Registry exposes query API for integration

---

## Stage B - AuthZ Schema and Persistence ✅

### Tasks
- [x] 1. Create migrations for RBAC and direct grants
- [x] 2. Add indexes/constraints for company-scoped lookups
- [x] 3. Implement Eloquent models and relationships
- [x] 4. Seed baseline roles/capabilities for existing modules only
- [x] 5. Reserve and use Base module migration prefix `0100_01_11_*` for AuthZ
- [x] 6. Register production seeders inside AuthZ migrations (`up()` register, `down()` unregister)

### Target Files (proposed)
1. `app/Base/Authz/Database/Migrations/0100_01_11_000000_create_base_authz_roles_table.php`
2. `app/Base/Authz/Database/Migrations/0100_01_11_000001_create_base_authz_capabilities_table.php`
3. `app/Base/Authz/Database/Migrations/0100_01_11_000002_create_base_authz_role_capabilities_table.php`
4. `app/Base/Authz/Database/Migrations/0100_01_11_000003_create_base_authz_principal_roles_table.php`
5. `app/Base/Authz/Database/Migrations/0100_01_11_000004_create_base_authz_principal_capabilities_table.php`
6. `app/Base/Authz/Database/Migrations/0100_01_11_000005_create_base_authz_decision_logs_table.php`
7. `app/Base/Authz/Models/*`
8. `app/Base/Authz/Database/Seeders/*`

### Done Criteria
1. Schema migrates cleanly
2. Role/capability assignment is queryable by company
3. Baseline seed data is deterministic

---

## Stage C - Authorization Service and Policy Engine ✅

### Tasks
- [x] 1. Implement decision DTO (Data Transfer Object) and reason code enum
- [x] 2. Implement `AuthorizationService` (`can`, `authorize`, `filterAllowed`)
- [x] 3. Implement policy pipeline order:
   - actor validity
   - company scope
   - capability grant
   - resource conditions
   - final decision
- [x] 4. Enforce fail-closed behavior on exceptions

### Target Files (proposed)
1. `app/Base/Authz/Contracts/AuthorizationService.php`
2. `app/Base/Authz/DTO/Actor.php`
3. `app/Base/Authz/DTO/ResourceContext.php`
4. `app/Base/Authz/DTO/AuthorizationDecision.php`
5. `app/Base/Authz/Enums/AuthorizationReasonCode.php`
6. `app/Base/Authz/Services/AuthorizationEngine.php` + `AuditingAuthorizationService`
7. `app/Base/Authz/Policies/*`
8. `tests/Feature/Authz/AuthorizationServiceTest.php`

### Done Criteria
1. Deny-by-default proven by unit tests
2. Reason codes are deterministic and machine-readable
3. Exceptions fail closed with explicit reason code

---

## Stage D - First Module Integration (Web/API/Menu) ✅

### Tasks
- [x] 1. Pick one existing module as reference integration (Role UI, Impersonation)
- [x] 2. Add explicit `authorize(...)` calls at action boundaries (middleware `authz:capability`)
- [x] 3. Integrate menu visibility checks via capability keys (`AuthzMenuAccessChecker`)
- [x] 4. Add API/web tests for allow/deny/cross-company behavior

### Target Files (proposed)
1. `<chosen-module>/Controllers/*`
2. `<chosen-module>/Services/*`
3. `config/menu.php` (or module menu config)
4. `tests/Feature/Authz/*`

### Done Criteria
1. Backend protections enforced independently of menu visibility
2. Cross-company access denied by default
3. At least one full module path protected end-to-end

---

## Stage E - Agent Delegation (Partial)

### Tasks
- [x] 1. Implement Agent actor model (`Actor` with `PrincipalType::AGENT`, `actingForUserId`)
- [x] 2. Add delegated-user / supervision context (`actingForUserId` in Actor DTO)
- [ ] 3. Assignment-time validation: block assigning role/capability to subordinate unless assigner has it
- [ ] 4. Cascade revocation: on role/capability removal from principal, cascade to all subordinates (recursive via Employee.supervisor_id)

### Target Files (proposed)
1. ~~`app/Base/Authz/Actor/AgentActorFactory.php`~~ — Actor DTO used directly; no separate factory
2. Role/capability assignment service(s) — add validation + cascade hooks
3. `app/Base/AI/*` (integration points)
4. `tests/Feature/Authz/AuthorizationServiceTest.php` (Agent scenarios covered)
5. `tests/Feature/Authz/CascadeRevocationTest.php` (or similar)

### Done Criteria
- [ ] 1. Assignment validation: cannot assign role/capability to subordinate without assigner having it
- [x] 2. Decision logs distinguish `user` vs `agent`
- [x] 3. Delegation context validation covered (Agent without `actingForUserId` denied)
- [ ] 4. Cascade revocation: when supervisor loses role/capability, subordinates lose it too

---

## Stage F - Audit, Observability, Hardening (Partial)

### Tasks
- [x] 1. Persist decision logs with correlation metadata (`DatabaseDecisionLogger`, `AuditingAuthorizationService`)
- [ ] 2. Provide query/inspection command or endpoint
- [x] 3. Add regression tests for revocation and unknown capability paths
- [ ] 4. Add performance assertion for policy evaluation hot paths

### Target Files (proposed)
1. `app/Base/Authz/Audit/*`
2. `app/Base/Authz/Console/*` or API endpoint files
3. `tests/Feature/Authz/DecisionLogTest.php`
4. `tests/Performance/Authz/*` (if performance suite exists)

### Done Criteria
- [x] 1. Security review can reconstruct key allow/deny decisions (decision logs persisted)
- [x] 2. Revoked permissions take effect immediately
- [ ] 3. Policy engine meets latency target for common checks

## 4. Cross-Cutting Rules

1. Do not add `group_id` yet
2. Do not treat menu visibility as authorization enforcement
3. Do not bypass `AuthorizationService` in business code
4. All sensitive checks must produce traceable reason codes

## 5. Seeding Strategy (Detailed)

### Seed Scope
1. Seed only canonical capability registry values from `app/Base/Authz/Config/authz.php`
2. Seed system roles as global (`company_id = null`) templates
3. Seed role-capability mappings for those system roles
4. Do not seed principal role assignments globally (tenant/company specific)

### Seeder Location and Naming
1. Location: `app/Base/Authz/Database/Seeders/`
2. Production seeders:
   - `AuthzCapabilitySeeder.php`
   - `AuthzRoleSeeder.php`
   - `AuthzRoleCapabilitySeeder.php`
3. Development seeders:
   - location: `app/Base/Authz/Database/Seeders/Dev/`
   - naming: `Dev{Description}Seeder.php` (example: `DevAuthzCompanyAssignmentSeeder.php`)
   - base class: extend `App\Base\Database\Seeders\DevSeeder`
   - implementation method: `seed()` (not `run()`)

### Execution and Registration
1. Production seeders are migration-owned:
   - each relevant migration uses `RegistersSeeders`
   - `up()` calls `registerSeeder(...)`
   - `down()` calls `unregisterSeeder(...)`
2. Keep seeder execution order deterministic:
   - capabilities -> roles -> role_capabilities
3. Never depend on `DatabaseSeeder::run()` for module seeders
4. Dev seeders are not registered in migrations; run explicitly when needed

### Idempotency Rules
1. Use `updateOrCreate` / `firstOrCreate` for all production seeding writes
2. Use stable natural keys:
   - capability: `key`
   - role: `company_id + code` (with `company_id = null` for system roles)
   - role_capability pivot: `role_id + capability_id`
3. Seeder reruns must be safe and produce same final state

### Role Templates (v1)
1. `core_admin`:
   - full `core.user.*`, `core.company.*`
2. `user_viewer`:
   - `core.user.list`, `core.user.view`
3. `user_editor`:
   - `core.user.list`, `core.user.view`, `core.user.create`, `core.user.update`, `core.user.delete`

### Assignment Model
1. Company-level assignments are explicit operational actions (UI/CLI/API), not migration seeds
2. For local development/testing, use Dev seeder for sample assignments per company/user
3. Stage D tests may create assignments directly in test setup for deterministic coverage

### Verification Checklist
1. `php artisan migrate --seed` creates/upserts capability + role + mapping baseline
2. Running `php artisan migrate --seed` twice yields no duplicates
3. Unknown capability remains denied even after seeding
4. Route/menu checks succeed after assigning `user_viewer` or `user_editor`
5. Dev seeder executes only in local environment and is run explicitly (not via migration registration)

## 6. Test Matrix (Minimum)

1. Unknown capability -> denied
2. Missing actor context -> denied
3. Same-company allowed path (with role grant)
4. Cross-company denied path
5. Direct capability revocation effect
6. Agent denied when supervisor denied (via cascade revocation before next check)
7. Assignment validation: cannot assign capability to subordinate that assigner does not have
8. Cascade revocation: removing role/capability from supervisor removes it from all subordinates

## 7. Dependencies and Sequencing

1. Stage A before Stage B/C
2. Stage C before Stage D/E
3. Stage D and E before Stage F
4. Agent approval inbox work blocked until Stage B + C + E complete

## 8. Immediate Next Execution Step

Implement assignment-time validation and cascade revocation. See [00-prd.md](00-prd.md) §3.1 and §5 Remaining. Hook into role/capability assignment flows to validate assigner has the capability; hook into role/capability removal to cascade to subordinates via Employee.supervisor_id.
