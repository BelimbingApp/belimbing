# Pluggable Modules Architecture

**Document Type:** Architecture Specification
**Purpose:** Define how BLB evolves from a monolithic application into a framework whose application modules can be plugged in and out independently — country packs, optional domain modules, third-party contributions.
**Status:** Direction confirmed. Implementation phased.
**Audience:** BLB framework team, licensee developers, future country-pack contributors.
**Last Updated:** 2026-05-16
**Related:**
- `docs/architecture/file-structure.md` — current layer convention.
- `docs/guides/extensions/licensee-development-guide.md` — existing extension separation model.
- `docs/guides/extensions/private-extension-repositories.md` — existing nested-git pattern.
- `docs/tutorials/people/` — domain crash course; the People domain is the first pluggable case.

---

## 1. Vision

BLB is a framework, not an app. It ships **one opinionated reference implementation** (today: Malaysian operations) and provides stable contracts so:

- Licensees can swap any optional module for their own.
- Country specialists can build `blb-payroll-sg`, `blb-payroll-id`, etc. against the same Payroll framework `blb-payroll-my` uses.
- A company that uses an external payroll system can install Attendance + Leave + Claim and skip Payroll entirely.
- Future domains (Finance, Sales, Procurement) follow the same shape — ship MY-first, design the contracts so others can extend.

The model is the one Laravel, Django, and Rails followed: the reference is the documentation, contracts are the public API, the community contributes around the edges.

---

## 2. Goals

1. **True plug-and-play.** A module the deployment doesn't need should not be installed. Removing it should not break the modules that remain.
2. **Stable contracts.** Events, interfaces, payloads, and contributor protocols change on SemVer boundaries, not on internal whims.
3. **Same Git ergonomics as today.** The existing `extensions/{licensee}/` nested-git pattern works. We extend it; we don't replace it.
4. **No premature composer split.** Lift modules out of the main tree when contracts have stabilized — not before.
5. **One reference, many implementations.** BLB's own Malaysian content is the canonical example, not a privileged path.

---

## 3. Architectural Layers

The current layer convention (`docs/architecture/file-structure.md`) stays. The plug-ability rules apply per layer.

| Layer | Path | Plug-ability | Distribution |
|---|---|---|---|
| **Base** | `app/Base/{Module}/` | Not pluggable | Main repo |
| **Application Core** | `app/Modules/Core/{Module}/` | Not pluggable | Main repo |
| **Application Modules** | `app/Modules/{Domain}/{Module}/` | **Pluggable** | Nested git today; composer later |
| **Country Packs** | `app/Modules/{Domain}/{Module}{CountryISO}/` | **Pluggable** | Same as application modules |
| **Licensee Extensions** | `extensions/{licensee}/{module}/` | Pluggable (private) | Nested git, private origin |

### 3.1 Why `app/Base/` is not pluggable

`app/Base/` contains framework infrastructure — database, queue, routing, logging, locale, settings storage, the Livewire harness, the menu registry. Removing any of it would break the application itself. It's the equivalent of Laravel's framework packages: assumed present, not optional.

### 3.2 Why `app/Modules/Core/` is not pluggable

Core application modules — Company, User, Employee, Authz, etc. — define the identities and authorization model that every other module depends on. They are the **required application surface**. A deployment without `Core/Company` cannot meaningfully run any other module.

If a module's removal would orphan most other modules' data, it belongs in Core.

### 3.3 What goes in `app/Modules/{Domain}/`

Everything else. Today: `Commerce`, `Operation`, `People`. These domains contain modules that are **optional or substitutable**:

- A licensee may use Attendance without Payroll (external payroll).
- A licensee may use Payroll without Attendance (manual timesheet entry).
- A multinational installs `Payroll`, `PayrollMY`, and `PayrollSG`.
- A company in a domain BLB hasn't shipped yet (e.g. Indonesia) installs `Payroll` plus their own `PayrollID` built against the contract.

---

## 4. Module Taxonomy

Within the pluggable layer, modules fall into three roles. The role is not enforced by location; it's enforced by contract.

### 4.1 Source modules

Author business facts and emit them as events. Country-neutral.

Examples: Attendance, Leave, Claim, and future Sales, Procurement.

Properties:
- Owns its own tables, lifecycle, approvals.
- Dispatches events for facts that downstream consumers may care about (e.g. `AttendanceAllowanceMaterialized`).
- Has no compile-time dependency on consumer modules.
- Works in isolation — Attendance can run on a deployment without Payroll installed.

### 4.2 Consumer / processing modules

Listen to source events, classify, calculate, post.

Examples: Payroll (framework), Finance (future).

Properties:
- Depends on the source module's *event class* (so the event payload type-resolves).
- Does not depend on source-module tables or services.
- Registers listeners via service-container tagging.
- Provides internal contracts (interfaces, payload value objects) that **specialization packages** implement.

### 4.3 Specialization packages

Plug into a consumer module's contracts to provide country, regulatory, or industry specifics.

Examples: `PayrollMY` (Malaysia statutory), `PayrollSG` (Singapore statutory), future `FinanceMFRS` / `FinanceIFRS`.

Properties:
- Depends on its parent consumer module's contracts.
- Ships scheme calculators, rate tables, statutory profiles, forms, country-specific UI.
- Multiple specializations of the same consumer can be installed (multinational deployment).
- Discoverable via service-container tagging.

---

## 5. Communication Contracts

How modules talk to each other across plug-out boundaries.

### 5.1 Events for cross-module data flow

When a source module produces a fact that *might* matter to other modules, it dispatches an event:

```
app/Modules/People/Attendance/Events/AttendanceAllowanceMaterialized.php
```

The event is a value object with the producer's domain shape — employee ID, date, rule ID, amount, the opaque pay-item code string the rule carries. The producer does not know who listens.

Consumers register listeners via their own service provider. If a consumer is uninstalled, no listener — the event has no observer, the source module continues unaffected.

**Event payloads are public API.** Once shipped, fields can be added but not removed or renamed. Breaking changes require a new versioned event class (`AttendanceAllowanceMaterializedV2`).

### 5.2 Interfaces for contributor protocols

Where a consumer module needs to discover capabilities from other installed modules — pay-item declarations, scheme calculators, form generators — the consumer publishes a contract interface and discovers implementers via container tags:

```php
// Defined in Payroll (consumer)
interface PayItemContributor {
    public function payItems(): array;
}

// Implemented in Attendance (source)
class AttendancePayItems implements PayItemContributor { ... }

// Registered in Attendance's service provider
$this->app->tag(AttendancePayItems::class, 'payroll.pay_item_contributor');

// Discovered by Payroll at sync time
foreach ($this->app->tagged('payroll.pay_item_contributor') as $contributor) { ... }
```

The interface lives in the consumer because the consumer defines the protocol. The implementer ships with the producer. Neither imports the other's models.

### 5.3 Intake services as internal write paths

Once a consumer module needs to persist a contribution, it does so through its own internal service (e.g. `PayrollContributionIntake`). The service is **never** called from outside the consumer; only by the consumer's own listeners.

This preserves transactional integrity, idempotency keying, and the ability to evolve the persistence shape without breaking producers.

### 5.4 Module manifest

Each pluggable module ships a manifest declaring:

- Its name, namespace, version.
- Modules it requires (by name and minimum version).
- Modules it optionally integrates with.
- The events it publishes (public-API list).
- The contributor interfaces it implements or publishes.

Format: a `module.json` or PHP config in the module root. The runtime registry reads manifests on boot to verify dependencies and detect missing-but-required modules early.

---

## 6. Country Packs as a Special Class

A country pack is a specialization package targeting a consumer module. The most immediate case is Payroll.

### 6.1 What Core Payroll owns

- Run lifecycle, periods, neutral input table (`PayrollInput`).
- Intake service, pending-contribution dedup, run locking.
- Pay-item taxonomy structure (tables, classifier).
- Result ledger and GL posting interface.
- Orchestrator that walks inputs, classifies, dispatches to scheme calculators, writes results.
- Contracts: `StatutorySchemeCalculator`, `FormGenerator`, `PayItemContributor`, `StatutoryProfileSchema`.

### 6.2 What `PayrollMY` ships

- Scheme calculators (`EpfCalculator`, `SocsoCalculator`, `EisCalculator`, `PcbCalculator`, `HrdCorpCalculator`).
- Rate tables and bands as seeds for `PayrollStatutoryRuleSet`.
- Pay items + classifications scoped to MY.
- Statutory profile schemas (employer registrations, employee tax ID format, zakat election, TP1 reliefs).
- Form generators (EA, E, CP39, Form A, Form 8A, CP21/22/22A).
- Country-specific Livewire surfaces.

### 6.3 What `PayrollSG` would ship

Same shape, different content: CPF, SDL, AIS/IR8A. Built against Core Payroll's contracts, no shared code with PayrollMY required.

### 6.4 Multi-country deployments

A multinational installs `Payroll` plus `PayrollMY` and `PayrollSG`. The orchestrator routes each employee to the calculators registered for their country of employment. Employees with cross-country residency are out of scope for v1.

---

## 7. Physical Structure: How Modules Are Stored and Versioned

### 7.1 Current state

- Main repo (`belimbing`) contains `app/Base/`, `app/Modules/{Core,Commerce,Operation,People}/`, `resources/core/`, `docs/`.
- `extensions/{licensee}/{module}/` is a nested git repo with its own `origin`, ignored by the parent via `.git/info/exclude`. This is the working "sub-git" pattern.
- No composer split has been done.

### 7.2 The decision: extend nested-git first; composer later

Two distribution mechanisms could carry pluggable modules:

**Nested git (the existing sub-git pattern)**

Each pluggable module is its own git repository, checked out into the BLB working tree at its canonical path:

```
belimbing/app/Modules/People/Attendance/   (repo: blb-attendance, origin: github.com/blb/attendance)
belimbing/app/Modules/People/Payroll/      (repo: blb-payroll,    origin: github.com/blb/payroll)
belimbing/app/Modules/People/PayrollMY/    (repo: blb-payroll-my, origin: github.com/blb/payroll-my)
```

The parent repo lists these paths in `.gitignore` (public) or `.git/info/exclude` (per-checkout). Each module is a standalone git repo with its own commits, branches, releases, issues.

**Composer packages**

Each module is a composer package (`belimbing/attendance`), installed via `composer require`, autoloaded via PSR-4. Files live under `vendor/` or are published to module folders via the package's installer.

### 7.3 Why nested-git first

- **Consistency with the existing extensions model.** Licensees already understand nested-git via the extension guide. Extending the same pattern to BLB-owned modules means one mental model.
- **Path-based module discovery already works.** Service providers, menu glob (`app/Modules/*/*/Config/menu.php`), migration discovery, view discovery — all operate on filesystem paths. Composer-based discovery would require lifting those off paths and onto PSR-4 namespace scans. That's real work and not the place to start.
- **Developer ergonomics during stabilization.** Modifying a module's code while developing the framework that consumes it is trivial in a nested-git world: edit, commit in the module, commit a path/version pin in the parent. With composer, you need path repositories or local-path overrides during development.
- **No autoloader gymnastics.** The current `App\Modules\People\Attendance\` namespace resolves via the framework's existing module loader. Composer would require either renaming all namespaces to package-style (`Blb\People\Attendance\`) or maintaining custom autoload rules per module.
- **Versioning still works.** Each nested repo carries tags. The parent records the committed SHA of each nested repo. Reproducible.

### 7.4 Why composer later, not never

When BLB matures past the stabilization phase, composer becomes the right tool for:

- **External contributors.** A community-built `blb-payroll-id` is far easier to install via `composer require blb/payroll-id` than via "clone this nested repo into this path."
- **Statutory updates.** A new tax year for `blb-payroll-my` ships as a composer minor version bump. Licensees update with `composer update blb/payroll-my`.
- **Genuine multi-version coexistence.** If two licensees pin different `blb-payroll-my` versions, composer's lockfile handles it cleanly.
- **Marketplace and discoverability.** Packagist or a private Satis is built for this.

The transition happens module-by-module, when each module has earned stable contracts.

### 7.5 The hybrid in practice

Three categories of code coexist:

1. **Main repo content.** `app/Base/`, `app/Modules/Core/`, `resources/core/`, `docs/`. Single git repo, framework-owned.
2. **BLB-owned pluggable modules.** Nested git repos at `app/Modules/{Domain}/{Module}/`, each with its own public origin (`github.com/BelimbingApp/blb-<module>`). Same authoring team as the main repo but separable for issue tracking, release cadence, and future composer migration.
3. **Licensee-owned extensions.** Nested git repos at `extensions/{licensee}/{module}/`, private origins. The existing model, unchanged.

A licensee's local working tree looks the same regardless of category — directories on disk. The difference is which remote each directory points at.

---

## 8. Module Boot and Discovery

The framework's module loader needs three responsibilities to make plug-out real:

### 8.1 Detection

On boot, scan `app/Base/`, `app/Modules/Core/`, `app/Modules/*/*/`, and `extensions/*/*/` for module roots. A module root is identified by a `ServiceProvider.php` and (eventually) a `module.json` manifest.

A module that is not present on disk is simply not loaded. No fatal error, no warning beyond a debug log line.

### 8.2 Dependency resolution

The manifest declares required and optional dependencies. On boot:

- If a **required** dependency is missing, fail loudly with a clear message: "Attendance requires Core/Company. Install or fix."
- If an **optional** dependency is missing, log it at info level and continue. Event listeners that would have lived in the missing module are simply not registered.

### 8.3 Service-provider order

Modules register their service providers in dependency order. The framework's existing module bootstrapping is path-based today; it will need to honor the manifest's dependency graph.

---

## 9. Migration Path

Moving from the current monolithic checkout to the pluggable shape is staged. Each phase produces a usable system.

### Phase 0 (current)

- All BLB code in one git repo.
- `extensions/{licensee}/` already uses nested git.
- No formal contracts between application modules; cross-module imports happen freely.

### Phase 1 — Stabilize cross-module contracts (in-tree)

Goal: get the seams right while everything is still in one repo. No physical split yet.

- Define event classes for the producer modules currently using direct service calls. Migrate Attendance, Leave, Claim onto events.
- Define contributor interfaces (`PayItemContributor`, `StatutorySchemeCalculator`, etc.).
- Refactor existing Malaysian content in Payroll behind the scheme-calculator and form-generator contracts.
- Stand up the `module.json` manifest format and the boot-time manifest reader.
- Make missing optional dependencies fail soft.

Exit criterion: Attendance, Leave, Claim, and Payroll communicate **only** through events and contracts. No cross-module model imports.

### Phase 2 — First nested-git split

Goal: prove the nested-git mechanics on one BLB-owned module before doing the rest.

- Pick the smallest-blast-radius module first. Likely Claim or Leave.
- Create a public origin (`github.com/BelimbingApp/blb-claim`).
- Move the module's history into the new repo (`git filter-repo` or fresh init with squashed history).
- Add the path to the parent repo's `.gitignore`.
- Document the contributor workflow (parent + nested checkout, pulling each).

Exit criterion: a fresh `git clone` of the framework plus two `git clone`s of the nested module repos produces a working application identical to the pre-split state.

### Phase 3 — Roll out across `app/Modules/{Domain}/`

Goal: split the remaining pluggable modules.

- Attendance, Payroll, future modules each become nested repos.
- Country specialization splits out: `PayrollMY` (and any subsequent country) lives in its own repo.
- Establish release tagging conventions per module.
- Stand up `docs/architecture/{module}-extension-api.md` for each consumer module that has a contributor surface.

Exit criterion: BLB ships as "framework repo + N module repos." Licensees can pick which modules to include.

### Phase 4 — Build a second country pack

Goal: validate the contracts on a real second country.

- Build a minimal `PayrollSG` (even just one scheme — CPF — and one form — IR8A). It does not need to be production-grade.
- Watch for places where Core Payroll's contracts force SG to fight Malaysia-shaped assumptions.
- Fix those seams in Core. Re-test MY.

Exit criterion: someone can build a new country pack without modifying Core Payroll.

### Phase 5 — Open to external contributors

Goal: marketplace and community.

- Composer-ify the modules that have proven stable contracts.
- Publish to Packagist (or Satis).
- Define a contribution guide, a contract-stability policy, and a SemVer commitment.

This phase is at least 12–18 months out and should not influence current decisions.

### Phase 6 — Apply the pattern to Finance

When the People domain has shipped and stabilized, repeat the pattern for Finance: ship MY-context first (MFRS reporting, statutory accounts), design the contracts so other regimes (IFRS, US-GAAP) can specialize.

---

## 10. Developer Workflow

### 10.1 Working in the main repo

Unchanged from today. Clone, branch, commit, push to `origin/main`.

### 10.2 Working on a BLB-owned nested module

Two repos, two pushes:

```
cd belimbing                                    # main repo
cd app/Modules/People/Attendance                # nested module repo
git checkout -b feature/new-rule
... edit ...
git commit
git push                                        # pushes to blb-attendance origin

cd -                                            # back to main repo
git status                                      # nested repo path is ignored — no diff in parent
```

Releases:
- Nested module tags its version (e.g. `v0.4.2`).
- Main repo's setup/install docs note the tested module versions.
- Eventually (Phase 5), composer takes over the version pinning.

### 10.3 Working on a licensee extension

Identical to the current model. See `docs/guides/extensions/private-extension-repositories.md`.

### 10.4 Working across modules during a refactor

When a contract change requires coordinated edits across two modules:

- Create matching feature branches in each affected nested repo.
- Edit, commit, push in each.
- Document the required version combinations in the PR descriptions.
- Merge in dependency order (Core consumer → producers).

This is the same coordination cost the extension model already incurs. Not zero, not bad.

---

## 11. Open Questions

These do not block Phase 1 but should be settled before later phases.

1. **Namespace strategy at composer-ization.** Stay with `App\Modules\People\Attendance\` (Laravel module convention) or move to vendor-style `Blb\People\Attendance\`? Vendor-style is more idiomatic for composer packages; Laravel-style is what the framework loader expects today. Decision affects Phase 5.

2. **Manifest format.** PHP file (familiar but executes on parse) or `module.json` (declarative but adds JSON parsing). Pick early in Phase 1.

3. **Statutory profile schema for country packs.** JSON column on a unified table vs per-country tables. Probably JSON first, evolve to tables if pain emerges. Decision belongs to the Payroll team in Phase 1.

4. **Cross-module migrations.** When `PayrollMY` ships migrations that touch `Payroll`'s tables (adding classification rows), do they live in the country pack or in core? Country pack — it's the country pack's data. But the migration ordering needs to be correct.

5. **Contract governance.** Solo today, harder later. Document the breaking-change policy before opening to external contributors in Phase 5.

6. **Optional-dependency soft-fail UX.** What does the admin UI show when a deployment is missing an optional module? "Payroll is not installed; attendance-emitted allowances are unrouted." This is a small but real UX question.

---

## 12. What This Document Does Not Cover

- **Implementation details of the boot-time manifest reader.** A separate design when Phase 1 starts.
- **Composer package layout, autoloading, asset publishing.** Belongs in a Phase 5 design doc.
- **CI/CD across multiple repos.** Phase 2 surfaces this; address when it does.
- **Versioning policy and release cadence.** Phase 3 surfaces this.
- **The conceptual layer of any specific domain.** That lives in tutorials (`docs/tutorials/{domain}/`) and per-module design docs (`docs/plans/{domain}/`).

---

## 13. Summary

- BLB becomes a framework with one canonical reference (MY) and stable contracts.
- `app/Base/` and `app/Modules/Core/` stay in the main repo; everything in `app/Modules/{Domain}/` becomes pluggable.
- Communication across module boundaries: events for facts, interfaces for capabilities, intake services for internal persistence.
- Country packs are specialization packages plugging into a consumer module's contracts. Reference: `PayrollMY`.
- Physical distribution: extend the existing nested-git pattern from `extensions/` to BLB-owned pluggable modules first. Composer later, per module, when contracts have stabilized.
- Migrate in phases: stabilize contracts in-tree → split one module → split the rest → build a second country → open to external contributors → repeat for Finance.

The model is not novel. It is how mature open-source frameworks scale. The work is in committing to it consistently and resisting the temptation to add shortcuts that fail soft contracts later.
