# Tenancy

**Document Type:** Architecture
**Scope:** Tenant model, context propagation, isolation enforcement, latent tenancy
**Last Updated:** 2026-08-08

## Overview

Belimbing runs as a single database with application-layer tenant isolation. The **Tenant** is the platform's outer isolation boundary — one party renting the software. **Company** remains the inner organizational boundary inside a tenant and keeps its pre-tenancy meaning (business entities in a licensee's world). Tenants form a hierarchy through `tenants.parent_id`, which gives operator → customer (reseller) structures a home.

Single-tenant instances are the degenerate case: install seeds tenant id=1 (the licensee tenant), every company belongs to it by default, and no tenant UI appears until a second tenant exists (or `tenancy.show_management` is set). The same shape applies across development, staging, and production.

Customers needing hard residency or dedicated resources use instance-per-tenant deployments (`docs/plans/blb-hosted-instances.md`); app-layer tenancy is for multi-tenant SaaS and agency/reseller hosting on one instance.

## Current behavior

This doc describes implemented behavior. The owning module is `app/Base/Tenancy` (see its `AGENTS.md` for canonical rules); the plan of record is `docs/plans/base-tenancy.md`.

### Data model

- `tenants`: `id`, `parent_id` (nullable self-hierarchy), `name`, `status`, soft deletes. Tenant id=1 (`Tenant::LICENSEE_TENANT_ID`) is seeded by the create-migration and kept in sync by `FrameworkPrimitivesProvisioner`; it cannot be deleted.
- `companies.tenant_id`: indexed, defaults to 1. A company's tenant is assigned at creation and treated as immutable.
- Audit tables (`base_audit_mutations`, `base_audit_actions`) carry an indexed nullable `tenant_id` stamped at capture time; rows predating tenancy backfill to the licensee tenant, while rows captured without a resolvable tenant (e.g. anonymous requests) remain null.

### Tenant context

`TenantContext` (scoped binding, `ApplicationTenantContext`) is the only current-tenant carrier:

- **Web:** `ResolveTenantContext` middleware resolves the authenticated user's tenant (derived from their company); guests resolve to null.
- **Queue:** the tenant ID is stamped onto the queue payload at dispatch; the worker restores it on `JobProcessing` and clears it on `JobProcessed`/`JobFailed`, so sequential jobs in one worker never share context.
- **CLI/scheduler:** platform operations run with no tenant context by default; tenant-scoped console work wraps execution in `TenantContext::runForTenant($id, ...)`.

Consumers fail closed on null: no tenant context must never widen into unscoped access. Octane/FrankenPHP scoped-binding flushes plus explicit queue clearing defend against worker leakage.

### Authorization

`TenantScopePolicy` (Base/Authz) runs in the policy pipeline ahead of `CompanyScopePolicy` and compares actor and resource tenant IDs, denying cross-tenant access with `DENIED_TENANT_SCOPE` regardless of role grants. Resources carrying `company_id` but no `tenant_id` are enriched through the `TenantDirectory` contract (Base/Authz contract; Core/Company binds `CompanyTenantDirectory`), so modules that only carry `company_id` — the pre-tenancy convention — are tenant-isolated without schema changes. When a resource's tenant cannot be resolved, the policy abstains and company scope remains the guard.

### Settings

`ScopeType::TENANT` adds a tenant layer to the cascade. Resolution order is user → company → tenant → global (and company → tenant → global, tenant → global). Definitions opt in by declaring the `tenant` scope; definitions without it resolve exactly as before.

### AI configuration

Provider credentials stay company-owned. `ConfigResolver::resolveDefault()` gains a tenant fallback: when the agent's company has no working provider, the first other company in the same tenant with a working configuration (oldest first, typically the tenant's anchor company) supplies the default. Lookups never cross tenant boundaries.

### Storage

`TenantStoragePath` prefixes caller-supplied upload directories with `tenants/{id}/` whenever a tenant context is active; `MediaAssetStore::putUploadedFile()` applies it at the single upload entry point. With no tenant context, paths are unchanged, so existing single-tenant installs keep their layout.

### Operator surface

Admin tenant management (list, create with optional parent) lives at `admin/tenancy/tenants` behind the `admin.tenancy.tenant.*` capabilities. The menu surface is gated by the `tenancy.visible` menu condition: more than one tenant, or `tenancy.show_management` set true.

## Boundaries deliberately not built

Per-tenant module entitlements, quotas, metering, billing, provisioning UX, branding, and cross-tenant support impersonation remain operator/product scope built on these primitives. See the plan of record for the exclusions and their seams.
