# DESIGN.md

## Brand & Style

BLB should feel professional, compact, warm, and trustworthy. The interface should support high information density without feeling cramped, loud, or cold. Visual restraint matters more than novelty: surfaces should layer cleanly, actions should read clearly, and motion should clarify state rather than advertise itself.

The design system is meant for internal product work, administration, and workflow-heavy screens. It should not resemble a marketing site or a consumer app. The standard BLB page should look deliberate, sober, and highly legible over long sessions.

## Colors

BLB uses semantic color roles rather than raw palette classes in Blade. Views should speak in terms of surfaces, borders, ink, muted text, accent, and status roles. The implemented values live in `resources/core/css/tokens.css`; this document explains intent, not numeric authority.

- `surface-page`: primary page background
- `surface-card`: cards, inputs, dropdowns, modals, and body surfaces
- `surface-subtle`: headers, hover surfaces, and low-emphasis grouping
- `surface-sidebar`: persistent navigation chrome
- `surface-bar`: top bar and status bar surfaces
- `ink`: primary readable text
- `muted`: supporting labels, metadata, placeholders, and secondary copy
- `accent`: primary action emphasis
- status colors: success, warning, danger, and info for feedback only

Use status colors to communicate state, not to decorate layout. Use accent sparingly enough that primary actions still stand out.

## Typography

Typography should read as compact and competent. Headings should prefer medium weight over heavy bold, labels should be small and disciplined, and tables or operational values should use tabular rhythm where that improves scanning.

- page titles: medium-weight, tight tracking, clear hierarchy
- section headings: compact and calm, not oversized
- labels: uppercase, small, muted, and consistent
- body copy: short, plain, and operational
- tabular values: use tabular numerals when alignment matters

Avoid decorative type treatments. The standard voice is operational clarity.

## Layout

Layout should prioritize fast scanning and clear grouping. BLB defaults to dense spacing, but dense does not mean cramped. Internal spacing inside controls and cards should stay tight; larger page sections should still breathe enough that the eye can separate concerns quickly.

- prefer stacked cards and grids with clear surface boundaries
- prefer short distances between a label and its control
- prefer meaningful grouping over oversized whitespace
- use responsive layouts that stay readable on narrow screens
- let page structure read top-to-bottom without visual clutter

## Elevation & Depth

Depth should be subtle. Cards, dropdowns, and modals should separate themselves from the page through surface contrast, borders, and restrained shadows. The goal is layered clarity, not visual drama.

- ordinary panels: border plus light shadow
- active overlays: stronger elevation than cards
- hover emphasis: subtle lift or tonal shift, never exaggerated bounce
- focus emphasis: clear ring and border transition

## Shapes

BLB favors rounded corners that feel modern but not playful. Inputs, buttons, cards, overlays, and grouped surfaces should share a coherent radius family so that the UI feels designed as one system rather than assembled from unrelated parts.

- inputs and buttons: rounded, compact, touch-safe
- cards and overlays: slightly larger radius than controls
- badges: fully rounded when used as pills

## Components

Component semantics should stay at the framework level. `DESIGN.md` describes what a pattern is for; component files and the UI reference area show concrete usage.

### Buttons

- primary button: main forward action
- secondary button: lower-emphasis supporting action
- ghost button: low-chrome inline action
- danger button: destructive action only

### Inputs

- text input: short structured text
- textarea: longer authored text
- select: short, stable option list
- combobox: longer or searchable option list
- free text: use only when values are genuinely open-ended

### Feedback

- inline alert: page or form-level notice shown in flow
- flash notification: transient stacked message for action results
- validation error: field-level or form-level correction guidance

### Navigation

- tabs: peer content sections within one page context
- page header: title, subtitle, actions, and optional help
- pagination: movement through dense result sets

### Overlays

- modal: focused interruption for secondary work
- confirmation dialog: explicit checkpoint for destructive or irreversible actions

### Data Display

- cards: grouped operational content
- tables: dense record browsing and comparison
- badges: concise status or classification markers

## Do's and Don'ts

Do:

- use semantic tokens, not raw palette classes, in Blade
- reuse `x-ui.*` primitives instead of rewriting common controls
- choose compact layouts that still preserve hierarchy
- prefer clear, truthful labels and help copy
- make interaction states visible enough to compare with production pages

Don't:

- introduce one-off color values or arbitrary layout styling in Blade
- treat every page like a blank canvas
- use cinematic motion or marketing-style ornament
- overload accent and status colors until hierarchy collapses
- turn `DESIGN.md` into a prop table or duplicate of `tokens.css`

