# plugin-manager-ui

**Status:** Phases 1–3 complete (2026-05-16). Superseded in part — the screen is now **System → Software → Bundles**, and the manifest-`role` grouping it describes was removed as unused (YAGNI); see `docs/plans/plugin-term-retirement.md`. Phase 4 (topic tagging + cross-references) remains, retargeted to the `blb-bundle` topic.
**Last Updated:** 2026-06-21
**Sources:**
- `docs/architecture/module-system.md` — the module-system spec this UI surfaces.
- `app/Base/Foundation/ModuleManifest/ModuleManifestReader.php` — already produces the data the dashboard needs.
- `docs/plans/people/12_attendance-event-decoupling.md` Phase 5 — established the `extra.blb` manifest schema.
- `docs/runbooks/payroll-plugin-extraction.md` — the operator-facing companion for the install commands the catalog will surface.

**Agents:** claud/opus-4.7

---

## Problem Essence

Operators running BLB have no in-product view of which BLB plugins are installed, which are available, or what each one declares. Today the only way to inspect the plugin graph is to grep the filesystem; the only way to discover available plugins is to know that `github.com/BelimbingApp/` exists.

## Desired Outcome

A first-party admin screen that shows what is installed (with manifest metadata and dependency health) and what is available from BelimbingApp's GitHub org, with install instructions surfaced as copyable CLI commands. No code is fetched or executed from the UI. Operators get the visibility WordPress admins expect, without inheriting WordPress's attack surface.

## Top-Level Components

| Component | Responsibility |
|---|---|
| `system.plugins` authz capabilities | Two new capabilities — `system.plugins.view` for read access, `system.plugins.manage` for refreshing the GitHub cache. Defined in a system-wide authz config under `app/Base/`. |
| Plugin status dashboard (Livewire) | Reads `ModuleManifestReader::all()` plus the dependency-verification output. Renders installed-plugin cards: name, role, version, requires, optional, publishes-events, consumes-events, on-disk path, missing-dependency warnings. |
| BelimbingApp catalog service | Backend service that queries the GitHub API for repos in the BelimbingApp org tagged with the `blb-plugin` topic. Fetches each repo's `composer.json` from its default branch, parses `extra.blb`, returns the list. Cached for 24 h with a manual refresh button. Anonymous (no token required at this scope; 60 req/hr is comfortable for ~10 repos). |
| Catalog UI (Livewire) | Companion tab on the same screen. Renders each available plugin: name, description, version, declared events, install-command snippet (copy button), "already installed" badge when the local manifest matches. |
| `extra.blb.version` and `extra.blb.description` fields | Two small additions to the existing per-module `composer.json` manifests so the dashboard and catalog have something to render beyond bare module names. |

## Design Decisions

**Read-only, by design.** The UI does not clone, install, run migrations, or execute any code that came from outside the deployment. Install actions surface as CLI text the operator copies and runs. This keeps the attack surface inside the BLB main repo's existing trust boundary: an attacker with admin credentials cannot escalate to arbitrary code execution via this screen.

**BelimbingApp-only sources.** Phase 1 trusts exactly one source: the BelimbingApp GitHub organisation. No "add custom plugin URL" UI. We control everything published under that org; the trust model is the same as trusting the main BLB repo itself. Custom sources reopen the WordPress-class attack surface (typosquatting, social engineering, supply-chain compromise) and add no value until real third-party plugins exist. Revisit when there is demand from a real third party.

**No automated installer (on this screen).** Out of scope for the Bundles catalog. Automated install is a feature flag away once the catalog ships, but introducing it requires audit logging, rollback handling, queue-job execution, signed manifests, per-environment lockouts, and a security review. Defer until operators ask for it; until then, the documented CLI workflow plus the catalog is enough.

> **Correction (2026-06-21):** the product is no longer "no install from the UI." **System → Software → Updates** (the Deployment screen) already performs authenticated `git pull` + `composer install` + asset build + `php artisan migrate` + worker reload for every discovered bundle, including private repos via per-owner tokens stored under GitHub Access. The read-only stance is therefore specific to *this catalog screen*, not a product-wide prohibition; the honest remaining manual step is the *initial clone* of a not-yet-present bundle. Tracked in `docs/plans/plugin-term-retirement.md`.

**Anonymous GitHub API access.** The catalog hits public GitHub endpoints with no authentication. Rate limit is 60 requests/hr per server IP, which the 24 h cache renders moot for ~10 plugins. Authenticated mode (operator-supplied token raising the limit to 5,000/hr) is a follow-up if and only if rate limiting becomes a real complaint.

**Cache is durable and explicit.** The catalog cache lives in the database (a `system_plugin_catalog_cache` table or similar), not just an in-process cache. Survives reboots. A "Refresh from GitHub" button clears and re-fetches. The cache stores the parsed manifest, the source SHA, and the fetch timestamp, so an operator can see whether they're looking at fresh data.

**The dashboard reuses `ModuleManifestReader`.** No new manifest-parsing code. The reader already returns the data the dashboard needs; Phase A is mostly UI on top of it.

**Two manifest field additions.** `extra.blb.version` (SemVer string) and `extra.blb.description` (short text) get added to the existing per-module `composer.json` files. Both are zero-risk additions and useful regardless of the UI — every status query benefits from a version and a human-readable description.

**Location in the admin shell.** Lives under `admin/system/bundles` (originally `admin/system/plugins`), to match the existing `admin/system/*` family (logs, database tables, etc.). Sidebar entry under **System → Software → Bundles** (the "Software" group also hosts Updates, Business Domains, and GitHub Access).

## Public Contract

The screen exposes two tabs.

**Installed tab** lists every BLB module the runtime knows about. For each: name, module identifier, role (source / consumer / plugin), version (or "unversioned" if the manifest does not declare one), filesystem path, list of required modules (with a green check or red flag based on presence), list of optional modules (info-style indicator), events published, events consumed, and a "manifest details" disclosure for the raw `extra.blb` JSON.

A summary banner reports dependency health: number of installed plugins, number of unmet required dependencies, number of optional dependencies satisfied. Red banner if any required dep is missing; otherwise green.

**Available tab** lists plugins discovered from the BelimbingApp org. For each: name, description, latest released version (derived from tags; falls back to the default-branch composer.json version), role, declared events, source URL, and a copy-to-clipboard "install command" block. Plugins already present locally show an "Installed" badge with the local version next to the available version.

A "Refresh from GitHub" button is gated by `system.plugins.manage`. Last-fetched timestamp is visible on the tab.

Neither tab triggers any code execution from outside the local repo. No background jobs are queued. No filesystem writes happen except the catalog-cache row update.

## Out of Scope

- **Automated install / uninstall** from the UI. Operators run the documented CLI commands.
- **Custom plugin URLs** beyond BelimbingApp. Add when there is real demand from a real third party.
- **GitHub authentication.** Anonymous is fine at this scope.
- **Migration management UI.** Out of scope; `php artisan migrate` remains the canonical path.
- **Plugin permissions / sandboxing.** Not solvable in PHP without process isolation; not attempted.
- **Marketplace ratings / reviews / downloads counts.** Catalog is a list, not a store.

## Phases

### Phase 1 — Manifest field additions

**Status:** Complete. {claud/opus-4.7}

- [x] Added `extra.blb.version` (`0.1.0`) and `extra.blb.description` to all five People sub-module `composer.json` files. {claud/opus-4.7}
- [x] Extended `ModuleManifest` value object with `version` and `description`. {claud/opus-4.7}
- [x] Extended `ModuleManifestReader::parse`; description falls back to the composer-level `description` when `extra.blb.description` is absent. {claud/opus-4.7}
- [x] `ModuleManifestReaderTest` asserts both new fields are populated. {claud/opus-4.7}

### Phase 2 — Installed-plugin dashboard

**Status:** Complete. {claud/opus-4.7}

- [x] Defined `admin.system.plugins.view` and `admin.system.plugins.manage` in `app/Base/Foundation/Config/authz.php`. Naming matches the existing `admin.system.*` family. {claud/opus-4.7}
- [x] Livewire component at `app/Base/Foundation/Livewire/PluginManager.php`. {claud/opus-4.7}
- [x] Installed tab renders manifests grouped by role (plugin / source / unknown) with version, description, requires, optional, publishes, consumes, on-disk path. Dependency-health banner reports unmet required dependencies. {claud/opus-4.7}
- [x] Route `admin/system/plugins` in `app/Base/Foundation/Routes/web.php` gated by `admin.system.plugins.view`. {claud/opus-4.7}
- [x] Sidebar entry under "System" in `app/Base/Foundation/Config/menu.php`. {claud/opus-4.7}
- [x] Feature tests: admin sees the page with installed modules; dashboard reports unmet `core/employee` + `core/company` (Core sub-modules have no manifest yet); unauthorized users get 403. {claud/opus-4.7}

**Note on dependency banner state:** With the People domain installed and no manifests on Core sub-modules, the dashboard correctly reports `core/employee` and `core/company` as unmet. Visibility is intentional. When Core sub-modules ship manifests in a future plan, the banner flips to satisfied with no code changes.

### Phase 3 — BelimbingApp catalog tab

**Status:** Complete. {claud/opus-4.7}

- [x] Cache schema `base_foundation_plugin_catalog_cache` with source / repo / branch / SHA / parsed manifest fields and `fetched_at` timestamp. Unique on `(source, repo_name)`. {claud/opus-4.7}
- [x] `BelimbingAppCatalogService` with `refresh()`, `available()`, `lastFetchedAt()`, configurable `ttlHours()`. Source constant `github:BelimbingApp`, topic filter `blb-plugin`. Per-repo failures during refresh are swallowed so one malformed manifest does not abort the sync. {claud/opus-4.7}
- [x] `PluginCatalogEntry` value object including `suggestedInstallCommand()` that builds `git clone … app/Modules/{Domain}/{Module}` from the module identifier. {claud/opus-4.7}
- [x] Catalog tab in `PluginManager`. Renders cards with description, role, version, install command; "Installed" badge when the module identifier matches a local manifest. {claud/opus-4.7}
- [x] "Refresh from GitHub" button gated by `admin.system.plugins.manage`. Last-fetched timestamp visible. {claud/opus-4.7}
- [x] Feature tests with `Http::fake()`: catalog refresh ingests the expected entries; tab renders them with correct Installed badges; refresh action populates cache and switches tab; refresh requires the manage capability. {claud/opus-4.7}

**Note — unrelated authz fix:** Earlier Payroll-side mapping screens used a non-existent `AuthorizationService::actorCan()` method that the tests never exercised directly. Fixed in the same commit to use the real `can()->allowed` API, matching the pattern used in `PluginManager`. {claud/opus-4.7}

### Phase 4 — Documentation and topic tagging

**Scope**

- [ ] Tag the existing BelimbingApp repos with the `blb-plugin` topic so the catalog finds them. (Operator-side GitHub action; no code change.)
- [ ] Update `docs/architecture/module-system.md` to point at the dashboard as the canonical place to inspect the loaded module graph.
- [ ] Update `docs/runbooks/payroll-plugin-extraction.md` to mention that post-extraction operators can verify the extracted plugin in the dashboard.
- [ ] Add a short page under `docs/guides/` describing how to use the dashboard.

**Exit criterion**

- Every published BLB plugin is topic-tagged.
- Documentation cross-references the dashboard wherever plugin state is discussed.

## Open Questions

- **Where do `system.plugins.*` capabilities live?** Whichever existing Base authz config currently owns the `system.logs.*` and `system.database-tables.*` family. Verify at Phase 2 start.
- **Default cache TTL.** Stated 24 h above; tune at Phase 3 once we see real-world catalog sizes and update cadence. Probably configurable via env var (`BLB_PLUGIN_CATALOG_TTL_HOURS`).
- **Version display when a plugin has no semver tag.** Fall back to `extra.blb.version` from the default branch's `composer.json`. If that's missing too, display "unversioned" and surface it in the manifest details.
- **What "Installed" means for cached entries.** Compare by `extra.blb.module` (the canonical identifier, e.g. `people/payroll`), not by composer package name. Same identity rule used elsewhere.

## Notes

The HALT norm in `docs/plans/AGENTS.md` applies: this plan is to be reviewed and approved before implementation. No code has been written.
