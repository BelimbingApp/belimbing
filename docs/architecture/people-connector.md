# People Provider and PeopleConnector Boundary

**Document Type:** Architecture Specification
**Scope:** Ownership, topology, provider contracts, deployment modes, transport, identity, and persistence for provider-neutral People integration
**Last Updated:** 2026-08-31
**Related:** `docs/architecture/module-system.md`, `docs/architecture/database.md`, `docs/architecture/tenancy.md`, `app/Base/Integration/AGENTS.md`, `docs/plans/people/01_people-modules.md`

## Overview

Belimbing keeps its native HR system and its provider-neutral integration layer in separate optional Domains. `People` remains mounted at `app/Domains/People/` and owns the HR records and workflows supplied by the native provider. `PeopleConnector` mounts independently at `app/Domains/PeopleConnector/`; it is the anti-corruption boundary around whichever HR provider is active and the authoritative owner of supplemental Skill and Training capabilities.

This is the intended architecture for the connector implementation. It applies unchanged across development, staging, and production. Placement may change the adapter transport, but it does not change capability ownership, identity semantics, authorization, failure behavior, or the provider-neutral contract.

## Topology and lifecycle

```text
app/Domains/
├── People/                         native HR provider Domain
└── PeopleConnector/                provider-neutral connector Domain
    ├── Connector/                  provider contract, adapters, projections, health
    ├── Skill/                      requirements, assessments, actions, score history
    └── Training/                   requests, events, participants, evaluation, effectiveness
```

`PeopleConnector` is delivered by `BelimbingApp/blb-people-connector` and has an independent optional-Domain lifecycle. Installing or enabling `People` does not implicitly install or enable `PeopleConnector`, and disabling either Domain does not delete the other's durable data. The connector must boot safely in an unconfigured or disconnected state.

The stable Module identities and migration slots are:

| Module | Stable ID | Migration prefix |
|--------|-----------|------------------|
| Connector | `people-connector/connector` | `0330_01_01_*` |
| Skill | `people-connector/skill` | `0330_02_01_*` |
| Training | `people-connector/training` | `0330_02_03_*` |

The physical namespace is `App\Domains\PeopleConnector`. Connector is the foundation; Skill depends on Connector; Training depends on Connector and Skill. New dependencies must follow that direction rather than creating a cycle between the supplemental capabilities.

## Source-of-truth ownership

Every field and workflow has one authoritative owner.

| Concern | Authoritative owner | Connector treatment |
|---------|---------------------|---------------------|
| BLB tenant, company, and signed-in principal | Base Tenancy and Core Company/User | References the platform identities required for tenant scope and authorization. |
| Native employee, organization, payroll, attendance, leave, claim, and other enabled HR workflows | `People`, when it is the selected provider | Reads only through the first-party adapter and retains the minimum approved projection, provenance, and freshness state. |
| Equivalent HR capabilities supplied by an external provider | That provider, to the extent declared by its verified capability contract | Uses the provider adapter; unsupported or read-only behavior remains explicit. |
| Provider selection, capability declarations, stable external identity maps, minimal workforce projections, connection health, freshness, and reconciliation | PeopleConnector / Connector | Stores connector-owned operational state without becoming a second HR master. |
| Skill catalogues, requirement profiles, assessments, development actions, reassessments, qualification mappings, evidence-validity decisions, current-score projections, and coverage | PeopleConnector / Skill | Owns append-only competency facts and decides whether linked evidence is current and counts toward competence. |
| Training needs and approvals, catalogues and events, participant attendance/results, issued certificate artifacts and dates, evaluations, effectiveness reviews, and training passports | PeopleConnector / Training | Owns the complete supplemental training lifecycle and links its evidence to Skill and provider-neutral workforce references. |
| External transport, OAuth transport primitives, secret redaction, and outbound exchange observability | Base Integration | Connector supplies business meaning, capability policy, parsing, idempotency, and reconciliation. |

When `People` is the selected provider, it remains the HR system of record. Connector-owned Skill and Training records do not move employee-master, payroll, leave, attendance, or claim authority into PeopleConnector. Conversely, completing a provider-owned course or attendance record cannot mutate connector-owned competency history unless a governed import or connector workflow creates the corresponding official connector record.

Training owns the certificate issued for a participant record, including its number, issuer-supplied dates, and artifact provenance. Skill may link that record as evidence, but Skill alone owns the qualification mapping and the decision that the evidence is current and may count toward a competency score or coverage. Linking does not copy or create a second authoritative certificate artifact.

## Provider-neutral contract

Connector-owned features depend on a provider-neutral port, never on `People` models, HR2000 fields, or provider tables. The contract must carry:

- truthful, versioned capability declarations, including unsupported, read-only, writable, asynchronous/file-exchange, and provider-UI hand-off modes;
- tenant-scoped stable company, staff, user, manager, department, position/tier, and active-employment references;
- source/provider provenance plus as-of and freshness state;
- paginated reads and only the commands the provider actually supports;
- structured unsupported, validation, authorization, conflict, temporary, and unknown-outcome failures; and
- bootstrap, incremental synchronization, idempotency, reconciliation, and compatibility semantics.

One active provider is authoritative for a capability within an applicable tenant/company scope. Multiple installed adapters are inventory, not permission for concurrent writers. Connector-owned features may continue using their own historical records during a provider outage, but decisions that require current workforce or authorization context fail closed or expose an explicit stale state.

Provider changes preserve connector history through reviewed stable-identity remapping. Mutable names and email addresses are never silent identity keys.

## Same-installation provider boundary

When `People` and `PeopleConnector` run in the same Belimbing installation:

- the first-party adapter is the only translation layer between the provider-neutral contract and People-owned behavior;
- Skill and Training code cannot import People models, query People tables, or create foreign keys into them;
- the adapter may consume a documented People public contract, but it returns the same capability, identity, provenance, freshness, and error shapes as every other adapter;
- in-process calls remain in-process rather than making an artificial HTTP request back into the same application; and
- People remains authoritative for its HR records, while PeopleConnector remains authoritative for connector projections and supplemental Skill and Training records.

The in-process adapter is subject to the same conformance suite as remote adapters. Co-location is a transport optimization, not a different feature contract.

## Remote-provider boundary

When the selected HR provider is remote, `People` may be absent or disabled. The configured adapter crosses an authenticated, versioned provider boundary and projects only the data approved for connector workflows.

All outbound non-LLM network communication must use Base Integration's transport seam. HTTP calls use `App\Base\Integration\Services\IntegrationGateway`; future file, SFTP, webhook, or other transports must extend or use the Base Integration-owned seam rather than opening a second unobserved transport path. Provider adapters must not scrape screens or use undocumented databases, endpoints, or human-session credentials.

Remote placement must define authentication, timeouts, safe retry eligibility, unknown outcomes, schema/version negotiation, service levels, and reconciliation. The connector owns the business decision to retry and the idempotency key; Base Integration executes the approved transport policy and records a redacted exchange. Provider-specific parsing and user-facing recovery remain in the adapter/Connector Module, not Base Integration.

## Base Integration requirement

`people-connector/connector` relies on Base Database, Base Settings, Base Integration, Base Tenancy, Core Company, and Core User. These are architecture and migration-order dependencies, not a literal prescription that every Base component appear in `extra.blb.requires-modules`: Base Tenancy is a mandatory platform component rather than an optional Module prerequisite. Connector deliberately has no hard dependency on `people/*` or `core/employee`.

Base Integration is a transport gate, not a People-domain owner. It provides observable outbound exchange execution, OAuth primitives, retention, and mandatory secret/payload redaction. PeopleConnector owns provider configuration meaning, capability negotiation, identity mapping, synchronization checkpoints, reconciliation, and provider-facing error translation. An in-process same-installation adapter does not create a fake external exchange merely to exercise Base Integration.

## Persistence and identity

Connector-owned tenant data carries an indexed `tenant_id`. Provider-linked records store stable external references, provider/source provenance, and the organization/person snapshot needed to interpret history. They do not use foreign keys to People or external-provider tables, and deleting or replacing an adapter cannot cascade into connector-owned Skill or Training data.

Tables use the `people_connector_{module}_{entity}` prefix. Append-only assessments and decisions remain historical facts; current status, gaps, due state, and coverage are derived projections with visible inputs and as-of state. Provider remapping changes the reviewed identity link, not the historical fact.

## HR2000 discovery remains open

This architecture does not assert that SBG's HR2000 installation exposes an API, webhook, supported database interface, file exchange, SSO surface, or usable training history. Its exact product and version, hosting mode, licensed modules, vendor support terms, stable identifiers, approved read/write directions, security approval, and service levels require customer/vendor discovery.

Until that evidence exists, an HR2000 adapter may be designed and tested against the provider-neutral contract and approved fixtures, but it cannot claim production capability. Public marketing names are discovery input, not proof of an enabled or supported integration surface.
