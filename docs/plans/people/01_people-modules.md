# people/01_people-modules

**Status:** In progress — the current native People Modules are shipped; future native HR Modules and the separate PeopleConnector program remain open.
**Last Updated:** 2026-08-31
**Sources:**
- `app/Domains/People/` — current native People provider Domain
- `docs/architecture/people-connector.md` — provider, connector, transport, and supplemental-capability ownership boundary
- `docs/architecture/database.md` — `0320` People and `0330` PeopleConnector migration registries
- `app/Core/Employee/` — Core Employee model and domain logic
- `app/Core/Company/` — Core company hierarchy used for scoping
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll architecture and Malaysia country-pack research
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 i-Payroll parity benchmark
- `docs/plans/people/04_pdf-generation-strategy.md` — PDF rendering infrastructure (complete); Payroll and employee-facing Module surfaces consume `App\Base\Pdf\Jobs\RenderPdfJob` for every visual document
- `docs/architecture/pdf-rendering.md` — renderer surface, template convention (`resources/core/views/pdf/<module>/...`), concurrency model
- `https://github.com/BelimbingApp/blb-people/issues/20` — provider-neutral People Connector master
- `https://github.com/BelimbingApp/blb-people/issues/21` — ownership and HR data-boundary source issue
- `https://github.com/BelimbingApp/blb-people/issues/23` — connector repository/bootstrap source issue
- `docs/plans/AGENTS.md` — plan conventions
**Agents:** claude-code/opus-4.6, amp/gpt-5.1-codex, codex/gpt-5.6-sol

## Problem Essence

The original roadmap treated every HR-adjacent capability as a People Module and no longer matches the repository: Settings, Employees, Attendance, Leave, Claim, and Payroll now exist. It also put Training inside People, which would couple the replacement Skill and Training system to one HR provider and create competing ownership when a deployment selects HR2000 or another provider.

## Desired Outcome

`People` remains Belimbing's native HR provider and owns the HR records and workflows it supplies. `PeopleConnector` is a separate optional Domain at `app/Domains/PeopleConnector/`: it isolates provider differences, projects the minimum workforce context, and owns the provider-independent Skill and Training lifecycle. A deployment can use native People or a supported remote provider without changing connector-owned feature code or creating two systems of record.

## Top-Level Components

### Native People provider

| Module | State | Responsibility |
|--------|-------|----------------|
| **Settings** | Shipped | Native provider reference data, employee-work profiles, portal access, and People settings. |
| **Employees** | Shipped | Employee directory/workbench, profile review, export, and native organizational context. |
| **Attendance** | Shipped, evolving | Attendance operations, policy, roster, overtime, and payroll-facing facts. |
| **Leave** | Shipped, evolving | Entitlements, balances, applications, approvals, calendars, and payroll-facing facts. |
| **Claim** | Shipped, evolving | Expense/benefit claims, policies, approvals, evidence, and payroll reimbursement facts. |
| **Payroll** | Shipped, evolving | Country-neutral payroll engine, calculation, outputs, statutory packs, and employee payroll surfaces. |
| **Recruitment** | Planned | Requisitions, candidate pipeline, interviews, offers, and handoff into employee onboarding. |
| **Onboarding** | Planned | Checklist-driven document, equipment, orientation, and probation workflows. Training assignments link to PeopleConnector rather than creating a People-owned training store. |
| **Performance** | Planned | Goals, review cycles, manager/self assessment, and performance-improvement workflows. |
| **Disciplinary** | Planned | Incident, investigation, action, appeal, and closure workflows. |
| **Report** | Planned | Authorized cross-Module HR analytics over People-owned facts. Connector-owned Skill and Training analytics remain in PeopleConnector. |

Self-service is not a separate Module. Each owning Module exposes its employee, manager, HOD, HR/Finance, and administrator surfaces through capability-gated workflows.

### PeopleConnector

| Module | State | Responsibility |
|--------|-------|----------------|
| **Connector** | Planned in `BelimbingApp/blb-people-connector` | Provider contract, adapters, capability truth, tenant-scoped external identities, minimal workforce projections, freshness, health, and reconciliation. |
| **Skill** | Planned in `BelimbingApp/blb-people-connector` | Skill catalogues, requirements, assessments, gap actions, reassessments, score history, and coverage. |
| **Training** | Planned in `BelimbingApp/blb-people-connector` | Training needs/approvals, catalogues/events, participant records, evaluations, effectiveness, and passports. |

## Design Decisions

**PeopleConnector is an optional Domain, not a People Module or deployment Extension.** A separate lifecycle keeps provider integration and provider-independent supplements available when native People is not the selected HR system. The mount is `app/Domains/PeopleConnector/`, and the stable Module IDs are `people-connector/connector`, `people-connector/skill`, and `people-connector/training`.

**The selected provider owns HR; PeopleConnector owns supplements.** Native People remains authoritative for the HR capabilities it supplies. A supported remote provider remains authoritative for its declared capabilities. PeopleConnector stores only the approved projection and owns Skill and Training records regardless of provider; it never creates a second employee, payroll, leave, attendance, or claim master.

**Placement changes transport, not the contract.** A same-installation People adapter uses an in-process public contract. Remote adapters send external traffic through Base Integration. Both modes expose the same capability, stable-identity, freshness, failure, and conformance contract.

## Public Contract

- Skill, Training, and provider-neutral Connector services import no People model. Only the first-party adapter may consume a documented People public contract, and connector tables have no foreign keys into People or external-provider tables.
- Connector-owned tenant data carries indexed `tenant_id`, stable external references, provenance, and interpretable as-of snapshots.
- Base Integration is required for external transport and exchange observability but does not own provider semantics or Skill/Training workflows.
- Provider capability and write support are explicit; unsupported behavior fails before the UI accepts an impossible operation.
- HR2000 product, hosting, authentication, field, integration-rights, and service-level discovery remains open and is not inferred from public marketing.

## Phases

### Boundary and bootstrap

- [x] Establish the People/PeopleConnector ownership boundary, mount path, stable Module IDs, transport rule, and `0330` migration allocation in platform architecture. {codex/gpt-5.6-sol}
- [ ] Land the installable `BelimbingApp/blb-people-connector` Domain scaffold with an honest disconnected state, truthful Module-manifest requirements, and explicit reliance on the mandatory Base Integration and Tenancy components.
- [ ] Publish the provider-neutral contract and shared adapter conformance fixtures before connector-owned feature Modules depend on a real provider.

### Provider integration

- [ ] Implement the first-party same-installation People adapter without exposing People models or tables to Skill/Training code.
- [ ] Complete SBG/customer/vendor discovery for HR2000, then implement only the supported adapter surface with approved fixtures and reconciliation.
- [ ] Prove provider outage, stale projection, duplicate delivery, and provider replacement behavior before production activation.

### Supplemental capabilities

- [ ] Build Skill and Training against adapter test doubles and connector-owned persistence in the dependency order recorded by their source issues.
- [ ] Demonstrate the complete requirement-to-effective-training-to-reassessment loop without any provider write changing connector-owned competency history.
