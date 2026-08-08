# ADR 0002: Tenant model above Company

**Document Type:** Architecture Decision Record
**Status:** Accepted
**Scope:** Tenant entity, isolation mechanism, context propagation, single-tenant latency
**Last Updated:** 2026-08-08

## Context

Belimbing targets SMBs and agencies, but its only isolation boundary was one licensee company per instance (`Company::LICENSEE_ID = 1`, `CompanyScopePolicy`). Two generic demands break that model: SaaS operation for many small companies on one instance, and agencies hosting their own clients (resale) with customer isolation. Retrofitting tenancy after domains accumulate becomes a data migration across every table in every module; wired in early, it is a bounded change against the single uniform `company_id` boundary every module already respects.

## Decision

A new **Tenant** entity sits above Company (`docs/architecture/tenancy.md`):

- `tenants` table with `parent_id` for operator/reseller hierarchies; `companies.tenant_id` references it, defaulted and backfilled to the licensee tenant id=1.
- **Tenant is the outer isolation boundary** (one party renting the software); Company stays the inner organizational boundary and is never overloaded to mean "renter of the software".
- Isolation is **single-database, application-layer**: `TenantScopePolicy` ahead of `CompanyScopePolicy` in the authz pipeline, tenant enrichment through the `TenantDirectory` contract, a tenant layer in the settings cascade, tenant fallback in AI config resolution, tenant-stamped audit rows, and tenant-partitioned storage paths.
- Context propagation is **explicit and fail-closed**: middleware resolves from the authenticated actor; queue jobs are stamped at dispatch and rehydrated on run; CLI/scheduler run tenant-scoped work via `TenantContext::runForTenant()`. No context resolves to no tenant rows, never to all rows.
- **Tenancy is latent** on single-tenant instances: no tenant UI until a second tenant exists or `tenancy.show_management` is set, preserving the self-hosted SMB product identity.
- Multi-tenant SaaS and agency hosting use this app-layer tenancy; customers needing residency or dedicated resources use instance-per-licensee deployments.

## Alternatives Considered

### Operator-layer tenancy

Each hosted product builds its own tenant concept; the platform stays untouched. Rejected: platform-owned services (settings, authz, AI credentials, audit) remain company-scoped and would leak across an operator's customers; every future module would re-invent scoping. Tenancy is a platform property or it is nothing.

### Company-as-tenant

Use the existing company tree as the tenant hierarchy. Rejected on honesty and fit: companies are also business-relationship records (customers, suppliers, portal users); a tenant's own customer company would semantically become a sub-tenant, corrupting both concepts, and the name cannot carry the renter role truthfully.

### Schema-per-tenant or database-per-tenant

Rejected: they break the one-artifact install story, module-owned migrations, and every platform assumption about a single ledger. Hard residency needs are served by instance-per-tenant deployment instead — an already-working pattern, not a compromise.
