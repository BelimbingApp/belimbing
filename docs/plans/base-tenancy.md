# base-tenancy

**Status:** Completed — explicit operator and primary-company model implemented and validated
**Last Updated:** 2026-08-11
**Sources:** `docs/architecture/module-system.md`; `docs/architecture/user-employee-company.md`; `docs/architecture/tenancy.md`; `docs/architecture/decisions/0002-tenant-model.md`; `docs/plans/blb-hosted-instances.md`; `PRODUCT.md` (SMB and agency audiences)
**Agents:** amp/kimi-k3; amp/gpt-5.6; codex/sol-high

## Problem Essence

Belimbing originally isolated one installation through a company whose numeric ID was assumed to be 1. That convention could not represent a platform operator, multiple SaaS customers, or reseller-hosted tenants honestly, and it made retained sequence values part of application behavior. Tenancy must instead carry explicit operator and primary-company roles while preserving existing rows and the Base → Core dependency direction.

## Desired Outcome

- **Tenant is the platform's outer isolation boundary; Company remains the inner (organizational) boundary.** Company keeps its current meaning — the tenant's own business plus related companies — and is never overloaded to mean "renter of the software."
- **Identity is explicit.** Exactly one tenant is marked as the platform operator, and each fully provisioned tenant has one Core/Company-owned primary-company assignment. Numeric IDs carry no role meaning.
- **Single-tenant instances keep the same experience.** With exactly one tenant, no tenant UI, switcher, or cognitive overhead appears. Tenancy is latent until a second tenant exists — the self-hosted SMB product identity is preserved.
- **Platform services are tenant-safe and fail-closed:** authz, settings, AI provider config, audit, and storage cannot read or write across tenants; no tenant context resolves to no tenant rows, never to all rows.
- **Resale is structural:** `tenants.parent_id` gives operator → customer hierarchies a home, so a hosting partner administers its own sub-tenants without platform privileges.
- **Operators and products consume tenancy primitives** (context, scoping, settings layer, test helpers) without re-inventing them. Quotas, provisioning UX, branding surfaces, metering, and billing remain operator/product scope built on these primitives.
- **Deployment mapping is explicit:** multi-tenant SaaS operation uses this app-layer tenancy; customers needing residency, dedicated resources, or air-gapped installs use the existing instance-per-tenant pattern (`blb-hosted-instances`).

## Top-Level Components

- **`app/Base/Tenancy`** — the tenancy mechanism: `Tenant` model, `TenantContext` (current-tenant resolution and propagation), `TenantScopePolicy` for the authz policy pipeline, a tenant-scoping trait for models, and context propagation for middleware, queue, scheduler, and CLI. Lives in Base beside Authz and Settings because it is a cross-cutting platform mechanism, not an enterprise Domain.
- **Core integration** — `companies.tenant_id` is required, explicit, immutable, foreign-keyed, and has no default. Core/Company owns `tenant_primary_companies` and implements the Base-owned `FrameworkPrimitivesProvisioner` contract. Installation creates the operator tenant, its primary company, assignment, initial admin, and Lara transactionally.
- **Settings** — a `tenant` value in the existing generic `scope_type`/`scope_id` mechanism; cascade becomes user → company → tenant → global.
- **Core AI** — provider/model config cascade gains a tenant layer implemented through the tenant's explicit primary company: agent workspace → company provider → primary-company provider → runtime defaults. Provider rows stay company-owned; lookups never infer an oldest company or cross tenants.
- **Audit** — indexed `tenant_id` columns on both audit tables (stamped from the row's own `tenant_id`, then request context, then ambient tenant context) so audit history queries can filter per tenant; historic rows retain their deterministic legacy backfill. Implemented as columns rather than the subject-suffix sketch below, because columns are indexable and cover entries without a subject.
- **Test utilities** — tenant fixtures and helpers (`tests/Pest.php`), plus a cross-tenant isolation suite proving fail-closed denial across authz, settings, and AI config.

## Design Decisions

### 1. Where the Tenant concept lives

Three options were weighed:

- **A. Operator-layer tenancy (each hosted product builds its own tenant concept; platform untouched).** Rejected. Platform-owned services — settings, authz, AI provider credentials, audit — remain company-scoped and would leak across an operator's customers unless each operator bypasses or rebuilds each one. Every future module re-invents scoping, and the platform's own authoring rules could no longer promise isolation. Tenancy is a platform property or it is nothing.
- **B. Company-as-tenant (the existing `parent_id` company tree is the tenant hierarchy).** The smallest diff: the platform already isolates by company, and `CompanyScopePolicy` even calls its boundary "the tenant boundary." Rejected on honesty and fit: companies are also business-relationship records (customers, suppliers, portal users). Under B, a tenant's own customer company — a row in its CRM, say — would semantically become a sub-tenant, corrupting both concepts. Root-company-plays-tenant is a role the name cannot carry truthfully.
- **C. New Tenant entity above Company (recommended).** `tenants` owns the operator marker and hierarchy; `companies.tenant_id` owns explicit company placement; Core/Company's `tenant_primary_companies` owns the tenant-to-primary-company relationship. Company stays the inner organizational boundary and Tenant is the outer software-renting boundary. Existing ID-1 rows remain deterministic upgrade input only. C costs more up front than B but is the only option whose names and dependency direction tell the truth.

### 2. Isolation mechanism

Single database with application-layer enforcement. Enforcement is layered: `TenantScopePolicy` in the authz pipeline (runs before `CompanyScopePolicy`), a tenant-aware query-scope convention for models, and a fail-closed `TenantContext` (no context → no rows). Schema-per-tenant and DB-per-tenant were rejected: they break the one-artifact install story, module-owned migrations, and every platform assumption about a single ledger. Customers needing hard residency or dedicated isolation get instance-per-tenant deployments instead — an already-working pattern, not a compromise.

### 3. Context propagation

Explicit over ambient: tenant context resolves once (middleware from the authenticated actor; queue jobs stamped at dispatch and rehydrated on run; scheduler/CLI take an explicit tenant or run as platform context). Defense against the classic ambient-context leak — a queued job silently executing as the wrong tenant — is a contract requirement, and the isolation suite must prove it.

### 4. Latent tenancy

Tenancy must not tax the single-tenant SMB experience that is Belimbing's reason to exist. With exactly one tenant, nothing tenant-shaped surfaces in the UI. Tenant management is admin-only and appears when a second tenant is created (or via an explicit platform setting for the first).

### 5. What this plan deliberately does NOT build

- **Per-tenant module entitlements** (an operator selling domains separately per tenant). `DomainState` is instance-global today; tenant-scoped enablement is a commercial-layer concern. The seam is recorded here so entitlement work later extends `DomainState` rather than inventing a parallel gate.
- **Quotas, metering, billing, provisioning UX, branding surfaces.** Operator/product scope. The platform's obligation is limited to not blocking them: tenant-scoped settings give branding/quota config a home, and tenant-stamped audit/telemetry give metering its raw signal.
- **Cross-tenant platform-operator access** (support impersonation). Needs an explicit audited capability; specified when the first real operator workflow demands it, not speculatively.

## Public Contract

- `TenantContext`: resolves the current tenant ID or null; consumers fail closed on null.
- `tenants` — `id`, `parent_id` (nullable, operator/reseller hierarchy), `name`, `status`, `is_platform_operator`, timestamps, and soft deletes. A partial unique index permits at most one marked operator; runtime requires exactly one after provisioning.
- `companies.tenant_id` — required (NOT NULL), indexed, no default, immutable in normal application behavior, and foreign-keyed to `tenants.id`. Unique `(id, tenant_id)` plus a composite parent FK prevents cross-tenant company trees.
- `tenant_primary_companies` — Core/Company-owned table with `tenant_id` as primary/foreign key, unique `company_id`, and composite `(company_id, tenant_id)` FK to `companies(id, tenant_id)`. No assignment means provisioning is incomplete; missing, cross-tenant, or soft-deleted assigned rows are invariant violations.
- Authz: `tenant_scope` policy key, evaluated before `company_scope`; denies cross-tenant resource access regardless of role grants.
- Settings: scope `tenant`; resolution order global → tenant → company → user.
- AI config: agent workspace → company → tenant → runtime defaults.
- Authoring rule (lands in root `AGENTS.md` at Phase 4): **new tables holding tenant-owned data carry `tenant_id`; high-volume tables must denormalize it even when derivable via company.**

## Phases

### Phase 1 — Base/Tenancy core

- [x] `tenants` migration + `Tenant` model + `companies.tenant_id` migration with backfill to 1 {amp/kimi-k3}
- [x] `TenantContext` service (resolve, propagate, fail-closed) and web middleware {amp/kimi-k3}
- [x] `TenantScopePolicy` registered in the authz pipeline ahead of `CompanyScopePolicy` {amp/kimi-k3}
- [x] Installer: initial tenant bootstrap — delivered inside `FrameworkPrimitivesProvisioner`, which `MigrateCommand` invokes and `60-migrations.sh` runs in all environments {amp/kimi-k3}
- [x] Validation: fresh install boots; existing instance migrates with zero behavior change (`migrate --dev` clean, schema drift CLEAN); focused Pest coverage on context resolution and policy denial (`tests/Feature/Base/Tenancy/`) {amp/kimi-k3}

### Phase 2 — Platform service integration

- [x] Settings `tenant` scope + cascade user → company → tenant → global (opt-in per definition; tenant link derived through `TenantDirectory` when the scope carries no explicit tenant id) {amp/kimi-k3}
- [x] Core AI config cascade gains tenant layer (explicit primary-company fallback in `ConfigResolver::resolveDefault()`) {amp/gpt-5.6}
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

### Phase 6 — Explicit operator and primary-company identity

- [x] Add the explicit platform-operator marker, database uniqueness, marker-based resolution/deletion protection, and distinct missing/corrupt exceptions without making Base depend on Core {amp/gpt-5.6}
- [x] Remove the company tenant default, add tenant and same-tenant hierarchy foreign keys, and add Core/Company-owned primary-company schema with deterministic, ambiguity-safe legacy backfill {amp/gpt-5.6}
- [x] Replace runtime licensee-ID APIs and fallbacks across setup, framework provisioning, locale, settings, AI, authz, user/employee/company UI, seeders, and scripts {amp/gpt-5.6}
- [x] Make operator and arbitrary tenant provisioning transactional, idempotent, sequence-safe, and explicit about incomplete versus corrupt primary-company state {amp/gpt-5.6}
- [x] Close adjacent tenant leaks in address ownership/attachment, chat agent selection, and system-agent provisioning {amp/gpt-5.6}
- [x] Update architecture, ADR, module docs, and local guidance for the post-migration contract {amp/gpt-5.6}
- [x] Run PostgreSQL migration/constraint validation in CI {codex/sol-high}
- [x] Run the full repository test and formatting checks {codex/sol-high}
