# Livewire Morph Identity and Interaction Stability

Status: In progress
Last Updated: 2026-07-25
Sources: `resources/core/views/AGENTS.md`, `resources/core/views/components/ui/tabs.blade.php`, `resources/core/views/components/ui/tab.blade.php`, `tests/Feature/Modules/Core/Ui/TabsViewTest.php`
Agents: Codex/gpt-5.6-sol

## Problem Essence

Livewire interactions can lose focus, caret position, scroll, or Alpine state when a server render changes the identity or structure of DOM nodes that should have survived the morph. The codebase also has selection controls that request a server render even though their state is only consumed by a later action.

## Desired Outcome

Search, filtering, and multi-selection feel like ordinary stable browser controls across Core, domain modules, and extensions. Shared components make unstable identity difficult to express, authoring guidance explains the invariants, and representative regression checks catch violations before they reach a user.

## Top-Level Components

- Shared UI primitives own stable server-rendered identity and accessible relationships.
- Livewire screens decide whether an interaction truly requires an immediate server render.
- Authoring rules and regression checks prevent random morph identity and symptom-masking JavaScript.

## Design Decisions

Documentation alone is useful but cannot prevent a shared component from silently generating a new ID on every render. A global focus-restoration script would hide node replacement, add flicker, and create races between unrelated Livewire requests.

The recommended direction is enforced stable identity in shared primitives, explicit stable IDs at call sites where accessibility relationships require them, and no ID where identity is unnecessary. Immediate server rendering remains available for searches and filters that need server results; selection state that only feeds a later action stays client-side until that action.

This direction is slightly more explicit at component call sites, but it removes hidden lifecycle behavior, keeps interfaces honest, and fixes the cause rather than compensating for it.

## Public Contract

- Every `x-ui.tabs` instance supplies a page-unique, render-stable `tabs-id`; tab and panel IDs are present and identical in every server render.
- Livewire-rendered `id` and `wire:key` values never depend on randomness, time, or render order.
- Debounced live search is allowed when the result set is server-rendered, provided the input and its ancestors have stable structure and identity.
- Checkbox arrays that only feed a later bulk action use plain `wire:model`; client-reactive directives own counts and enabled state.
- Focus or scroll restoration JavaScript is not an accepted fix for a morph-identity defect.

## Phases

### Stable tabs and table selection

Affected pages: `/admin/system/data-share#mirror` and every page using `x-ui.tabs`

- [x] Replace random or client-only tab identity with required server-rendered IDs and migrate every current caller. Codex/gpt-5.6-sol
- [x] Add a regression test proving tab markup and accessibility relationships are identical across renders. Codex/gpt-5.6-sol
- [x] Document plain `wire:model` for action-only table selection and use Livewire client-reactive directives for counts and button state. Codex/gpt-5.6-sol
- [x] Reproduce and verify Data Share search focus with changing result counts, selected rows, and overlapping requests. Codex/gpt-5.6-sol

Evidence: `TabsViewTest`, Data Share feature tests, compiled Blade views, and live `b → ba → bas → base` plus `c → co` checks.

### Shared form-control identity audit

Affected pages: representative forms and search screens using `x-ui.input`, `x-ui.textarea`, `x-ui.select`, `x-ui.checkbox`, `x-ui.radio`, `x-ui.secret-input`, `x-ui.acknowledge-input`, and `x-ui.combobox`

- [ ] Inventory shared primitives that generate IDs with randomness and classify whether each ID is required, derivable from stable binding/domain data, or should become a required caller prop.
- [ ] Remove per-render random ID defaults from Livewire-facing primitives without weakening label, description, error, or ARIA relationships.
- [ ] Migrate current callers that need explicit stable IDs across Core, domain modules, and extensions.
- [ ] Add focused contract tests that fail when shared Livewire-facing primitives reintroduce random identity.

Validation: render each primitive twice with the same inputs and prove the identity-bearing HTML is identical; exercise representative Livewire forms through at least one server morph.

### Remaining live-selection audit

- [ ] Classify remaining `.live` checkbox arrays as immediate-server behavior or later-action-only state.
- [ ] Convert later-action-only selections to plain `wire:model` while preserving client-reactive counts, disabled state, select-page behavior, and accessibility.
- [ ] Retain `.live` only where the checked state genuinely changes server-rendered content immediately, with a regression test for that dependency.

Validation: targeted feature tests plus live checks on Database Residue, Geonames postcode selection, Data Share offers, and Commerce eBay import selection.
