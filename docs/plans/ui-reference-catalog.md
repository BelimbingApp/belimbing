# UI Reference Catalog

**Agent:** Codex
**Status:** In Progress
**Last Updated:** 2026-04-23
**Sources:** `docs/todo/ui-reference-catalog.md`, `resources/core/views/AGENTS.md`, `resources/core/css/tokens.css`, `docs/guides/theming.md`, https://github.com/google-labs-code/design.md

## Problem Essence

BLB has no authoritative place where humans and agents can inspect the standard UI as it actually renders and behaves. As a result, design intent lives in prose, token values live in CSS, and interaction patterns are inferred from scattered production pages instead of a shared visual-and-behavioral reference.

## Desired Outcome

BLB should have a small set of complementary design references that prevent drift without creating competing sources of truth. Humans and agents should be able to read the design language, inspect the implemented tokens, and browse an in-app set of UI catalog pages that show the intended `x-ui.*` usage and behavior through the real application shell.

## Top-Level Components

| Piece | Responsibility |
|---|---|
| `DESIGN.md` | Portable design-intent document for agents and tooling: style language, semantic token vocabulary, and high-level component semantics |
| `resources/core/css/tokens.css` | Canonical implementation of token values and dark-mode overrides |
| UI catalog pages | Authoritative visual and behavioral reference showing how BLB primitives and layout idioms actually appear and behave in the app |
| `resources/core/views/AGENTS.md` | Repo-specific authoring rules for Blade, Livewire, Tailwind, accessibility, and component reuse |
| Administration / System entry | Discoverable in-app surface for humans to review the standard UI during design and implementation work |
| Dev shortcut route | Optional convenience route for development, subordinate to the in-app reference area |

## Design Decisions

**Adopt `DESIGN.md`, but keep it thin.** We should use `DESIGN.md` as the portable design brief for agents, not as a second implementation spec. It should describe BLB's visual identity, semantic design vocabulary, and high-level component intent, while remaining subordinate to the repo's implemented reality.

**Make the catalog pages the human-facing visual authority.** The catalog should not claim to be the only source of truth, but it should be the authoritative reference for human eyes. Its job is to show real `x-ui.*` usage and behavior, rendered through the actual Blade pipeline and app shell, so humans can identify drift in production pages and direct agents back toward the standard.

**Keep token values authoritative in `tokens.css`.** BLB already stores semantic tokens and dark-mode overrides in `resources/core/css/tokens.css`. Duplicating those values manually inside `DESIGN.md` would create avoidable drift. If both artifacts carry similar information, `tokens.css` wins.

**Use section alignment so the artifacts reinforce each other.** `DESIGN.md` and the catalog should use a similar top-to-bottom structure where practical: overview, colors, typography, layout/spacing, shapes/elevation, components, and usage guardrails. The documents should answer different questions while staying easy to compare.

**Use `DESIGN.md` for aesthetic intent, not just tokens.** The upstream examples are strongest when they start with a sharp point of view about the product's personality and interaction feel, then let tokens support that direction. BLB should do the same in a restrained, enterprise-appropriate voice: professional, compact, warm, trustworthy, and high-information-density without feeling cold or cramped.

**Keep component entries generic and reusable.** The examples are most useful where their component tokens describe broad reusable primitives such as primary buttons, input fields, cards, list items, and badges. BLB should follow that pattern and avoid page-specific or campaign-style component names inside `DESIGN.md`.

**Let prose carry depth, motion, and density guidance.** The examples use prose for the parts that do not fit neatly into token tables: tonal layering, hover lift, glass depth, whitespace philosophy, and interaction emphasis. BLB should use prose the same way for compact layout rhythm, enterprise restraint, focus treatment, and surface hierarchy instead of forcing everything into token declarations.

**Do not introduce the `design.md` CLI in the first pass.** BLB can adopt the document format without adopting the upstream CLI tooling. The first implementation should keep `DESIGN.md` as a hand-authored document and avoid adding new Node or Bun-based design-tooling requirements until the document proves its value in practice.

**Put the catalog in the application, not in hidden developer-only space.** Because the catalog is intended for human review and product ideation, it needs a stable, discoverable home inside `Administration > System`. A dev-only shortcut may still exist, but it should not be the only place where the standard UI can be reviewed.

**Make the catalog interactive where behavior matters.** A static swatch wall is insufficient. Components whose value depends on behavior such as flash notifications, comboboxes, modals, tabs, loading states, validation states, and dismiss interactions should be live so humans can inspect timing, spacing, transitions, hierarchy, and keyboard or mouse behavior.

**Split the reference into multiple logical pages.** A single page will become noisy and hard to use. The catalog should be organized into a small set of pages grouped by concern, so humans can browse the reference area during feature design and agents can be pointed at the exact page that demonstrates the intended pattern.

**Use a persistent section rail on desktop, with a lighter mobile fallback.** The section list is primary navigation for the reference area, not secondary content. On larger screens it should behave like a stable left rail so humans can jump between groups repeatedly; on smaller screens a simpler card-based selector is sufficient. The desktop rail should stay visually rich enough to guide ideation, not collapse into a bare list of labels.

**Extract the resizable side-panel shell into a reusable component.** The UI reference area and future internal tools should not each re-implement their own draggable side rail. The layout shell should be reusable, while each page owns its own navigation content and information architecture.

**Stay in whiteboard mode until the structure feels stable.** This plan should define the document boundaries, contracts, and responsibilities first. Detailed build sequencing can wait until the content model is settled and the user is satisfied with the split.

## Public Contract

- `DESIGN.md` exists at the repository root as a human-readable and machine-readable design brief for agents.
- `DESIGN.md` describes design intent, semantic roles, and high-level component semantics. It does not replace Blade components, Tailwind classes, or `tokens.css`.
- The primary UI reference surface lives inside `Administration > System` and is intended for human review as well as agent guidance.
- The UI reference surface is made of multiple logically grouped catalog pages rather than one oversized catch-all page.
- The catalog pages render actual `x-ui.*` components and layout patterns rather than screenshots, static HTML dumps, or Storybook-style isolated stories.
- Interactive components remain interactive where behavior is part of the standard being documented.
- `resources/core/views/AGENTS.md` points to both the design brief and the catalog area so agents can discover the full reference stack quickly.
- An optional `/dev/ui` shortcut may exist for development convenience, but it is not the primary contract.
- The initial implementation does not add the upstream `design.md` CLI or any generated token-export pipeline.

**Non-goals:**

- Do not make `DESIGN.md` the sole normative source for token values while the format is still alpha.
- Do not duplicate every component prop, variant, or implementation detail in `DESIGN.md`.
- Do not treat the catalog as a component behavior test harness.
- Do not create two unrelated reference systems with different terminology or section ordering.
- Do not add Bun or Node-based design CLI tooling as part of the initial rollout.
- Do not hide the only human-usable reference behind a local-only route.

## Working Shape

The recommended document split is:

1. `DESIGN.md` answers what aesthetic system BLB is trying to produce.
2. `resources/core/css/tokens.css` answers what token values are actually implemented.
3. the UI catalog area answers how those rules render and behave through BLB primitives and page patterns.
4. `resources/core/views/AGENTS.md` answers how contributors should apply the system in this repository.

The examples suggest that BLB's `DESIGN.md` should be strongest in these areas:

- a crisp `Brand & Style` section that states BLB's visual personality in plain language
- a semantic color story that explains role and usage, not only hex values
- typography guidance that explains hierarchy and tone, not only sizes
- layout prose that explains density, breathing room, and grouping philosophy
- component semantics limited to framework-level primitives such as buttons, inputs, cards, badges, alerts, tables, tabs, and modals
- do's and don'ts that express BLB-specific guardrails such as semantic tokens only, no raw primitives in Blade, and compact but readable layouts

The catalog area should cover concrete rendered usage:

- semantic color swatches
- typography roles
- spacing tokens
- buttons, form controls, badges, alerts, cards, tabs, modals
- page headers, tables, pagination, empty states, loading states
- icon usage, action groups, help text

The catalog area should also cover behavior where behavior affects design choice:

- flash notifications: position, title/subtitle hierarchy, spacing, duration, dismiss behavior, stacking
- combobox versus select versus free text input behavior
- modal open and close behavior
- validation and loading transitions
- hover, focus, disabled, and dismiss states where they materially affect perception

The catalog pages should be grouped logically, with a structure close to:

- Foundations
- Inputs
- Feedback
- Actions
- Navigation
- Overlays
- Data Display
- Composite Patterns

Sample intended coverage for each grouping:

- **Foundations:** color tokens, typography scale, spacing rhythm, shape and elevation rules, icon language, and motion principles
- **Inputs:** text input, search input, textarea, select, combobox, datetime, checkbox, radio, and comparison demos such as select versus combobox versus free text
- **Feedback:** flash notifications, inline alerts, validation states, form-level error summaries, loading indicators, empty states, and other status surfaces
- **Actions:** primary, secondary, ghost, and danger buttons, icon actions, grouped actions, destructive entry points, and loading or disabled action states
- **Navigation:** tabs as the canonical home, page-header navigation affordances, pagination, filter bars, section switchers, and keyboard-focus treatment for navigation controls
- **Overlays:** modal dialogs, confirmation dialogs, help panels, focus trapping, dismiss behavior, backdrop treatment, and overlay stacking rules
- **Data Display:** cards, badges, tables, status treatments, datetime display, dense metadata blocks, row-hover states, and table empty or loading states
- **Composite Patterns:** full admin pages and flows that combine the primitives, such as index pages, forms, detail pages, modal flows, and search-and-select workflows

`DESIGN.md` should stay at the semantic layer:

- overview / brand and style
- colors
- typography
- layout and spacing
- elevation and depth
- shapes
- component semantics
- do's and don'ts

`DESIGN.md` should avoid:

- page-specific or feature-specific component names unless they are true framework patterns
- duplicating every value from `tokens.css` by hand when the CSS file is already the implemented authority
- turning into a second component API reference or prop table
- cinematic or campaign-style language that does not match BLB's enterprise posture

If the approach proves useful, BLB may later add a derived artifact such as a token export or Tailwind-facing transformation generated from the same design source. That is a possible follow-up, not part of the initial scope.

## Phases

### Phase 1 — Design Reference Baseline

**Goal:** Establish the document stack and vocabulary before building the in-app reference area.

- [x] Create root `DESIGN.md` with BLB's baseline sections: Brand & Style, Colors, Typography, Layout, Elevation & Depth, Shapes, Components, and Do's and Don'ts
- [x] Keep `DESIGN.md` limited to semantic intent and framework-level component meanings rather than prop-level implementation detail
- [x] Align `DESIGN.md` vocabulary with existing semantic roles in `resources/core/css/tokens.css`
- [x] Update `resources/core/views/AGENTS.md` so agents are directed to `DESIGN.md` for design intent and to the catalog for rendered usage

### Phase 2 — Rendered Catalog Shell

**Goal:** Make the catalog area available inside the real application shell and administration IA.

- [x] Add an authenticated `Administration > System` entry for the UI reference area
- [x] Define the page structure for multiple logical catalog pages instead of a single catch-all page
- [x] Use the initial grouping structure: Foundations, Inputs, Feedback, Actions, Navigation, Overlays, Data Display, and Composite Patterns
- [x] Create the initial shell for the catalog area in the standard app layout
- [x] Add introductory copy explaining that the area is the visual-and-behavioral reference, not the token authority
- [ ] Optionally add a `/dev/ui` shortcut only as a convenience alias to the same reference area
- [x] Mirror the high-level section order from `DESIGN.md` where practical so the artifacts are easy to compare

### Phase 3 — Foundations and Primitive Usage

**Goal:** Cover the base visual system and core `x-ui.*` primitives with interactive demonstrations where needed.

- [x] Render semantic color swatches and labels for the implemented BLB token roles
- [x] Render typography roles and compact-spacing examples that reflect BLB's actual density model
- [x] Populate the Foundations page with color, type, spacing, shape, elevation, icon, and motion references
- [x] Populate the primitive pages with examples for buttons, inputs, selects, textareas, search inputs, checkboxes, radios, badges, alerts, cards, tabs, modals, icons, help text, and action groups
- [x] Make behavior-dependent primitives interactive where that affects understanding of the standard
- [x] Add short inline annotations before each section explaining when the pattern should be used

### Phase 4 — Composite Patterns

**Goal:** Show the page-level patterns agents are most likely to compose incorrectly when working from scattered examples, and give humans an ideation surface for feature design.

- [x] Render page headers, tables, pagination, empty states, loading states, and other composite admin patterns through the real app shell
- [x] Add a dedicated feedback or messaging page covering flash notification behavior in detail
- [x] Add a dedicated inputs page that helps humans compare combobox, select, and free-text patterns
- [x] Treat tabs as canonically documented under Navigation and cross-reference them from Composite Patterns where page context matters
- [x] Show representative validation, hover, disabled, and loading states where they materially affect markup choices
- [x] Prefer examples that demonstrate BLB's compact enterprise posture rather than decorative or marketing-style layouts

### Phase 5 — Review and Drift Controls

**Goal:** Make the reference stack trustworthy enough to guide future UI work for both humans and agents.

- [ ] Verify that the catalog renders without errors in light and dark mode
- [x] Confirm that each catalog section uses real `x-ui.*` components or canonical layout patterns rather than one-off markup where a primitive exists
- [x] Confirm that the interactive behaviors shown in the catalog reflect the intended standard rather than demo-only behavior
- [x] Review `DESIGN.md`, `tokens.css`, `AGENTS.md`, and the catalog together for vocabulary drift
- [x] Leave CLI-based linting, diffing, and export workflows out of scope for this phase unless the plan is explicitly revised later

## Drift Prevention Rules

- Prefer deriving `DESIGN.md` language from implemented BLB conventions rather than inventing a parallel vocabulary.
- Prefer changing `tokens.css` first when token values evolve, then update `DESIGN.md` prose if the design meaning changed.
- Prefer updating the relevant catalog page when usage patterns or component recommendations change.
- Keep the naming of semantic roles aligned across `DESIGN.md`, `tokens.css`, and `AGENTS.md`.
- If the same idea must appear in more than one place, each file should express a different layer: intent, value, rule, or rendered example.
