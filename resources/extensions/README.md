# Resources

Presentation layer for BLB — CSS, Blade views, and JavaScript.

## Directory Layout

```
resources/
├── core/              # BLB framework UI (owned by upstream)
│   ├── css/           # Design tokens, component styles
│   │   ├── tokens.css
│   │   └── components.css
│   ├── views/
│   │   ├── components/ui/   # Reusable Blade components (x-ui.*)
│   │   └── livewire/        # Livewire page templates
│   └── js/
│
├── extensions/        # Licensee overrides (owned by fork)
│   └── {licensee}/
│       ├── css/
│       │   └── tokens.css       # Brand color overrides
│       ├── views/
│       │   ├── components/      # Component overrides
│       │   └── livewire/        # Page template overrides
│       └── js/
│
└── app.css            # Vite entry point
```

Licensee overrides win by CSS cascade and Blade view resolution order. Set `VITE_THEME_DIR={licensee}` in `.env` to activate.

**Guides:**

- [UI Architect (AGENTS.md)](../core/views/AGENTS.md) — Blade/Livewire/Tailwind conventions, component inventory
- [Theming](../../docs/guides/theming.md) — token overrides and component resolution
- [UI Layout](../../docs/architecture/ui-layout.md) — core/licensee presentation split
- [Licensee Development Guide](../../docs/guides/extensions/licensee-development-guide.md) — UI boundary rules (§4.5)
