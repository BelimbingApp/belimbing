# UI Layout Architecture

> **Status:** Draft for discussion
> **Layout file:** `resources/core/views/components/layouts/app.blade.php`

## Layout Zones

The authenticated app shell is a vertical flex column (`h-screen overflow-hidden`) with a sidebar inset. Five zones:

```
+------------------------------------------------------------------+
| A. Top Bar  (h-11)                                               |
|  [≡ Toggle] [Belimbing]              [Theme Toggle] [Lara]       |
+----------+-------------------------------------------------------+
| B.       | C. Main Content                                       |
| Sidebar  |    (flex-1, overflow-y-auto, p-3 sm:p-4)              |
| drag-    |                                                       |
| resizable|    +------+------+------+                              |
| with     |    | Tab1 | Tab2 | Tab3 |  <-- page-level tabs        |
| icon-rail|    +------+------+------+------                        |
| snap     |    | Tab content                |                      |
|          |    |                             |                      |
| [Pinned] |    +----------------------------+                      |
| [-------]|                          +-------------------------+   |
| [A-Z     |                          | D. Lara Chat Panel      |   |
|  menu    |                          |    (floating overlay     |   |
|  tree]   |                          |     / mobile fullscreen) |   |
|       |--|                          +-------------------------+   |
|  drag |  |                                                        |
| handle|  |                                                        |
+-------+--+-------------------------------------------------------+
| E. Status Bar  (h-6)                                             |
|  [env] [debug] [impersonation] [warnings]   [Lara] [version]    |
+------------------------------------------------------------------+
```

### Shell Persistence

Zones A, B, D, and E are **persistent** -- they maintain their state when Main Content (zone C) changes. Only zone C swaps content. This is achieved by:

- **Livewire navigation (`wire:navigate`).** When a navigation event occurs, Livewire fetches the new page and morphs the DOM, replacing Main Content while preserving Alpine state in the shell.
- **URL update via `history.pushState`.** The browser URL reflects the current page without a full page load.
- **Client-side menu state.** Sidebar active item and pinned section update client-side on navigation. The server is only consulted for the Main Content payload.

This means the sidebar width/rail state, pinned items, Lara Chat conversation, Status Bar indicators, and any ephemeral Alpine state in the shell survive navigation.

### A. Top Bar

- **Component:** `<x-layouts.top-bar />`
- **Height:** `h-11`, fixed, `shrink-0`
- **Surface:** `bg-surface-bar`, bottom border
- **Left:** Sidebar toggle button (dispatches `toggle-sidebar`), app title "Belimbing"
- **Right:** Dark/light theme toggle (persisted to `localStorage`), Lara chat trigger

### B. Sidebar

- **Component:** `<x-menu.sidebar>` with `<x-menu.tree>` and `<x-menu.item>`
- **Surface:** `bg-surface-sidebar`, right border
- **Content:**
  - **Pinned section:** User-curated quick-access items (see Sidebar Menu below)
  - **Main menu:** Alphabetically ordered hierarchical menu tree, scrollable (`overflow-y-auto`)
  - **Footer:** User avatar (initials on `bg-accent` circle), name, email, logout button; separated by `border-t`

#### Desktop: Drag-Resizable with Icon Rail Snap

The sidebar width is **continuously draggable** via a drag handle on its right edge:

- **Drag handle:** Invisible until hovered. Cursor changes to `col-resize`, subtle highlight on hover.
- **Width range:** Minimum `w-14` (icon rail) to maximum `w-72` (or configurable).
- **Icon rail snap:** When dragged below a collapse threshold (~48px), the sidebar snaps to the **icon rail** -- a narrow strip showing only menu icons, no labels. In rail mode, pinned items show as icons and the main menu collapses to icons only.
- **Expand from rail:** Dragging the handle wider from the icon rail transitions to the full sidebar with labels once the threshold is crossed.
- **Toggle button:** The Top Bar toggle button still works -- it snaps between the icon rail and the last-used expanded width.
- **Persistence:** The sidebar width (or rail state) is saved to `localStorage` and restored on reload.

#### Mobile: Slide-Out Drawer

- **Behavior:** Fixed-width drawer (`w-56`), not drag-resizable.
- **Trigger:** Top Bar toggle button opens/closes the drawer.
- **Backdrop:** Semi-transparent overlay (`z-30`), tap to dismiss.
- **Position:** `z-40`, positioned between Top Bar and Status Bar.

#### Sidebar Menu

The menu has two sections:

**Pinned section** (top of sidebar, above the divider):
- User-curated list of frequently used items from any depth of the menu tree.
- **Drag-reorderable** within the pinned section (HTML5 drag-and-drop, Alpine handlers). Visual feedback: dragged item dims, accent-colored insertion line at drop target.
- **Pin action:** Pin icon button on any navigable menu item (visible on hover) to add/remove from pinned section.
- **Storage:** Ordered list of menu item IDs per user, stored server-side in `user_pinned_menu_items` table. Server-provided on page load; mutations via JSON API (`POST /api/pinned-menu-items/toggle`, `POST /api/pinned-menu-items/reorder`).
- **Optimistic UI:** Alpine state updates immediately on pin/unpin/reorder; fetch fires in background. Server response reconciles state. On failure, state rolls back (toggle) or keeps optimistic order (reorder).
- Items in the pinned section also remain in their normal position in the main menu.
- Drag-reorder only available in expanded sidebar mode; rail mode shows pinned items as icons without reorder.

**Main menu** (below the divider):
- **Alphabetically ordered** -- no manual position assignments.
- **Not reorderable** -- new items appear in their natural alphabetical position.
- Hierarchical tree with expand/collapse for child items.
- Active item highlighted based on current route.

### C. Main Content

- **CSS:** `flex-1 overflow-y-auto bg-surface-page p-3 sm:p-4`
- **Content:** Livewire-navigated page content. Only this zone changes on navigation.
- **URL sync:** `history.pushState` updates the URL to match the loaded page.
- **Typical page structure:** Pages use `<x-ui.page-header>` for title, description, actions, and help slot.

#### Page-Level Tabs

Complex models use tabs to group related attributes within a page:

- **Components:** `<x-ui.tabs>` (container) with `<x-ui.tab>` (panel) children.
- **Purpose:** Organize dense forms and detail views (e.g., a customer record with General, Addresses, Contacts, Financial, Notes tabs).
- **Behavior:** Client-side tab switching (Alpine.js). Active tab persisted in URL hash (`#tab-id`) via `history.replaceState` so it survives refresh. Responds to browser back/forward via `hashchange` listener.
- **Variants:** `underline` (default — bottom border with accent indicator) or `pill` (rounded background toggle).
- **ARIA:** Full WAI-ARIA Tabs Pattern — `role="tablist"` / `role="tab"` / `role="tabpanel"`, `aria-selected`, `aria-controls`, `aria-labelledby`. Keyboard navigation: Arrow Left/Right to cycle, Home/End for first/last.
- **Not application-level tabs.** These do not represent multiple open screens. Each page manages its own tabs independently.

```blade
<x-ui.tabs :tabs="[
    ['id' => 'general', 'label' => __('General')],
    ['id' => 'addresses', 'label' => __('Addresses')],
    ['id' => 'contacts', 'label' => __('Contacts'), 'icon' => 'heroicon-o-user-group'],
]" default="general">
    <x-ui.tab id="general">...</x-ui.tab>
    <x-ui.tab id="addresses">...</x-ui.tab>
    <x-ui.tab id="contacts">...</x-ui.tab>
</x-ui.tabs>
```

### D. Lara Chat Panel

- **Component:** `<livewire:ai.lara-chat-overlay />`
- **Trigger:** Top Bar Lara button or `Ctrl+K` / `Cmd+K`
- **Auth:** Only available to authenticated users
- **Persistent:** Maintains conversation state across Main Content navigation.

#### Desktop: Floating Overlay

- **Position:** `fixed right-3 sm:right-4 bottom-8 z-50` (above Status Bar)
- **Size:** `w-[min(56rem,calc(100vw-2rem))] h-[min(80vh,46rem)]`
- **Surface:** `bg-surface-card`, rounded-2xl, shadow-lg

#### Mobile: Full-Screen Takeover

- **Behavior:** Takes over the entire viewport below the Top Bar.
- **Dismissal:** Close button or back gesture.
- **Rationale:** A floating panel is unusable on small screens. Full-screen gives the chat adequate space for input and conversation history.

### E. Status Bar

- **Component:** `<x-layouts.status-bar />`
- **Height:** `h-6`, fixed, `shrink-0`
- **Surface:** `bg-surface-bar`, top border
- **Left:** Environment name, debug mode indicator, system warnings:
  - **Impersonation** (`text-status-danger`): Active impersonation session with "end impersonation" action. Higher severity than other warnings.
  - **Licensee not set** (`text-status-warning`): Configuration reminder.
  - **Lara not configured / not activated** (`text-status-warning`): Setup reminder.
- **Right:** Lara chat launcher, version string

## Alpine.js Application State

State lives on the `<body>` element via `x-data`:

| Variable | Type | Persisted | Purpose |
|----------|------|-----------|---------|
| `sidebarOpen` | `boolean` | No | Mobile sidebar drawer visibility |
| `sidebarWidth` | `number` | `localStorage` | Desktop sidebar width in pixels |
| `sidebarRail` | `boolean` | `localStorage` | Whether sidebar is in icon-rail mode |
| `laraChatOpen` | `boolean` | `localStorage` | Lara chat overlay visibility |
| `laraChatMode` | `string` | `localStorage` | Chat display mode (`overlay` or `docked`) |
| `laraChatFullscreen` | `boolean` | `localStorage` | Whether chat is in fullscreen mode |
| `laraDockWidth` | `number` | `localStorage` | Docked panel width in pixels |

State lives on the sidebar `<aside>` element via `x-data`:

| Variable | Type | Persisted | Purpose |
|----------|------|-----------|---------|
| `pinnedIds` | `string[]` | Server (DB) | Ordered list of pinned menu item IDs |
| `_pinBusy` | `boolean` | No | Prevents concurrent pin/unpin requests |
| `_dragIdx` | `number\|null` | No | Index of pinned item being dragged |
| `_dropIdx` | `number\|null` | No | Index of current drop target |
| `menuItemsFlat` | `object` | No | Flat map of navigable menu items (from server) |

### Custom Events

| Event | Source | Effect |
|-------|--------|--------|
| `toggle-sidebar` | Top Bar button | Snap between icon rail and last expanded width (desktop) or open/close drawer (mobile) |
| `open-agent-chat` | Top Bar Lara button | Opens chat overlay (desktop) or full-screen chat (mobile) |
| `close-agent-chat` | Escape key / close button | Closes chat overlay |
| `agent-chat-opened` | After chat opens | Signals overlay for focus management |
| `agent-chat-execute-js` | Agent chat | Executes AI-injected JavaScript |

## Auth Layouts

Three variants for unauthenticated pages:

| Variant | Component | Use Case |
|---------|-----------|----------|
| Simple | `<x-layouts.auth.simple>` | Default; centered minimal form |
| Card | `<x-layouts.auth.card>` | Form wrapped in a card |
| Split | `<x-layouts.auth.split>` | Two-column: branding + form |

`<x-layouts.auth>` delegates to `simple` by default.

## Settings Layout

- **Component:** `<x-settings.layout>`
- **Structure:** Sub-sidebar nav (Profile, Password, Appearance) + content area
- **Nests inside:** Main Content area (zone D)

## Semantic Surface Tokens

All zones use a shared design-token vocabulary defined in `resources/core/css/tokens.css`:

| Token | Applied To |
|-------|-----------|
| `bg-surface-page` | Main content background (zone D) |
| `bg-surface-bar` | Top Bar (B) and Status Bar (F) |
| `bg-surface-sidebar` | Sidebar (C) |
| `bg-surface-card` | Cards, modals, Lara Chat panel (E) |
| `bg-surface-subtle` | Hover states, secondary backgrounds |
| `border-border-default` | All structural dividers |
| `text-ink` | Primary text |
| `text-muted` | Secondary text, labels, Status Bar |
| `text-accent` | Links, actionable elements |

## No Volt -- Standard Livewire Components Only

BLB does not use Livewire Volt (single-file components). All pages use standard Livewire: a PHP component class in `app/` and a Blade template in `resources/`.

### Rationale

Volt embeds PHP logic inside Blade files, collapsing the controller/view boundary. This creates a problem for the core/licensee separation:

- **Logic in `resources/`** means the licensee cannot override presentation without inheriting or duplicating business logic.
- **No independent override path** -- a licensee wanting to change a page's markup must also adopt its PHP logic, or vice versa.
- **Agent convenience is irrelevant.** Volt's single-file format is a human ergonomic. Coding agents do not benefit from fewer files; they benefit from predictable, well-separated locations.

### Page Structure

Every page is a pair:

```
app/Http/Livewire/Dashboard.php                        # Logic
resources/core/views/livewire/dashboard.blade.php      # Presentation
```

Licensee overrides either side independently:

```
app/{Licensee}/Http/Livewire/Dashboard.php                  # Override logic
resources/extensions/{licensee}/views/livewire/dashboard.blade.php     # Override presentation
```

### What Is Preserved

Removing Volt loses nothing at runtime:

| Feature | Source | Affected by Volt removal? |
|---------|--------|---------------------------|
| HMR | Vite + `@tailwindcss/vite` | No -- watches Blade files regardless |
| Reactive properties | Livewire (`wire:model.live`, `#[Reactive]`) | No -- Livewire feature, not Volt |
| Alpine interactivity | Alpine.js (`x-data`, `@click`) | No -- pure frontend |
| Component reactivity | Livewire lifecycle | No -- standard Livewire components have full support |

## Core / Licensee Directory Separation

BLB enforces a clear physical boundary between framework-owned assets and licensee customizations at the `resources/` root level.

### Directory Structure

```
app/                                      # Business logic (PHP)
  Base/                                   #   BLB framework internals
  Modules/                                #   BLB modules
  Http/
    Livewire/                             #   BLB core Livewire page components
  {Licensee}/                             #   Licensee logic overrides
    Http/
      Livewire/                           #   Override page logic

resources/                                # Presentation only
  core/                                   # BLB framework-owned -- do not edit
    css/
      tokens.css                          #   Primitives + semantic tokens + dark mode
      components.css                      #   .nav-link, .divider, base layer
    views/
      components/                         #   Blade components (layouts, menu, ui)
      livewire/                           #   Livewire page templates
    js/
      app.js
  {licensee}/                             # Licensee-owned -- named by licensee
    css/
      tokens.css                          #   Override primitives, add palettes
      components.css                      #   Override/extend component classes
    views/
      components/                         #   Blade component overrides
      livewire/                           #   Page template overrides
    js/
  app.css                                 # Entry point: imports core/*, then {licensee}/*
```

**Override model:**

| What | BLB core location | Licensee override location | Mechanism |
|------|-------------------|---------------------------|-----------|
| Design tokens | `resources/core/css/tokens.css` | `resources/extensions/{licensee}/css/tokens.css` | CSS cascade |
| CSS components | `resources/core/css/components.css` | `resources/extensions/{licensee}/css/components.css` | CSS cascade |
| Blade components | `resources/core/views/components/` | `resources/extensions/{licensee}/views/components/` | View resolution order |
| Page templates | `resources/core/views/livewire/` | `resources/extensions/{licensee}/views/livewire/` | View resolution order |
| Page logic | `app/Http/Livewire/` | `app/{Licensee}/Http/Livewire/` | Class binding / service container |

### Configuration

The licensee directory name is configured via `.env`:

```env
VITE_THEME_DIR=acme
```

- **Vite side:** `vite.config.js` reads `import.meta.env.VITE_THEME_DIR` to resolve CSS/JS entry points and `@source` paths.
- **PHP side:** The licensee module config reads `env('VITE_THEME_DIR', 'custom')`, exposed via `config('theme.dir')`.
- **Default:** `custom` when unset.

### Load Order

`app.css` imports in strict order -- core first, licensee second:

```css
@import 'tailwindcss';

/* Core tokens and components */
@import './core/tokens.css';
@import './core/components.css';

/* Licensee overrides (loaded after core, wins by cascade) */
@import './{licensee}/tokens.css';
@import './{licensee}/components.css';
```

Licensee CSS overrides core via normal CSS cascade. Licensee Blade components override core via view resolution order (licensee path registered before core path).

### Design Principles

- **Ownership is visible.** A licensee can see at a glance what they've customized by looking at their directory.
- **Upgrades are safe.** BLB updates touch `core/` only. Licensee files are never overwritten.
- **Convention over configuration.** The structure mirrors core exactly -- same subdirectories, same filenames. A licensee only creates the files they want to override.

## Open Questions

- Should the Status Bar grow to include more operational indicators (e.g., queue health, active jobs)?
