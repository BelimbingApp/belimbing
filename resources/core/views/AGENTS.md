# UI Authoring Rules

Shared UI authoring rules for Blade, Livewire 4, Tailwind CSS 4, and Alpine.js 3 — Core `resources/core/views/`, domain module `Views/`, and extension `Views/`.

Authority stack:
- `DESIGN.md`: product intent
- `resources/core/css/tokens.css`: token values
- `Administration > System > UI Reference`: rendered behavior
- **This file**: authoring rules

## View Placement

`resources/core/views/` is only for Base/Core presentation: shell layouts, shared components, auth/profile/admin pages, and reusable UI. Non-Core module views live under the module `Views/` directory and use module view namespaces.

Core Livewire folders mirror navigation:

| Folder | Use |
|--------|-----|
| `admin/` | Administration pages |
| `auth/` | Guest auth flow |
| `it/` | Business Operations -> IT |
| `profile/` | Current-user settings |

Rules:
- Administration pages -> `livewire/admin/{feature}/`
- Business Operations pages -> top-level business domain folder
- Guest pages -> `livewire/auth/`
- Self-service profile pages -> `livewire/profile/`
- `view('livewire.admin.users.index')` must match `livewire/admin/users/index.blade.php`
- Non-Core modules use namespaced views such as `owner-module::livewire.dashboard.index`

## Core Rules

- Reuse `x-ui.*` and `x-icon` before writing local markup.
- Extract/extend a component when a UI pattern appears 2+ times.
- Use semantic tokens only; no raw palette classes or hex in Blade.
- Keep layouts responsive; avoid fixed widths that break narrow screens.
- Use stable control IDs; do not rely on generated/random IDs in callers.
- All user-facing strings use `__()`, `@lang`, or `trans_choice()`.
- Translate whole sentences with placeholders; do not concatenate fragments.
- Animate only `transform` and `opacity`; respect reduced motion.
- Prefer skeletons/inline status over indefinite spinners.
- Paginate unbounded lists; use `wire:key` in lists.
- Prefer `wire:model.live.debounce` over unthrottled updates.
- Keep initial page HTML around 150 KB; measure with `php artisan blb:perf:page-weights`.
- No proprietary icon sets, hosted fonts, analytics scripts, or SaaS widgets.

## Tokens

Use these semantic roles in Blade:

| Role | Tokens |
|------|--------|
| Surfaces | `bg-surface-page`, `bg-surface-card`, `bg-surface-subtle`, `bg-surface-sidebar`, `bg-surface-bar` |
| Borders | `border-border-default`, `border-border-input` |
| Text | `text-ink`, `text-muted`, `text-accent` |
| Accent | `bg-accent`, `hover:bg-accent-hover`, `text-accent-on` |
| Status | `text-status-*`, `bg-status-*-subtle`, `border-status-*-border` |

Add a token in `tokens.css` when a new UI role is needed.

## Spacing And Type

- Use semantic spacing: `p-card-inner`, `py-table-cell-y`, `px-table-cell-x`, `space-y-section-gap`, `px-input-x`, `py-input-y`.
- Form controls use `px-input-x` and `py-input-y`; never hardcode `px-3`, `py-1.5`, or `py-2` on controls.
- Font: `font-sans` only.
- Headings: `font-medium tracking-tight`; use medium over bold.
- Labels: `text-[11px] uppercase tracking-wider font-semibold text-muted`.
- Data: `text-sm font-normal text-ink`; secondary text `text-muted`; tables use `tabular-nums`.

## Blade Preamble

Only add `@var` when it adds real type context:
- Livewire views: annotate `$this` with the Livewire class.
- Blade components/views with `@props`: annotate only complex/non-obvious types.
- Do not add placeholder annotations such as `/** @var $this */`.

## Component Inventory

Canonical primitives in `resources/core/views/components/ui/`. **Always use these instead of raw markup.** Grouping mirrors UI Reference.

### Foundations

| Component | Use |
|-----------|-----|
| `x-icon` | Canonical icon registry; no raw SVG for common icons |
| `x-ui.card` | Bordered surface |
| `x-ui.page-header` | Page title, actions, optional help |
| `x-ui.help` / `x-ui.field-help` | Contextual help |
| `x-ui.datetime` | Locale/timezone-aware date/time |

### Inputs

| Component | Use |
|-----------|-----|
| `x-ui.input` / `x-ui.integer-input` / `x-ui.secret-input` | Text, number, secret fields |
| `x-ui.textarea` | Multi-line text |
| `x-ui.select` / `x-ui.segmented-control` | Short option lists |
| `x-ui.combobox` | Searchable lookup |
| `x-ui.country-combobox` / `x-ui.currency-combobox` | GeoNames-backed pickers |
| `x-ui.checkbox` / `x-ui.radio` | Boolean or single-choice options |
| `x-ui.search-input` | Search fields |
| `x-ui.filter-bar` | Responsive search plus one or more list filters |
| `x-ui.time-input` | Time entry |

### Interaction Patterns

| Component | Use |
|-----------|-----|
| `x-ui.edit-in-place.*` | Read-first field editors |
| `x-ui.acknowledge-input` | Typed destructive confirmation |
| `x-ui.disclosure` | Collapsible sections |
| `x-ui.template-picker` | Template selection |

### Feedback

| Component | Use |
|-----------|-----|
| `x-ui.alert` | Persistent page/form feedback |
| `x-ui.session-flash` | Post-redirect `success`/`error` flash |
| `x-ui.notification-hub` | Same-page `notify` events |
| `x-ui.flash-stack` | Notification stack positioning |

### Actions

| Component | Use |
|-----------|-----|
| `x-ui.button` | All buttons and button-weight links |
| `x-ui.link` / `x-ui.link-group` | Text links; owns glyph/target/rel. Group 2+ adjacent links with a divider |
| `x-ui.icon-action` / `x-ui.icon-action-group` | Compact icon actions |

### Navigation

| Component | Use |
|-----------|-----|
| `x-ui.tabs` / `x-ui.tab` | Page-level tabs |
| `x-ui.navigation-menu` | Menu tree rendering |

### Overlays

| Component | Use |
|-----------|-----|
| `x-ui.modal` | Dialogs |
| `x-ui.inspector-drawer` / `x-ui.inspector-default-width-button` | Inspector drawer workflow |

### Data Display

| Component | Use |
|-----------|-----|
| `x-ui.badge` | Status/metadata chips |
| `x-ui.table` | Application table shell |
| `x-ui.th` / `x-ui.sortable-th` | Table headers |
| `x-ui.record-history` | Audit/history summaries |
| `x-ui.stat-strip` + `x-ui.stat` | Key-figure strips: uppercase label, large right-aligned value, small sub-line, hairline dividers |

### Composite / Shell

| Component | Use |
|-----------|-----|
| `x-ui.side-panel-layout` | Resizable side-panel pages |
| `x-ui.sidebar` / `x-ui.user-menu` | Core shell only |
| `x-ui.catalog-section` | UI Reference/catalog section copy |

If a primitive is missing, create it under `components/ui/` with `@props`, `$attributes->class(...)`, semantic tokens, and a small API.

## Tables

- Use `x-ui.table` for application tables.
- Use `x-ui.sortable-th` for sortable headers; use `x-ui.th` for static headers.
- Sort useful indexed or already-query-backed columns by default, especially names, dates, statuses, and numeric totals; skip tiny static tables where order is obvious and sort state adds noise.
- Caller owns sorting, pagination, row identity, links, inline editing, actions, and domain formatting.
- Prefer explicit caller-owned `<tr>/<td>` markup.
- Specialized non-application tables can stay local.

## Select Vs Combobox

- Use `x-ui.select` for short stable lists.
- Use `x-ui.combobox` for lists with more than 8 options, long labels, or code/name search.
- Use `search-url` for large lookups.

## Link Dictionary

Things that move the user are links. Things that mutate data are buttons.

| Behavior | Element | `x-ui.link kind` | Glyph | Target / rel |
|----------|---------|------------------|-------|--------------|
| In-app same tab | `<a wire:navigate>` | `internal` | none | none |
| In-app forced new tab | `<a>` | `new-tab` | trailing `heroicon-o-arrow-top-right-on-square` | `_blank` / `noopener` |
| In-page anchor | `<a>` | `anchor` | leading `heroicon-o-link` | none |
| External site | `<a>` | `external` | trailing `heroicon-o-arrow-top-right-on-square` | `_blank` / `noopener noreferrer` |
| Download | `<a download>` | `download` | leading `heroicon-o-arrow-down-tray` | none |
| Copy | `<button>` | n/a | `heroicon-o-clipboard` | n/a |
| Modal/dialog | `<button>` | n/a | optional `heroicon-o-arrows-pointing-out` | n/a |
| Drawer/slide-over | `<button>` | n/a | trailing `heroicon-o-dock-right` | n/a |
| Mutation | `<button>` | n/a | domain glyph | n/a |
| Compact open (card/widget header) | `x-ui.icon-action` | n/a | `heroicon-m-arrow-right` | `wire:navigate` |

Rules:
- Use `x-ui.link`; do not hand-write `target`, `rel`, or affordance icons for text links.
- Button-weight links use `x-ui.button as="a"` and the same glyph/rel contract.
- External and forced-new-tab both use the box-arrow glyph.
- Copy always uses clipboard, never box-arrow.
- Force new tabs only when losing current state is costly or target is side-reference.
- Use the compact open action (`x-ui.icon-action` with the arrow glyph) only where space is tight and the destination is already named by the surrounding heading — a dashboard widget's card title, not standalone body text. Everywhere else, in-app navigation is `x-ui.link` with no glyph (see the internal-kind row above). `x-ui.widget-header` is the canonical consumer.

**Page-header actions: button weight is reserved for mutation and for the
one primary forward CTA.** A page typically has at most one of the latter
(e.g. "New Audit"). Everything else that just moves the user — Settings, a
related report, a sibling page — is `x-ui.link`, never
`x-ui.button as="a" variant="secondary/ghost"`. A secondary-variant button
sitting next to a real `wire:click` button looks equally "clickable to do
something" as the mutation beside it; only the link's lighter weight tells
the user which one is safe to click without consequence.

**Two or more `x-ui.link`s next to each other need `x-ui.link-group`.**
Same-color text links separated only by the actions slot's flex gap read as
one continuous phrase, not two links — the group's divider makes each
link's boundary unambiguous. Wrap them; do not add a manual gap/margin
instead.

## Read-First Detail Pages

- Show facts first: text, badges, links, compact summaries.
- Use `x-ui.edit-in-place.*` for independent low-risk fields.
- For combobox-backed facts, the displayed value opens the combobox.
- Use grouped inline editors for coupled fields needing Apply/Cancel.
- Use modal/full form for workflows, high-risk changes, permissions, associations, or cross-field validation.
- Livewire page owns draft state, validation, authorization, persistence, and side effects.
- Auditable detail pages expose mutation history with `x-ui.record-history`; do not make users infer recent changes from timestamps alone.
- Do not force create/setup/import/modal-entry flows into read-first mode.

## Form Control IDs

- Always pass explicit `id` to form controls: `x-ui.input`, `x-ui.select`, `x-ui.textarea`, `x-ui.checkbox`, `x-ui.radio`, etc.
- IDs should be stable and purpose-based, e.g. `employee-company`, `company-status`, `provider-is-active`.
- Visible labels must target that ID.

## Accessibility Checks

- `<dt>` / `<dd>` pairs live inside `<dl>`.
- Static headings/descriptors use `<p>` or `<span>`, not `<dt>` or `<label>`.
- Do not add `aria-label` when visible text already provides the accessible name.
- Extra accessible context must still include the visible text.
- Multiple `<nav>` regions need names.
- Icon-only and overlay-dismiss controls need names.
- Avoid ARIA roles on native elements unless implementing the full pattern.
- Before finishing Blade changes, scan for orphaned labels, mismatched names, redundant roles, and fake list/detail semantics.

## Final UI Scan

- Professional, clean, compact.
- No one-off colors, spacing, raw controls, or raw SVG icons.
- No text overflow or incoherent overlap on mobile/desktop.
- No nested cards or decorative page-section cards.
- No one-off `<style>` blocks in Blade; use Tailwind classes or core CSS.
- Prefer UI Reference examples before inventing a local pattern.
