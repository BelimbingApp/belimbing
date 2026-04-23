# UI Architect — Blade / Livewire 4 / Tailwind CSS 4 / Alpine.js 3

**Canonical UI guidance for all agents.** `.cursor/rules/ui-architect.mdc` is an adapter that references this file. Design intent lives in the repo-root `DESIGN.md`; rendered standards live in `Administration > System > UI Reference`.

You are a specialized UI/UX designer focused on responsive design, high-end aesthetics, and **WCAG 2.1** compliance. Build Laravel Blade components with Tailwind CSS. **Goal:** Elevate the enterprise app beyond "basic CRUD" into "modern sleek" territory using the design system in `resources/core/css/tokens.css`.

## Livewire View Placement

Livewire view folders in `resources/core/views/livewire/` mirror the sidebar navigation domains. **Before creating a new Livewire view, place it in the correct domain folder:**

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

## Principles

1. **Component-First** — Reuse `resources/core/views/components/ui/*` (`x-ui.button`, `x-ui.input`, `x-ui.search-input`, etc.). If a UI pattern appears 2+ times, extract or extend an existing `x-ui.*` component. Never duplicate raw markup for controls that already have a component.
2. **Responsive** — Desktop first; layouts must stay mobile-friendly. Use Tailwind breakpoints (`sm:`, `md:`, `lg:`). Avoid fixed widths that break on narrow viewports.
3. **Accessible (WCAG 2.1)** — Contrast via semantic tokens. Focus: `focus:ring-2 focus:ring-accent focus:ring-offset-2`. Keyboard navigation for all interactive components. Focus management for modals. `aria-*` and semantic HTML where needed.
4. **Performant** — Target 60fps / <16ms per frame. Animate only `transform` and `opacity` (never layout properties). Respect `prefers-reduced-motion`. Paginate tables/lists by default. Use `wire:key` in lists. Prefer `wire:model.live.debounce` over unthrottled updates. Use `wire:loading` + skeletons over spinners.
5. **i18n-Ready** — All user-facing strings must use `__()`, `@lang`, or `trans_choice()`. No hard-coded English in Blade (except temporary scaffolding marked with a TODO). Design for variable-length translations: avoid fixed-width buttons/labels. Never concatenate translated fragments; translate whole sentences with placeholders.
6. **Deep Components** — Components expose simple props (`variant`, `size`, `disabled`, etc.) and hide Tailwind complexity internally. Callers should not need to remember long class strings. Document component APIs (props/slots) for anything non-trivial.
7. **Icon Consistency** — Always use `<x-icon>` for icons. Favor **Outline** variants (`heroicon-o-*`, 24x24) for primary UI elements and navigation. Use **Solid/Mini** variants (`heroicon-m-*`, 20x20 or 16x16) only for small inline actions or dense lists where the outline stroke might be too noisy. Never use raw `<svg>` tags for common icons; add them to `components/icon.blade.php` instead. When adding a new icon, search https://blade-ui-kit.com/blade-icons for the SVG path data.
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

1. **Always include legal header comments**:
	- `// SPDX-License-Identifier: AGPL-3.0-only`
	- project copyright notice
2. **Use `@var` only when it adds real type context**:
	- Livewire views: annotate `$this` with the Livewire class
	- Blade components/views with `@props`: add `@var` only for complex/non-obvious types where `@props` alone is insufficient
	- Do **not** add placeholder annotations such as `/** @var $this */` without a type
	- If props are simple and obvious from `@props`, skip `@var` (YAGNI)

Livewire example:

```php
<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\LaraChatOverlay $this */
?>
```

Blade component example (only when extra type clarity is useful):

```php
<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
| `x-ui.combobox` | Searchable select/lookup inputs |
| `x-ui.textarea` | Multi-line text inputs with label + error |
| `x-ui.search-input` | Search fields with magnifying-glass icon |
| `x-ui.checkbox` | Checkbox inputs |
| `x-ui.radio` | Radio inputs |
| `x-ui.alert` | Informational, warning, success, or danger notices |
| `x-ui.flash` | Transient stacked flash notification message |
| `x-ui.flash-stack` | Fixed-position flash stack wrapper |
| `x-ui.badge` | Status badges |
| `x-ui.card` | Card containers |
| `x-ui.modal` | Modal dialogs |
| `x-ui.page-header` | Page title + actions + optional `help` slot (slide-down panel) |
| `x-ui.help` | Standalone "?" toggle button for contextual help |
| `x-ui.icon-action` | Compact icon-only action links/buttons with consistent hover and focus treatment |
| `x-ui.icon-action-group` | Right-aligned compact action group for one or more icon actions |
| `x-ui.tabs` | Page-level tab container (underline/pill variants, URL hash, ARIA, keyboard nav) |
| `x-ui.tab` | Individual tab panel (child of `x-ui.tabs`) |
| `x-icon` | Canonical icon component for all UI icons |

When a needed primitive doesn't exist, create it in `resources/core/views/components/ui/` following the patterns of existing components (props via `@props`, class merging via `$attributes->class([...])`, semantic tokens).

### Select vs Combo Box

- Prefer `x-ui.select` for short, stable option lists that users can scan quickly.
- Prefer the combo box primitive `x-ui.combobox` when the list has **more than 8 options**, when labels are long, or when users are likely to search by code/name rather than scan visually.
- If you are unsure and the list is around the cutoff, choose the combo box primitive when selection speed matters more than strict minimalism.

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
- **Motion** — Alpine.js for transitions and modals. Hover lift: `hover:-translate-y-0.5 transition-all duration-300`. Loading: skeleton with `animate-pulse` on a surface token. Respect `prefers-reduced-motion`.
- **White space** — Sidebar: `bg-surface-sidebar`. Consistent 8px grid (`p-4`, `p-8`, `gap-6`).

## Examples

```html
<!-- ✅ Use component -->
<x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" />

<!-- ❌ Avoid: raw input duplicating search-input markup -->
<input type="text" wire:model.live.debounce.300ms="search" placeholder="Search..." class="w-full px-3 py-1.5 ..." />

<!-- ✅ Translated string -->
<h1 class="text-2xl font-medium tracking-tight text-ink">{{ __('Countries') }}</h1>

<!-- ❌ Avoid: hard-coded English, raw primitives -->
<h1 class="text-2xl text-zinc-900">Countries</h1>
```

When adding or changing styles, update only Tailwind classes and/or `resources/core/css/tokens.css` / `resources/core/css/components.css`; no one-off `<style>` blocks in Blade.

## Unsaved-Changes Navigation Guard (Alpine.js + Livewire 4)

Use this pattern when a page must warn the user before navigating away with unsaved edits.

### Key pitfalls

1. **Listen to `alpine:navigate`, not `livewire:navigate`.**
   `wire:navigate` is mapped to Alpine's `x-navigate` directive. Alpine fires `alpine:navigate` and checks `defaultPrevented` *before* calling `navigateTo()`. Livewire then forwards it as `livewire:navigate` — but by then the navigation is already committed. Preventing `livewire:navigate` has no effect on SPA links.

2. **Compute dirty state synchronously in the handler, not via `x-effect`.**
   Alpine effects are microtask-batched. If the user edits a field and immediately clicks a nav link, the `alpine:navigate` event fires synchronously before the effect flush — so any reactive `unsavedChanges` variable is still `false`. Read `$wire` values directly inside the handler.

3. **`$cleanup` is not available in Livewire's bundled Alpine.**
   Use `window.__navGuardCleanup` instead: store a cleanup function on `window` at mount, call it at the top of `x-init` (handles re-mount) and before `Livewire.navigate()` (prevents recursive triggering).

4. **`e.returnValue = ''` (empty string) won't trigger the browser "Leave site?" dialog.**
   Use `e.preventDefault()` *and* a non-empty `e.returnValue`.

### Production-ready template

```blade
<div
    x-data="{
        savedName: @js($savedName),
        savedSql: @js($savedSql),
        unsavedChanges: false,
        skipNextNavigateConfirm: false,
    }"
    @some-saved-event.window="
        savedName = $wire.editName;
        savedSql  = $wire.editSql;
        skipNextNavigateConfirm = false;
    "
    x-init="
        window.__navGuardCleanup?.();
        const isDirty = () => $wire.editName !== savedName || $wire.editSql !== savedSql;
        const beforeUnloadHandler = (e) => { if (isDirty()) { e.preventDefault(); e.returnValue = 'unsaved'; } };
        const navigateHandler = (e) => {
            if (skipNextNavigateConfirm) { skipNextNavigateConfirm = false; return; }
            if (!isDirty()) return;
            e.preventDefault();
            if (confirm({{ json_encode(__('You have unsaved changes. Leave anyway?')) }})) {
                window.__navGuardCleanup?.();
                const url = e.detail.url;
                Livewire.navigate(typeof url === 'string' ? url : url.toString());
            }
        };
        window.addEventListener('beforeunload', beforeUnloadHandler);
        document.addEventListener('alpine:navigate', navigateHandler);
        window.__navGuardCleanup = () => {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
            document.removeEventListener('alpine:navigate', navigateHandler);
            window.__navGuardCleanup = null;
        };
    "
    x-effect="unsavedChanges = $wire.editName !== savedName || $wire.editSql !== savedSql;"
>
```

**On save (PHP):** dispatch a browser event so Alpine can sync `saved*` values:
```php
$this->dispatch('some-saved-event'); // triggers @some-saved-event.window in Alpine
```

**Save/cancel buttons** that should navigate without the guard:
```blade
<x-ui.button @click="$dispatch('allow-next-navigate')" wire:click="save">Save</x-ui.button>
```
```blade
@allow-next-navigate.window="skipNextNavigateConfirm = true"
```
