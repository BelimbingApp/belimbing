# Tenancy Module (app/Base/Tenancy)

Tenant is the platform's **outer isolation boundary** — one party renting the
software. Company (Core) remains the **inner organizational boundary** inside a
tenant. Tenants form a hierarchy (`parent_id`) so an operator can administer its
own customer sub-tenants (resale).

## Canonical rules

- Exactly one live tenant is explicitly marked `is_platform_operator`; IDs have no
  runtime semantics. Legacy ID 1 is deterministic migration input only.
- Every provisioned tenant has one primary company. Core/Company owns the
  `tenant_primary_companies` relationship and its manager because Base cannot depend
  on Core. Missing assignment means not yet provisioned; an invalid assigned company
  is an invariant violation, and deletion requires an explicit safe transfer first.
- `TenantContext` (scoped binding) is the only current-tenant carrier. Web requests
  resolve it via `ResolveTenantContext` middleware; queue jobs are stamped with the
  dispatch-time tenant on the payload and rehydrated on `JobProcessing`, then cleared
  on completion so worker processes never leak context between jobs; CLI/scheduler
  tenant work wraps execution in `TenantContext::runForTenant($id, ...)`. Consumers
  fail closed on null — never widen to unscoped access.
- Base cannot depend on Core: `Tenant` has no `companies()` relation. The inverse
  (`Company::tenant()`) and the company→tenant lookup (`CompanyTenantDirectory`,
  bound against Authz's `TenantDirectory` contract) live in Core/Company.
- Base owns the narrow `FrameworkPrimitivesProvisioner` contract; Core/Company
  implements transactional, idempotent provisioning of the operator tenant, primary
  company, relationship, initial admin, and Lara.
- Authz enforcement is `TenantScopePolicy` (Base/Authz pipeline, ahead of
  `CompanyScopePolicy`). It compares DTO tenant IDs only and abstains when the
  resource carries no tenant — company scope remains the guard there.
- New tables holding tenant-owned data carry `tenant_id`; high-volume tables
  denormalize it even when derivable via company.

## Platform integrations (owned elsewhere, contract here)

- **Settings**: `tenant` scope; cascade user → company → tenant → global. Definitions
  opt in by declaring the `tenant` scope.
- **AI**: `Core/AI ConfigResolver::resolveDefault()` falls back to the explicitly
  assigned tenant primary company's provider config when the agent's company has
  none; it never infers the oldest company and never crosses tenants.
- **Audit**: both audit tables carry `tenant_id`; stamped from the row's own
  `tenant_id`, then request context, then ambient tenant context.
- **Media**: `MediaAssetStore::putUploadedFile()` prefixes directories with
  `tenants/{id}/` via `TenantStoragePath` when a tenant context is active.
- **Admin surface**: `admin/tenancy/tenants` (capabilities `admin.tenancy.tenant.*`),
  gated by the `tenancy.visible` menu condition — visible when more than one tenant
  exists or `tenancy.show_management` is set. Keep single-tenant instances free of
  tenant UI.

## Test helpers

`createTenant()` and `createTenantWithCompany()` in `tests/Pest.php`; isolation,
propagation, settings-cascade, and admin-surface suites live in
`tests/Feature/Base/Tenancy/`.

Plan of record: `docs/plans/base-tenancy.md`.
