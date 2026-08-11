# Tenancy

**Document Type:** Architecture
**Scope:** Tenant model, context propagation, isolation enforcement, latent tenancy
**Last Updated:** 2026-08-11

## Overview

Belimbing runs as a single database with application-layer tenant isolation. The **Tenant** is the platform's outer isolation and subscription boundary — one party renting the software. **Company** remains the inner organizational boundary inside a tenant. Tenants form a hierarchy through `tenants.parent_id`, which gives platform operator → customer (reseller) structures a home.

Exactly one live tenant is explicitly marked as the platform operator. Each provisioned tenant has one primary company; numeric IDs have no semantic meaning. Single-tenant instances use the same shape, while tenant UI remains hidden until a second tenant exists (or `tenancy.show_management` is set). The same shape applies across development, staging, and production.

Customers needing hard residency or dedicated resources use instance-per-tenant deployments (`docs/plans/blb-hosted-instances.md`); app-layer tenancy is for multi-tenant SaaS and agency/reseller hosting on one instance.

## Current behavior

This doc describes implemented behavior. The owning module is `app/Base/Tenancy` (see its `AGENTS.md` for canonical rules); the plan of record is `docs/plans/base-tenancy.md`.

### Data model

- `tenants`: `id`, `parent_id` (nullable self-hierarchy), `name`, `status`, `is_platform_operator`, and soft deletes. A partial unique index permits at most one marked operator. Runtime resolution requires the explicit marker and treats multiple or soft-deleted marked rows as invariant violations; deletion of the operator is blocked.
- `companies.tenant_id`: required and explicit, with no database default. It references `tenants`, is immutable through normal model operations, and participates in unique `(id, tenant_id)` and composite same-tenant parent constraints.
- `tenant_primary_companies`: Core/Company-owned relationship with `tenant_id` as primary key and foreign key, unique `company_id`, and composite `(company_id, tenant_id)` foreign key to `companies(id, tenant_id)`. This ownership preserves the rule that Base cannot depend on Core.
- `addresses.tenant_id`: required, indexed, explicit, immutable, and foreign-keyed to `tenants`. Address lists, route binding, company/employee attachment, linked-entity rendering, and timezone updates enforce the same tenant boundary; unattached addresses remain owned by the tenant that created them rather than becoming global records.
- `base_authz_roles`: company-less rows are reserved for configured system roles.
  Every custom role has an owning company and is exposed only inside that company's
  tenant. The owning company is foreign-keyed, and PostgreSQL enforces the exact
  system/company ownership shape with
  `base_authz_roles_custom_company_check` (SQLite uses equivalent write triggers).
- Audit tables (`base_audit_mutations`, `base_audit_actions`) carry an indexed nullable `tenant_id` stamped at capture time; rows captured without a resolvable tenant (for example anonymous requests) remain null.

Missing primary assignment means not yet provisioned. Once assigned, a missing, cross-tenant, or soft-deleted referenced company is corruption and raises an invariant violation. Primary-company deletion is blocked until an explicit safe transfer assigns another live company.

### Provisioning and migration compatibility

`PrimaryCompanyManager` creates a tenant, its primary company, and the relationship transactionally. Framework installation provisions the platform-operator tenant, primary company, initial admin, and Lara in one transaction; retries are idempotent and sequence-safe. Base owns the narrow `FrameworkPrimitivesProvisioner` contract, while Core/Company implements the company/user/employee coordination.

Legacy ID 1 values are retained data and deterministic migration input only. When upgrading an installation whose Core/Company schema already exists, the operator-marker migration marks legacy tenant ID 1. During a genuinely fresh replay, it removes the sole pre-company bootstrap artifact created by the released predecessor migration and leaves operator creation to sequence-backed provisioning. The primary-company backfill lets live legacy company ID 1 win only for an old operator without an explicit designation; for every other tenant, one non-soft-deleted company is assigned, none leaves the tenant unprovisioned, and multiple live candidates stop migration with an actionable error. An operator may resolve ambiguity by inserting a valid same-tenant designation into `tenant_primary_companies` before retrying. Status does not break ambiguity: a suspended or archived company is still an existing candidate. The migration never infers the oldest company.

Address tenancy backfill derives ownership from linked companies and employees. One linked tenant is deterministic; links spanning tenants fail preflight. An unlinked legacy address is assigned only when the database contains exactly one tenant; with multiple tenants its ownership is ambiguous and migration fails before adding the column. Unsupported addressable types also fail with the address and type named. This prevents migration from silently donating an address to the operator tenant.

Legacy custom roles whose `company_id` was null are anchored to the
platform-operator primary company. If any such role is already assigned through a
company outside the operator tenant, migration fails and identifies the assignment;
the operator must clone the role into the intended tenant or remove the invalid
assignment. PostgreSQL migrations lock their source tables before preflight and hold
those locks through DDL/backfill, preventing concurrent writes from turning a
deterministic decision into stale data.

Rollback is intentionally constrained: after a non-1 operator has been used, the marker migration refuses rollback. Company-integrity rollback restores the obsolete tenant default of 1 solely for schema rollback compatibility, not as a supported semantic downgrade. The data-backfill rollback is a no-op because it cannot distinguish migrated rows from later assignments or transfers; rolling back the preceding schema migration removes the relationship table.

### Tenant context

`TenantContext` (scoped binding, `ApplicationTenantContext`) is the only current-tenant carrier:

- **Web:** `ResolveTenantContext` middleware resolves the authenticated user's tenant (derived from their company); guests resolve to null.
- **Queue:** the tenant ID is stamped onto the queue payload at dispatch; the worker restores it on `JobProcessing` and clears it on `JobProcessed`/`JobFailed`, so sequential jobs in one worker never share context.
- **CLI/scheduler:** platform operations run with no tenant context by default; tenant-scoped console work wraps execution in `TenantContext::runForTenant($id, ...)`.

Consumers fail closed on null: no tenant context must never widen into unscoped access. Octane/FrankenPHP scoped-binding flushes plus explicit queue clearing defend against worker leakage.

### Authorization

`TenantScopePolicy` (Base/Authz) runs in the policy pipeline ahead of `CompanyScopePolicy` and compares actor and resource tenant IDs, denying cross-tenant access with `DENIED_TENANT_SCOPE` regardless of role grants. Resources carrying `company_id` but no `tenant_id` are enriched through the `TenantDirectory` contract (Base/Authz contract; Core/Company binds `CompanyTenantDirectory`), so modules that only carry `company_id` — the pre-tenancy convention — are tenant-isolated without schema changes. When a resource's tenant cannot be resolved, the policy abstains and company scope remains the guard.

Configured system roles remain company-less and reusable in every tenant. Custom
roles are never deployment-global: their owning company anchors them to one tenant,
while assignment to users in sibling companies of that tenant remains supported.

### Settings

`ScopeType::TENANT` adds a tenant layer to the cascade. Resolution order is user → company → tenant → global (and company → tenant → global, tenant → global). Definitions opt in by declaring the `tenant` scope; definitions without it resolve exactly as before.

### AI configuration

Provider credentials stay company-owned. `ConfigResolver::resolveDefault()` falls back to the tenant's explicitly assigned primary company's working provider configuration when the agent's company has none. Lookups never infer an anchor by age and never cross tenant boundaries.

Chat agent selection resolves the employee through a company in the current tenant before reading sessions, provider configuration, or identity. Lara provisioning treats an existing system employee in another company as corruption and never silently re-homes it.

### Storage

`TenantStoragePath` prefixes caller-supplied upload directories with `tenants/{id}/` whenever a tenant context is active; `MediaAssetStore::putUploadedFile()` applies it at the single upload entry point. With no tenant context, paths are unchanged, so existing single-tenant installs keep their layout.

### Operator surface

Admin tenant management (list, create with optional parent) lives at `admin/tenancy/tenants` behind the `admin.tenancy.tenant.*` capabilities. The menu surface is gated by the `tenancy.visible` menu condition: more than one tenant, or `tenancy.show_management` set true.

## Boundaries deliberately not built

Per-tenant module entitlements, quotas, metering, billing, provisioning UX, branding, and cross-tenant support impersonation remain operator/product scope built on these primitives. See the plan of record for the exclusions and their seams.
