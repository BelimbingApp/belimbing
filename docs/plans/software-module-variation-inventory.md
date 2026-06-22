# software-module-variation-inventory

**Status:** Proposed — generic Software Inventory and module-variation implementation plan; Payroll remains the worked example, not the architecture center.
**Last Updated:** 2026-06-22
**Sources:**
- `docs/architecture/module-system.md` — canonical vocabulary for Module, Distribution Bundle/Bundle, adapter, extension seam, slot, discovery contracts, and the current adapter/slot tension.
- `resources/core/views/livewire/base/foundation/modules.blade.php` + `app/Base/Foundation/Livewire/Modules.php` + `app/Base/Foundation/Services/DomainInstaller.php` + `app/Base/Foundation/Services/ExtensionInstaller.php` — current **Modules** screen. PR #152 merged the former Bundles inventory/catalog and Business Domains lifecycle screens into this one; PR #153 added private-extension install.
- `resources/core/views/livewire/admin/system/software/deployment/index.blade.php` + `app/Base/Software/Services/DistributionBundleRepository.php` — current Updates surface and Git-backed Distribution Bundle discovery.
- `docs/plans/plugin-manager-ui.md` + `docs/plans/plugin-term-retirement.md` — prior Bundles/catalog decisions and terminology cleanup.
- `app/Modules/Commerce/Plugins/` — live extension-seam precedent for discovered adapter/data contributions.
- `app/Modules/People/Payroll/` + `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll Country Pack v0 contract and Malaysia as the first adapter-shaped worked example.

**Agents:** claud/opus-4.8, amp/gpt-5.5-oracle

---

## Problem Essence

BLB has precise module-system vocabulary, but the operator surface still risks flattening different things into one list: delivery **Bundles**, ownership **Modules**, runtime **adapter contributions**, host **extension seams**, and deployment-selected **slots**. Payroll exposed the ambiguity because country packs look like installable software, but the real implementation problem is generic: System → Software needs to show how these component types relate without teaching operators the wrong model.

## Desired Outcome

System → Software becomes the trustworthy inventory for pluggable BLB software. An operator can answer, without reading the filesystem: which Bundles are installed or available, which Modules each Bundle contains, which adapter/data contributions each Bundle adds to host seams, which slot implementation is selected for any slotted module path, what is updateable, and what capability is missing for a company or workflow.

The product stays honest about action boundaries:
- **Bundles** are what operators install, uninstall, and update.
- **Modules** are ownership boundaries inside those Bundles.
- **Adapters** are runtime contributions delivered by Bundles and resolved by host-module contracts.
- **Slots** are one selected whole-module implementation per deployment, not a UI toggle.

Payroll remains the first concrete proof: Malaysia payroll rules should appear as an add-on/adapter Bundle contributing to the Payroll country-pack seam, while a true Payroll slot would be a separate whole-module implementation selected at deployment time.

## Top-Level Components

| Component | Responsibility |
|-----------|----------------|
| Software Inventory read model | Base read model that groups installed module manifests under actual Distribution Bundle roots, attaches dependency health/update state, and exposes contained modules, contributions, and selected slots as relationships. |
| Bundle catalog classification | Extends the BelimbingApp catalog from a flat repo list into grouped available Bundles: business domains, add-ons/adapters, and slot implementations. |
| Contribution summaries | Read-only reporting contract through which host seams describe discovered runtime contributions for the UI. This is not a universal adapter runtime; Commerce and Payroll keep their own contracts. |
| Slot surface | Read model and UI rules for selected slot implementations and catalog alternatives. Slots are shown as deployment choices with migration warnings, never as enable/disable switches. |
| System → Software IA | Keeps the existing Software group but sharpens page jobs: Inventory shows relationships; Business Domains owns domain lifecycle; Updates pulls/builds/migrates/reloads existing Bundles; GitHub Access owns credentials. |

## Design Decisions

### The page concept is Software Inventory; the action object remains Bundle

The visible destination should be **System → Software → Inventory** once the grouped view lands. The route/authz may keep `bundles` during the first implementation if renaming identifiers would add noise, but the page title and copy should say what the user is doing: inspecting installed and available software. Inside that page, the primary rows are still **Bundles**, because Bundles are the install/update unit defined by the architecture spec.

### Installed inventory is grouped by delivery unit, not module manifest

The current Bundles screen renders module manifests as cards. That is useful data, but it makes the page title lie: one domain Bundle can contain several Modules, and one extension Bundle can contribute behavior to another Module without being that Module. The installed tab should group by Distribution Bundle root first, then show contained Modules, requirements, published/consumed events, contribution summaries, delivery path, and update/dirty state inside the row details.

### Adapter visibility is generic; adapter execution remains module-specific

Do not create a universal adapter framework. A good extension seam is owned by the host module because the host knows the contract: Commerce owns marketplace/readiness contributions; Payroll owns country packs. The generic part is only the UI/reporting layer: host seams can publish contribution summaries so Inventory can say “Malaysia payroll rules” or “Ham auto-parts readiness” without knowing how payroll calculation or marketplace readiness works.

### Slots are selected implementations, never casual switches

A slot fills a fixed module path and namespace for the whole deployment. The UI may show the selected implementation and catalog alternatives, but it must not offer an “enable,” “disable,” or “switch” button. Switching a live slot is a deployment/data-migration project. Catalog alternatives should be visibly different from add-on/adapters and carry that warning.

### Catalog facts may come from manifests; installed facts must come from runtime discovery

Available Bundles cannot execute code, so the catalog may rely on declared metadata such as bundle kind, target module, contribution summaries, and slot target. Installed contributions should be read from the actual runtime registries where possible so the UI reports what is really discovered, not stale prose in a package manifest.

### Payroll country packs are the adapter example, not the generic model

Payroll should not define the whole architecture. The generic decision is: if variants share an engine and differ by rules/integrations, show them as add-on/adapters; if variants replace the whole module lifecycle/schema/contract, show them as slot implementations. Payroll country packs are adapters because BLB can serve MY and SG companies in one deployment; a slot is one implementation per deployment.

## Public Contract

### Operator vocabulary

| Architecture term | UI language |
|-------------------|-------------|
| Distribution Bundle / Bundle | Primary inventory/catalog/update object. Use “Bundle” in rows, status, update copy, and install commands. |
| Module | Secondary detail under a Bundle: “Contained modules.” Useful for dependency/debugging, not the main action target. |
| Adapter | Technical badge/detail under “Contributions.” Primary label should be domain-specific, such as “Malaysia payroll rules” or “Shopee channel.” |
| Extension seam | Technical detail as “Contribution point” or “Host seam.” Do not use it as a top-level page or action label. |
| Slot | “Selected implementation” with a Slot badge and migration warning. Never presented as a toggle. |

### System → Software pages

**Inventory** (the current **Modules** screen — renamed to Inventory only if/when Bundle-grouping lands; see the Phase 2 reconciliation note):
- Installed tab: grouped by Bundle, with compact table rows or cards and a detail drawer/disclosure for contained modules, dependencies, events, contributions, selected slot status, path/repo/branch, dirty/unpushed state, and dependency warnings.
- Catalog tab: grouped by intent — Business domains, Add-ons/adapters, Slot implementations. Business-domain entries link to the Business Domains lifecycle flow; add-ons/adapters expose install guidance; slot implementations show selection/migration warnings and no direct switch.

**Business Domains:** domain lifecycle only: install, disable, enable, uninstall. It can show a small summary of contributions within a domain, but it should link to Inventory for details.

**Updates:** update existing Distribution Bundles: pull, composer install/autoload refresh, asset build, migrations, worker reload. Rename the visible title from Deployment to Updates to match the menu; deployment remains explanatory copy.

**GitHub Access:** credentials only.

### Software Inventory read model

The read model should expose these shapes to the UI:
- **InstalledBundle:** stable key, label, kind (`platform`, `business-domain`, `add-on`, `extension`, `slot-implementation`), root path, repository/branch/commit where known, update/dirty state, lifecycle state where relevant, contained Modules, dependency health, contributions, and selected slots.
- **InstalledModule:** module id, path, manifest name/version/description, owning Bundle key, required/optional modules, published/consumed events.
- **ContributionSummary:** provider module, provider Bundle, host module, seam id, kind (`adapter`, `data`, `readiness`, `panel`, `export`, etc.), label, status, and domain metadata such as country/channel where relevant.
- **SlotSummary:** slot module id/path, selected Bundle, selected variant label/version, and catalog alternatives when known.

### Catalog metadata

Additive catalog metadata should be supported without breaking existing manifests. The minimum useful facts are bundle kind, human label, intended install path/command, contained or provided module ids, contribution summaries for unavailable add-ons, required modules, and slot target for slot implementations. Installed state still wins over catalog declarations.

## Worked Example: Payroll

The desired Inventory story for Payroll is:
- `blb-people` appears as a Business Domain Bundle containing People modules, including `people/payroll` if the Payroll engine still ships inside the People domain.
- `blb-payroll-my` appears as an Add-on/adapter Bundle, not a replacement for `people/payroll`. Its primary label is “Malaysia payroll rules”; details say it contributes a Payroll country pack for `MY` to the `people/payroll` country-pack seam and requires `people/payroll`.
- A company whose payroll country has no installed pack is shown as a Payroll readiness gap, not as a broken BLB installation. Invalid, duplicate, or incompatible pack configuration remains a system-level error.
- A hypothetical whole Payroll implementation variant appears only under Slot implementations: “Selected implementation for `people/payroll`.” It cannot be installed beside the selected implementation and cannot be switched from the UI without a migration plan.

## Phases

### Phase 1 — Plan rewrite and PR retitle

Goal: PR #151 becomes the generic module-variation/UI plan instead of a Payroll-only options document.

- [x] Rename the plan from `payroll-pluggable-modules` to `software-module-variation-inventory`. {amp/gpt-5.5-oracle}
- [x] Reframe Payroll as a worked example and record the generic IA: Bundles, Modules, adapter contributions, extension seams, and slots. {amp/gpt-5.5-oracle}
- [x] Include concrete implementation phases for the Software UI and read models. {amp/gpt-5.5-oracle}
- [x] Retitle the GitHub PR to match the generic plan. {amp/gpt-5.5-oracle}

### Phase 2 — IA/copy cleanup with no runtime behavior change

Goal: the existing Software pages use honest labels before the data model changes.

> Reconciliation (2026-06-22): PR #152 had already merged Bundles + Business Domains into one **Modules** screen, so this phase was adapted from "rename the Bundles page" to "modernize the Modules page." Decision: keep the **Modules** name and its `modules` route/menu identifiers; the Bundle-centric **Inventory** rename is deferred to the Phase 3 Bundle-grouping decision rather than re-opening #152. {claud/opus-4.8}

- [x] Keep the merged **Modules** screen name/route; defer the Inventory rename to the Phase 3 Bundle-grouping decision. {claud/opus-4.8}
- [x] Replace the local `<header>`/`<nav>` markup in the Modules view with `x-ui.page-header` and `x-ui.tabs` (client tabs synced to the server via `wire-action="setTab"`; `#[Url]` dropped in favour of the primitive's `persistence="query"`). {claud/opus-4.8}
- [x] Rewrite the Modules subtitle so it does not conflate bundles and modules — each row is a bundle (business domain or extension); modules live inside. (No misleading "installed modules / installed bundles" summary cards survived the #152 merge.) {claud/opus-4.8}
- [x] Updates page title already reads "Updates" (deployment wording kept only as explanatory copy / table caption) — verified, no change needed. {claud/opus-4.8}
- [x] Add inline help (page-header help slot) defining Bundle, Module, Contribution, and Slot in operator terms. {claud/opus-4.8}

Validation: `ModulesTest`, `UpdateMenuTest`, and `ExtensionInstallTest` pass (21 tests) after the markup migration; both tab panels render server-side, so content assertions stay tab-agnostic.

### Phase 3 — Installed Software Inventory read model

Goal: Installed tab groups by Distribution Bundle and preserves module-level detail.

- [x] Add a Software Inventory service that combines `DistributionBundleRepository`, `ModuleManifestReader`, module-root discovery, dependency checks, and domain disabled state — `SoftwareInventoryService`, with a pure `assemble()` so the grouping rules are unit-testable off disk. {claud/opus-4.8}
- [x] Extend Distribution Bundle discovery to recognize module-level git roots under `app/Modules/*/*` so future slot implementations are visible alongside domain-level roots. {claud/opus-4.8}
- [x] Map each installed Module to its nearest Distribution Bundle root (longest containing bundle path); fall back to the platform Bundle for Base/Core and non-nested development files. {claud/opus-4.8}
- [x] Render installed inventory as Bundle rows/cards with contained Modules and path/repo/branch + dirty/unpushed state. *(Scope: driven by the read model for the platform "Base + Core" card and any nested module/slot bundles, plus a repo·branch·commit line on each domain/extension card. The domain/extension cards still source their lifecycle/audit from the installers; migrating them fully onto the read model is a follow-up.)* {claud/opus-4.8}
- [x] Keep dependency warnings at the Bundle row level while preserving exact requiring/required Module ids. *(Scope: the read model attaches issues to the owning Bundle; the UI still renders the existing global dependency banner, which already preserves the ids. Moving the detail into each row is a follow-up.)* {claud/opus-4.8}

Validation: `SoftwareInventoryServiceTest` proves a domain Bundle with multiple Modules renders once, an extension Bundle renders separately, Base/Core fall back to platform, a module-level git root is recognised as its own slot bundle, and dependency issues attach to the owning Bundle (5 tests, 21 assertions). `ModulesTest`/`ExtensionInstallTest`/`UpdateMenuTest` stay green (26 tests total).

### Phase 4 — Contribution summaries for adapter/data visibility

Goal: Inventory shows installed adapter/data contributions without owning their runtime semantics.

- [x] Add a read-only contribution summary contract for host seams — `ContributionSummary` DTO + `InventoryContributionProvider` contract + `InventoryContributionRegistry`, discovered from each module's `Config/inventory.php` by `InventoryContributionDiscoveryService` (tolerant: a broken provider is skipped, not fatal). {claud/opus-4.8}
- [x] Implement a Commerce provider over the existing Commerce plugin seam so marketplace/readiness/catalog/panel/insight contributions appear as Inventory contributions. *(blb-commerce#2)* {claud/opus-4.8}
- [x] Implement a Payroll provider over `PayrollCountryPackRegistry`, showing country packs by country and pack version. *(blb-people#2)* {claud/opus-4.8}
- [x] Display contributions under the providing Bundle and host Module, human labels first and technical seam ids second — the read model attributes each contribution to its module's Bundle (falling back to the domain Bundle when a domain like Commerce ships no per-module manifests); rendered by the `bundle-contributions` partial on each card. {claud/opus-4.8}
- [x] Treat missing/broken contribution capability as skip-and-continue display data, not a hard Inventory failure; the owning seam keeps its own validation (Payroll country packs still fail loudly in their registry). {claud/opus-4.8}

Validation: `SoftwareInventoryServiceTest` proves contributions attach to the providing bundle and to the domain bundle when no manifest exists; `InventoryContributionDiscoveryTest` proves `Config/inventory.php` discovery + contract filtering + tolerance; `PayrollInventoryContributionProviderTest` (blb-people) and `CommerceInventoryContributionProviderTest` (blb-commerce) prove each provider summarises its seam. 12 belimbing-side tests; providers green in their repos.

### Phase 5 — Catalog grouping and manifest metadata

Goal: Available Bundles are grouped by intent and do not confuse add-ons with slot replacements.

- [ ] Extend catalog entries to classify available Bundles as Business domains, Add-ons/adapters, or Slot implementations.
- [ ] Add additive catalog metadata for contribution summaries and slot targets so unavailable Bundles can be described without executing their code.
- [ ] Group Catalog tab sections as Business domains, Add-ons/adapters, and Slot implementations.
- [ ] Link Business-domain catalog entries to the Business Domains install flow instead of duplicating domain lifecycle controls in Inventory.
- [ ] Show add-on/adapter install guidance as install/copy instructions for the target path, with required-module warnings when the host module is not installed.
- [ ] Show slot implementations with selected-implementation status and migration warnings; do not add switch/install-alongside controls.

Validation: catalog tests cover one business domain, one adapter add-on, and one slot implementation; slot alternatives do not render as installable alongside an already selected slot.

### Phase 6 — Payroll country packs as the adapter proof

Goal: Payroll validates the generic adapter path without becoming the generic architecture.

- [x] Add Payroll country-pack discovery through a `Config/payroll.php` contribution file, modelled on Commerce discovery but stricter (registration failures throw rather than silently no-op). {claud/opus-4.8 — blb-people#1}
- [x] Move Malaysia registration from direct ServiceProvider wiring to the discovered Payroll seam while Malaysia remains internal. {claud/opus-4.8 — blb-people#1}
- [ ] Neutralize the Payroll engine manifest so `people/payroll` describes the country-neutral engine, not Malaysia. *(cross-repo: belimbing `ModuleManifestReaderTest` and blb-people contract test assert `blb/payroll-my` — coordinate both.)*
- [ ] Ensure country-pack classification/calculation flows through the `PayrollCountryPack` contract facets.
- [ ] Surface “missing pack for company country” as Payroll readiness data; block final approval/close/export where statutory payroll would otherwise look complete.
- [x] Spike proving MY and SG resolve different packs in one deployment (registry-level, reusing the contract-test pack double; a discovery-based variant with a named fake pack is a later refinement). {claud/opus-4.8 — blb-people#1}

Validation: targeted Payroll tests prove discovered pack registration, duplicate/incompatible pack failure, per-company pack resolution, and missing-pack readiness behavior.

### Phase 7 — Slot guardrails and documentation alignment

Goal: the architecture doc and UI teach the same slot semantics.

- [ ] Update `docs/architecture/module-system.md` so Payroll is not the canonical slot example for country variation; make Payroll the adapter worked example and make slot examples generic or explicitly hypothetical until a real slot arrives.
- [ ] Document that slot Bundles fill one module path per deployment and switching is a migration project.
- [ ] Add Inventory UI copy/tests that prevent slot alternatives from rendering as toggles.
- [ ] Cross-link the module-system doc to Software Inventory as the operator surface for inspecting installed Bundles, Modules, contributions, and selected slots once implemented.

Validation: docs review plus feature tests for slot-copy guardrails in the Catalog tab.
