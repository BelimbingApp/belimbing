# UI Reference Catalog

## Problem Essence

No single renderable reference exists for BLB's design system. Agents infer UI patterns from scattered production views, leading to inconsistent class choices, ad-hoc color usage, and missed component reuse.

## Status

Proposed

## Desired Outcome

A dev-only renderable Blade page (`/dev/ui`) that shows every standardized UI pattern — `x-ui.*` component usage, token-driven color and spacing, typography scale, state variants, and layout idioms. Agents use it as the ground truth for producing consistent markup; developers render it to catch visual regressions and verify dark-mode parity.

## Public Contract

- Route: `GET /dev/ui` — restricted to local/dev environments only (no production exposure).
- The page renders real `x-ui.*` components through the actual Blade pipeline, not static screenshots or HTML dumps.
- Each section carries inline comments explaining **when** to use the pattern, not just what it looks like.
- The file lives at `resources/core/views/dev/ui-catalog.blade.php`.
- `resources/core/views/AGENTS.md` gains a pointer to this catalog so agents automatically know it exists.

**Non-goals:**

- This is not a Storybook-style isolated component sandbox. It is a flat Blade page inside the real app shell.
- It does not test behavior (that belongs in Pest). It tests visual correctness by human eye.
- It does not replace the `x-ui.*` component files as the source of truth for implementation. It shows usage, not internals.

## Top-Level Components

| Piece | Responsibility |
|---|---|
| Dev route registration | `GET /dev/ui` bound to a plain view response; middleware-gated to local env |
| `ui-catalog.blade.php` | The renderable reference — all sections in one file, organized by category |
| Inline annotations | Comment blocks before each section explaining when and why to use the pattern |
| `AGENTS.md` pointer | One-line addition to `resources/core/views/AGENTS.md` so agents discover the catalog |

## Design Decisions

**Real render, not static HTML.** The catalog uses actual `x-ui.*` components and semantic tokens. If a component changes, the catalog reflects it automatically. A static HTML file would silently diverge.

**Flat Blade file, not a Livewire component.** The catalog has no reactive behavior — it is a visual inventory. A plain Blade view is simpler, faster to load, and easier for agents to read without Livewire lifecycle noise.

**Inline comments as the annotation layer.** Prose rules belong in `AGENTS.md`; the catalog's job is to show concrete markup. But a one-to-three-line comment block before each section — "use this for primary destructive actions", "use badge-danger when a count exceeds threshold" — gives agents enough context to match pattern to intent without leaving the file.

**Dev-only route, no auth middleware.** The catalog contains no sensitive data. A simple `abort_unless(app()->isLocal(), 403)` guard is sufficient. Adding admin auth would make the catalog inaccessible before auth is set up, which defeats its purpose during development.

**Catalog covers usage surface, not exhaustive prop permutations.** Show the patterns agents will actually write. Document unusual props inline. Full prop tables belong in component docblocks, not here.

## Sections to Cover

Organized top-to-bottom as they appear in the catalog:

1. **Color tokens** — semantic surface, border, ink, muted, accent swatches with token names labeled
2. **Typography** — heading scale, body/data, label style, muted, tabular-nums
3. **Spacing tokens** — visual ruler of all semantic spacing values
4. **Buttons** — primary, secondary, ghost, danger, disabled, loading state, icon-only (`x-ui.button`)
5. **Form controls** — input, search-input, select, textarea, checkbox, radio, combobox, datetime (`x-ui.*`)
6. **Validation states** — field-level error display, form-level error summary
7. **Badges** — all variants (`x-ui.badge`)
8. **Alerts** — info, success, warning, danger (`x-ui.alert`)
9. **Cards** — basic, with header, with footer (`x-ui.card`)
10. **Page header** — with and without actions (`x-ui.page-header`)
11. **Tables** — header row, data rows, row hover, empty state, loading skeleton
12. **Pagination** — standard Livewire paginator appearance
13. **Tabs** — horizontal tabs (`x-ui.tabs` / `x-ui.tab`)
14. **Modal** — standard dialog, confirmation dialog (`x-ui.modal`)
15. **Icon usage** — outline vs solid/mini, `<x-icon>` wrapper examples
16. **Empty states** — icon + heading + description + optional CTA
17. **Loading states** — wire:loading skeleton pattern, spinner usage
18. **Action groups** — `x-ui.icon-action`, `x-ui.icon-action-group`
19. **Help text** — `x-ui.help`

## Phases

### Phase 1 — Route and shell

**Goal:** The catalog URL works and the page loads in the app shell with the correct layout.

- [ ] Register `GET /dev/ui` in `routes/web.php` with `abort_unless(app()->isLocal(), 403)` guard
- [ ] Create `resources/core/views/dev/ui-catalog.blade.php` using the standard app layout
- [ ] Add placeholder heading and first section (color tokens) to confirm the render pipeline works
- [ ] Add pointer to `resources/core/views/AGENTS.md`: location of catalog + its purpose for agents

### Phase 2 — Foundation tokens and primitives

**Goal:** Color tokens, typography, spacing, and icon usage are documented and visually rendered.

- [ ] Color tokens section — one swatch row per semantic role, labeled with the token name
- [ ] Typography section — heading scale h1–h4, body/data, label style, muted, tabular-nums example
- [ ] Spacing tokens section — paddings, gaps, cell spacing as labeled visual blocks
- [ ] Icon section — outline (nav/primary), solid/mini (dense/inline), `<x-icon>` usage comment

### Phase 3 — Components

**Goal:** Every `x-ui.*` component appears in its standard usage pattern with an annotation comment.

- [ ] Buttons — primary, secondary, ghost, danger, disabled, loading; icon-only variant
- [ ] Form controls — input, search-input, select, textarea, checkbox, radio, combobox, datetime
- [ ] Validation states — per-field `$errors->first()` pattern, form-level error list
- [ ] Badges — all variants
- [ ] Alerts — all four types
- [ ] Cards — plain, with header, with footer
- [ ] Tabs — horizontal, active vs inactive state
- [ ] Modal — confirm dialog skeleton, standard content dialog
- [ ] Action groups — icon-action, grouped row actions
- [ ] Help — inline help text usage

### Phase 4 — Layout patterns and composite states

**Goal:** Higher-order patterns agents reach for when building real pages are documented.

- [ ] Page header — title only, title + subtitle, title + actions
- [ ] Table — full pattern: header row, data rows, row hover, `wire:key`, empty state, loading skeleton
- [ ] Pagination — Livewire paginator inside a table wrapper
- [ ] Empty state — icon + heading + description + optional CTA button
- [ ] Loading state — `wire:loading` skeleton vs spinner, `wire:loading.delay` usage note

### Phase 5 — Agent wiring

**Goal:** Agents reliably discover and use the catalog.

- [ ] Confirm `resources/core/views/AGENTS.md` pointer is clear and actionable
- [ ] Verify the catalog renders without errors in both light and dark mode
- [ ] Smoke-check that every `x-ui.*` component referenced in the catalog actually exists
