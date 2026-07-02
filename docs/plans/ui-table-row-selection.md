# ui-table-row-selection

**Status:** Proposed
**Last Updated:** 2026-07-02
**Sources:** None — spawned from review of `x-ui.table` gaps alongside `ui-table-pagination-per-page.md`.
**Agents:** amp/glm-5.2

## Problem Essence

`x-ui.table` has no first-class row-selection / batch-action primitive. Every list that needs bulk operations (delete-selected, assign-selected, export-selected) reinvents its own checkbox column, selection set, "select all on this page" vs "select all matching the query" semantics, and a bulk-actions bar. The result is drift: each implementation makes different choices about row identity (model key vs array index), cross-page selection persistence, and what "select all" means under an active search — and there is no shared blessed path to converge on.

This is larger and more speculative than the pagination work: only some lists need it, the selection-state model has real correctness traps (cross-page, "select all matching", clear-on-search), and it touches the table shell, a new component, a concern, and an actions bar. Per root `AGENTS.md` §2, larger corrections get a plan now and implementation later.

## Desired Outcome

A blessed, opt-in row-selection system for `x-ui.table`-backed lists: a checkbox-column component, a `SelectsRows` concern owning selection state with explicit "this page" vs "all matching" modes, a bulk-actions bar, and a documented adoption list — so new lists that need batch actions use one correct path instead of reinventing one, and existing lists converge over time.

## Top-Level Components

- **`x-ui.table-row-select`** (or a slot on `x-ui.th`/`x-ui.td`) — the checkbox column header (select-all-on-page / select-all-matching) and per-row checkbox.
- **`Concerns\SelectsRows`** — trait owning the selection set (`array<string|int>` of keys, not indexes), the `allMatching` flag, reset-on-search, and the "select all matching this query" idiom.
- **`x-ui.bulk-actions-bar`** — the action region rendered above/below a list when selection is non-empty.
- **Adoption list** — explicit set of lists that opt in, so the trait is not speculative scaffolding.

## Design Decisions

**Row identity model.** Options: (a) array index; (b) model primary key; (c) pluggable key resolver. **Recommend (b) by default, (c) for composite keys.** Indexes break the moment the page order shifts; keys survive re-sort and re-pagination. Selection must store **keys**, never indexes, so cross-page selection is well-defined. A list with a composite identity overrides a `rowKey($model)` hook. Honesty: the stored selection is a set of keys, not row references.

**"Select all" semantics.** Options: (a) page-only; (b) all-matching-the-query; (c) both with explicit modes. **Recommend (c).** Bulk delete/export over a filtered set is a real workflow; page-only is the safe default for destructive actions. The concern exposes both modes and the bulk-actions bar shows which is active ("12 selected on this page" / "Select all 483 matching"). Clearing selection on search/sort change is the safe default to avoid acting on rows the user can no longer see.

**Where the checkbox lives.** Options: (a) a dedicated `x-ui.table-row-select` column component callers place in `head`/`body`; (b) built into `x-ui.table` behind a `selectable` prop. **Recommend (a).** Same reasoning as per-page: `x-ui.table` stays a Livewire-agnostic shell. A dedicated column component composes into the existing `head`/`body` slots and keeps selection concerns out of the shell. `x-ui.table` gains at most an opt-in `selectable` flag for affordances (e.g. row `data-selected` styling), not state.

**Persistence.** Defer URL/session persistence of selection. Selection is transient action state, not a view configuration; persisting it invites stale-action bugs (act on a set that no longer matches). Matches DESIGN.md: persist *meaningful* state, not ephemeral intent.

## Public Contract

(to be finalized before implementation; sketched here so the design is concrete)

- `Concerns\SelectsRows`: `public array $selectedKeys = []`; `public bool $allMatching = false`; `public function toggleRow(mixed $key): void`; `public function togglePage(array $pageKeys): void`; `public function selectAllMatching(): void`; `public function clearSelection(): void`; `protected function rowKey(mixed $model): int|string`; `public function selectedCount(): int`; resets selection on search via `updatedSearch`.
- `x-ui.table-row-select` head variant: props `allPageKeys`, `selectedKeys`, `allMatching`, `mode`; emits `wire:click` for select-all-on-page / select-all-matching.
- `x-ui.bulk-actions-bar`: slot for action buttons; shows `selectedCount()` and the active mode; renders an explicit "clear selection" control.

## Phases

(Implementation deferred — this plan exists now per root `AGENTS.md` §2. Phases populate when the build is greenlit.)

- [ ] Finalize row-identity + selection-state contract; pick `int|string` key shape and the `rowKey` override hook
- [ ] Add `Concerns\SelectsRows` (toggle/toggle-page/select-all-matching/clear, reset-on-search, `selectedCount`)
- [ ] Add `x-ui.table-row-select` head + row components
- [ ] Add `x-ui.bulk-actions-bar` component
- [ ] Document the adoption list and migrate the first 2–3 lists that genuinely need batch actions

Validation (when implemented)

- Unit tests for `SelectsRows` covering: cross-page selection survives re-sort, clear-on-search, `allMatching` count vs `selectedKeys` count, composite-key override.
- Manual: a migrated list performing a bulk action on a multi-page filtered selection.

## Notes

- Deliberately plan-only in this pass; do not implement alongside `ui-table-pagination-per-page.md`. The pagination work is already wide (233 callers' chrome); layering selection on top would broaden blast radius and risk shipping two half-validated primitives.
- Revisit the "select all matching" idiom against any existing saved-view / filter contract (`People/Employees` saved views) before implementing, so selection composes with saved filters rather than fighting them.
