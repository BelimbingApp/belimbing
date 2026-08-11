# ADR 0002: Tenant model above Company

**Document Type:** Architecture Decision Record
**Status:** Accepted
**Scope:** Tenant entity, isolation mechanism, context propagation, single-tenant latency
**Last Updated:** 2026-08-11

## Context

Belimbing targets SMBs and agencies, but its historical isolation convention was one installation-owning company with numeric ID 1 (`CompanyScopePolicy`). Two generic demands break that model: SaaS operation for many small companies on one instance, and agencies hosting their own clients (resale) with customer isolation. Retrofitting tenancy after domains accumulate becomes a data migration across every table in every module; wired in early, it is a bounded change against the single uniform `company_id` boundary every module already respects.

## Decision

A new **Tenant** entity sits above Company (`docs/architecture/tenancy.md`):

- `tenants` has `parent_id` for operator/reseller hierarchies and an explicit `is_platform_operator` marker. Exactly one live tenant is the platform operator after provisioning; numeric IDs have no runtime meaning.
- **Tenant is the outer data-isolation and subscription boundary** for the operator or a hosted customer; Company stays the inner organizational boundary and is never overloaded to identify the party operating the deployment.
- Every provisioned tenant has exactly one primary company. Core/Company owns the `tenant_primary_companies` relationship because Base/Tenancy cannot depend on Core; absence is valid only while transactional provisioning has not completed.
- `companies.tenant_id` is required, has no database default, is immutable in normal model operations, and is protected by tenant and same-tenant parent foreign keys.
- Tenant-owned records that are not safely derivable at every access boundary carry their own tenant key. Address therefore has a required immutable `tenant_id`; this keeps unattached addresses tenant-owned and makes lists, routes, polymorphic attachment, and timezone mutations fail closed.
- Isolation is **single-database, application-layer**: `TenantScopePolicy` ahead of `CompanyScopePolicy` in the authz pipeline, tenant enrichment through the `TenantDirectory` contract, a tenant layer in the settings cascade, tenant fallback in AI config resolution, tenant-stamped audit rows, and tenant-partitioned storage paths.
- Context propagation is **explicit and fail-closed**: middleware resolves from the authenticated actor; queue jobs are stamped at dispatch and rehydrated on run; CLI/scheduler run tenant-scoped work via `TenantContext::runForTenant()`. No context resolves to no tenant rows, never to all rows.
- **Tenancy is latent** on single-tenant instances: no tenant UI until a second tenant exists or `tenancy.show_management` is set, preserving the self-hosted SMB product identity.
- Multi-tenant SaaS and agency hosting use this app-layer tenancy; customers needing residency or dedicated resources use instance-per-tenant deployments.

Historical tenant and company ID 1 values remain deterministic input for upgrading old installations only. They are not identities, defaults, or runtime lookup conventions.

### Database integrity

- PostgreSQL enforces at most one operator marker with a partial unique index on
  `tenants.is_platform_operator = true`.
- `companies.tenant_id` is required, has no default, and references `tenants`.
  Unique `(id, tenant_id)` and a composite parent foreign key prevent a company
  hierarchy from crossing tenants.
- `tenant_primary_companies.tenant_id` is both primary key and tenant foreign key;
  unique `company_id` prevents one company serving multiple tenants; composite
  `(company_id, tenant_id)` references `companies(id, tenant_id)` so the selected
  company must belong to that tenant. Restricted deletes prevent silent dangling
  relationships.
- Custom authorization roles require an owning company. Company-less roles are
  reserved for configured system roles, so a nullable role scope cannot become an
  accidental deployment-global capability grant across tenants.

### Provisioning lifecycle

A tenant row may exist without a primary-company assignment only while it is not yet
fully provisioned. `PrimaryCompanyManager` creates ordinary tenants, their first
company, and the assignment in one transaction. Installation uses a Base-owned
provisioning contract implemented by Core/Company to create or resolve the operator
tenant, its primary company, initial administrator, and Lara in one transaction.
Retries resolve the explicit roles rather than adopting numeric IDs. Existing users
or system agents owned by another tenant are invariant failures, never silently
reassigned.

### Migration and rollback

Upgrade migrations retain existing IDs. Legacy tenant/company ID 1 is accepted only
as deterministic input where the released schema established that meaning. A tenant
with one live company is backfilled; one with none remains unprovisioned; multiple
live candidates fail preflight unless an explicit valid assignment already exists.
The migration never chooses the oldest company. Fresh migration replay removes only
the predecessor migration's unowned bootstrap tenant; the predecessor's PostgreSQL
sequence synchronization is retained, so normal provisioning consumes the next
generated value. Legacy custom global roles are anchored to the operator tenant's
primary company; cross-tenant legacy assignments fail with an actionable preflight
rather than being silently retained or dropped. PostgreSQL source tables are locked
for the duration of migration preflights and writes so live DML cannot invalidate a
decision between inspection and backfill.

Rollback cannot restore semantic-ID safety. Primary-company backfill rollback is a
no-op because migrated assignments cannot be distinguished from later operational
transfers; dropping the preceding relationship-table migration removes those rows.
The company-integrity rollback restores the old default only to reproduce the old
schema, and operator-marker rollback refuses to erase a non-1 operator role. A
database backup is therefore required before deployment, and rollback after new
tenants or transfers is a data-recovery decision rather than a routine downgrade.
The custom-role rollback removes enforcement but deliberately does not erase the
explicit company ownership assigned during upgrade.

## Alternatives Considered

### Operator-layer tenancy

Each hosted product builds its own tenant concept; the platform stays untouched. Rejected: platform-owned services (settings, authz, AI credentials, audit) remain company-scoped and would leak across an operator's customers; every future module would re-invent scoping. Tenancy is a platform property or it is nothing.

### Company-as-tenant

Use the existing company tree as the tenant hierarchy. Rejected on honesty and fit: companies are also business-relationship records (customers, suppliers, portal users); a tenant's own customer company would semantically become a sub-tenant, corrupting both concepts, and the name cannot carry the renter role truthfully.

### Schema-per-tenant or database-per-tenant

Rejected: they break the one-artifact install story, module-owned migrations, and every platform assumption about a single ledger. Hard residency needs are served by instance-per-tenant deployment instead — an already-working pattern, not a compromise.
