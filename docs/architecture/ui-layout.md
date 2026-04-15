# UI Layout Architecture

**Layout file:** `resources/core/views/components/layouts/app.blade.php`

## Layout Zones

The authenticated app shell is a vertical flex column (`h-screen overflow-hidden`). Below the top bar, a horizontal flex row holds the **sidebar**, **main content**, and (when Lara is open in docked desktop mode) an optional **right dock column**. Lara can also render as a **floating overlay** or **fullscreen layer** over the main column—see [Lara chat](#d-lara-chat). Five zones:

```
+---------------------------------------------------------------------+
| A. Top Bar  (h-7)                                                   |
|  [≡ Toggle]  Belimbing             [Timezone @auth]  [Theme]        |
+----------+-------------------------------------------+--------------+
| B.       | C. Main Content                           | D. Lara      |
| Sidebar  |   (flex-1, overflow-y-auto)               |   (docked    |
| drag-    |                                           |    desktop   |
| resizable|   Page / Livewire slot; optional in-main  |    only:     |
| icon-rail|   fullscreen Lara layer (see below)       |    resizable |
|          |                                           |    side      |
| [Pinned] |                                           |    panel)    |
| [-------]|                                           |              |
| [A-Z     |                                           |              |
|  menu    |                                           |              |
|  tree]   |                                           |              |
+----------+-------------------------------------------+--------------+
| E. Status Bar  (h-6)   [env] [debug] [warnings]   [Lara] [version]  |
+---------------------------------------------------------------------+

  Lara (D) placement depends on mode — not all at once:
  • Docked: column D (flex sibling of main), drag-resize from left edge
  • Overlay (desktop): fixed bottom-right card above status bar (outside the row)
  • Fullscreen (desktop sm+): covers zone C only (absolute inside <main>)
  • Mobile: fixed strip top-11 … bottom-6 (below A, above E)
```

### Shell Persistence

The app **layout shell** (zones A, B, E, plus Lara chrome when open) stays mounted; only the **main slot** (zone C) is replaced when navigating. This is achieved by:

- **Livewire navigation (`wire:navigate`).** Livewire fetches the new page and morphs the DOM under `<main>` while Alpine state on `<body>` and the sidebar survives.
- **URL update via `history.pushState`.** The browser URL reflects the current page without a full page load.
- **Client-side menu state.** Sidebar active item and pinned URLs update on navigation; the server supplies the next page’s Livewire HTML for the main column.

**Lara:** A single `<livewire:ai.chat />` instance lives in the layout. Alpine moves its root element between **teleport targets** (overlay, docked aside, mobile strip, or fullscreen container inside `<main>`) so conversation state survives navigation and mode switches without remounting the component.

### A. Top Bar

- **Component:** `<x-layouts.top-bar />`
- **Height:** `h-7`, fixed, `shrink-0`
- **Surface:** `bg-surface-bar`, bottom border
- **Left:** Sidebar toggle button (dispatches `toggle-sidebar`), app title "Belimbing"
- **Right (authenticated):** Timezone display mode control (persists via server + reload). **Right (all):** Dark/light theme toggle (persisted to `localStorage` on `theme`). Lara is **not** on the top bar; it is launched from the [Status Bar](#e-status-bar) (or keyboard shortcuts).

### B. Sidebar

- **Component:** `<x-menu.sidebar>` with `<x-menu.tree>` and `<x-menu.item>`
- **Surface:** `bg-surface-sidebar`, right border
- **Content:**
  - **Pinned section:** User-curated quick-access items (see Sidebar Menu below)
  - **Main menu:** Label-sorted hierarchical menu tree (see Sidebar Menu), scrollable (`overflow-y-auto`)
  - **Footer:** User avatar (initials on `bg-accent` circle), name, email, logout button; separated by `border-t`

#### Desktop: Drag-Resizable with Icon Rail Snap

The sidebar width is **continuously draggable** via a drag handle on its right edge:

- **Drag handle:** Invisible until hovered. Cursor changes to `col-resize`, subtle highlight on hover.
- **Width range:** `56px` (icon rail, `RAIL_WIDTH`) to `288px` (`MAX_WIDTH`), enforced while dragging.
- **Icon rail snap:** When dragged at or below the collapse threshold (`80px`, `COLLAPSE_THRESHOLD`), the sidebar snaps to the **icon rail** (`56px`) — icons only, no labels. In rail mode, pinned items show as icons and the main menu collapses to icons only.
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
- User-curated list of frequently used **pages**, keyed by **normalized URL** (not menu-item IDs). Pins can target any navigable route the user chooses to pin.
- **Drag-reorderable** within the pinned section (HTML5 drag-and-drop, Alpine handlers). Visual feedback: dragged item dims, accent-colored insertion line at drop target.
- **Pin action:** Pin control on navigable menu items (and page-level pin events) adds/removes by URL.
- **Storage:** Per-user rows in `user_pins` (`UserPin`), with `url_hash` for uniqueness. Mutations: `POST` routes named `pins.toggle` and `pins.reorder` (see `app/Modules/Core/User/Routes/web.php` — `api/pins/toggle`, `api/pins/reorder` under the authenticated group).
- **Optimistic UI:** Alpine state updates immediately on pin/unpin/reorder; fetch fires in background. Server response reconciles state. On failure, state rolls back (toggle) or keeps optimistic order (reorder).
- Pinned URLs also appear in their normal place in the main menu tree when that route exists there.
- Drag-reorder only available in expanded sidebar mode; rail mode shows pinned items as icons without reorder.

**Main menu** (below the divider):
- **Ordered by label** — `MenuBuilder` sorts items at each tree level with `mb_strtolower($label)` (see `app/Base/Menu/MenuBuilder.php`). Config `position` values are retained for compatibility but are **not** the primary sort key.
- **Not user-reorderable** — order follows the builder’s sort rules.
- Hierarchical tree with expand/collapse for child items.
- Active item highlighted based on current route.

### C. Main Content

- **CSS:** `relative flex-1 overflow-y-auto bg-surface-page px-1 py-2 sm:px-4 sm:py-1`
- **Content:** Livewire-navigated page content (`{{ $slot }}`). Only this column’s primary page body swaps on navigation.
- **Lara fullscreen:** When Lara is open in fullscreen mode on desktop (`sm+`), an absolutely positioned layer (`inset-0 z-50`) sits **inside** `<main>` and receives the chat teleport target—main chrome remains, but the page content is covered until the user exits fullscreen.
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

### D. Lara chat

- **Component:** `<livewire:ai.chat />` (single instance; Alpine moves it between targets — see Shell Persistence).
- **Trigger:** Status Bar Lara control when Lara is activated (`$dispatch('open-agent-chat')`), or `Ctrl+K` / `Cmd+K` to toggle. If Lara is not activated, the status bar links to setup instead.
- **Auth:** Targets and shortcuts apply to authenticated users only (`@auth` in layout).
- **Persistent:** Conversation state is kept in the Livewire component while the shell layout stays mounted.

#### Display modes (desktop, `sm` and up)

Modes are mutually exclusive (stored in Alpine + `localStorage`):

| Mode | Behavior |
|------|----------|
| **Overlay** (default) | `fixed right-3 sm:right-4 bottom-8 z-50` card above the status bar. Size `w-[min(56rem,calc(100vw-2rem))] h-[min(80vh,46rem)]`, `bg-surface-card`, rounded-2xl, shadow. |
| **Docked** | Flex column **right of main**: `border-l`, `bg-surface-card`, width from `laraDockWidth` (drag the **left** edge to resize). Min `320px` (`DOCK_MIN`); max bounded by `DOCK_MAX` (`Math.floor(window.innerWidth * 0.6)` at layout init). |
| **Fullscreen** | Covers only the **main** region (`absolute inset-0` inside `<main>`, desktop `hidden sm:block`), not the sidebar or status bar. |

- **Toggle overlay ↔ docked:** `Ctrl+Shift+K` / `Cmd+Shift+K` (`toggle-agent-chat-mode`); exiting fullscreen first if needed.
- **Toggle fullscreen:** `Ctrl+Shift+F` / `Cmd+Shift+F` (`toggle-agent-chat-fullscreen`).

#### Mobile (viewport below `sm` / 640px)

- **Behavior:** Fixed strip `inset-x-0 top-11 bottom-6 z-50` — between top bar and status bar, full width.
- **Dismissal:** Close control or `Escape` (layout listens for `close-agent-chat` / escape). A floating card would be too cramped; this mode maximizes readable chat height.

#### `localStorage` keys (Lara)

| Key | Purpose |
|-----|---------|
| `agent-chat-1-open` | `1` / `0` — panel open |
| `agent-chat-1-mode` | `overlay` or `docked` |
| `agent-chat-1-fullscreen` | `1` / `0` — fullscreen within main |
| `agent-chat-1-dock-width` | Docked column width (px) |

### E. Status Bar

- **Component:** `<x-layouts.status-bar />`
- **Height:** `h-6`, fixed, `shrink-0`
- **Surface:** `bg-surface-bar`, top border
- **Left:** Environment name, debug mode indicator (when `APP_DEBUG`), then contextual warnings (examples):
  - **Impersonation** (`text-status-danger`): Active impersonation with stop action.
  - **Licensee not set** (`text-status-danger`): Link to licensee setup when missing.
  - **Locale** (`text-status-warning`, when authorized): Unconfirmed or inferred locale — link to localization admin.
- **Right:** Lara entry point — if Lara is activated, a button opens chat (`open-agent-chat`) and shows busy pulse when `laraBusy`; otherwise an **Activate Lara** link to setup. App version string.

## Alpine.js Application State

State lives on the `<body>` element via `x-data`:

| Variable | Type | Persisted | Purpose |
|----------|------|-----------|---------|
| `sidebarOpen` | `boolean` | No | Mobile sidebar drawer visibility |
| `sidebarWidth` | `number` | `localStorage` key `sidebarWidth` | Desktop expanded width; rail uses `RAIL_WIDTH` (56) |
| `sidebarRail` | `boolean` | `localStorage` key `sidebarRail` (`1`/`0`) | Icon-rail vs expanded |
| `_lastExpandedWidth` | `number` | No (mirrors persisted width) | Restores width when leaving rail |
| `laraChatOpen` | `boolean` | `agent-chat-1-open` | Lara panel open |
| `laraChatMode` | `string` | `agent-chat-1-mode` | `overlay` or `docked` |
| `laraChatFullscreen` | `boolean` | `agent-chat-1-fullscreen` | Fullscreen within `<main>` (desktop) |
| `laraPrefillPrompt` | `string\|null` | No | Optional prompt when opening from `open-agent-chat` |
| `laraBusy` | `boolean` | No | Driven by `agent-chat-busy` / `agent-chat-idle` |
| `laraDockWidth` | `number` | `agent-chat-1-dock-width` | Docked column width (default 448px) |
| `_laraDockDragging` | `boolean` | No | True while resizing dock |

State lives on the sidebar `<aside>` element via `x-data`:

| Variable | Type | Persisted | Purpose |
|----------|------|-----------|---------|
| `pins` | `array` | Server (`user_pins`, hydrated on load) | Ordered pinned entries `{ id, label, url, icon }` |
| `window.__pinBusy` | `boolean` | No | Prevents concurrent pin API calls (toggle/reorder) |
| `_dragIdx` | `number\|null` | No | Index of pinned item being dragged |
| `_dropIdx` | `number\|null` | No | Index of current drop target |
| `menuItemsFlat` | `object` | No | Flat map of navigable menu items by id (for pin-by-menu-id) |

### Custom Events

| Event | Source | Effect |
|-------|--------|--------|
| `toggle-sidebar` | Top Bar button | Snap between icon rail and last expanded width (desktop) or open/close drawer (mobile) |
| `open-agent-chat` | Status Bar / callers | Opens Lara; optional `detail.prompt` prefill via `openLaraChat` |
| `close-agent-chat` | Escape / close control | Sets `laraChatOpen` false |
| `toggle-agent-chat-mode` | `Ctrl+Shift+K` / `Cmd+Shift+K` | Switches `overlay` ↔ `docked` (clears fullscreen first) |
| `toggle-agent-chat-fullscreen` | `Ctrl+Shift+F` / `Cmd+Shift+F` | Toggles fullscreen-within-main |
| `agent-chat-opened` | After open | Dispatched with optional `detail.prompt` for focus / consumers |
| `agent-chat-busy` / `agent-chat-idle` | Chat Livewire | Sets `laraBusy` for status bar pulse |
| `agent-chat-execute-js` | Agent chat | Executes AI-injected JavaScript (`executeLaraJs`) |

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
- **Nests inside:** Main Content area (zone C)

## Semantic Surface Tokens

All zones use a shared design-token vocabulary defined in `resources/core/css/tokens.css`:

| Token | Applied To |
|-------|-----------|
| `bg-surface-page` | Main content background (zone C) |
| `bg-surface-bar` | Top Bar (A) and Status Bar (E) |
| `bg-surface-sidebar` | Sidebar (B) |
| `bg-surface-card` | Cards, modals, Lara surfaces (overlay / dock / fullscreen / mobile) |
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
