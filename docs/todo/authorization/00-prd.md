# Authorization (AuthZ) PRD / Delivery Plan

**Document Type:** PRD + Implementation Todo
**Status:** Partially Implemented
**Last Updated:** 2026-02-26
**Architecture Source:** `docs/architecture/authorization.md`

---

## 0. Implementation Status (Current)

| Stage | Status | Notes |
|-------|--------|-------|
| A – Core Capability Vocabulary | Done | `CapabilityRegistry`, `CapabilityKey`, `CapabilityCatalog`; unknown capability fails tests |
| B – Policy Engine + RBAC | Done | Schema, models, `AuthorizationService`, company scope, deny-by-default |
| C – App Integration Surface | Done | `AuthorizeCapability` middleware, `AuthzMenuAccessChecker`, role UI, impersonation |
| D – Agent Delegation | Partial | Actor with `PrincipalType::AGENT` + `actingForUserId`; same RBAC as human (principal_id = employee_id). **Remaining:** assignment-time validation + cascade revocation (see §3.1) |
| E – Audit, DX, Hardening | Partial | `DatabaseDecisionLogger`, `AuditingAuthorizationService`, reason codes. **Gap:** No decision log query endpoint/console tooling; no performance assertions |

**Implemented components:**
- Capability registry and grammar
- Migrations: roles, role_capabilities, principal_roles, principal_capabilities, decision_logs, `principal_type` rename
- Models: Role, RoleCapability, PrincipalRole, PrincipalCapability, DecisionLog
- `AuthorizationEngine` + `AuditingAuthorizationService` + policy pipeline (ActorContext, KnownCapability, CompanyScope, Grant)
- `can`, `authorize`, `filterAllowed` API
- `AuthorizeCapability` middleware (`authz:capability`)
- Menu integration via `AuthzMenuAccessChecker`
- Role management UI (`admin/roles`), impersonation (`admin.user.impersonate`)
- Tests: unit (CapabilityRegistry, AuthorizeCapability), feature (AuthorizationService, RoleUi, Impersonation)

---

## 1. Product Goal
Ship a production-usable AuthZ foundation that enforces identical rules for users and their Agents before Agent approval workflows are built.

## 2. Success Criteria

1. Every protected action can be traced to a capability key.
2. Policy decisions are consistent across web, API, and Agent runtime.
3. Deny-by-default is enforced system-wide.
4. Agent cannot perform any action its supervisor cannot perform.

## 3. Scope

### In Scope
1. Capability registry and naming convention
2. RBAC role assignment per company
3. Unified authorization service (`can`, `authorize`, `filterAllowed`)
4. Decision logging with reason codes
5. Menu integration as consumer
6. Agent delegated actor evaluation

### Out of Scope
1. Complex policy builder UI
2. Full ACL override engine
3. External IdP policy synchronization

### Naming Contract (Locked)
1. Term: `Agent` (not `PA`)
2. Principal type values: `user` and `agent`
3. Framework AI capability prefix: `ai.agent.*`
4. Delegation context field in current actor DTO: `actingForUserId` (may later be complemented by richer supervision context)

### 3.1 Agent Permission Model (Locked)

- **Same RBAC as human:** Agent uses the same roles and permissions (principal_roles, principal_capabilities). `principal_type = 'agent'`, `principal_id = employee_id`.
- **Assignment-only:** No runtime policy that intersects with supervisor. Supervisor assigns roles/capabilities to Agent; we validate at assignment time that supervisor can only assign what they have.
- **Cascade revocation:** When a supervisor loses a permission, all subordinates lose it too. Enforced programmatically:
  - When role R is removed from principal P → cascade removal of role R to all subordinates (recursively via Employee.supervisor_id).
  - When direct capability X is removed from principal P → cascade removal of X to all subordinates.
- **No runtime intersection policy:** The Agent ≤ supervisor invariant is maintained by assignment validation + cascade revocation, not by loading supervisor effective permissions at decision time.

## 4. Staged Delivery

## Stage A - Core Capability Vocabulary

Deliverables:
1. Define capability naming convention and owners
2. Seed baseline capabilities for current modules
3. Add static validation for unknown capability usage

Acceptance:
1. Unknown capability usage fails tests/CI
2. Capability inventory is documented and queryable

## Stage B - Policy Engine + RBAC

Deliverables:
1. Role/capability schema and assignments
2. `AuthorizationService` implementation
3. Company-scope gates

Acceptance:
1. Deny-by-default verified by tests
2. Role grant allows expected actions
3. Cross-company access denied

## Stage C - App Integration Surface

Deliverables:
1. Controller/Livewire integration pattern (`authorize(...)`)
2. Route/API middleware hooks where applicable
3. `menu.php` capability checks via service

Acceptance:
1. Menu visibility matches policy decisions
2. Backend endpoints remain enforced even if menu hidden

## Stage D - Agent Delegation Integration

Deliverables:
1. Agent actor mapping (same RBAC; `principal_id = employee_id`)
2. Assignment-time validation: supervisor can only assign roles/capabilities they have
3. Cascade revocation: when supervisor loses role/capability, cascade to all subordinates (Employee.supervisor_id)
4. Decision logs include actor type (`user` / `agent`)

Acceptance:
1. Agent ≤ supervisor invariant via assignment validation + cascade (no runtime policy)
2. Audit records can differentiate Agent vs user decisions

## Stage E - Audit, DX, and Hardening

Deliverables:
1. Decision log query endpoint/console tooling
2. Reason-code mapping for user-safe messages
3. Performance checks for hot paths

Acceptance:
1. Security review can reconstruct decision path
2. p95 decision latency remains within target for common checks
3. Error paths fail closed with explicit reason codes

## 5. Work Breakdown

### Done
1. ~~Finalize capability taxonomy (`<domain>.<resource>.<action>`)~~
2. ~~Implement authz schema migrations~~
3. ~~Implement models for roles/capabilities/assignments~~
4. ~~Implement `AuthorizationService` and policy pipeline~~
5. ~~Add policy integration (role UI, impersonation, middleware, menu)~~
6. ~~Agent actor model (`Actor` with `PrincipalType::AGENT`, `actingForUserId`)~~
7. ~~Decision logging and reason code enums~~
8. ~~Pest unit + feature coverage~~

### Remaining
1. Assignment-time validation: block assigning role/capability to subordinate unless assigner has it
2. Cascade revocation: on role/capability removal from principal, cascade to all subordinates (recursive via Employee.supervisor_id)
3. Decision log query endpoint or console tooling
4. Performance assertions for hot paths (optional)
5. Document module integration recipe for adopters

## 6. Test Strategy

### Unit
1. Capability resolution
2. Role/capability evaluation
3. Company scope guard
4. Assignment validation (supervisor can only assign what they have)
5. Cascade revocation (subordinate loses when supervisor loses)

### Feature
1. Protected endpoint allow path
2. Protected endpoint deny path
3. Cross-company denial
4. Menu item visibility by capability
5. Agent authorization uses same RBAC; revocation cascades to subordinates

### Security/Regression
1. Fail-closed on service exceptions
2. Unknown capability denial
3. Revocation takes effect immediately (including cascade to subordinates)

## 7. Risks

1. Capability sprawl without ownership discipline
2. Inconsistent enforcement if teams bypass service
3. Hidden coupling between menu rules and backend policies
4. Migration churn while module boundaries are still evolving

## 8. Mitigations

1. Capability owner required per bounded context
2. Lint/static check for direct bypass patterns
3. Shared integration test harness for web/API/Agent paths
4. Keep early schema simple and evolvable (destructive changes acceptable now)

## 9. Exit Gate for Agent Stage 2 (Approve/Reject)

Do not implement Agent approval inbox until all are true:
1. ~~Stage B complete (engine + RBAC)~~
2. Stage D complete (Agent delegation) — **blocker:** assignment validation + cascade revocation
3. ~~Decision logging operational~~
4. At least one sensitive workflow validated end-to-end with AuthZ enforcement
