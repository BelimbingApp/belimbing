# Theme Customization Guide

**Document Type:** Developer Guide
**Audience:** BLB framework and extension developers
**Last Updated:** 2026-05-25

---

## Overview

BLB currently has one framework theme surface: `resources/core/`. Extension
modules do **not** use a companion `resources/extensions` tree, and there is no
`VITE_THEME_DIR` activation flow. Module-owned presentation belongs beside the
extension code under `extensions/{owner}/{module}/Views/` and is registered by
that module's `ServiceProvider`.

Theme work falls into two buckets:

1. **Framework-wide theme changes** — edit `resources/core/css/tokens.css` and
   `resources/core/css/components.css` in the BLB framework repo.
2. **Extension-owned screens** — ship Blade views under the extension module's
   `Views/` directory, with any module-specific assets wired explicitly by that
   module.

## Framework Theme Tokens

Tailwind CSS v4 uses CSS custom properties (`@theme`) for theming. BLB exposes
its shared theme variables in `resources/core/css/tokens.css`.

Example:

```css
@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
    --color-accent: var(--color-blue-600);
    --color-accent-hover: var(--color-blue-700);
}
```

Use semantic tokens in views (`bg-surface-card`, `text-ink`, `text-accent`) so
future palette changes stay centralized.

## Framework Components

Shared Blade components live in `resources/core/views/components/`. Change them
there only when the change is meant to improve BLB for every adopter. Private
extension modules should not shadow framework components through a parallel view
tree.

For extension pages, prefer composing the existing core components from views
under `extensions/{owner}/{module}/Views/`. If a reusable component is missing,
add it to `resources/core/` and contribute it upstream instead of creating a
licensee-only duplicate.

## Extension Module Views

Extension Livewire components and routes should render namespaced views loaded
by the extension provider:

```php
public function boot(): void
{
    $this->loadViewsFrom(__DIR__.'/Views', 'owner-module');
}
```

Then render extension views with that namespace, for example
`view('owner-module::livewire.dashboard.index')`.

This keeps the extension removable as one directory:

```text
extensions/{owner}/{module}/
├── Livewire/
├── Views/
│   └── livewire/
├── Routes/
├── Config/
└── ServiceProvider.php
```

## Dark/Light Mode

BLB uses Tailwind's dark mode with the `.dark` class. Dark-mode token values
belong in `resources/core/css/tokens.css` beside the default semantic tokens.

When adding or changing tokens:

1. Define the default semantic token.
2. Define the `.dark` value when contrast requires it.
3. Use the semantic token in Blade instead of hard-coded color primitives.

## Guidelines

### For BLB Framework Developers

1. Use semantic color names, not hard-coded hex values in views.
2. Expose customization through tokens where the visual decision is global.
3. Keep shared component APIs stable and documented through props.
4. Test both light and dark modes.
5. Put reusable UI improvements in `resources/core/`.

### For Extension Developers

1. Keep extension-owned views under `extensions/{owner}/{module}/Views/`.
2. Reuse core components where possible instead of copying them.
3. Do not create `resources/extensions/{owner}/`.
4. Document any module-specific CSS or assets in the extension module.
5. Test extension screens against the current core theme.

## Related Documents

- `docs/architecture/ui-layout.md` — shell and presentation architecture
- `docs/architecture/file-structure.md` — module and extension organization
- `docs/guides/extensions/licensee-development-guide.md` — extension placement rules
