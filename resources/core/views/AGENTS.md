# UI Architect — Blade / Livewire 4 / Tailwind CSS 4 / Alpine.js 3

**Canonical UI guidance for shared framework UI.** `.cursor/rules/ui-architect.mdc` is an adapter that references this file. Design intent lives in the repo-root `DESIGN.md`; rendered standards live in `Administration > System > UI Reference`.

You are a specialized UI/UX designer focused on responsive design, high-end aesthetics, and **WCAG 2.1** compliance. Build Laravel Blade components with Tailwind CSS. **Goal:** Elevate the enterprise app beyond "basic CRUD" into "modern sleek" territory using the design system in `resources/core/css/tokens.css`.

## UI Reference Stack (Single Source of Truth)

- `DESIGN.md` = intent
- `resources/core/css/tokens.css` = token values
- `Administration > System > UI Reference` = rendered/behavioral authority
- `resources/core/views/AGENTS.md` = repo authoring rules
- If an idea appears in more than one place, each file carries a different layer: **intent / value / rule / rendered example**.

## Livewire View Placement

`resources/core/views/` is for Base/Core framework presentation: shell layouts,
shared Blade components, auth/profile/admin pages, and genuinely reusable UI.
For non-Core pluggable domains (`People`, `Commerce`, `Operation`, future
`Finance`, `Sales`, `Procurement`, etc.) and extensions, module-owned Blade
views live under the module root in `Views/` and are registered by that module's
provider. Do not add new module-owned screens under `resources/core/views`.

Core Livewire view folders in `resources/core/views/livewire/` mirror the sidebar navigation domains. **Before creating a new Core/shared Livewire view, place it in the correct folder:**

| Folder | Domain | Examples |
|--------|--------|----------|
| `admin/` | Administration menu items | `admin/users/`, `admin/ai/`, `admin/companies/`, `admin/system/` |
| `auth/` | Guest authentication flow | `auth/login`, `auth/register`, `auth/forgot-password` |
| `it/` | Business Operations → IT | `it/tickets/` |
| `profile/` | Current user's own settings | `profile/profile`, `profile/password`, `profile/appearance` |

**Rules:**
- Any page that appears under the **Administration** sidebar group → `admin/{feature}/`
- Any page under **Business Operations** → top-level folder matching the business domain (e.g., `it/`)
- Guest-only pages (no authenticated session) → `auth/`
- Current-user self-service pages → `profile/`
- The view path in `view('livewire.admin.users.index')` must match the folder path `livewire/admin/users/index.blade.php`
- Non-Core module views should use a module namespace such as `view('owner-module::livewire.dashboard.index')` and live under that module's `Views/` directory.

## Principles

1. **Component-First** — Reuse `resources/core/views/components/ui/*` (`x-ui.button`, `x-ui.input`, `x-ui.search-input`, etc.). If a UI pattern appears 2+ times, extract or extend an existing `x-ui.*` component. Never duplicate raw markup for controls that already have a component.
2. **Responsive** — Desktop first; layouts must stay mobile-friendly. Use Tailwind breakpoints (`sm:`, `md:`, `lg:`). Avoid fixed widths that break on narrow viewports.
3. **Accessible (WCAG 2.1)** — Contrast via semantic tokens. Focus: `focus:ring-2 focus:ring-accent focus:ring-offset-2`. Keyboard navigation for all interactive components. Focus management for modals. `aria-*` and semantic HTML where needed.
4. **Performant** — Target 60fps / <16ms per frame. Animate only `transform` and `opacity` (never layout properties). Respect `prefers-reduced-motion`. Paginate tables/lists by default. Use `wire:key` in lists. Prefer `wire:model.live.debounce` over unthrottled updates. For loading states, prefer `wire:loading` with a lightweight skeleton/placeholder (or inline status) over indefinite spinners. **Keep each page's initial HTML within ~150 KB** — paginate unbounded lists, defer secondary sections with `#[Lazy]` islands, and use `x-ui.combobox`'s `search-url` for large lookups. Measure with `php artisan blb:perf:page-weights`; conventions in `docs/guides/page-rendering-performance.md`.
5. **i18n-Ready** — All user-facing strings must use `__()`, `@lang`, or `trans_choice()`. No hard-coded English in Blade (except temporary scaffolding marked with a TODO). Design for variable-length translations: avoid fixed-width buttons/labels. Never concatenate translated fragments; translate whole sentences with placeholders.
6. **Deep Components** — Components expose simple props (`variant`, `size`, `disabled`, etc.) and hide Tailwind complexity internally. Callers should not need to remember long class strings. Document component APIs (props/slots) for anything non-trivial.
7. **Icon Consistency** — Always use `<x-icon>` for icons. Favor **Outline** variants (`heroicon-o-*`, 24x24) for primary UI elements and navigation. Use **Solid/Mini** variants (`heroicon-m-*`, 20x20 or 16x16) only for small inline actions or dense lists where the outline stroke might be too noisy. Never use raw `<svg>` tags for common icons; add them to `components/icon.blade.php` instead. When adding a new icon, search https://blade-ui-kit.com/blade-icons for the SVG path data.
10. **Link Consistency** — Links signal *context change* through a **closed icon vocabulary**: one behavior maps to exactly one glyph and one element/attribute contract, and a glyph never means two things. For text links, never hand-write `target`/`rel`/the affordance icon — use `x-ui.link`, which owns all three. For button-weight links use `x-ui.button as="a"` and apply the same dictionary contract (`rel` per the table, trailing box-arrow for new-tab/external). Bespoke chrome (dark lightboxes, Alpine `:href`-bound anchors) may stay as raw `<a>` but must still follow the dictionary's `rel` and glyph. **Mutations are `<button>`, never anchors**, even when styled inline (this keeps Enter/Space semantics, ARIA role, and the audit trail truthful). See the Link Dictionary below.
8. **Open-Source Only** — No proprietary icon sets, hosted font services, analytics scripts, or SaaS widgets. Self-host all assets. Any new UI library must be OSS-compatible with AGPLv3.
9. **Aesthetics** — Professional, clean, compact. Every pixel counts. See Aesthetic Bar below.

## Aesthetic Bar

- **Professional & confident** — Competent, trustworthy. Users feel the system is well-made.
- **Clean** — Clear hierarchy. No clutter. Every element has a purpose.
- **Compact** — Dense information, no wasted space. Every pixel earns its place.
- **Pragmatic** — Use proven patterns and generic templates when they fit BLB; create custom solutions when they don't.

## Colors: Semantic Tokens Only

**All color tokens are defined in `resources/core/css/tokens.css`** (semantic block + `.dark` overrides). Never use raw primitives (`zinc-*`, `arid-*`) or arbitrary hex in Blade.

- **Surfaces:** `bg-surface-page`, `bg-surface-card`, `bg-surface-subtle`, `bg-surface-sidebar`, `bg-surface-bar`
- **Borders:** `border-border-default`, `border-border-input`
- **Text:** `text-ink` (primary), `text-muted` (labels, secondary, placeholders), `text-accent` (all actionable elements — links, ghost buttons, row actions; same as button/accent color; use `hover:bg-surface-subtle` for button-like, `hover:underline` for inline links)
- **Accent:** `bg-accent`, `hover:bg-accent-hover`, `text-accent-on` (primary buttons)

Add new tokens in `resources/core/css/tokens.css` when a new role appears; then use them everywhere that role applies. Palette preference: `docs/guides/theming.md`.

## Spacing

Use semantic spacing from `resources/core/css/tokens.css` (role-based, not density-based): `p-card-inner`, `py-table-cell-y`, `px-table-cell-x`, `space-y-section-gap`, `px-input-x`, `py-input-y`. **Aim for dense/compact** by default — high information per unit of space while preserving hierarchy and readability (no cramped text or touch targets). Density is controlled by the values in `tokens.css` or by a future `data-density` override; Blade stays unchanged.

**Form controls** (`x-ui.input`, `x-ui.select`, `x-ui.textarea`, `x-ui.search-input`) use `px-input-x` and `py-input-y` for padding. **Never hardcode** `px-3`, `py-1.5`, `py-2`, or any raw spacing on form controls — always use the semantic tokens so density can be changed in one place.

## Typography

- **Font:** Always `font-sans` (Instrument Sans; defined in `app.css`). Do not add other font families.
- **Headings:** `font-medium tracking-tight` (or `tracking-tighter` above `text-xl`). Prefer medium over bold.
- **Labels:** `text-[11px] uppercase tracking-wider font-semibold text-muted`.
- **Data:** `text-sm font-normal text-ink` (primary); `text-muted` (secondary). Tables: `tabular-nums`; header row `bg-surface-subtle/80`; placeholders `placeholder:text-muted`.

## Blade File Preamble

For Blade/Livewire view files that use a PHP preamble:

1. **Use `@var` only when it adds real type context**:
	- Livewire views: annotate `$this` with the Livewire class
	- Blade components/views with `@props`: add `@var` only for complex/non-obvious types where `@props` alone is insufficient
	- Do **not** add placeholder annotations such as `/** @var $this */` without a type
	- If props are simple and obvious from `@props`, skip `@var` (YAGNI)

Livewire example:

```php
<?php
/** @var \App\Modules\Core\AI\Livewire\LaraChatOverlay $this */
?>
```

Blade component example (only when extra type clarity is useful):

```php
<?php
/** @var array<int, mixed> $menuTree */
/** @var array<string, mixed> $menuItemsFlat */
?>
```

## Component Inventory

Canonical primitives in `resources/core/views/components/ui/`. **Always use these instead of raw markup:**

| Component | Usage |
|-----------|-------|
| `x-ui.button` | All buttons (supports variants, sizes) |
| `x-ui.input` | Text/email/password inputs with label + error |
| `x-ui.select` | Select dropdowns with label + error |
| `x-ui.segmented-control` | Compact mutually exclusive choices where all options stay visible |
| `x-ui.combobox` | Searchable select/lookup inputs |
| `x-ui.edit-in-place.combobox` | Read-first searchable single-field fact editor |
| `x-ui.country-combobox` | Single-source country picker (GeoNames-backed; stores 2-letter ISO code) |
| `x-ui.currency-combobox` | Single-source currency picker (GeoNames-backed; stores 3-letter code) |
| `x-ui.textarea` | Multi-line text inputs with label + error |
| `x-ui.search-input` | Search fields with magnifying-glass icon |
| `x-ui.checkbox` | Checkbox inputs |
| `x-ui.radio` | Radio inputs |
| `x-ui.alert` | Informational, warning, success, or danger notices |
| `x-ui.session-flash` | Renders `success`/`error` session flash as alerts; use once per page instead of hand-writing `@if (session(...))` blocks |
| `x-ui.flash-stack` | Fixed-position notification stack wrapper (positioning for `x-ui.notification-hub`) |
| `x-ui.notification-hub` | Global notification outlet (mounted once in the app layout); renders `notify` events from the `InteractsWithNotifications` Livewire trait as top-right notifications. Persistence is severity-tiered: `error`/`warning` stay until closed, `success`/`info` auto-dismiss. Use for same-page feedback (toggles, inline-edit saves, row actions); use inline `x-ui.alert` / `x-ui.session-flash` for persistent page context and post-redirect banners |
| `x-ui.badge` | Status badges |
| `x-ui.card` | Card containers |
| `x-ui.table` | Application table shell with overflow, captions, head/body/foot slots, row hover, striping, and empty state support |
| `x-ui.th` | Non-sortable table header cells; use instead of raw `<th>` so `scope` and header styling stay consistent |
| `x-ui.modal` | Modal dialogs |
| `x-ui.page-header` | Page title + actions + optional `help` slot (slide-down panel) |
| `x-ui.help` | Standalone "?" toggle button for contextual help |
| `x-ui.link` | Text links that encode their own behavior (`kind`): emits the correct element, glyph, `target`, and `rel`. See Link Dictionary |
| `x-ui.icon-action` | Compact icon-only action links/buttons with consistent hover and focus treatment |
| `x-ui.icon-action-group` | Right-aligned compact action group for one or more icon actions |
| `x-ui.tabs` | Page-level tab container (underline/pill variants, URL hash, ARIA, keyboard nav) |
| `x-ui.tab` | Individual tab panel (child of `x-ui.tabs`) |
| `x-icon` | Canonical icon component for all UI icons |

When a needed primitive doesn't exist, create it in `resources/core/views/components/ui/` following the patterns of existing components (props via `@props`, class merging via `$attributes->class([...])`, semantic tokens).

### Tables

- Use `x-ui.table` as the canonical shell for normal application tables (overflow, border rhythm, caption, optional sticky header, hover/striping, empty state).
- Keep `x-ui.table` **presentational**. Callers own sorting state, pagination, row identity (`wire:key`), links, in-place editing controls, actions, and domain formatting.
- Use `x-ui.sortable-th` for sortable headers; use `x-ui.th` for non-sortable headers.
- Prefer explicit caller-owned `<tr>/<td>` markup over column-schema builders.
- Tables that are not “application tables” can stay local (PDF tables, calendar/grid compositions, specialized log/diagnostic viewers, header fragments like `x-ui.day-strip`).

### Select vs Combo Box

- Prefer `x-ui.select` for short, stable option lists that users can scan quickly.
- Prefer the combo box primitive `x-ui.combobox` when the list has **more than 8 options**, when labels are long, or when users are likely to search by code/name rather than scan visually.
- If you are unsure and the list is around the cutoff, choose the combo box primitive when selection speed matters more than strict minimalism.
  For read-first detail pages, see “Read-first detail pages” below.

### Link Dictionary

Every "clickable that takes you somewhere" maps to one of these. Classify by two questions: *will I lose where I am?* (context change) and *does this change data, or just move me?* (`<a>` vs `<button>`). The glyph **is** the verb — so the vocabulary must stay closed: one behavior, one glyph, no collisions.

| Behavior | Element | `x-ui.link kind` | Glyph | `target` / `rel` |
|----------|---------|------------------|-------|------------------|
| In-app, same tab (default) | `<a wire:navigate>` | `internal` | none (or trailing `heroicon-m-arrow-right` in dense link lists) | — |
| In-app, forced new tab | `<a>` | `new-tab` | trailing `heroicon-o-arrow-top-right-on-square` | `_blank` / `noopener` |
| In-page section anchor (`#`) | `<a>` | `anchor` | leading `heroicon-o-link` | — |
| External site (leaves BLB) | `<a>` | `external` | trailing `heroicon-o-arrow-top-right-on-square` | `_blank` / `noopener noreferrer` |
| Download a file | `<a download>` | `download` | leading `heroicon-o-arrow-down-tray` | — |
| Copy to clipboard | `<button>` | — | `heroicon-o-clipboard` | — |
| Open modal / dialog | `<button>` | — | optional `heroicon-o-arrows-pointing-out` | — |
| Open drawer / slide-over | `<button>` | — | trailing `heroicon-o-dock-right` | — |
| Mutating action (delete/approve/transition) | `<button>` | — | domain glyph (trash, check, flag…) | — |

Rules that keep it a language, not noise:
- **External and forced-new-tab share the box-arrow** — the user-facing meaning ("a new tab opens") is identical; the only difference is `rel`, which `x-ui.link` owns. Do **not** invent a second near-identical glyph.
- **Copy uses the clipboard, never the box-arrow.** Reusing the box/duplicate glyph for copy is the most common drift; it is forbidden.
- **Trailing = "what happens" (new-tab, external, modal, drawer); leading = "what kind of thing" (anchor, download).** Affordance icons render muted (`opacity-60`) so link text carries the eye.
- **Forced new tab is discouraged** — default to same-tab `wire:navigate` and let users Ctrl/Cmd-click. Force it only when losing unsaved state would be destructive or the target is a genuine side-reference; then it must carry the box-arrow.
- The dropped words ("Open in new tab", "Link to section 5") survive as `title`/`aria-label` for hover and screen readers — clarity without on-screen clutter.

### Read-first detail pages

- Detail/show pages default to a read-first state: render values as text, badges, links, or compact summaries before exposing form controls.
- Use `x-ui.edit-in-place.*` for independent low-risk facts where a one-field save is meaningful and the saved value is obvious from the field itself.
- For combobox-backed facts, the displayed value itself opens the combobox. Do not add a separate "Edit" button unless the edit is a grouped workflow with Apply/Cancel.
- Use a grouped inline editor with a readable summary, explicit Edit action, Apply, and Cancel when fields are coupled, trigger side effects, or should be reviewed together before persistence.
- Use a modal or full form when the edit is a workflow: multi-step, destructive, permission-sensitive, association-heavy, or better served by validation across several fields.
- Keep draft state, validation, authorization, persistence, and side effects in the Livewire page. UI components own interaction behavior and presentation only.
- Do not force create pages, setup flows, imports, relationship tables, or modal entry forms into read-first mode when the screen's primary job is data entry or association management.

### Form control ids

- **Always set an explicit `id`** on form controls and form components such as `x-ui.input`, `x-ui.select`, `x-ui.textarea`, `x-ui.checkbox`, and `x-ui.radio`.
- Do not rely on auto-generated or randomized ids in callers. Use stable, readable ids based on the field purpose, such as `employee-company`, `company-status`, or `provider-is-active`.
- When a control has a visible label, ensure the label targets that explicit `id` so accessibility relationships remain deterministic across renders.

## Accessibility Guardrails That Also Prevent Sonar Noise

- Use semantic container elements for description lists:
  - `<dt>` / `<dd>` pairs must live inside `<dl>`
  - if the text is only a section heading, use `<p>` or `<span>`, not `<dt>`
- Use `<label>` only for actual form controls with a stable target `id`. If the text is a group heading or static descriptor, use `<span>` / `<p>` instead.
- Do not add `aria-label` when visible text already gives the correct accessible name. If extra context is needed, make sure the accessible name still contains the visible text.
- Landmark and dismiss controls should keep explicit names:
  - `<nav>` elements need an accessible name when there is more than one navigation region
  - icon-only or overlay-dismiss controls need an `aria-label`
- Avoid adding ARIA roles to native elements unless the component truly implements the full ARIA pattern. Reuse `x-ui.combobox` and other existing primitives instead of re-creating list box/menu semantics ad hoc.
- Before finishing a Blade change, scan for these common regressions: orphaned labels, mismatched `aria-label`s, redundant roles on native elements, and list/detail semantics implemented with generic `<div>` wrappers.

## Elevating to Modern Sleek

- **Layered depth** — Page: `bg-surface-page`. Cards/panels: `bg-surface-card` with `border-border-default` and `rounded-2xl shadow-sm`. Primary actions: `bg-accent` / `text-accent-on`.
- **Motion** — Alpine.js for transitions and modals. Hover lift: `hover:-translate-y-0.5 transition-all duration-300`. Loading: prefer a lightweight skeleton/placeholder (`animate-pulse` on a surface token) or inline status rather than indefinite spinners. Respect `prefers-reduced-motion`.
- **White space** — Sidebar: `bg-surface-sidebar`. Consistent 8px grid (`p-4`, `p-8`, `gap-6`).

## Notes

- Prefer the in-app UI Reference pages for examples: `Administration > System > UI Reference`.
- Avoid one-off `<style>` blocks in Blade; change Tailwind classes and/or `resources/core/css/tokens.css` / `resources/core/css/components.css`.
- Unsaved-changes navigation guard (Livewire navigation): `docs/guides/unsaved-changes-navigation-guard.md`.
