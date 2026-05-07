# Menu System Cleanup

**Status:** In Progress (Phases 1–4 applied)
**Last Updated:** 2026-05-07 (Phase 4 implemented; tests green)
**Sources:** `app/Base/Menu/`, all 25 `Config/menu.php` files under `app/Base/*`, `app/Modules/*/*`, and `extensions/*/*`
**Agents:** claude/opus-4-7

## Problem Essence

The 25 `Config/menu.php` files driving the admin sidebar have drifted across five dimensions: (1) one of the three top-level buckets (`commerce`) is declared as a side-effect inside a non-Menu module, so disabling that module orphans unrelated submenus, and the existing `business` bucket is too generic to absorb foreseeable additions (HR, accounting, maintenance, production); (2) the `position` field on every menu item is dead weight — `MenuBuilder::buildTree` sorts alphabetically by label and ignores it (`app/Base/Menu/MenuBuilder.php:40-54`); (3) the `system` bucket has accumulated 14 flat children spanning diagnostics, database, configuration, and integrations with no internal grouping; (4) item IDs and permission keys use two parallel conventions — IDs are loosely `<group>.<entity>` while permissions glue multi-word entities with underscores (`admin.system_info.view`), and Employees sits under `admin` for historical reasons rather than because it belongs there; (5) when a menu item misbehaves, there is no UI affordance to tell whether it came from `app/` (BLB core) or `extensions/` — the diagnostic path leaks all the way back to grep. Smaller polish items (a label typo, missing icons, a parent that is both a route and a container, a null-filter helper duplicated in only one module) compound the impression of accreted convention.

## Desired Outcome

1. **Top-level taxonomy that scales.** Today's live buckets are `admin`, `operations` (renamed from `business`), `commerce`, and `people` (new — humans only; receives Employees + Employee Types on day one, plus a Licensee Company surface that is a relationship-filtered view of the master Companies table). `finance`, `procurement`, `maintenance`, and `production` are reserved as commented-out entries in the central menu config so the slot and convention are visible to the next contributor without rendering an empty container.
2. **One declaration site for top-level groups.** All four live buckets and any reserved-bucket comments live in `app/Base/Menu/Config/menu.php`. Disabling a downstream module never orphans an unrelated subtree.
3. **No dead fields in menu configs.** The `position` key is removed from MenuItem, MenuRegistry, and every `Config/menu.php`. Alphabetical sort by label is the single, documented ordering rule.
4. **`system` bucket is navigable at a glance.** Leaves grouped under four containers (Diagnostics, Database, Configuration, Integrations); System Info stays as a direct child for the default landing.
5. **One naming convention.** Both item IDs and permission keys share the same dotted-segment grammar; permissions are item IDs with an action segment appended. The convention is codified as a header comment in `app/Base/Menu/Config/menu.php`.
6. **Polish items resolved.** Ham insights nest under their own `commerce.ham-auto-parts` container; the `TestTransport` label is fixed; `quality.ncr` and `quality.scar` get icons; the AI module's null-filter helper moves into `MenuDiscoveryService` so every module benefits without boilerplate; parents are containers (no parent both routes and parents children).
7. **Source visible on demand.** Each rendered menu item carries its source module and file as metadata; a Menu Inspector admin page lists every registered item with source path, parent, permission, condition, and computed visibility; in `APP_DEBUG=true` the source path appears on hover. The live sidebar gets no permanent extension marker — diagnosis is one click away when needed and invisible otherwise.
8. **No regressions.** `php artisan route:list` clean; test suite green; `@can` checks across Blade and controllers continue to gate correctly under renamed permission keys.

## Top-Level Components

- **`app/Base/Menu/Config/menu.php`** — the canonical site for top-level group declarations, the four `admin.system.*` subgroup containers, the convention header comment, and commented-out reservations for future buckets (`finance`, `maintenance`, `production`).
- **`app/Base/Menu/MenuItem.php` / `MenuRegistry.php`** — drop the `position` parameter and field; add a `source` field carrying `module_name` and `file` from discovery so downstream code can answer "where is this defined?". Bump or invalidate the registry cache key so the persisted shape change is clean.
- **`app/Base/Menu/MenuBuilder.php`** — update the comment at lines 40-44 so the sort rule is stated as the rule, not as a backward-compat fallback.
- **`app/Base/Menu/Services/MenuDiscoveryService.php`** — already extracts `_source.module_name` and `_source.file` (`MenuDiscoveryService.php:84-93`) but the metadata is dropped at registration. Forward it into `MenuItem`. Also: absorb the null-key normalization currently inlined in `app/Modules/Core/AI/Config/menu.php:6-8` so every module can omit nullable keys without boilerplate.
- **Menu Inspector page** — a new admin route, parented under `admin.system.diagnostics`, that renders every registered item with its source, parent, permission, condition, and computed visibility. Same flavor as the existing `admin.system.ui-reference`. Single Livewire/Blade component; reuses `MenuRegistry::getAll()`.
- **All 25 `Config/menu.php` files** — strip `position`; reparent `system` leaves into the new containers; reparent Employees/Companies under `people`; rename IDs and permission keys to the single convention; fix Ham insight parenting; minor label and icon edits.
- **References to renamed permission keys** — controllers, policies, middleware, and Blade `@can`/`@cannot` blocks. Pre-flight grep produced ~25 referencing files outside the menu configs; the new ID-format renames extend that surface to wherever item IDs are referenced (only `parent` fields, expected, but verified by grep).

## Design Decisions

### D1: Domain-anchored top-level groups, rename `business`→`operations`, re-home Employees, keep Companies as a single surface, reserve future buckets as comments

Four problems solved together:

- **Each top-level bucket is declared by its domain anchor — a stable, non-leaf file.** `commerce` was originally declared inline in `app/Modules/Commerce/Inventory/Config/menu.php` (a leaf), so disabling Inventory orphaned Catalog/Marketplace/Ham. The fix is not to move every bucket into Base/Menu (that creates layering reversed: Base knowing about commerce/operations/people domains it doesn't own, and an asymmetric extension model where extensions can't declare buckets the same way). Instead, each domain anchors its own bucket in `<domain>/Config/menu.php` — one level up from leaf modules. `MenuDiscoveryService` scans both depths so anchors and leaves are picked up. Extensions follow the same shape (`extensions/<vendor>/Config/menu.php` for vendor-anchored buckets, `extensions/<vendor>/<package>/Config/menu.php` for package leaves), so an extension can declare a top-level bucket with the same authority as core. Anchor locations: `admin` in `app/Base/Menu/Config/menu.php` (Base-layer's own bucket); `commerce` in `app/Modules/Commerce/Config/menu.php`; `operations` in `app/Modules/Operation/Config/menu.php`; `people` in `app/Modules/People/Config/menu.php` (new directory; Employee module stays under `Modules/Core/Employee/` for now and parents into `people` — codebase namespace and menu navigation don't have to match). Reservations (`finance`, `procurement`, `maintenance`, `production`) live as commented-out entries in `app/Base/Menu/Config/menu.php` until each has a domain anchor.
- **Rename `business`→`operations`.** "Business" is too generic and offers no guidance for HR, accounting, maintenance, or production work. "Operations" matches the existing module path namespace (`app/Modules/Operation/`) and absorbs the current `business` children (Quality, IT) cleanly, plus future operational modules (maintenance, production).
- **Re-home Employees only.** `admin.employees` (and `admin.employee-types`) live under `admin` for historical reasons, not by design. Move them under a new `people` bucket. People is humans-only — employees today, future HR/payroll/leave/performance modules later. Addresses stays under `admin`, paired with Geonames as foundational reference data.
- **Companies master surface stays under `admin`; relationship-filtered views surface in workflow buckets.** Codebase finding: BLB stores all legal entities — internal, customer, supplier, partner, agency — in one `companies` table, with relationship-typed joins via `company_relationships` and `company_relationship_types` (codes `internal`, `customer`, `supplier`, `partner`, `agency`; each carries an `is_external` flag). The data has no hard internal/external boundary; a row's role is its relationship type, which is time-bounded and can change. The master Companies surface (full CRUD across all relationships) lives under `admin.companies`. Audience-scoped filtered views surface in their workflow bucket: `commerce.customer` (when Customer UI lands), `procurement.supplier` (under a future bucket), and `people.licensee-company` (internal-relationship view, useful for HR/employee admins). Companies is explicitly *not* placed under `people` — they aren't humans, and the shared-table design makes any single-relationship master bucketing wrong. The pattern is detailed in D10.
- **Reserve future buckets as comments.** Empty containers do not render — `MenuBuilder::buildTree` drops containers with no visible children. So a literal empty entry would be invisible. Instead, add commented-out entries in `app/Base/Menu/Config/menu.php` with TODO notes (`finance`, `procurement`, `maintenance`, `production`). The comment is the just-in-time reminder for the next contributor; the plan file is the longer-form roadmap. The graduation rule: a slot becomes a live bucket once it has two or more meaningful submodules. `people` qualifies on day one (Employees + Employee Types plus the Licensee Company filtered view); `finance` and `procurement` do not yet.

### D2: Delete the `position` field outright

`MenuBuilder::buildTree` sorts by `mb_strtolower($item->label)` (`app/Base/Menu/MenuBuilder.php:48-54`); the in-code comment already says position "is no longer used as the primary sort." The field is referenced only inside `app/Base/Menu/` — no view, controller, or external module reads it. Keeping the field is misleading: contributors will keep tuning a number that does nothing. Delete from `MenuItem`, from `MenuRegistry::persist()`, from every config, and update the `MenuBuilder` comment to state alphabetical sort as the rule. Invalidate the registry cache so the old serialized shape does not haunt deployments. If, later, deterministic curated order becomes a real need, it will be re-introduced as a deliberate feature with collision rules — not as today's accidental field.

### D3: Subgroup the System bucket

Group the existing 14 flat `system` children under four new containers, all parented to `admin.system`. System Info stays as a direct child of `admin.system` so the default landing remains one click away.

| Container                       | Children                                                            |
| ------------------------------- | ------------------------------------------------------------------- |
| `admin.system.diagnostics`      | Logs, Failed Jobs, Job Batches, Sessions, Cache, Scheduled Tasks, Menu Inspector |
| `admin.system.database`         | Database Tables, Database Queries, Database Backups                 |
| `admin.system.configuration`    | Settings, Localization, UI Reference                                |
| `admin.system.integrations`     | Outbound Exchanges, Test Transport                                  |

The four containers are declared in `app/Base/Menu/Config/menu.php` (the same file that owns top-level buckets — they are structural, not module-owned). Each existing leaf changes one line: its `parent` field updates to the appropriate subgroup ID.

### D4: A single naming convention for IDs and permission keys

Codified as a header comment in `app/Base/Menu/Config/menu.php`. One grammar; permissions are item IDs with an action suffix.

- **Segment grammar.** Lowercase. Dotted. Hyphens (not underscores) for multi-word tokens within a segment. Singular nouns for identifier segments (labels in the UI can stay plural prose).
- **Item ID:** `<bucket>.<entity>` or `<bucket>.<subgroup>.<entity>` or `<bucket>.<subgroup>.<area>.<entity>` — depth as the structure requires. The first segment is always a live top-level bucket (`admin`, `operations`, `commerce`, `people`). Examples: `admin.system.log`, `commerce.marketplace.ebay-setting`, `people.employee`, `operations.quality.ncr`.
- **Permission key:** `<item-id>.<action>` where `<action>` is from a fixed vocabulary: `view`, `list`, `manage`, `create`, `update`, `delete`. Example: `admin.system.log.list`. A permission key without a corresponding menu item ID is allowed but should still follow `<bucket>.<entity>...<action>`.

The cost is a wider rename than the previous draft's permission-only sweep — most item IDs gain a bucket prefix (`system.log` → `admin.system.log`, `quality.ncr` → `operations.quality.ncr`), and underscored multi-word entities hyphenate (`system_info` → `system.info` or `failed_job` → `failed-job`). The benefit is that any reader of any single menu item ID knows where it lives in the tree without reading the parent chain, and the permission shape is mechanical (`id + action`).

### D5: Move null-filter normalization into discovery

`app/Modules/Core/AI/Config/menu.php:6-8` defines a local `$item` callable that strips null keys before pushing items onto the array. Only that one module uses it. The natural home is `MenuDiscoveryService::processFile()` — once discovery filters nulls, every module can omit `condition`, `permission`, `route`, etc. without wrapping each entry. Strip the `$item(...)` calls from the AI menu after the discovery change lands.

### D6: Parents are always containers

`commerce.marketplace` currently carries both a `route` and child items (`app/Modules/Commerce/Marketplace/Config/menu.php`). The other parents are pure containers. Standardize: a node is either a leaf with a route, or a container with children — never both. Convert `commerce.marketplace` into a container; promote its current route into a sibling-of-eBay-Settings child item (`commerce.marketplace.ebay`). The "hide containers with no visible children after permission filtering" behavior in `MenuBuilder::buildTree` already handles the empty-container case, so no builder change is needed.

### D7: Ham insights group under their own container

The Ham extension currently emits six items (settings + five insights) directly under `commerce`. Add a `commerce.ham-auto-parts` container and re-parent the six items beneath it. Optionally split insights further into `commerce.ham-auto-parts.insights` to mirror the URL structure; defer that split unless it improves the rendered tree visually.

### D8: Surface menu source metadata; Menu Inspector page is the diagnostic surface

`MenuDiscoveryService` already captures `_source.module_name` (e.g., `Menu`, `Geonames`, `auto-parts`) and `_source.file` (relative path) for every item, but `MenuItem::fromArray` drops it. Persist it on `MenuItem` so downstream code can answer "where is this defined?".

Three layered surfaces, by intent:

- **Always-on:** the source data is on `MenuItem`. No UI cost. Code can read it.
- **On-demand:** a Menu Inspector page (route under `admin.system.diagnostics`) renders every registered item with id, label, parent, source file, source module, permission, condition, and computed visibility for the current user. This is what someone opens when a menu item misbehaves. It is the answer to the user's diagnostic problem ("is this from core or an extension?") and it does not pollute the live sidebar.
- **Debug mode:** when `APP_DEBUG=true` (or a per-session debug toggle), the source path appears on the menu item via a `title=` tooltip. Off in production by default.

No permanent live-sidebar marker (no "EXT" pill, no per-extension icon tone). It ages badly and clutters the only surface that everyone uses every day. The inspector + debug tooltip combination addresses the diagnostic need without that cost. If passive identification is wanted later, the source data on `MenuItem` makes it a one-line view change.

### D10: Domain-scoped Companies views — one table, multiple lenses

The shared `companies` table (D1) needs a deliberate menu pattern so domain users find their relevant subset where they expect it without duplicating the master surface or letting filtered views drift apart.

**The pattern.**

- **Master surface.** Lives where the primary owner reads/writes. For Companies that is `admin.companies` — full CRUD across all relationships, system-of-record. Sysadmins land here.
- **Audience-scoped filtered views.** Surface in the workflow bucket where the audience expects them. Each is a relationship-typed view of the same `companies` table:
  - `commerce.customer` — `relationship_type=customer`, audience: sales.
  - `procurement.supplier` — `relationship_type=supplier`, audience: procurement (future bucket).
  - `people.licensee-company` — `relationship_type=internal`, audience: HR/employee admins. Surfaces "our company info" without sending users into system admin.
- **No master duplication.** The master surface exists in exactly one place. Filtered views never re-implement full CRUD across all relationships; they constrain to their relationship type.
- **Shared rendering, distinct audiences.** A shared base controller / Livewire component handles the `companies` query and rendering; each filtered surface is a thin subclass that supplies its relationship-type filter and any audience-specific column choices. Views never drift because the rendering code is one place.
- **Route-based scope is enforced in the policy, not the route.** A user visiting `admin.companies.show?id=42` and a salesperson visiting `commerce.customers.show?id=42` both flow through the same policy check that consults the target row's relationships against the user's permissions. The relationship-aware policy lives in `CompanyPolicy::view($user, Company $company)` and is the single source of truth; routes are just entry points.
- **Permissions follow the audience.** Each surface names its own permission key (D4): `admin.company.list`, `commerce.customer.list`, `procurement.supplier.list`, `people.licensee-company.view`. Granting `commerce.customer.list` does not implicitly grant `admin.company.list`; roles compose audience-scoped permissions explicitly.

This pattern generalizes beyond Companies — any shared table that serves multiple audiences (e.g., a future `contacts` or `transactions` table) can use the same shape.

### D9: Phase order minimizes churn collisions

Phases 1, 2, 3, 4, 5, 6 each touch a small disjoint slice. Phase 7 (the rename) touches the largest set of files and is therefore last — every other change has already settled by then, so a missed reference is easier to bisect.

## Phases

### Phase 1 — Centralize, rename, and re-home top-level groups

**Goal:** all root-level menu buckets declared in `app/Base/Menu/Config/menu.php`; `business` retired; Employees and Companies live under `people`; future buckets reserved as comments.

- [x] Add the convention header comment to `app/Base/Menu/Config/menu.php` describing the segment grammar and the `permission = id + action` rule (D4 prose; full effect lands in Phase 7) claude/opus-4-7
- [x] Extend `MenuDiscoveryService::$scanPatterns` to scan one-level paths (`app/Modules/*/Config/menu.php`, `extensions/*/Config/menu.php`) so domain anchors are discovered alongside leaf modules; extend `extractModuleName()` with matching regex patterns claude/opus-4-7
- [x] Create `app/Modules/Commerce/Config/menu.php` declaring the `commerce` bucket (anchor file) claude/opus-4-7
- [x] Create `app/Modules/Operation/Config/menu.php` declaring the `operations` bucket (anchor file; replaces the renamed `business`) claude/opus-4-7
- [x] Create `app/Modules/People/Config/menu.php` declaring the `people` bucket (new directory + anchor file; Employee module stays under `Modules/Core/Employee/`) claude/opus-4-7
- [x] Strip the `commerce`, `operations`, `people` declarations from `app/Base/Menu/Config/menu.php`; keep only `admin`, `system`, and the commented-out reservations claude/opus-4-7
- [x] Add commented-out reservations for `finance`, `procurement`, `maintenance`, `production` with TODO notes claude/opus-4-7
- [x] Remove the inline `commerce` declaration from `app/Modules/Commerce/Inventory/Config/menu.php:9-12`, leaving only the inventory leaf claude/opus-4-7
- [x] Re-parent `quality` (in `app/Modules/Core/Quality/Config/menu.php`) and `it` (in `app/Modules/Operation/IT/Config/menu.php`) from `business` to `operations` claude/opus-4-7
- [x] Re-parent `admin.employees` and `admin.employee-types` (in `app/Modules/Core/Employee/Config/menu.php`) under `people` (parent change only in this phase; ID rename to `people.employee` / `people.employee-type` happens in Phase 7) claude/opus-4-7
- [x] Companies stays under `admin` — no parent change in this phase claude/opus-4-7
- [ ] Add a `people.licensee-company` menu entry (parent: `people`) per D10. **Deferred** during Phase 1: requires a new permission key (`people.licensee-company.view`) and authz registration; the existing `admin.setup.licensee` route is the natural target (its `Licensee::mount()` redirects to `admin.companies.show` when `Company::LICENSEE_ID` exists). Pick up as a small follow-up once we want a dedicated entry point.
- [ ] Verify the rendered tree at `/admin` after a `php artisan cache:clear`: Operations contains Quality and IT; People contains Employees and Employee Types; Admin no longer contains Employees but still contains Companies, Users, Addresses, Geonames, AI, Authorization, Audit Log, Workflows, System; Commerce still resolves with Inventory disabled

### Phase 2 — Delete the `position` field

**Goal:** remove a misleading dead field from configs and code.

- [x] Remove the `position` parameter from `MenuItem::__construct` and `MenuItem::fromArray` (`app/Base/Menu/MenuItem.php`) claude/opus-4-7
- [x] Remove `position` from `MenuRegistry::persist()` (also added the missing `condition` field while there) claude/opus-4-7
- [x] Update the comment block in `MenuBuilder::buildTree` to state alphabetical sort as the rule and explain why explicit positions are not supported claude/opus-4-7
- [x] Bumped `MenuRegistry::CACHE_KEY` to `blb.menu.registry.v2` with a comment explaining the bump (avoid stale serialized payload from before the field removal) claude/opus-4-7
- [x] Strip every `'position' => N,` line from all menu config files (55 lines across 22 files) claude/opus-4-7
- [x] `php artisan cache:clear` clean; menu test suite (11 tests) passes; discovery probe shows 63 items, 4 top-level buckets, no validation errors claude/opus-4-7

### Phase 3 — Subgroup the System bucket

**Goal:** four named containers under `system` (renamed to `admin.system` in Phase 7), with leaves re-parented.

- [x] Add `system.diagnostics`, `system.database`, `system.integrations` containers to `app/Base/Menu/Config/menu.php`, each parented to `system` (icons: signal / circle-stack / link). The originally proposed `system.configuration` container was dropped during review — Settings, Localization, UI Reference don't cohere as a subgroup; they live as direct children of `system` alongside System Info. UI Reference in particular is a developer reference catalog, not configuration. claude/opus-4-7
- [x] Re-parent leaves in `app/Base/Log/Config/menu.php`, `app/Base/Queue/Config/menu.php`, `app/Base/Session/Config/menu.php`, `app/Base/Cache/Config/menu.php`, `app/Base/Schedule/Config/menu.php` → `system.diagnostics` claude/opus-4-7
- [x] Re-parent leaves in `app/Base/Database/Config/menu.php` (all three) → `system.database` claude/opus-4-7
- [x] Re-parent the Outbound Exchanges item in `app/Base/Integration/Config/menu.php` and the Test Transport item in `app/Base/System/Config/menu.php` → `system.integrations` claude/opus-4-7
- [x] Settings, Localization, UI Reference, System Info all stay/become direct children of `system` claude/opus-4-7
- [x] Discovery probe + menu test suite (11 tests) green claude/opus-4-7
- [ ] Visual check at `/admin/system/*` — every item still reachable; active highlighting still works

(IDs stay in pre-rename form during Phase 3; full rename to `admin.system.*` happens in Phase 7. Parent fields use the new subgroup IDs.)

### Phase 4 — Group Ham insights

**Goal:** six Ham items under one container instead of cluttering Commerce root.

- [x] Add a `ham.auto-parts` container in `extensions/ham/auto-parts/Config/menu.php` parented to `commerce` (ID becomes `commerce.ham-auto-parts` in Phase 7); icon `heroicon-o-wrench-screwdriver` claude/opus-4-7
- [x] Re-parent the existing six items from `parent: commerce` to the new container claude/opus-4-7
- [x] Renamed the Settings leaf label from "Ham Auto Parts" to "Settings" (the container now carries the brand) and gave it `heroicon-o-cog-6-tooth` claude/opus-4-7
- [ ] **Deferred:** nested insights sub-container for the five insight items. Six items under one container is not crowded; defer unless visual review prompts otherwise.

### Phase 5 — Polish

**Goal:** small fixes that do not need their own phase.

- [ ] Fix label `TestTransport` → `Test Transport` (`app/Base/System/Config/menu.php:37`)
- [ ] Add icons to `quality.ncr` and `quality.scar` in `app/Modules/Core/Quality/Config/menu.php` (suggest `heroicon-o-flag` and `heroicon-o-shield-exclamation`, pick to taste)
- [ ] Move the null-filter normalization into `MenuDiscoveryService::processFile()`; remove the `$item` callable from `app/Modules/Core/AI/Config/menu.php`
- [ ] Convert `commerce.marketplace` to a pure container in `app/Modules/Commerce/Marketplace/Config/menu.php`; move its current route to a new child `commerce.marketplace.ebay`
- [ ] Confirm `MenuBuilder` empty-container hiding still works for the (briefly) empty `commerce.marketplace` if its children get permission-filtered out

### Phase 6 — Surface menu source metadata

**Goal:** make extension-vs-core source identifiable on demand without polluting the live sidebar.

- [ ] Add `?string $sourceModule` and `?string $sourceFile` (or a single `?array $source`) to `MenuItem`; populate from the `_source` metadata in `MenuItem::fromArray`
- [ ] Update `MenuRegistry::persist()` to include the source fields in the serialized cache shape; bump the cache key
- [ ] Add a Menu Inspector page parented under `admin.system.diagnostics` (route, controller/Livewire component, Blade view) that lists every registered item — id, label, parent, source module, source file, permission, condition, computed visibility for the current user — with filtering by source module
- [ ] Gate the Inspector behind a new permission (e.g., `admin.system.menu-inspector.view`)
- [ ] Add an `APP_DEBUG`-gated `title=` tooltip on rendered menu items showing source file and module (template-only change in the menu Blade partial)
- [ ] Document the diagnostic flow in the convention comment at the top of `app/Base/Menu/Config/menu.php`

### Phase 7 — Naming normalization (last on purpose)

**Goal:** apply the codified single convention to every item ID and every permission key.

**Pre-flight**

- [ ] Run a single grep sweep enumerating every reference to each target string (item IDs and permission keys); save the file list as scratch input for the rename batches
- [ ] Confirm no item IDs are referenced outside menu configs by anything other than `parent` fields

**Item ID renames (by location)**

- [ ] `app/Base/Menu/Config/menu.php` — `system` → `admin.system`; subgroup IDs already authored as `admin.system.*` in Phase 3
- [ ] `app/Base/System/Config/menu.php` — `system.info` → `admin.system.info`; `system.localization` → `admin.system.localization`; `system.ui-reference` → `admin.system.ui-reference`; `system.test-transport` → `admin.system.test-transport`
- [ ] `app/Base/Schedule/Config/menu.php` — `system.scheduled-tasks` → `admin.system.scheduled-task`
- [ ] `app/Base/Log/Config/menu.php` — `system.logs` → `admin.system.log`
- [ ] `app/Base/Queue/Config/menu.php` — `system.failed-jobs` → `admin.system.failed-job`; `system.job-batches` → `admin.system.job-batch`
- [ ] `app/Base/Integration/Config/menu.php` — `system.integration-outbound-exchanges` → `admin.system.outbound-exchange`
- [ ] `app/Base/Settings/Config/menu.php` — `system.settings` → `admin.system.setting`
- [ ] `app/Base/Session/Config/menu.php` — `system.sessions` → `admin.system.session`
- [ ] `app/Base/Database/Config/menu.php` — `system.database-tables` → `admin.system.database-table`; `system.database-queries` → `admin.system.database-query`; `system.database-backups` → `admin.system.database-backup`
- [ ] `app/Base/Cache/Config/menu.php` — `system.cache` → `admin.system.cache`
- [ ] `app/Base/Audit/Config/menu.php` — `audit` → `admin.audit`; `audit.mutations` → `admin.audit.mutation`; `audit.actions` → `admin.audit.action`
- [ ] `app/Base/Authz/Config/menu.php` — `authz` → `admin.authz`; `authz.capabilities` → `admin.authz.capability`; `authz.roles` → `admin.authz.role`; `authz.principal-roles` → `admin.authz.principal-role`; `authz.principal-capabilities` → `admin.authz.principal-capability`; `authz.decision-logs` → `admin.authz.decision-log`
- [ ] `app/Base/Workflow/Config/menu.php` — `admin.workflows` → `admin.workflow`
- [ ] `app/Modules/Core/User/Config/menu.php` — `admin.users` → `admin.user`
- [ ] `app/Modules/Core/Address/Config/menu.php` — `admin.addresses` → `admin.address`
- [ ] `app/Modules/Core/Geonames/Config/menu.php` — `admin.geonames.countries` → `admin.geonames.country`; `admin.geonames.admin1` → `admin.geonames.admin1-division`; `admin.geonames.postcodes` → `admin.geonames.postcode`
- [ ] `app/Modules/Core/AI/Config/menu.php` — `ai` → `admin.ai`; `ai.lara` → `admin.ai.lara`; `ai.task-models` → `admin.ai.task-model`; `ai.providers` → `admin.ai.provider`; `ai.tools` → `admin.ai.tool`; `ai.pricing-overrides` → `admin.ai.pricing-override`; `ai.control-plane` → `admin.ai.control-plane`
- [ ] `app/Modules/Core/Employee/Config/menu.php` — `admin.employees` → `people.employee`; `admin.employee-types` → `people.employee-type`
- [ ] `app/Modules/Core/Company/Config/menu.php` — `admin.companies` → `admin.company`; `admin.companies.legal-entity-types` → `admin.company.legal-entity-type`; `admin.companies.department-types` → `admin.company.department-type`
- [ ] `app/Modules/Core/Quality/Config/menu.php` — `quality` → `operations.quality`; `quality.ncr` → `operations.quality.ncr`; `quality.scar` → `operations.quality.scar`
- [ ] `app/Modules/Operation/IT/Config/menu.php` — `it` → `operations.it`; `it.tickets` → `operations.it.ticket`
- [ ] `app/Modules/Commerce/Inventory/Config/menu.php` — `commerce.inventory.items` → `commerce.inventory.item`
- [ ] `app/Modules/Commerce/Marketplace/Config/menu.php` — `commerce.marketplace.ebay_settings` → `commerce.marketplace.ebay-setting`; the new ebay leaf added in Phase 5 is authored directly as `commerce.marketplace.ebay`
- [ ] `extensions/ham/auto-parts/Config/menu.php` — `ham.auto_parts.settings` → `commerce.ham-auto-parts.setting`; `ham.auto_parts.insights.sold_this_month` → `commerce.ham-auto-parts.insights.sold-this-month`; `ham.auto_parts.insights.top_earners_last_90_days` → `commerce.ham-auto-parts.insights.top-earners-last-90-days`; `ham.auto_parts.insights.recent_sales` → `commerce.ham-auto-parts.insights.recent-sale`; `ham.auto_parts.insights.sales_by_category` → `commerce.ham-auto-parts.insights.sales-by-category`; `ham.auto_parts.insights.listed_without_sale` → `commerce.ham-auto-parts.insights.listed-without-sale`

After each ID rename, update the corresponding `parent` field on any child items referencing the old ID.

**Permission key renames (`<id>.<action>`)**

- [ ] `admin.system_info.view` → `admin.system.info.view`
- [ ] `admin.system_localization.manage` → `admin.system.localization.manage`
- [ ] `admin.system_ui_reference.view` → `admin.system.ui-reference.view`
- [ ] `admin.system_transport_test.view` → `admin.system.test-transport.view`
- [ ] `admin.system_log.list` → `admin.system.log.list`
- [ ] `admin.system_session.list` → `admin.system.session.list`
- [ ] `admin.system_cache.view` → `admin.system.cache.view`
- [ ] `admin.system_failed_job.list` → `admin.system.failed-job.list`
- [ ] `admin.system_job_batch.list` → `admin.system.job-batch.list`
- [ ] `admin.system_scheduled_task.list` → `admin.system.scheduled-task.list`
- [ ] `admin.system_table.list` → `admin.system.database-table.list`
- [ ] `admin.backup.list` → `admin.system.database-backup.list`
- [ ] `admin.integration_exchange.list` → `admin.system.outbound-exchange.list`
- [ ] `admin.audit_log.list` → `admin.audit.log.list` (the menu item permission); audit-action permission becomes `admin.audit.action.list`
- [ ] `admin.role.list` → `admin.authz.role.list`
- [ ] `admin.capability.list` → `admin.authz.capability.list`
- [ ] `admin.principal_role.list` → `admin.authz.principal-role.list`
- [ ] `admin.principal_capability.list` → `admin.authz.principal-capability.list`
- [ ] `admin.decision_log.list` → `admin.authz.decision-log.list`
- [ ] `admin.ai_lara.manage` → `admin.ai.lara.manage`
- [ ] `admin.ai_task_model.manage` → `admin.ai.task-model.manage`
- [ ] `admin.ai_provider.manage` → `admin.ai.provider.manage`
- [ ] `admin.ai_tool.manage` → `admin.ai.tool.manage`
- [ ] `admin.ai_pricing_override.manage` → `admin.ai.pricing-override.manage`
- [ ] `admin.ai_control_plane.view` → `admin.ai.control-plane.view`
- [ ] `admin.settings.manage` → `admin.system.setting.manage`
- [ ] `core.employee_type.list` → `core.employee-type.list` (or migrate to `people.employee-type.list`; pick once on permission domain — see note below)
- [ ] `workflow.process.manage` — already conforming under `workflow.*`; rename only if the convention requires it to live under its bucket (`admin.workflow.manage`); decide during Phase 7 review

**Permission-domain alignment note.** Today some permissions use a domain prefix that does not match the menu bucket (`core.employee.list` lives under what will become `people.employee` in the menu). Two options: (a) rename permissions to match the new bucket — `people.employee.list` — for full consistency, but at higher referencing-file cost; (b) keep the `core.*` permissions as-is because they encode codebase-domain (`app/Modules/Core/`), while the menu encodes navigation-domain. Recommendation: (a) — the convention is "permission = id + action," and the value of the convention is that you can derive one from the other. Diverging the prefixes defeats it.

**Verification**

- [ ] `php artisan route:list` — no broken route references
- [ ] Test suite green
- [ ] Spot-check `@can` gates in Blade by walking representative pages with an account that has the renamed permissions granted
- [ ] `php artisan cache:clear` between rename batches if the registry cache holds stale entries

### Phase 8 — Verification

**Goal:** end-to-end confidence after all phases.

- [ ] `php artisan cache:clear`
- [ ] `php artisan route:list` — clean
- [ ] Test suite — green
- [ ] Visual: load `/admin`, walk the entire tree (Admin/Operations/Commerce/People), verify every leaf reaches its target page
- [ ] Active highlighting works for nested routes (e.g., navigating to a Companies edit page highlights `people.company`)
- [ ] Menu Inspector page lists every item with correct source attribution; filter by source module shows only Ham items when `auto-parts` is selected
- [ ] With `APP_DEBUG=true`, hover tooltips show the source file path

## Risks and Notes

- **Permission-key drift between this rename and external consumers.** If any Slack alert template, exported audit log filter, or seeded role bundle refers to the old underscore-style permission strings literally, those will silently break. The pre-flight grep covers in-repo references; out-of-repo consumers (saved searches, role assignments in seeded data, downstream reports) need a manual spot-check.
- **Cache invalidation.** Both the registry (`blb.menu.registry`) and the per-route built tree (`blb.menu.tree.{route}`) need clearing once Phase 2 changes the persisted shape and again when Phase 6 adds source fields. A single `php artisan cache:clear` covers both, but production deploys must include this step.
- **Item-ID-as-key churn.** Every item ID in Phase 7 changes form. Item IDs are referenced internally by `parent` fields and by `MenuRegistry`'s collection keys. Pre-flight grep confirms whether any consumer outside the Menu module references an ID literally (unlikely but verify). Each ID rename pairs with a `parent` field update on its children — handled per-file in the Phase 7 checklist.
- **`business`→`operations` is a breaking ID change.** Mitigated by being a single rename; only Quality and IT reference it as a parent.
- **Domain-scoped Companies views are expected; master duplication is forbidden.** Per D10, audience-scoped filtered views in workflow buckets (`commerce.customer`, `procurement.supplier`, `people.licensee-company`) are the intended pattern. What's forbidden is duplicating the master surface itself or letting filtered views drift apart. A shared base controller / Livewire component for the Companies-listing rendering is strongly recommended so the rendering code is one place; subclasses supply the relationship-type filter and any audience-specific column choices.
- **Authz is modestly more nuanced under D10.** Each audience-scoped surface names its own permission, and the relationship-aware policy in `CompanyPolicy::view($user, Company $company)` enforces row-level scoping based on the target row's relationships. The Authz module already has the primitives (capabilities + roles + decision logs); the new work is in role bundles and policy rules, not in the framework itself. Risk to watch: granting an audience-scoped permission must not implicitly grant master access; route entry must not bypass the policy. Both are policy-level concerns, not menu-level.
- **Empty container hiding.** `MenuBuilder::buildTree` already drops containers with no visible children after permission filtering. The new `admin.system.*` containers, the converted `commerce.marketplace` container, and the `commerce.ham-auto-parts` container all rely on this behavior — verified in code, but worth a visual confirmation per phase.
- **Phase 7 churn surface.** ~25 referencing files plus the menu configs themselves, expanded by ID-format renames. The phase ordering (7 last) means every other phase has already settled when these renames land, isolating any miss.
