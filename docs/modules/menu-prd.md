# Menu System — Consolidated PRD

**Document Type:** PRD (single source of truth)
**Status:** Consolidated v1
**Last Updated:** 2026-02-09
**Author:** BLB Team

---

## 1. Executive Summary

**What:** A dynamic, auto-discovered, hierarchical menu system where modules self-register navigation via convention. VS Code-inspired tree UI with cache-only storage.

**Why:** Replace hardcoded Blade sidebar to scale cleanly to 100+ items, eliminate merge conflicts, enable module isolation, and align with BLB's strategic programming philosophy.

---

## 2. Goals and Scope

### Phase Goals (Now)
- Auto-discovery of module and extension menus via convention
- Hierarchical tree with position-based ordering (sections: Administration, Business Operations)
- VS Code-inspired collapsible tree UI
- Cache-only storage with environment-aware caching (local = always fresh, production = cached)
- Artisan commands for cache management

### Non-Goals (Deferred)
- Permission-based visibility (add field to schema, but don't filter yet)
- Context switching (Business/Admin toggle) - may add later if menu exceeds 50+ items
- Drag-and-drop reordering
- `before`/`after` positioning
- Search/filter (add in Phase 2 if needed)
- Badges, favorites, AI panel

### Success Targets
- 100% of core modules use discovery; 0 hardcoded items
- Cached render < 10ms; discovery < 500ms
- ≤ 3 clicks to any item; search to item < 5 seconds (when search ships)

---

## 3. Users and Stories

### All Users (Employees, Admins)
- Navigate via collapsible tree; parent of active auto-expands
- Sections organize menu (Administration, Business Operations)
- Find items quickly in organized hierarchy

### Developers (Modules, Extensions)
- Define items in a `menu.php` with parent-child and position
- Items appear in correct order within menu tree
- Extend or override existing items (ID-based, last-wins)

---

## 4. Functional Requirements

### Registration and Discovery
- System scans menu definition files by convention:
  - `app/Base/{Component}/Config/menu.php`
  - `app/Core/{Module}/Config/menu.php`
  - `app/Domains/{Domain}/Config/menu.php` for a Domain anchor
  - `app/Domains/{Domain}/{Module}/Config/menu.php`
  - `app/Extensions/{Extension}/Config/menu.php` for an Extension anchor
  - `app/Extensions/{Extension}/{Module}/Config/menu.php`
- Discovery order is Base → Core → enabled Domains → Extensions.
- Discovery runs on cache miss; invalid files log warnings (non-fatal)

### Structure and Schema
- Each item has:
  - `id` (unique, e.g., `admin.geonames.postcodes`)
  - `label` (string or translation key)
  - Optional: `icon`, `route` or `url`, `parent`, `position` (integer, default: 1000), `permission` (string, ignored for now)
- ID naming: `{section}.{module}[.{submodule}...]` (e.g., `admin.modules.install` or `business.customers.list`)
- Duplicate IDs: last definition wins (enables extension override)
- Items without route are containers (toggle only)
- Items may have both route and children only if UX affordance is clear; default: container OR link

### Menu Organization
- Single unified menu with logical sections (Administration, Business Operations)
- Root-level containers organize related items
- Collapsed by default; expand on demand
- Expanded/collapsed state persisted locally (localStorage)

### Rendering and Interaction
- Collapsible tree; active item highlighted; parent chain auto-expands
- Optional search box filters visible items (P1)
- Responsive: desktop always visible; tablet collapsible; mobile overlay

### Validation and Safety
- Detect and fail-fast on circular parents
- Log and skip items with missing parents or invalid routes (graceful)

---

## 5. Non-Functional Requirements

### Performance
- Cached menu build/render < 10ms
- Discovery and cache rebuild < 500ms for typical repo
- Support 500+ items without UX degradation (search and collapse mitigate)

### Reliability and Ergonomics
- Discovery tolerant to partial errors; logs are actionable
- Simple local development: optional cache bypass in local

---

## 6. Architecture and Key Decisions

### Decisions (Confirmed)

**Build custom** (not third-party packages); align to BLB module structure and SeederRegistry precedent

**Position-based ordering only** (integer); suggested ranges:
- 0–99: core framework items
- 100–999: core modules
- 1000–9999: business modules
- 10000+: extensions

**Cache-only storage;** source of truth in code (`menu.php`); environment-aware caching

**VS Code-inspired collapsible tree UI**

**Single unified menu** with sections; defer context switching until menu exceeds 50+ items

**Blade + Alpine** for UI state; Livewire not required for menu shell

### Essential Technical Patterns

**Auto-Discovery Service:**
- Scans module and extension paths for `menu.php`
- Collects raw arrays; logs invalid files

**Registry:**
- Normalizes and validates items; merges with last-wins by ID
- Stores raw normalized items in cache (registry cache)

**Builder:**
- Resolves parent-child tree
- Sorts siblings by position; computes active and has-active-child flags
- Caches built tree

### Directory Conventions

**Framework code:**
- `app/Base/Menu/`: MenuServiceProvider, MenuRegistry, MenuBuilder, Services/MenuDiscoveryService
- Blade components: `resources/core/views/components/menu/{sidebar,tree,item}.blade.php`

---

## 7. UI/UX Specifications

### Sidebar Layout
- **Width:** 256px (fixed, left side)
- **Sections:** Menu Tree (scrollable), Quick actions (Settings, Logout), User profile
- **Interaction:** Click link navigates; click container toggles expand/collapse; chevron toggles
- **Accessibility:** Tree roles and ARIA; keyboard navigation (arrows, Enter/Space, Home/End)

### Menu Tree
- **Root containers:** Administration, Business Operations (collapsed by default)
- **Hierarchy:** Unlimited depth, practical max ~3 levels
- **Active item:** Highlighted with bg-primary/10, auto-expand parent chain
- **Collapsed state:** Persisted to localStorage

### Responsive Behavior
- **Desktop (lg+):** Sidebar always visible
- **Tablet (md):** Sidebar collapsible (toggle button in header)
- **Mobile (sm):** Hamburger triggers full-screen overlay with backdrop

### Visual Design
- **Layout:** Sidebar (256px) + Main content (flex-1)
- **Color tokens:** Base colors from daisyUI/MaryUI; active uses primary
- **Typography:** Menu items text-sm; active font-medium
- **Icons:** Heroicons outline (menu items w-4 h-4)
- **Animation:** Expand/collapse 200ms ease-out; hover 150ms
- **Indentation:** depth × 16px (max visible: 64px/4 levels, then scroll indent)

---

## 8. Caching Strategy

### Layers and Keys
- **Registry cache:** Raw normalized items (`blb.menu.registry`)
- **Built tree cache:** Resolved tree (`blb.menu.tree`)

### Environment-Aware Caching
- **Development (local):** Skip cache entirely; always discover fresh on page load
- **Production/Staging:** Use cache; require explicit invalidation

**Implementation:**
```php
if (app()->environment('local')) {
    // Always discover, no cache - instant feedback during development
    $items = $discovery->discover();
} else {
    // Use cache - performance in production
    $items = Cache::remember('menu.registry', fn() => $discovery->discover());
}
```

### Cache Invalidation
- **Production/Staging:** Deploy runs `php artisan cache:clear` (standard Laravel)
- **Auto-rebuild:** Next request after cache clear automatically discovers and rebuilds menu
- **Debug command (deferred):** `php artisan menu:list` to inspect discovered items

---

## 9. Menu Definition Format

**File:** the owning Base component, Core Module, Domain/Extension anchor, or Domain/Extension Module `Config/menu.php` path listed above.

**Structure (minimal example):**

```php
return [
    'items' => [
        [
            'id' => 'admin.geonames',
            'label' => 'Geonames',
            'icon' => 'heroicon-o-globe-alt',
            'position' => 100,
            'parent' => 'admin',  // Under Administration section
        ],
        [
            'id' => 'admin.geonames.postcodes',
            'label' => 'Postcodes',
            'route' => 'admin.geonames.postcodes.index',
            'parent' => 'admin.geonames',
            'position' => 20,
        ],
    ],
];
```

**Field Notes:**
- `label` accepts translation key or plain string
- `route` or `url` optional; container if neither provided
- `parent` typically references root sections (`admin` or `business`) or other menu items
- `permission` field may exist but is ignored until permission system ships (deferred)

**Root Sections (Framework-Provided):**
- `admin` - Administration section (modules, git, config, system)
- `business` - Business Operations section (customers, invoices, orders, etc.)

---

## 10. Implementation Phases

### Phase 1 (MVP) — S (1–2 days)
- Auto-discovery (modules + extensions), registry, validation (duplicate IDs, circular parents)
- Builder (position sort, parent-child tree, active chain)
- Environment-aware caching (local = no cache, production = cached; auto-rebuild on cache miss)
- Sidebar with collapsible tree, active highlighting
- Framework-provided root sections (Administration, Business Operations)
- **Deliverable:** Functional menu with Geonames example; refresh page = see changes in local

### Phase 2 (Extensions and Hardening) — M (1–2 days)
- Extension override tests
- Edge-case validations (missing parents/routes) with clear logs
- Persist expanded/collapsed state (localStorage)
- Auto-expand to active item on load
- **Deliverable:** Production-ready menu system

### Phase 3 (Polish - If Needed) — S-M (1–2 days)
- Search/filter (if menu exceeds 30+ items)
- Responsive mobile overlay
- Context switching (if menu exceeds 50+ items and clear Business/Admin split emerges)
- **Deliverable:** Enhanced UX based on actual usage patterns

---

## 11. Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Cache invalidation bugs | Simple, explicit commands; deploy hook; local bypass option |
| Over-nesting UX complexity | Recommend practical max ~3 levels; rely on search; review large trees |
| Alpine state drift | Keep UI state minimal; persist only expansion and context; avoid complex coupling |
| Scope creep | Adhere to non-goals; phase gates |

---

## 12. Alignment with BLB Strategy

- **Deep modules, simple interfaces:** Complex resolution hidden behind discovery/registry/builder; modules only write `menu.php`
- **Auto-discovery by convention:** Zero manual registration
- **YAGNI and KISS:** Position integers only; cache-only; Blade + Alpine; defer permissions and advanced UI
- **Strategic programming:** Invest in quality design now; enables scalable module ecosystem

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-01-20 | AI + Kiat | Initial multi-file draft |
| 1.0 | 2026-02-09 | AI + Kiat | Consolidated PRD; removed redundancy and YAGNI features |
