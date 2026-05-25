# pluggable-module-view-colocation.md

**Status:** Complete
**Last Updated:** 2026-05-25
**Sources:** `docs/architecture/pluggable-modules.md`, `docs/architecture/file-structure.md`, `docs/architecture/ui-layout.md`, `docs/guides/extensions/licensee-development-guide.md`, user discussion on full-stack module colocation.
**Agents:** Amp/GPT-5

## Problem Essence

BLB is moving toward pluggable modules for every domain outside Base and Core, but older Laravel habits scattered module-owned Blade files under `resources/`. That split weakens removability: a plugin can own its PHP, routes, migrations, and tests in one directory while its UI remains elsewhere.

## Desired Outcome

Pluggable modules become full-stack ownership units. A module's routes, Livewire classes, views, migrations, config, tests, and module-specific assets live under that module root, so a nested-git or composer plugin can be installed, removed, reviewed, and released as one coherent directory.

## Top-Level Components

- **Framework infrastructure:** `app/Base/{Module}` remains non-pluggable and does not own product UI.
- **Application Core:** `app/Modules/Core/{Module}` and `resources/core` remain framework-owned. Shared layouts, shell chrome, and reusable Blade components live here.
- **Pluggable domain modules:** `app/Modules/{Domain}/{Module}` for non-Core domains owns full-stack module internals, including `Views/`.
- **Licensee extensions:** `extensions/{owner}/{module}` follows the same full-stack shape as pluggable domain modules.
- **Frontend build:** Tailwind and Vite watch module-owned `Views/` directories in addition to `resources/core/views`.

## Design Decisions

### Full-stack module colocation is the default outside Base/Core

For People, Commerce, Operation, Finance, Sales, Procurement, and future domains, new module-owned Blade views belong under the module's `Views/` directory. This matches the pluggable architecture: plugins are vertical products, not backend packages with UI fragments elsewhere.

### `resources/core` remains the shared framework UI surface

The application shell, shared layouts, reusable components, design tokens, and core-only pages stay in `resources/core`. This keeps common UI in one place without turning plugin screens into global overrides.

### Namespaced module views are the contract

Each module or plugin registers its `Views/` directory through its service provider and renders views by namespace. This avoids global view-path precedence and keeps ownership explicit.

### No per-licensee `resources/extensions` layer

Licensee UI follows the same module-colocation rule as every other plugin. A separate `resources/extensions` layer would reintroduce scattered ownership and hidden override behavior.

### Assets use explicit host build entry points

Blade colocation is the default rule, and most modules should compose shared
`resources/core` components, tokens, Livewire actions, and small Alpine
expressions before adding private assets. When module-specific CSS or JavaScript
is truly needed, source lives under the module's `Assets/` directory and becomes
active only through an explicit reviewed Vite entry/import in the host app.
Nested-git plugins do not auto-inject scripts or styles.

## Public Contract

- Base and Core are framework-owned and not pluggable.
- `resources/core` is the only global framework presentation tree.
- Non-Core domain modules and extensions own module-specific presentation under `Views/` in the module root.
- Framework docs and agent guidance should reject new module-owned Blade files under `resources/core` unless the file is genuinely shared framework UI.
- Build tooling must scan module `Views/` paths so colocated Blade files participate in Tailwind class discovery and dev refresh.
- Module-owned CSS and JavaScript source belongs under `Assets/` and requires explicit host build wiring; framework-wide tokens and shared JavaScript remain in `resources/core`.

## Phases

### Phase 1 — Document the architecture direction

- [x] Add this plan as the source of truth for full-stack module colocation. {Amp/GPT-5}
- [x] Update architecture docs to make `Views/` colocation the default for pluggable modules outside Base/Core. {Amp/GPT-5}
- [x] Align root agent guidance so future agents place module-owned UI with the module. {Amp/GPT-5}

### Phase 2 — Make current tooling compatible

- [x] Add Tailwind source scanning for `app/Modules/*/*/Views` and `extensions/*/*/Views`. {Amp/GPT-5}
- [x] Add Vite refresh paths for module-owned and extension-owned Blade views. {Amp/GPT-5}
- [x] Verify Livewire/component discovery expectations once the first non-Core pluggable module ships with colocated views. {Amp/GPT-5}

### Phase 3 — Migrate existing non-Core UI when domains are actively touched

- [x] Move Operation IT and Quality module-owned views from `resources/core/views` into their module roots. {Amp/GPT-5}
- [x] Move existing People module-owned views from `resources/core/views` into their module roots. {Amp/GPT-5}
- [x] Move existing Commerce module-owned views from `resources/core/views` into their module roots. {Amp/GPT-5}
- [x] Place new non-Core domain views under their module roots from the start. {Amp/GPT-5}
- [x] Keep only shared framework shell, layouts, and reusable Base/Core presentation in `resources/core`. {Amp/GPT-5}

### Phase 4 — Define plugin asset packaging after the Blade pattern proves stable

- [x] Decide how module-owned CSS and JavaScript are registered, built, and published for nested-git plugins. {Amp/GPT-5}
- [x] Revisit the asset contract for composer-installed plugins during the composerization phase. {Amp/GPT-5}
