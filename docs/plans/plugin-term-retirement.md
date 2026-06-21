# plugin-term-retirement

**Status:** Phases 1–2 complete (2026-06-21). Phases 3–4 proposed — HALT for approval before implementing them.
**Last Updated:** 2026-06-21
**Sources:**
- `docs/architecture/module-system.md` — Vocabulary note that defines Module / Distribution Bundle (Bundle) / adapter / extension seam and records "plugin" as retired on operator surfaces.
- `docs/plans/plugin-manager-ui.md` — the shipped dashboard this plan renames; its Design Decisions are partly stale and are corrected here.
- `app/Base/Foundation/Services/LandingPageResolver.php` — in-repo precedent for aliasing renamed menu IDs that are persisted as user prefs.

**Agents:** claud/opus-4.8

---

## Problem Essence

"Plugin" names four different things in the codebase (an installable unit, a manifest role, a Commerce extension seam, and loose prose for an optional module), which violates the **Honesty** principle in root `AGENTS.md`. The operator-facing labels were already corrected to "Bundle"; the remaining uses are internal identifiers, a manifest-schema value, a module namespace, and a now-stale plan doc — each a larger correction that must be captured as a plan rather than silently deferred.

## Desired Outcome

"Plugin" no longer appears as a load-bearing term. The install unit is **Bundle** (the operator short form of Distribution Bundle) everywhere — UI, routes, authz, table, catalog service, GitHub topic. The manifest *role* and the Commerce *extension seam* are renamed to honest, non-overloaded terms. Persisted identifiers carry back-compat aliases so no existing user pref, role assignment, or external repo tag breaks. `plugin-manager-ui.md` tells the truth about both naming and the install-from-UI security posture.

## Already Landed (small corrections, done this pass)

- Menu labels: group `Update` → **Software**, `Plugins` → **Bundles**, `Deployment` → **Updates** (route/permission IDs untouched). `tests/Feature/Menu/UpdateMenuTest.php` updated; Menu suite green. {claud/opus-4.8}
- `module-system.md` Vocabulary note + "Note on plugin (retired on operator surfaces)" added. {claud/opus-4.8}

These were the "fix immediately" tier. Everything below is the "larger correction → plan now" tier.

## Top-Level Components

Four clusters, grouped by *sense* of the word and tagged by data-trap cost.

### A. Bundle dashboard (install-unit sense) — persisted + external surfaces

The operator screen that inventories installed modules and the BelimbingApp catalog. Target term: **Bundle**.

- `App\Base\Foundation\Livewire\PluginManager` → `BundleManager`; blade `…/foundation/plugin-manager.blade.php` → `bundle-manager.blade.php`; page title "Plugins" → "Bundles".
- **URL** `admin/system/plugins` → `admin/system/bundles`; **route** `admin.system.plugins.index` → `admin.system.bundles.index` (`app/Base/Foundation/Routes/web.php`).
- **Menu id** `admin.system.plugins` → `admin.system.bundles` — *persisted as a landing-page pref*; needs a `LandingPageResolver` alias.
- **Authz** `admin.system.plugins.{view,manage}` → `admin.system.bundles.{view,manage}` (`app/Base/Foundation/Config/authz.php`) — verify whether capabilities persist as assigned role rows; alias/migrate if so.
- **DB table** `base_foundation_plugin_catalog_cache` → `base_foundation_bundle_catalog_cache` (migration `…_create_base_foundation_plugin_catalog_cache_table`). Rebuildable cache → low risk, but still a schema migration.
- `BelimbingAppCatalogService`: `TABLE` const, **`TOPIC` `blb-plugin` → `blb-bundle`** (external GitHub topic, currently untagged → free now), config key `plugin_catalog.ttl_hours` → `bundle_catalog.ttl_hours`.
- `PluginCatalogEntry` → `BundleCatalogEntry`; tests `PluginManagerTest`, `PluginCatalogTest` renamed.

### B. Manifest role value (`extra.blb.role: plugin`)

A module *role* in the dependency graph (paired with `source` / `unknown`), surfaced as dashboard groupings. Read by `ModuleManifestReader`; grouped in `BundleManager`/blade (`'plugin' => __('Plugins')`); asserted in `ModuleManifestReaderTest`, `MigrateCommandTest`, `PluginManagerTest`, `PluginCatalogTest`. **No shipped manifest sets a role today** → value lives only in reader logic, the dashboard heading, and fixtures.

### C. Commerce extension seam

`App\Modules\Commerce\Plugins\*`: `CommercePluginRegistry`, `CommercePluginDiscoveryService`, ServiceProvider, the `CommerceReadinessContributor` contract (already honestly named — keep), and tests. Consumers: `EbayMetadataRefreshCommand`, `Ebay\Settings`, `Inventory\Items\Show`, marketplace tests. The discovered contract file is `Config/commerce.php` (not "plugin"-named — unaffected). No persisted-data cost.

### D. Prose + the stale plan

- Loose "Payroll plugin" / "payroll plugin" in People module docblocks and test names — colloquial for an optional module.
- `docs/plans/plugin-manager-ui.md` — names the screen "Plugins" and justifies "no install from UI" with a rationale that `Update → Updates` (Deployment) already contradicts: it pulls, builds, migrates, and reloads private repos using stored per-owner tokens. The security framing is now factually wrong and must be corrected, not just renamed.

## Design Decisions

**Reuse "Bundle", don't coin a new word.** Decided already and recorded in `module-system.md`: the install unit is the Distribution Bundle; "Bundle" is its operator short form. Cluster A adopts it wholesale.

**BLB is pre-release — hard-rename, carry no aliases.** There are no production deployments, so there is no persisted user pref, assigned authz row, or external repo tag to preserve. Per the initialization-phase license in root `AGENTS.md`, rename identifiers outright and edit the original migration in place rather than adding a rename migration. No `LandingPageResolver` alias, no URL redirect — the old `admin/system/plugins` URL simply ceases to exist. (Had BLB shipped, the precedent would be the `LandingPageResolver` alias map from past menu-id renames; it does not apply now.)

**Sequence by data-trap urgency, not by visibility.** Strategic Programming says spend the cheap design budget before the cost rises. The GitHub topic (untagged) and the manifest role (unused in shipped manifests) are free to change now and expensive once adopted — so they rank high despite being low-visibility. The Commerce seam has no persisted cost and can move last or be left to the doc note if priorities slip.

**Rename the word, keep the concepts.** The manifest *role* taxonomy and the Commerce *seam* are sound designs; only the overloaded token changes. `CommerceReadinessContributor` keeps its name. Recommended targets: role `plugin` → **`optional`** (honest about "optionally installed / removable", read opposite `source`), a hard switch with no alias since no shipped manifest declares a role; Commerce `Plugins` → **`Extensions`** namespace with `CommerceExtensionRegistry` / `CommerceExtensionDiscoveryService`, matching the doc's "extension seam" language.

**Correct `plugin-manager-ui.md` in place.** Per the "no stale/contradictory content" hard rule, fix its naming and rewrite the install-from-UI section to reflect that Updates already performs authenticated pull/build/migrate/reload of private bundles; the honest remaining boundary is the *initial clone*, not "no code execution from the UI."

## Public Contract

After implementation:
- Bundle dashboard lives at `admin/system/bundles` (route `admin.system.bundles.index`, menu id `admin.system.bundles`), gated by `admin.system.bundles.{view,manage}`. The former `admin/system/plugins` URL, route, menu id, and authz ids are removed outright (BLB is pre-release; nothing references them in persisted data).
- Catalog cache table is `base_foundation_bundle_catalog_cache`; GitHub discovery topic is `blb-bundle`; TTL config key is `bundle_catalog.ttl_hours`.
- `extra.blb.role` canonical value is `optional`; the `plugin` value is removed, not aliased.
- Commerce extension contributions register through `CommerceExtensionRegistry`; `Config/commerce.php` keys are unchanged.

## Phases

### Phase 1 — Bundle dashboard rename + plan reconciliation

Goal: a reviewer opening `admin/system/bundles` sees the renamed screen titled "Bundles"; the old `admin/system/plugins` identifiers no longer exist (hard rename — BLB is pre-release); `plugin-manager-ui.md` is truthful.

- [x] Renamed `PluginManager` → `BundleManager`, blade `plugin-manager.blade.php` → `bundle-manager.blade.php`, page title "Plugin manager" → "Bundles"; route/URL `admin/system/plugins` → `admin/system/bundles` and the menu route reference. {claud/opus-4.8}
- [x] Renamed authz `admin.system.plugins.*` → `admin.system.bundles.*`. No seeder/role grant referenced it — `core_admin` is `grant_all`, so nothing else needed updating. {claud/opus-4.8}
- [x] Edited the migration in place to create `base_foundation_bundle_catalog_cache` (file renamed to match); updated `BelimbingAppCatalogService` `TABLE`, `TOPIC` (`blb-bundle`), `bundle_catalog.ttl_hours`; renamed `PluginCatalogEntry` → `BundleCatalogEntry`. {claud/opus-4.8}
- [x] Renamed `PluginManagerTest` → `BundleManagerTest`, `PluginCatalogTest` → `BundleCatalogTest`; updated assertions, route names, the `blb-bundle` fixture topics, and test-local constants. {claud/opus-4.8}
- [x] Updated `plugin-manager-ui.md`: screen name → Bundles; added a 2026-06-21 correction to the install-from-UI rationale; cross-referenced the Software menu group; bumped Status/Last Updated. {claud/opus-4.8}

Evidence: `BundleManagerTest` (4) + `BundleCatalogTest` (4) + full Menu suite (8) green — 16 passed, 71 assertions. The composer-package `"type": "blb-plugin"` in `People/Payroll/composer.json` is a distinct mechanism from the GitHub topic and is left to Phase 4.

Note: two pre-existing failures in `DatabaseTablesIncubationManagementTest` (git-commit path) reproduce on a clean `main` with this work stashed — not caused by this phase.

### Phase 2 — Remove `extra.blb.role` (YAGNI)

**Status:** Complete (2026-06-21). {claud/opus-4.8}

Decision (changed from the original "rename `plugin` → `optional`"): `role` drove no behavior — only a dashboard grouping and a catalog label — and the source/plugin distinction is derivable from the dependency graph and already duplicated by the composer `"type"`. So it was **deleted outright**, not renamed. (It was populated in 5 real People manifests, contrary to an earlier mis-read; deletion edits those too.)

- [x] Removed `role` from `ModuleManifest`, `ModuleManifestReader::parse`, `BundleCatalogEntry`, `BelimbingAppCatalogService` (build/insert/row), and the catalog-cache migration column. {claud/opus-4.8}
- [x] Flattened the `BundleManager` Installed tab — dropped the `source`/`plugin`/`unknown` grouping for one list; removed the catalog "Role" display. {claud/opus-4.8}
- [x] Removed `"role"` from the 5 People manifests (Attendance/Claim/Leave/Settings = source, Payroll = plugin) and the migrations-guide sample. {claud/opus-4.8}
- [x] Stripped role from `ModuleManifestReaderTest`, `MigrateCommandTest`, `BundleManagerTest`, `BundleCatalogTest`. {claud/opus-4.8}
- [x] Updated `module-system.md` (manifest-fields row + the plugin note). {claud/opus-4.8}

Evidence: `BundleManagerTest` (4) + `BundleCatalogTest` (4) + `ModuleManifestReaderTest` (7) + `MigrateCommandTest` (10) green — 25 passed, 81 assertions.

### Phase 3 — Commerce extension seam

Goal: `Commerce/Plugins` becomes `Commerce/Extensions`; all consumers compile against `CommerceExtensionRegistry`.

- [ ] Rename namespace/dir and `CommercePluginRegistry`/`CommercePluginDiscoveryService` → `CommerceExtension*`; keep `CommerceReadinessContributor`.
- [ ] Update consumers: `EbayMetadataRefreshCommand`, `Ebay\Settings`, `Inventory\Items\Show`, and Commerce/marketplace tests.

Validation: Commerce + marketplace suites green; `Config/commerce.php` discovery unchanged.

### Phase 4 — Prose + comment cleanup

Goal: no "plugin" left as a load-bearing noun; remaining hits are quoted history or external proper nouns.

- [ ] Replace "Payroll plugin" → "Payroll module" (or "optional Payroll module") across People docblocks and test names where it means the module.
- [ ] Re-tag BelimbingApp repos with `blb-bundle` when the catalog goes live (operator action; folds in the never-completed plugin-manager-ui Phase 4).
- [ ] Grep sweep: no `plugin` identifier remains outside deprecated aliases and quoted history.

Validation: repo-wide `plugin` grep returns only aliases, external names, and intentional historical references.
