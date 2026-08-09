# base-tenancy

**Status:** Implemented — Phases 1–5 complete
**Last Updated:** 2026-08-08
**Sources:** `docs/architecture/module-system.md`; `docs/architecture/user-employee-company.md`; `docs/architecture/tenancy.md`; `docs/architecture/decisions/0002-tenant-model.md`; `docs/plans/blb-hosted-instances.md`; `PRODUCT.md` (SMB and agency audiences)
**Agents:** amp/kimi-k3

## Problem Essence

Belimbing targets SMBs, but its only isolation boundary is one licensee company per instance (`Company::LICENSEE_ID = 1`, `CompanyScopePolicy` denies cross-company access). Two generic demands break that model. First, for many small companies SaaS is the easier form — they should not need to self-host to use, say, the People domain; an operator should be able to serve many small companies from one instance. Second, agencies are a stated platform audience, and an agency hosting Belimbing for its clients is a reseller: it needs its own customers isolated from each other while it administers all of them. Today neither is possible — company scoping was designed for one licensee's org structure, not for customer isolation. Tenancy retrofitted after domains accumulate becomes a data migration across every table in every module; wired into the platform now, it is a bounded change against the single uniform boundary (`company_id`) that every module already respects.

## Desired Outcome

- **Tenant is the platform's outer isolation boundary; Company remains the inner (organizational) boundary.** Company keeps its current meaning — business entities inside a licensee's world (their org, customers, suppliers) — and is never overloaded to mean "renter of the software."
- **Single-tenant instances see zero change.** Install creates one tenant (id=1, the licensee tenant); with exactly one tenant, no tenant UI, switcher, or cognitive overhead appears. Tenancy is latent until a second tenant exists — the self-hosted SMB product identity is preserved.
- **Platform services are tenant-safe and fail-closed:** authz, settings, AI provider config, audit, and storage cannot read or write across tenants; no tenant context resolves to no tenant rows, never to all rows.
- **Resale is structural:** `tenants.parent_id` gives operator → customer hierarchies a home, so a hosting partner administers its own sub-tenants without platform privileges.
- **Operators and products consume tenancy primitives** (context, scoping, settings layer, test helpers) without re-inventing them. Quotas, provisioning UX, branding surfaces, metering, and billing remain operator/product scope built on these primitives.
- **Deployment mapping is explicit:** multi-tenant SaaS operation uses this app-layer tenancy; customers needing residency, dedicated resources, or air-gapped installs use the existing instance-per-licensee pattern (`blb-hosted-instances`).

## Top-Level Components

- **`app/Base/Tenancy`** — the tenancy mechanism: `Tenant` model, `TenantContext` (current-tenant resolution and propagation), `TenantScopePolicy` for the authz policy pipeline, a tenant-scoping trait for models, and context propagation for middleware, queue, scheduler, and CLI. Lives in Base beside Authz and Settings because it is a cross-cutting platform mechanism, not an enterprise Domain.
- **Core integration** — `companies.tenant_id` (indexed, default 1); licensee tenant seeded at install alongside licensee company by `FrameworkPrimitivesProvisioner` (invoked by `MigrateCommand`, which `scripts/setup-steps/60-migrations.sh` runs in all environments); actor resolution extended from company to company→tenant where the Actor is built (`Actor::forUser()` seam).
- **Settings** — a `tenant` value in the existing generic `scope_type`/`scope_id` mechanism; cascade becomes user → company → tenant → global.
- **Core AI** — provider/model config cascade gains a tenant layer, implemented as a tenant-anchor fallback: agent workspace → company provider → first working provider config among the tenant's companies (oldest first) → runtime defaults. Provider rows stay company-owned; only the lookup cascades.
- **Audit** — indexed `tenant_id` columns on both audit tables (stamped from the row's own `tenant_id`, then request context, then ambient tenant context) so audit history queries can filter per tenant; historic rows backfill to the licensee tenant, since they predate tenancy. Implemented as columns rather than the subject-suffix sketch below, because columns are indexable and cover entries without a subject.
- **Test utilities** — tenant fixtures and helpers (`tests/Pest.php`), plus a cross-tenant isolation suite proving fail-closed denial across authz, settings, and AI config.

## Design Decisions

### 1. Where the Tenant concept lives

Three options were weighed:

- **A. Operator-layer tenancy (each hosted product builds its own tenant concept; platform untouched).** Rejected. Platform-owned services — settings, authz, AI provider credentials, audit — remain company-scoped and would leak across an operator's customers unless each operator bypasses or rebuilds each one. Every future module re-invents scoping, and the platform's own authoring rules could no longer promise isolation. Tenancy is a platform property or it is nothing.
- **B. Company-as-tenant (the existing `parent_id` company tree is the tenant hierarchy).** The smallest diff: the platform already isolates by company, and `CompanyScopePolicy` even calls its boundary "the tenant boundary." Rejected on honesty and fit: companies are also business-relationship records (customers, suppliers, portal users). Under B, a tenant's own customer company — a row in its CRM, say — would semantically become a sub-tenant, corrupting both concepts. Root-company-plays-tenant is a role the name cannot carry truthfully.
- **C. New Tenant entity above Company (recommended).** `tenants` table (`id`, `parent_id` for operator/reseller hierarchy, `name`, `status`), `companies.tenant_id` FK. Company stays the inner organizational boundary; Tenant is the outer software-renting boundary. Single-licensee deployments are the degenerate one-tenant case; migration is a uniform backfill to tenant 1. C costs more up front than B but is the only option whose names tell the truth, and the only one that gives resale hierarchies a clean home.

### 2. Isolation mechanism

Single database with application-layer enforcement. Enforcement is layered: `TenantScopePolicy` in the authz pipeline (runs before `CompanyScopePolicy`), a tenant-aware query-scope convention for models, and a fail-closed `TenantContext` (no context → no rows). Schema-per-tenant and DB-per-tenant were rejected: they break the one-artifact install story, module-owned migrations, and every platform assumption about a single ledger. Customers needing hard residency or dedicated isolation get instance-per-tenant deployments instead — an already-working pattern, not a compromise.

### 3. Context propagation

Explicit over ambient: tenant context resolves once (middleware from the authenticated actor; queue jobs stamped at dispatch and rehydrated on run; scheduler/CLI take an explicit tenant or run as platform context). Defense against the classic ambient-context leak — a queued job silently executing as the wrong tenant — is a contract requirement, and the isolation suite must prove it.

### 4. Latent tenancy

Tenancy must not tax the single-licensee SMB experience that is Belimbing's reason to exist. With exactly one tenant, nothing tenant-shaped surfaces in the UI. Tenant management is admin-only and appears when a second tenant is created (or via an explicit platform setting for the first).

### 5. What this plan deliberately does NOT build

- **Per-tenant module entitlements** (an operator selling domains separately per tenant). `DomainState` is instance-global today; tenant-scoped enablement is a commercial-layer concern. The seam is recorded here so entitlement work later extends `DomainState` rather than inventing a parallel gate.
- **Quotas, metering, billing, provisioning UX, branding surfaces.** Operator/product scope. The platform's obligation is limited to not blocking them: tenant-scoped settings give branding/quota config a home, and tenant-stamped audit/telemetry give metering its raw signal.
- **Cross-tenant platform-operator access** (support impersonation). Needs an explicit audited capability; specified when the first real operator workflow demands it, not speculatively.

## Public Contract

- `TenantContext`: resolves the current tenant ID or null; consumers fail closed on null.
- `tenants` — `id`, `parent_id` (nullable, operator/reseller hierarchy), `name`, `status`; id=1 is the licensee tenant, upserted at install like `Company::LICENSEE_ID`.
- `companies.tenant_id` — required (NOT NULL), indexed, default/backfilled to 1. No database foreign-key constraint: `companies` declares none today (`parent_id` included), and tenancy is not the place to change that convention. Integrity is held by the default, the undeletable licensee tenant, and soft-deleted tenants.
- Authz: `tenant_scope` policy key, evaluated before `company_scope`; denies cross-tenant resource access regardless of role grants.
- Settings: scope `tenant`; resolution order global → tenant → company → user.
- AI config: agent workspace → company → tenant → runtime defaults.
- Authoring rule (lands in root `AGENTS.md` at Phase 4): **new tables holding tenant-owned data carry `tenant_id`; high-volume tables must denormalize it even when derivable via company.**

## Phases

### Phase 1 — Base/Tenancy core

- [x] `tenants` migration + `Tenant` model + `companies.tenant_id` migration with backfill to 1 {amp/kimi-k3}
- [x] `TenantContext` service (resolve, propagate, fail-closed) and web middleware {amp/kimi-k3}
- [x] `TenantScopePolicy` registered in the authz pipeline ahead of `CompanyScopePolicy` {amp/kimi-k3}
- [x] Installer: licensee tenant upsert — delivered inside `FrameworkPrimitivesProvisioner`, which `MigrateCommand` invokes and `60-migrations.sh` runs in all environments {amp/kimi-k3}
- [x] Validation: fresh install boots; existing instance migrates with zero behavior change (`migrate --dev` clean, schema drift CLEAN); focused Pest coverage on context resolution and policy denial (`tests/Feature/Base/Tenancy/`) {amp/kimi-k3}

### Phase 2 — Platform service integration

- [x] Settings `tenant` scope + cascade user → company → tenant → global (opt-in per definition; tenant link derived through `TenantDirectory` when the scope carries no explicit tenant id) {amp/kimi-k3}
- [x] Core AI config cascade gains tenant layer (anchor-company fallback in `ConfigResolver::resolveDefault()`; `TenantDirectory::companyIdsInTenant()` added for it) {amp/kimi-k3}
- [x] Audit tenant stamping — indexed `tenant_id` columns on `base_audit_mutations`/`base_audit_actions`, stamped at every listener/middleware capture point, historic rows backfilled to tenant 1 {amp/kimi-k3}
- [x] Tenant-partitioned storage paths for tenant-owned files (`TenantStoragePath`, applied in `MediaAssetStore::putUploadedFile()`) {amp/kimi-k3}
- [x] Validation: cross-tenant settings/AI-config reads provably fail closed (`TenantSettingsCascadeTest`, `TenantConfigResolverTest`) {amp/kimi-k3}

### Phase 3 — Propagation and proof

- [x] Queue job tenant stamping at dispatch, rehydration on run, clearing after completion (`Tenancy\ServiceProvider` payload hook + job lifecycle listeners) {amp/kimi-k3}
- [x] Scheduler/CLI explicit-tenant convention (`TenantContext::runForTenant()`; documented in `app/Base/Tenancy/AGENTS.md`) {amp/kimi-k3}
- [x] Pest tenant helpers (`createTenant()`, `createTenantWithCompany()` in `tests/Pest.php`) {amp/kimi-k3}
- [x] Cross-tenant isolation suite: authz, settings, AI config, queued work (`tests/Feature/Base/Tenancy/`, `tests/Feature/AI/TenantConfigResolverTest.php`) {amp/kimi-k3}
- [x] Validation: isolation suite demonstrates a job dispatched without tenant context observes none; full suite green {amp/kimi-k3}

### Phase 4 — Operator surface and docs

- [x] Minimal admin tenant management (list + create with optional parent; latent per Decision 4 via the `tenancy.visible` menu condition and `tenancy.show_management` setting) {amp/kimi-k3}
- [x] `docs/architecture/tenancy.md` (current-behavior architecture doc) {amp/kimi-k3}
- [x] ADR `docs/architecture/decisions/0002-tenant-model.md` {amp/kimi-k3}
- [x] Root `AGENTS.md` authoring rule for `tenant_id` on new tenant-owned tables {amp/kimi-k3}
- [x] Validation: docs verified against code per `docs/architecture/AGENTS.md`; admin surface covered by `TenantAdminUiTest` {amp/kimi-k3}

### Phase 5 — Consumer proof from an existing Domain

- [x] Model-level tenant enrichment proven in the platform suite (`TenantIsolationTest`): a real Eloquent model carrying only `company_id` is enriched via the `TenantDirectory`, with cross-tenant denial and `filterAllowed` exclusion across the boundary (a People/Leave-specific duplicate was reviewed as redundant and dropped; the People suite passing unmodified is the composition proof) {amp/kimi-k3}
- [x] Demonstrate latent tenancy: a single-tenant instance shows no tenant surface (`TenantAdminUiTest` menu-condition cases) {amp/kimi-k3}
- [x] Validation: People domain suite passes unmodified (229 tests), proving tenancy composes without Domain code changes {amp/kimi-k3}
