# ham/05-commerce-ux-extension-bridge

**Status:** In Progress — Phase 1 verified complete; Phase 2 plugin seam is proven; first Phase 3/4 item cockpit readiness slices are implemented.
**Last Updated:** 2026-05-22
**Sources:**
- User request, 2026-05-22 — ship `extensions/ham` but close merchant UX gaps first, especially sub-category UI and Commerce plugging seams.
- Parent plan: `docs/plans/ham/01-ebay-car-parts-operations.md`.
- Companion research: `docs/plans/ham/02-ebay-sell-api-research.md`, `docs/plans/ham/03-lara-aging-stock-advisor.md`, `docs/plans/ham/04-ebay-motors-alignment.md`.
- Current Catalog implementation: `app/Modules/Commerce/Catalog/Models/Category.php`, `app/Modules/Commerce/Catalog/Livewire/Index.php`, `app/Modules/Commerce/Catalog/Livewire/Concerns/InteractsWithCatalogWorkbenchData.php`.
- Existing module packaging pattern: `app/Modules/People/Attendance/composer.json`, `app/Modules/People/Attendance/ServiceProvider.php`, `app/Modules/People/Attendance/Config/menu.php`, `app/Modules/People/Attendance/Config/authz.php`.
- Oracle product/architecture review, 2026-05-22.
**Agents:** Amp/claude-sonnet-4.5, Oracle/gpt-5.4

## Problem Essence

Ham can only feel shippable if the operator workflow cashes out the Commerce data model: a merchant needs to classify an item, see what is missing, fix it, and publish or improve the listing without understanding BLB internals. The current architecture has many of the right records, but key bridges are missing: Catalog category hierarchy exists in storage but not in the UI, the item page is not yet the obvious listing cockpit, and Commerce is not yet a pluggable host that lets `extensions/ham` contribute vertical behavior through explicit seams comparable to the People/Attendance module pattern.

## Desired Outcome

Belimbing should let Ham open a used auto part and immediately understand where it belongs, whether it is ready for eBay Motors, what exact action fixes the next gap, and which Ham-specific defaults came from the removable extension. The same work should turn Commerce into a pluggable host for future verticals by keeping generic hierarchy, readiness, marketplace, settings, workbench, and extension seams in core while leaving auto-parts vocabulary, eBay Motors defaults, Ham prompts, and Ham-specific views in `extensions/ham`.

## Top-Level Components

1. **Catalog hierarchy UX** — core Commerce Catalog already stores `parent_id`; the UI should let operators create, edit, browse, search, and select nested categories safely.
2. **Item listing cockpit** — the item detail page becomes the merchant's primary workbench for capture, readiness, and channel actions: photos, catalog assignment, attributes, fitment, descriptions, marketplace draft, and next blockers.
3. **Pluggable Commerce host** — a thin, explicit registration surface lets first-party modules and nested extensions plug in presets, routes, menus, settings, readiness contributors, workbench panels, insight pages, and marketplace providers without a broad plugin marketplace redesign.
4. **Readiness contributors** — core computes channel-neutral and eBay readiness from durable drafts and item data; extensions may add vertical guidance and defaults without hard-coding Ham behavior into core.
5. **Ham auto-parts extension** — owns auto-parts seeds, category mappings, Motors defaults, prompt overrides, and Ham-specific operator guidance.

## Design Decisions

- **Ship the merchant workflow, not another architecture pass.** The current Commerce split is directionally right. The immediate gap is operator cohesion: Ham must see a clear path from item capture to eBay readiness and listing maintenance.
- **Start with hierarchy as organization and leaf selection.** Catalog categories should support parent/child browsing, breadcrumbs, and leaf-aware selection now. Ancestor-based attribute inheritance is deliberately deferred until duplication proves painful; adding it too early would complicate validation and item attribute resolution before the merchant UX is even usable.
- **Make the item page the default cockpit.** Merchants do not want to navigate separate technical modules to discover why a part cannot be listed. The item page should surface missing category, missing identifiers, missing fitment, weak photos, stale metadata, missing policy/location defaults, eBay connection problems, and publish blockers in one checklist with links to fix each gap.
- **Make Commerce pluggable before making it a marketplace of plugins.** People/Attendance demonstrates a practical pattern: composer metadata, a service provider, routes, menu config, and authz config. Commerce should adopt that style plus a few domain registries instead of waiting for a full plugin marketplace.
- **Define a small plugin contract around real Commerce seams.** The first pluggable surface needs catalog presets/seeds, marketplace/channel providers, readiness contributors, item/workbench panels, insight pages, and settings/menu/authz/routes. Service providers follow the existing module discovery pattern where possible; the Commerce-specific registries carry business behavior.
- **One mechanism for first-party modules and nested extensions.** Ham should not need a special-case loader. A first-party Commerce module and `extensions/ham` should contribute Commerce behavior through the same registration contracts, with dependency metadata deciding whether a contribution is available.
- **Keep Ham removable.** No Ham-specific columns, labels, category IDs, Motors assumptions, or prompt text should be required for generic Commerce to work. Removing `extensions/ham` should leave a usable generic Commerce installation.
- **Treat eBay Motors readiness as a composition of core plus extension.** Core owns durable listing drafts, metadata cache, aspect mapping mechanics, policy/location checks, publish-safe media checks, and eBay operation state. Ham supplies Motors marketplace identifiers, category mappings, auto-parts vocabulary, and operator copy.
- **Readiness copy should be action-oriented.** A blocker such as “missing required aspect” should name the merchant field and fix location, not expose raw eBay or internal DTO terminology as the primary experience.

## Public Contract

- Commerce Catalog categories are hierarchical in the operator UI: an operator can assign a parent category, see category paths, avoid invalid parent cycles, and choose leaf categories for templates/items where a leaf is required.
- Category hierarchy v1 does not imply automatic inherited attributes. Attribute applicability remains the existing explicit global/category/template mechanism until a later plan changes it.
- Commerce exposes a small plugin registration surface for first-party modules and nested extensions to contribute catalog presets, marketplace/channel providers, readiness contributors, item/workbench panels, insight pages, and settings/menu/authz/routes.
- Commerce discovers first-party and extension contributions through one mechanism. Ham-specific behavior must not require hard-coded class references, route registration, menu entries, or config paths in core Commerce.
- A readiness contributor returns actionable gaps and successes for a durable subject such as an item or listing draft. Each gap includes a stable code, severity, human label, explanation, and an optional route/action target that fixes it.
- The item detail surface can render core and extension readiness together without knowing Ham-specific classes at compile time beyond the registry contract.
- Ham's extension contributes auto-parts presets and eBay Motors guidance through the harness; it does not patch core Commerce views directly unless a registry slot is insufficient and the insufficiency is then promoted into a core seam.
- The first shipping definition of done is merchant-visible: Ham can classify a part in a nested catalog, see readiness on the item, understand each missing step, and proceed toward eBay work without opening unrelated admin screens.

## Phases

## Implementation Checkpoint — 2026-05-22

Phase 1 is verified complete in the current codebase. Category hierarchy is now merchant-visible through route-backed Catalog submenu pages, a Categories tree/inspector workspace, path-aware search and labels, folder/leaf badges, explicit parent editing, cycle prevention, and category path display in templates, attributes, and inventory selectors. A full searchable combobox can still be a future usability enhancement, but the Phase 1 merchant requirement is met without adding inherited attribute semantics.

Phase 2 is partially complete. Core Commerce has a contribution registry/discovery service for catalog presets, marketplace providers, readiness contributors, workbench panels, and insight pages. Ham now contributes through `extensions/ham/auto-parts/Config/commerce.php` with an auto-parts catalog preset, readiness contributor, item workbench panel metadata, and insight page metadata. Existing extension routes/menu/settings/authz still use BLB's conventional extension discovery rather than a Commerce-only loader, which is acceptable for now but should be revisited if Commerce needs to render those contributions from the registry itself.

Phase 3 has its first shippable cockpit slice. The readiness contributor contract now evaluates inventory items, the Commerce registry normalizes item readiness panels, and the item detail page renders registered core and extension checklist panels with severity and fix anchors. Core Inventory contributes an item basics checklist for catalog fit, price, quantity, photos, and listing copy. Ham contributes auto-parts guidance for identifiers, fitment/universal fit, and condition evidence through the same registry surface.

### Phase 1 — Core Catalog hierarchy UX

Goal: make the existing `parent_id` model usable by merchants without adding inherited attribute semantics.

- [x] Add parent category selection to category create/edit flows, scoped to the current company. {Amp/claude-sonnet-4.5}
- [x] Prevent category cycles and self-parent assignment during create/edit. {Amp/claude-sonnet-4.5}
- [x] Render category rows with path or indentation so sub-categories are visible in the Catalog workbench. {Amp/claude-sonnet-4.5}
- [x] Add category breadcrumbs/path labels for template and attribute rows that reference a category. {Amp/claude-sonnet-4.5}
- [x] Split Catalog sections into route-backed submenu entries for Categories, Templates, and Attributes so each setup job is directly navigable. {Amp/claude-sonnet-4.5}
- [x] Make item/template category selection path-aware. Current implementation uses path labels in selectors and tree search; a richer combobox remains an optional usability enhancement, not a Phase 1 blocker. {Amp/claude-sonnet-4.5}
- [x] Add a leaf-category affordance where needed while still allowing parent categories to exist as organizational folders. The category inspector now labels selected categories as Folder or Leaf. {Amp/claude-sonnet-4.5}
- [x] Add focused tests for parent assignment, cycle prevention, path rendering data, and selector scoping. {Amp/claude-sonnet-4.5}

### Phase 2 — Make Commerce pluggable

Goal: make Commerce a pluggable host so `extensions/ham` and future verticals contribute behavior through explicit seams rather than bespoke core edits.

- [x] Document the minimum Commerce plugin shape using the People/Attendance module pattern as the baseline: metadata, service provider, routes, menu, authz, settings, and Commerce contribution declarations. {Amp/claude-sonnet-4.5}
- [x] Add or formalize discovery for Commerce contributions from both first-party modules and nested extensions, with no Ham-specific loader path. {Amp/claude-sonnet-4.5}
- [x] Add or formalize a catalog preset/seeder registry for extension-provided categories, templates, attributes, mappings, and default setup actions. {Amp/claude-sonnet-4.5}
- [x] Add or formalize a marketplace/channel provider registry that accepts providers from nested extensions through the same path as first-party providers. {Amp/claude-sonnet-4.5}
- [x] Add or formalize a readiness contributor registry for Commerce item/listing surfaces, including stable gap codes, severity, copy, and optional fix targets. {Amp/claude-sonnet-4.5}
- [x] Add or formalize item/workbench panel registration so extensions can add focused surfaces to the item cockpit without patching core views. {Amp/claude-sonnet-4.5}
- [x] Add or formalize a workbench/insight page registry for extension-provided merchant pages and menu placement. {Amp/claude-sonnet-4.5}
- [ ] Ensure extension-owned settings, menu entries, routes, and authz capabilities are discovered and removed cleanly with the extension.
- [x] Add tests proving a nested extension can contribute at least one catalog preset, readiness contributor, workbench panel or page, and marketplace-related registration without core hard-coding. {Amp/claude-sonnet-4.5}
- [x] Move Ham-specific Commerce contributions behind one of these seams or explicitly record why a new seam is needed. Ham now declares catalog preset, readiness contributor, workbench panel metadata, and insight page metadata through `Config/commerce.php`; routes/menu/settings remain on BLB extension conventions for now. {Amp/claude-sonnet-4.5}

### Phase 3 — Item listing cockpit

Goal: make the item detail page the primary operator surface for classification, readiness, and next actions.

- [x] Add a compact readiness panel to item detail that can render core readiness contributors. {Amp/claude-sonnet-4.5}
- [x] Surface catalog assignment, attributes, photos, description, fitment/universal-fit status, price/quantity/status, policy/location defaults, eBay connection state, and metadata freshness as checklist items where available. Core checklist, Ham checklist, and existing eBay readiness together cover this first cockpit slice. {Amp/claude-sonnet-4.5}
- [x] Ensure each readiness gap links to the field, modal, settings page, or marketplace page that fixes it. Registered cockpit checklist items now carry action anchors into item facts, catalog fit, fitment, attributes, photos, and descriptions; eBay readiness keeps settings links. {Amp/claude-sonnet-4.5}
- [x] Show a clear difference between blockers, warnings, and suggestions so Ham can act quickly. {Amp/claude-sonnet-4.5}
- [ ] Keep existing specialized pages reachable, but make the item page the place where the merchant learns what to do next.
- [x] Add Livewire tests for rendering readiness states and invoking fix links/actions where feasible. {Amp/claude-sonnet-4.5}

### Phase 4 — Ham extension wiring through the new seams

Goal: prove the harness by making Ham's current vertical behavior feel native without leaking it into core.

- [x] Register Ham auto-parts catalog presets through the catalog preset path. {Amp/claude-sonnet-4.5}
- [ ] Register Ham's eBay Motors category mappings and marketplace identifiers through extension config consumed by generic eBay services.
- [x] Register Ham-specific readiness guidance for auto-parts identifiers, fitment/universal-fit, and condition evidence. {Amp/claude-sonnet-4.5}
- [ ] Extend Ham-specific readiness guidance to Motors category mapping and listing quality copy once the mapping config seam is completed.
- [x] Register Ham-specific insight/workbench page metadata through the workbench/insight registry instead of relying only on hard-coded navigation. {Amp/claude-sonnet-4.5}
- [ ] Confirm uninstall/removal of the Ham extension leaves generic Commerce screens usable with no broken menu entries or missing class references.
- [x] Add extension-level tests proving Ham registration contributes expected presets/readiness without changing core behavior for a generic merchant. {Amp/claude-sonnet-4.5}

### Phase 5 — Merchant workflow validation

Goal: define success by Ham's actual operator path rather than by backend completeness.

- [ ] Walk through a new used-part item from capture to category/template assignment using nested categories.
- [ ] Confirm the item cockpit shows missing identifiers, fitment/universal-fit state, photo readiness, eBay mapping, and policy/location setup in operator language.
- [ ] Confirm each readiness gap has an obvious fix path and no dead-end admin terminology.
- [ ] Confirm Ham-specific defaults are visible where helpful but removable with the extension.
- [ ] Record any remaining UX blockers as follow-up checklist items in this plan or a narrower companion plan.

## Risks and Caveats

- **Hierarchy can invite premature inheritance.** Do not add inherited attributes in Phase 1 unless implementation proves the existing explicit global/category/template scopes cannot support the merchant workflow.
- **A plugin system can grow too large.** The harness should stay small and Commerce-specific until a second vertical extension proves which APIs need stability.
- **Readiness can become noisy.** Checklist items must be prioritized. A short blocker list is better than a full diagnostic dump.
- **Ham-specific UI pressure is real.** If the core cockpit lacks an extension slot Ham needs, add the smallest general slot rather than patching a Ham-only view into core.
- **Plans may overstate code reality.** Before implementation, verify which readiness, fitment, and publish pieces are actually present in code, not only marked complete in companion plans.
