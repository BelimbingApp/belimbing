# software-module-variation-inventory

**Status:** Proposed — generic Software Inventory and module-variation implementation plan; Payroll remains the worked example, not the architecture center.
**Last Updated:** 2026-08-05
**Sources:**
- `docs/architecture/module-system.md` — canonical vocabulary for Domain, Module, Extension, adapter, extension seam, slot, discovery contracts, and delivery provenance.
- `resources/core/views/livewire/base/foundation/domains.blade.php` + `app/Base/Foundation/Services/DomainInstaller.php` + `app/Base/Foundation/Services/ExtensionInstaller.php` — current **Domains** screen and lifecycle surfaces.
- `resources/core/views/livewire/admin/system/software/deployment/index.blade.php` + `app/Base/Software/Services/SoftwareSourceRepository.php` — current Updates surface and Git-backed source discovery.
- `docs/plans/plugin-manager-ui.md` + `docs/plans/plugin-term-retirement.md` — prior catalog decisions and terminology cleanup.
- `app/Domains/Commerce/Plugins/` — live extension-seam precedent for discovered adapter/data contributions.
- `app/Domains/People/Payroll/` + `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll Country Pack v0 contract and Malaysia as the first adapter-shaped worked example.

**Agents:** claud/opus-4.8, amp/gpt-5.5-oracle

---

## Problem Essence

BLB has precise module-system vocabulary, but the operator surface still risks flattening ownership **Domains**, contained **Modules**, semantically relaxed **Extensions**, runtime **adapter contributions**, host **extension seams**, deployment-selected **slots**, and delivery sources. Payroll exposed the ambiguity because country packs look like installable software, but the real implementation problem is generic: System → Software needs to show how these component types relate without teaching operators the wrong model.

## Desired Outcome

System → Software becomes the trustworthy inventory for pluggable BLB software. An operator can answer, without reading the filesystem: which Domains and Extensions are installed or available, which Modules each contains, which adapter/data contributions they add to host seams, which slot implementation is selected for any slotted Module path, what is updateable, and what capability is missing for a company or workflow. Repository and source information remains delivery provenance in supporting detail.

The product stays honest about action boundaries:
- **Domains** are the primary operator lifecycle object; Core is required, optional Domains are installable, enableable, disableable, and updateable.
- **Modules** are contained ownership boundaries within Core, a Domain, or an Extension.
- **Extensions** are deployment-selected, semantically relaxed mixed bags whose Module boundaries remain explicit.
- **Adapters** are runtime contributions delivered by Domains or Extensions and resolved by host-Module contracts.
- **Slots** are one selected whole-module implementation per deployment, not a UI toggle.

Payroll remains the first concrete proof: Malaysia payroll rules should appear as an add-on/adapter Extension contributing to the Payroll country-pack seam, while a true Payroll slot would be a separate whole-Module implementation selected at deployment time.

## Top-Level Components

| Component | Responsibility |
|-----------|----------------|
| Software Inventory read model | Base read model that presents Domains and Extensions with their contained Modules, dependency health, contributions, selected slots, and source provenance. |
| Catalog classification | Extends the BelimbingApp catalog from a flat repository list into grouped available Domains, Extensions, add-ons/adapters, and slot implementations. |
| Contribution summaries | Read-only reporting contract through which host seams describe discovered runtime contributions for the UI. This is not a universal adapter runtime; Commerce and Payroll keep their own contracts. |
| Slot surface | Read model and UI rules for selected slot implementations and catalog alternatives. Slots are shown as deployment choices with migration warnings, never as enable/disable switches. |
| System → Software IA | Keeps the existing Software group but sharpens page jobs: Inventory shows relationships; Domains owns Domain lifecycle; Updates pulls/builds/migrates/reloads installed sources; GitHub Access owns credentials. |

## Design Decisions

### The page concept is Software Inventory; Domains are the lifecycle object

The visible destination should be **System → Software → Inventory** once the grouped view lands. The route/authz may keep existing identifiers during the first implementation if renaming them would add noise, but the page title and copy should say what the user is doing: inspecting installed and available software. Primary lifecycle rows are optional **Domains**; Extensions appear separately. Source and repository detail supports update and diagnostics, never the product model.

### Installed inventory is grouped by delivery unit, not module manifest

The former inventory screen rendered Module manifests as cards. That is useful data, but it made the page title lie: a Domain can contain several Modules, and an Extension can contribute behavior to another Module without being that Module. The installed tab should group by Domain and Extension first, then show contained Modules, requirements, published/consumed events, contribution summaries, and source/repository/update state inside the row details.

### Adapter visibility is generic; adapter execution remains module-specific

Do not create a universal adapter framework. A good extension seam is owned by the host module because the host knows the contract: Commerce owns marketplace/readiness contributions; Payroll owns country packs. The generic part is only the UI/reporting layer: host seams can publish contribution summaries so Inventory can say “Malaysia payroll rules” or “Ham auto-parts readiness” without knowing how payroll calculation or marketplace readiness works.

### Slots are selected implementations, never casual switches

A slot fills a fixed module path and namespace for the whole deployment. The UI may show the selected implementation and catalog alternatives, but it must not offer an “enable,” “disable,” or “switch” button. Switching a live slot is a deployment/data-migration project. Catalog alternatives should be visibly different from add-on/adapters and carry that warning.

### Catalog facts may come from manifests; installed facts must come from runtime discovery

Available sources cannot execute code, so the catalog may rely on declared metadata such as ownership kind, target Module, contribution summaries, and slot target. Installed contributions should be read from the actual runtime registries where possible so the UI reports what is really discovered, not stale prose in a package manifest.

### Payroll country packs are the adapter example, not the generic model

Payroll should not define the whole architecture. The generic decision is: if variants share an engine and differ by rules/integrations, show them as add-on/adapters; if variants replace the whole module lifecycle/schema/contract, show them as slot implementations. Payroll country packs are adapters because BLB can serve MY and SG companies in one deployment; a slot is one implementation per deployment.

## Public Contract

### Operator vocabulary

| Architecture term | UI language |
|-------------------|-------------|
| Domain | Primary lifecycle and inventory object. Use “Domain” in rows, status, and install/enable/disable/update copy. |
| Module | Contained ownership unit. Useful for dependency/debugging, not a generic lifecycle action target. |
| Extension | Separate inventory/lifecycle object for deployment-selected, semantically relaxed customizations. |
| Source / repository | Delivery provenance only: show in update and diagnostics detail, not as a product architecture or lifecycle term. |
| Adapter | Technical badge/detail under “Contributions.” Primary label should be domain-specific, such as “Malaysia payroll rules” or “Shopee channel.” |
| Extension seam | Technical detail as “Contribution point” or “Host seam.” Do not use it as a top-level page or action label. |
| Slot | “Selected implementation” with a Slot badge and migration warning. Never presented as a toggle. |

### System → Software pages

**Inventory** (the current **Domains** screen; see the Phase 2 reconciliation note):
- Installed tab: grouped by Domain and Extension, with compact table rows or cards and a detail drawer/disclosure for contained Modules, dependencies, events, contributions, selected slot status, source/repository/branch, dirty/unpushed state, and dependency warnings.
- Catalog tab: grouped by intent — Domains, Extensions/add-ons, Slot implementations. Domain entries link to the Domain lifecycle flow; add-ons/adapters expose install guidance; slot implementations show selection/migration warnings and no direct switch.

**Domains:** Domain lifecycle only: install, disable, enable, uninstall. It can show a small summary of contributions within a Domain, but it should link to Inventory for details.

**Updates:** update installed sources: pull, composer install/autoload refresh, asset build, migrations, worker reload. Source/repository labels belong here as delivery provenance; deployment remains explanatory copy.

**GitHub Access:** credentials only.

### Software Inventory read model

The read model should expose these shapes to the UI:
- **InstalledSource:** stable delivery key, root path, repository/branch/commit where known, and update/dirty state. It is attached as provenance to an installed Domain, Extension, or selected slot implementation.
- **InstalledModule:** Module id, path, manifest name/version/description, owning Domain or Extension identity, required/optional Modules, published/consumed events.
- **ContributionSummary:** provider Module, provider Domain or Extension, host Module, seam id, kind (`adapter`, `data`, `readiness`, `panel`, `export`, etc.), label, status, and domain metadata such as country/channel where relevant.
- **SlotSummary:** slot Module id/path, selected source provenance, selected variant label/version, and catalog alternatives when known.

### Catalog metadata

Additive catalog metadata should be supported without breaking existing manifests. The minimum useful facts are ownership kind, human label, intended install path/command, contained or provided Module ids, contribution summaries for unavailable add-ons, required Modules, and slot target for slot implementations. Installed state still wins over catalog declarations.

## Worked Example: Payroll

The desired Inventory story for Payroll is:
- People appears as an optional Domain containing People Modules, including `people/payroll` if the Payroll engine still ships inside the People Domain. Its repository is shown only as delivery provenance.
- The Malaysia payroll source appears as an add-on/adapter Extension, not a replacement for `people/payroll`. Its primary label is “Malaysia payroll rules”; details say it contributes a Payroll country pack for `MY` to the `people/payroll` country-pack seam and requires `people/payroll`.
- A company whose payroll country has no installed pack is shown as a Payroll readiness gap, not as a broken BLB installation. Invalid, duplicate, or incompatible pack configuration remains a system-level error.
- A hypothetical whole Payroll implementation variant appears only under Slot implementations: “Selected implementation for `people/payroll`.” It cannot be installed beside the selected implementation and cannot be switched from the UI without a migration plan.

## Phases

### Phase 1 — Plan rewrite and PR retitle

Goal: PR #151 becomes the generic module-variation/UI plan instead of a Payroll-only options document.

- [x] Rename the plan from `payroll-pluggable-modules` to `software-module-variation-inventory`. {amp/gpt-5.5-oracle}
- [x] Reframe Payroll as a worked example and record the generic IA: Domains, Modules, Extensions, adapter contributions, extension seams, and slots. {amp/gpt-5.5-oracle}
- [x] Include concrete implementation phases for the Software UI and read models. {amp/gpt-5.5-oracle}
- [x] Retitle the GitHub PR to match the generic plan. {amp/gpt-5.5-oracle}

### Phase 2 — IA/copy cleanup with no runtime behavior change

Goal: the existing Software pages use honest labels before the data model changes.

> Reconciliation (2026-08-05): PR #152 had merged the former catalog and Business Domains into one **Modules** screen in June. ADR 0001 later superseded that operator noun: the surface is now **Domains**, uses `domains` route/menu identifiers, and presents Modules within each Domain. Phase 3 builds on that hierarchy instead of reopening the older screen split. {claud/opus-4.8; codex/gpt-5}

- [x] Keep the merged **Modules** screen name/route; defer the Inventory rename to the Phase 3 Domain/Extension grouping decision. {claud/opus-4.8}
- [x] Replace the local `<header>`/`<nav>` markup in the Modules view with `x-ui.page-header` and `x-ui.tabs` (client tabs synced to the server via `wire-action="setTab"`; `#[Url]` dropped in favour of the primitive's `persistence="query"`). {claud/opus-4.8}
- [x] Rewrite the Modules subtitle so it does not conflate Domains, Extensions, and Modules — Modules live inside their owning boundary. (No misleading summary cards survived the #152 merge.) {claud/opus-4.8}
- [x] Updates page title already reads "Updates" (deployment wording kept only as explanatory copy / table caption) — verified, no change needed. {claud/opus-4.8}
- [x] Add inline help (page-header help slot) defining Domain, Module, Extension, Contribution, and Slot in operator terms. {claud/opus-4.8}

Validation: `ModulesTest`, `UpdateMenuTest`, and `ExtensionInstallTest` pass (21 tests) after the markup migration; both tab panels render server-side, so content assertions stay tab-agnostic.

### Phase 3 — Installed Software Inventory read model

Goal: Installed tab groups by Domain and Extension while preserving Module-level detail and source provenance.

- [x] Add a Software Inventory service that combines `SoftwareSourceRepository`, `ModuleManifestReader`, four-root Module discovery, dependency checks, and Domain disabled state — `SoftwareInventoryService`, with a pure `assemble()` so the grouping rules are unit-testable off disk. {codex/gpt-5.6}
- [x] Extend source discovery to recognize nested Module Git roots under `app/Core/*`, `app/Domains/*/*`, and `app/Extensions/*/*` so selected slot implementations retain delivery provenance. {codex/gpt-5.6}
- [x] Map each installed Module to its owning Core, Domain, or Extension and attach nearest source provenance; Base and Core fall back to the platform source when no nested source exists. {codex/gpt-5.6}
- [x] Render installed inventory as Domain/Extension rows/cards with contained Modules and source/repository/branch + dirty/unpushed state. *(Scope: driven by the read model for the platform Base/Core card and any nested Module/slot source, plus a repo·branch·commit line on each Domain/Extension card. The Domain/Extension cards still source their lifecycle/audit from the installers; migrating them fully onto the read model is a follow-up.)* {codex/gpt-5.6}
- [x] Keep dependency warnings at the owning Domain/Extension row level while preserving exact requiring/required Module ids. *(Scope: the read model attaches issues to the owning boundary; the UI still renders the existing global dependency banner, which already preserves the ids. Moving the detail into each row is a follow-up.)* {codex/gpt-5.6}

Validation: `SoftwareInventoryServiceTest` proves a Domain with multiple Modules renders once, an Extension renders separately, Base/Core fall back to platform, a Module-level Git root retains source provenance for a selected slot, and dependency issues attach to the owning boundary (5 tests, 21 assertions). `DomainsTest`/`ExtensionInstallTest`/`UpdateMenuTest` stay green (26 tests total).

### Phase 4 — Contribution summaries for adapter/data visibility

Goal: Inventory shows installed adapter/data contributions without owning their runtime semantics.

- [x] Add a read-only contribution summary contract for host seams — `ContributionSummary` DTO + `InventoryContributionProvider` contract + `InventoryContributionRegistry`, discovered from each module's `Config/inventory.php` by `InventoryContributionDiscoveryService` (tolerant: a broken provider is skipped, not fatal). {claud/opus-4.8}
- [x] Implement a Commerce provider over the existing Commerce plugin seam so marketplace/readiness/catalog/panel/insight contributions appear as Inventory contributions. *(blb-commerce#2)* {claud/opus-4.8}
- [x] Implement a Payroll provider over `PayrollCountryPackRegistry`, showing country packs by country and pack version. *(blb-people#2)* {claud/opus-4.8}
- [x] Display contributions under the providing Domain or Extension and host Module, human labels first and technical seam ids second — the read model attributes each contribution to its Module's owner (falling back to the Domain when a Domain like Commerce ships no per-Module manifests); rendered by the contributions partial on each card. {codex/gpt-5.6}
- [x] Treat missing/broken contribution capability as skip-and-continue display data, not a hard Inventory failure; the owning seam keeps its own validation (Payroll country packs still fail loudly in their registry). {claud/opus-4.8}

Validation: `SoftwareInventoryServiceTest` proves contributions attach to the providing owner and to the Domain when no manifest exists; `InventoryContributionDiscoveryTest` proves `Config/inventory.php` discovery + contract filtering + tolerance; `PayrollInventoryContributionProviderTest` (blb-people) and `CommerceInventoryContributionProviderTest` (blb-commerce) prove each provider summarises its seam. 12 belimbing-side tests; providers green in their repositories.

### Phase 5 — Catalog grouping and manifest metadata

Goal: Available Domains, Extensions, and add-ons are grouped by intent and do not confuse add-ons with slot replacements.

- [ ] Extend catalog entries to classify available entries as Domains, Extensions/add-ons, or slot implementations.
- [ ] Add additive catalog metadata for contribution summaries and slot targets so unavailable sources can be described without executing their code.
- [ ] Group Catalog tab sections as Domains, Extensions/add-ons, and slot implementations.
- [ ] Link Domain catalog entries to the Domain install flow instead of duplicating Domain lifecycle controls in Inventory.
- [ ] Show add-on/adapter install guidance as install/copy instructions for the target path, with required-module warnings when the host module is not installed.
- [ ] Show slot implementations with selected-implementation status and migration warnings; do not add switch/install-alongside controls.

Validation: catalog tests cover one business domain, one adapter add-on, and one slot implementation; slot alternatives do not render as installable alongside an already selected slot.

### Phase 6 — Payroll country packs as the adapter proof

Goal: Payroll validates the generic adapter path without becoming the generic architecture.

- [x] Add Payroll country-pack discovery through a `Config/payroll.php` contribution file, modelled on Commerce discovery but stricter (registration failures throw rather than silently no-op). {claud/opus-4.8 — blb-people#1}
- [x] Move Malaysia registration from direct ServiceProvider wiring to the discovered Payroll seam while Malaysia remains internal. {claud/opus-4.8 — blb-people#1}
- [x] Neutralize the Payroll engine manifest so `people/payroll` describes the country-neutral engine, not Malaysia — composer `name` `blb/payroll-my` → `blb/payroll`, descriptions country-neutral; the *pack* identity (`belimbing/payroll-my`) stays Malaysia-specific. *(Cross-repo: blb-people manifest + belimbing `ModuleManifestReaderTest`; that belimbing assertion is guarded by People presence, so CI without People is unaffected — merge the blb-people change first.)* {claud/opus-4.8}
- [x] Country-pack classification/calculation already flows through the `PayrollCountryPack` contract facets — `PayrollRunCalculator` resolves the run's country, routes through `forCountry(...)->calculator()->calculate(...)` and the pay-item classifier facet, and emits a `no_country_pack` status when absent. Verified, no change. {claud/opus-4.8}
- [x] Surface “missing pack for company country” as Payroll readiness data and block final approval/close/export — `PayrollRunCountryPackGuard` flags a run whose calendar country has no installed pack (the calculator records `no_country_pack`) as a workbench readiness warning, and refuses `approve`/`close` (which also blocks the export-after-close flow) with an actionable error naming the country. *(blb-people#5)* {claud/opus-4.8}
- [x] Spike proving MY and SG resolve different packs in one deployment (registry-level, reusing the contract-test pack double; a discovery-based variant with a named fake pack is a later refinement). {claud/opus-4.8 — blb-people#1}

Validation: targeted Payroll tests prove discovered pack registration, duplicate/incompatible pack failure, per-company pack resolution, and missing-pack readiness behavior.

### Phase 7 — Slot guardrails and documentation alignment

Goal: the architecture doc and UI teach the same slot semantics.

- [ ] Update `docs/architecture/module-system.md` so Payroll is not the canonical slot example for country variation; make Payroll the adapter worked example and make slot examples generic or explicitly hypothetical until a real slot arrives.
- [ ] Document that slot sources fill one Module path per deployment and switching is a migration project.
- [ ] Add Inventory UI copy/tests that prevent slot alternatives from rendering as toggles.
- [ ] Cross-link the module-system doc to Software Inventory as the operator surface for inspecting installed Domains, Extensions, Modules, contributions, and selected slots once implemented.

Validation: docs review plus feature tests for slot-copy guardrails in the Catalog tab.
