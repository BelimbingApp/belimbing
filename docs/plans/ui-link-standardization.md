# ui-link-standardization

**Status:** Complete — implemented, tests green
**Last Updated:** 2026-06-20
**Sources:** `DESIGN.md`, `resources/core/views/AGENTS.md` (Icon Consistency, Link Dictionary, Component Inventory), `resources/core/views/components/icon.blade.php`, `resources/core/views/components/ui/link.blade.php`, `resources/core/views/components/ui/icon-action.blade.php`, UI Reference partials under `resources/core/views/livewire/admin/system/ui-reference/`
**Agents:** Claude/claude-opus-4-8

## Problem Essence

BLB has many kinds of "clickable that takes you somewhere," but no canonical mapping from *link behavior* → *element, icon, and attributes*. The result is drift: external links carry three different `rel` spellings (`noopener noreferrer`, `noreferrer`, `noopener`) or none, most have no external-affordance icon, and some hand-roll a literal text arrow (`Open Policy Groups →`). Users can't predict whether a click navigates, opens a tab, leaves the app, opens an overlay, or mutates data.

## Desired Outcome

A single, **closed and collision-free icon dictionary** where each link behavior maps to exactly one glyph and one element/attribute contract, expressed through reusable components so callers never hand-write `target`/`rel`/icon. The dictionary is documented across the three canonical layers (intent in `DESIGN.md`, rule in `AGENTS.md`, rendered example in UI Reference) and the existing inconsistent links are migrated onto it.

Done means:
- One verb ⇒ one glyph, enforced by components, never two meanings for one icon.
- Every cross-origin link gets identical, correct `rel`/`target` automatically.
- Mutations are `<button>`, navigations are `<a>` — no styled-anchor actions.
- A reviewer can open the UI Reference "Links" section and see every link type rendered with its glyph and rules.

## Top-Level Components

1. **The link taxonomy + icon dictionary** — the canonical table below; the single source of truth all code and docs derive from.
2. **`x-ui.link`** — new text-link primitive that takes a behavior and renders the correct element, icon, and safety attributes. Complements the existing icon-only `x-ui.icon-action`.
3. **Icon registry additions** — any glyphs in the dictionary not yet in `icon.blade.php`.
4. **Documentation** — `DESIGN.md` (intent paragraph), `AGENTS.md` (rule + dictionary table), UI Reference "Links" rendered section.
5. **Migration** — convert the ~16 `target="_blank"` sites and literal-arrow links onto the new contract.

## Design Decisions

### The principle: the icon is the verb, and the verb is closed

The user's directive: icons should let us drop words like "open / link / view / new tab." We adopt that — including on internal links — **on one condition that makes it safe**: the icon set is a *closed dictionary* where each glyph means exactly one behavior and never collides. The moment a glyph means two things, the vocabulary stops being a language and becomes noise, and we're back to needing words. Every decision below serves that invariant.

### Classify by two questions, not by visual appearance

Every clickable answers two things for the user:
1. *Will I lose where I am?* (context change — same view / new tab / leaves BLB / overlay)
2. *Does this change data, or just move me?* (navigation `<a>` vs action `<button>`)

The dictionary is organized on those axes, not on how the control looks.

### The canonical dictionary

| Behavior | Element | Glyph (registered name) | `target` / `rel` | Verb it replaces |
|---|---|---|---|---|
| In-app, same tab (the default) | `<a>` `wire:navigate` | none, or trailing `heroicon-m-arrow-right` in dense link lists | — | "go to" |
| In-app, **forced** new tab | `<a>` | `heroicon-o-arrow-top-right-on-square` | `target=_blank` `rel=noopener` | "open in new tab" |
| In-page section anchor (`#`) | `<a>` | leading `heroicon-o-link` | — | "link to section" |
| External site (leaves BLB) | `<a>` | trailing `heroicon-o-arrow-top-right-on-square` | `target=_blank` `rel=noopener noreferrer` | "leave BLB" |
| Download a file | `<a>` / `<button>` | leading `heroicon-o-arrow-down-tray` | `download` | "get file" |
| Copy to clipboard | `<button>` | `heroicon-o-clipboard` | — | "copy" |
| Open modal/dialog | `<button>` | optional trailing `heroicon-o-arrows-pointing-out` | — | "open dialog" |
| Open drawer/slide-over | `<button>` | trailing panel glyph *(needs adding)* | — | "open panel" |
| Mutating action (delete/approve/transition) | `<button>` | domain glyph (trash, check, flag…) | — | the action verb |

Notes that resolve the dangerous collisions up front:

- **External vs forced-new-tab both use the box-arrow.** This is the one acceptable shared glyph because the user-facing meaning ("a new tab is about to open") is genuinely the same; the *only* difference is `rel`, which is invisible. We do **not** invent a second near-identical box icon — that would fail the "tell them apart at a glance" test and add noise. The distinction lives in `rel`, owned by the component, not in the glyph.
- **Copy gets the clipboard, never the box-arrow.** Reusing the duplicate/box glyph for copy is the most common drift; the dictionary forbids it.
- **Section anchor uses `link`, not `#` typography.** We already have `heroicon-o-link`; standardize on it rather than rendering a literal `#`.
- **In-app forced-new-tab is discouraged.** Default to same-tab `wire:navigate` and let users Ctrl/Cmd-click to choose. Force a new tab only when losing unsaved state would be destructive or when the link is a genuine side-reference (e.g. "open Shifts while editing a policy"). When forced, it must carry the box-arrow so the surprise is signalled.

### Placement and weight rules (keep it from becoming noise)

- **Trailing = "what happens" (new tab, external, modal, drawer). Leading = "what kind of thing" (download, section anchor).** Consistent scan order.
- **Glyph renders muted** (below link-text contrast) so a list of six links doesn't shout; the text carries the eye, the icon answers the follow-up.
- **Tooltip/`aria-label` is the long form of the same verb** — the dropped words ("Open Shifts in a new tab") survive for screen readers and hover. Clarity without on-screen clutter, and no accessibility regression.

### Element semantics are non-negotiable

Navigation is `<a>`; anything that changes data or only opens an in-page overlay is `<button>`. This is already half-encoded: `x-ui.icon-action` renders `<a>` when `href` is set, else `<button>`. We extend the same discipline to text links via `x-ui.link`. Given the recent audit/workflow-transition work, conflating mutations into anchor styling would corrupt both accessibility (Enter vs Space, role) and the audit trail's notion of an action.

### Why a new `x-ui.link` rather than documentation alone

The drift is in hand-written `target`/`rel`/icon, so the fix must remove the opportunity to hand-write them. `x-ui.link` takes a single behavior prop and emits the correct element, glyph, `target`, `rel`, and muted styling. `x-ui.button as="a"` stays for button-weight links; `x-ui.icon-action` stays for icon-only. `x-ui.link` fills the gap: a **text link that knows its own behavior**.

## Public Contract

`x-ui.link` (final prop names to settle during build; behavior fixed):

- A behavior selector — proposed `kind` ∈ `internal` (default), `new-tab`, `anchor`, `external`, `download`. `internal` is the bare default and emits `wire:navigate`.
- `href` (navigation kinds) — required for all `<a>` kinds.
- The component owns: element choice, glyph + placement, `target`, `rel`, `download`, muted icon styling, and focus ring. Callers never pass `target`/`rel` directly.
- Accessible name: visible text is the name; an optional `title`/`aria-label` carries the long-form verb for new-tab/external/download without duplicating the visible text.
- Overlay and mutation behaviors are **not** `x-ui.link`'s job — they are `<button>`/`x-ui.button`/`x-ui.icon-action`; the dictionary documents their glyphs so the vocabulary stays whole, but the link primitive only covers navigation + download.

Invariant the component must guarantee: **one glyph never renders for two `kind`s**, and every `target=_blank` it emits also emits a `rel` containing `noopener`.

## Phases

### Phase 1 — Catalog the current state (sweep) — Claude/claude-opus-4-8

Goal: a written inventory of every link behavior in use and every divergence.

- [x] Inventoried all `target="_blank"` sites (16) and their `rel` spelling — mixed `noopener noreferrer` / `noreferrer` / `noopener` / none across `resources/core` and `app/Modules/{Commerce,People}`. {Claude/claude-opus-4-8}
- [x] Inventoried worded/arrow affordances: `Open Policy Groups →`, `Open Shifts in a new tab`, `Open full history →`, `:provider API key ↗`. The bulk of literal `→` in Blade are diff/breadcrumb separators (not link affordances) and were left alone. {Claude/claude-opus-4-8}
- [x] Confirmed `x-ui.icon-action` already renders `<a>`/`<button>` by `href` presence — fits the dictionary unchanged. {Claude/claude-opus-4-8}
- [x] Confirmed dictionary glyphs already registered: `arrow-top-right-on-square`, `link`, `arrow-down-tray`, `clipboard`, `arrows-pointing-out`, `dock-right` (drawer), `arrow-right` (mini). {Claude/claude-opus-4-8}

### Phase 2 — Icon registry additions — Claude/claude-opus-4-8

- [x] No additions needed — `heroicon-o-dock-right` already covers the drawer behavior and the 24px box-arrow reads fine at 14px, so no mini variant was added. {Claude/claude-opus-4-8}

### Phase 3 — `x-ui.link` primitive — Claude/claude-opus-4-8

- [x] Created `resources/core/views/components/ui/link.blade.php` (`kind` ∈ internal|new-tab|anchor|external|download; `href`, `icon`, `navigate` props; accepts Alpine-bound destinations are out of scope — see Phase 5). {Claude/claude-opus-4-8}
- [x] Encodes element/glyph/`target`/`rel`/placement per the dictionary; affordance icon muted `opacity-60`; internal default emits `wire:navigate` (opt out with `:navigate="false"`). {Claude/claude-opus-4-8}
- [x] Added `x-ui.link` to the Component Inventory; added a "Link Consistency" principle (#10). {Claude/claude-opus-4-8}
Evidence: `tests/Feature/Modules/Core/Ui/LinkViewTest.php` — 7 cases asserting `rel`/`target`/`wire:navigate`/`download` and per-kind glyph by SVG path. Green.

### Phase 4 — Documentation across the three layers — Claude/claude-opus-4-8

- [x] `DESIGN.md` — added a "Links" intent paragraph. {Claude/claude-opus-4-8}
- [x] `resources/core/views/AGENTS.md` — added the full Link Dictionary table + leading/trailing/muted rules + the collision rules. {Claude/claude-opus-4-8}
- [x] UI Reference — added a rendered "Links" card to `navigation.blade.php` showing each `kind` live plus the mutation-is-a-button row. {Claude/claude-opus-4-8}
Affected pages: `Administration > System > UI Reference` (Navigation page).

### Phase 5 — Migrate existing usages — Claude/claude-opus-4-8

Scope clarification settled during build: `x-ui.link` standardizes **inline text links**. Button-weight links (`x-ui.button as="a"`) and bespoke chrome (dark attachment lightbox, Alpine `:href`-bound anchors) are a separate, already-componentized/legitimately-local family — they keep their markup but were normalized to the dictionary's `rel` and box-arrow glyph. This mirrors the existing "tables that aren't application tables can stay local" carve-out.

- [x] Migrated 12 text-link sites to `x-ui.link kind=external|new-tab` (AI providers ×3, provider help panel, wire-log fragment, github-access, plugin-manager, eBay index/settings, inventory item listing, attendance policy-form + shift-template-form). {Claude/claude-opus-4-8}
- [x] Normalized the 4 button-weight/pill/Alpine new-tab links to `rel="noopener"` + trailing box-arrow (wire-log event pill, wire-log `x-ui.button`, attendance `Tune shifts` button, rosters-grid Alpine link). {Claude/claude-opus-4-8}
- [x] Normalized the 2 dark-lightbox attachment links to `rel="noopener noreferrer"`. {Claude/claude-opus-4-8}
- [x] Replaced literal `→` / `↗` / "in a new tab" affordances with the standard glyph + `title`. {Claude/claude-opus-4-8}
- [x] No mutations were found styled as anchors in the swept set. {Claude/claude-opus-4-8}
Validation: re-sweep shows every remaining `target="_blank"` is either the component itself or an accepted button-weight/Alpine/bespoke exception; `rel` is uniformly `noopener` (in-app new-tab) or `noopener noreferrer` (external) with no lone `noreferrer`. `php artisan view:cache` compiles all Blade; Pint clean; `LinkViewTest` + 155 feature/unit tests across the touched AI/foundation/update areas green.

## Litmus / open questions for the user

- **Should internal same-tab links carry the trailing `arrow-right` by default, or stay bare?** Recommendation: bare by default (keeps dense screens calm), arrow only inside link lists where rows need a "go" affordance. This is the one place the "icon replaces a word" directive trades against the "compact, every pixel earns its place" bar in `DESIGN.md`; worth an explicit call.
- **Keep external and forced-new-tab on the same box-arrow glyph?** Recommendation: yes — same user-facing meaning, distinction lives in `rel`. Splitting them would add a near-duplicate icon that fails the glance test.
