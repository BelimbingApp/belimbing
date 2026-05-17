# 16_attendance-roster-builder-dhh-followups.md

Status: In Progress
Last Updated: 2026-05-17
Sources: `docs/plans/people/15_attendance-roster-builder-ux.md`, `docs/plans/people/sbg_attendance_ref/Roster Builder Review v2 _DHH_ - standalone.html`, `app/Modules/People/Attendance/Livewire/Rosters.php`, `resources/core/views/livewire/people/attendance/partials/rosters-form.blade.php`, `resources/core/views/livewire/people/attendance/partials/rosters-list.blade.php`, `resources/core/views/livewire/people/attendance/partials/rosters-grid.blade.php`
Agents: {claude/opus-4.7}

## Problem Essence

The Roster Builder now lands on a calendar-as-list surface with a "New roster assignment" CTA (see `15_attendance-roster-builder-ux.md`). Form mode is reached behind that button but still carries four stacked cards — filter + assignment side panel + grid preview + coverage/publish — that read like an enterprise form rather than a conversation. List mode has a calendar and a tab toggle but no way to narrow the workforce, no summary of what needs attention, and no zoom out of the week scope. The DHH-lens review from May 2026 prescribed a much quieter surface: a sentence-form summary, prose-style filtering, a single bold action, and a flatter visual encoding throughout.

## Desired Outcome

A supervisor opening the Roster Builder sees the current period's calendar, a short sentence naming what still needs to be done, and a single way to act. Filtering happens through underlined prose phrases. Form mode reads as a guided flow, not a multi-card form. Coverage and validation appear inline against the calendar rather than in a separate stats strip. Month and day zoom levels are reachable without leaving the page.

## Top-Level Components

**Prose summary line.** A server-rendered sentence above the calendar that names the count and the named exceptions ("Sunday is empty — two operators short. Ben Tan is doing back-to-back nights, which breaks the rest rule."). Underlined phrases are clickable and scroll/highlight the offending cell or row.

**Filter prose line.** Replaces the per-mode chip rows with a single sentence — "Showing :shown of :total operators in :site, all roles." — where each underlined span opens a small popover to change that dimension.

**Calendar zoom levels.** A scope switcher (Month / Week / Day) extending the existing Prev/This-week/Next switcher. Month renders one tile per day per row, coloured by coverage/exception density. Day opens a drawer scoped to one date showing every employee on that date with shift breakdown, coverage and conflicts.

**Coverage heatmap.** Replaces the per-date/per-shift coverage cards with a date×shift matrix. Each cell shows assigned/required and is colour-coded; clicking opens the day drawer above.

**Form-mode subtraction.** Folds the filter + selection + form + grid preview + coverage + publish review from a four-card stack into a single conversational flow: pick the population, pick the shape, see the impact, send it. The grid preview, validation, and publish review stay inline; selection becomes a search-led affordance rather than a paginated table.

**Records edit-in-place.** Today the Records tab supports Delete only. Edit-via-mode-flip would let supervisors adjust an assignment's date range, shift, or policy without deleting and re-creating it.

## Design Decisions

**Server-generate the prose, do not template it client-side.** The DHH review flagged this as the one engineering risk. Generate the summary string from real coverage/validation findings on the Livewire side so the prose stays truthful and translatable, then render it as-is in Blade. Underlined phrases carry data-attributes that map back to cell ids for scroll/highlight.

**Treat zoom levels as a single scope property on Rosters Livewire.** A `listScope` property with values `month | week | day` (plus `listWeekAnchor`/`listDayAnchor` for the cursor). One renderer per scope keeps the grid component cohesive; share the day-type and assignment data layers across all three.

**Coverage heatmap is the calendar inverted.** Same date axis as the calendar but employees collapse into shift columns. Drilling from a heatmap cell into the day drawer mirrors drilling from a calendar cell into the same drawer, so the supervisor learns one interaction.

**Form subtraction follows DHH's "subtract first" principle.** Each card removed must lose only chrome, not meaning. The filter card stays (selection is core), but the assignment side panel collapses into a sentence form ("Apply :shift on :pattern from :start to :end, save as :state."). The coverage and publish review become inline strips, not standalone cards.

**Records edit reuses the existing form mode.** `editRosterAssignment($id)` flips `mode` to `'form'` and pre-fills the form properties from the assignment; on save it updates instead of creating. Matches the ShiftTemplates pattern.

## Public Contract

The roster surface should expose:

- a `summary` sentence with stable phrase tokens (gap, conflict, leave, overtime) that can be re-rendered on locale change;
- a `filter` sentence with underlined spans for each filterable dimension (population, site, role) that map to existing filter properties;
- a `listScope` property with three valid values and three predictable renderers;
- a `coverageMatrix` shape per (date, shift) keyed for both the heatmap and the day drawer;
- a `dayDrawer` panel scoped to one date, listing every assigned employee, conflicts, and coverage roll-up;
- an `editRosterAssignment` action that flips mode to `form` and seeds the form fields from an existing assignment.

The calendar grid partial (`rosters-grid.blade.php`) stays the primary renderer and continues to power both list and form modes; the new month/day renderers live next to it, not inside it.

## Phases

### Phase 1 — Prose summary

- [ ] Add a `rosterListSummary()` method on `Rosters` that returns a short narrative + a list of `{phrase, target}` tokens derived from the same coverage and validation primitives the form mode already uses. {agent/model}
- [ ] Render the sentence above the calendar in list mode; underlined phrases scroll the offending cell into view. {agent/model}
- [ ] Keep the sentence empty (no chrome) when there is nothing to flag — "All set for :period." or nothing at all. {agent/model}

### Phase 2 — Filter prose line

- [ ] Replace the form-mode filter row's grid of selects with a single sentence-form rendering of the active filters (population, site, role). {agent/model}
- [x] Promote the same sentence into list mode so supervisors can narrow the calendar without flipping to form mode. First-generation prose surfaces three dimensions (department, workforce class, status) above the Calendar tab; the remaining seven filter properties stay reachable via form mode for now. {claude/opus-4.7}
- [x] Each underlined phrase opens a small popover with the existing filter controls; the rest of the row stays static. Popovers are Alpine-local with `click.outside` to close, `wire:model.live` selects bound to the existing Livewire properties, and a Clear-filters affordance appears beside the sentence whenever any non-default filter is set. {claude/opus-4.7}

### Phase 3 — Calendar zoom levels

- [ ] Add `listScope` (`month | week | day`) to `Rosters` with `setListScope($scope)` action and a cursor property per scope. {agent/model}
- [ ] Render a Month view: one tile per day per row, coloured by coverage/exception density; clicking a tile zooms into Week. {agent/model}
- [ ] Render a Day view: one drawer-style panel scoped to one date, listing every assigned employee, conflicts, and a coverage roll-up. {agent/model}
- [ ] Keep the existing Week view as the default; share the day-type and assignment data with all three renderers. {agent/model}

### Phase 4 — Coverage heatmap

- [ ] Replace the per-date/per-shift coverage cards with a date×shift heatmap matrix. {agent/model}
- [ ] Colour each matrix cell by shortage/surplus severity; tooltip shows assigned/required and any open warnings. {agent/model}
- [ ] Clicking a matrix cell opens the day drawer from Phase 3. {agent/model}

### Phase 5 — Form mode subtraction

- [ ] Collapse the assignment side panel into a sentence-form ("Apply :shift on :pattern from :start to :end, save as :state."). {agent/model}
- [ ] Move validation findings inline as cell-level markers + a single below-the-grid strip rather than a separate card. {agent/model}
- [ ] Move publish review into a single bottom action band ("Ready when you are. Send to the team.") per the DHH design. {agent/model}
- [ ] Coverage in form mode reuses the heatmap from Phase 4 so creation and browse share one visual. {agent/model}

### Phase 6 — Records edit-in-place

- [ ] Add `editRosterAssignment($id)` that flips mode to `form` and seeds form fields from the assignment. {agent/model}
- [ ] Update `saveRosterAssignment()` to update instead of create when an editing id is set. {agent/model}
- [ ] Add an Edit button to each row in the Records tab. {agent/model}
- [ ] Restore the editing context on cancel/back; preserve a "discard changes?" prompt via the unsaved-changes guard in `resources/core/views/AGENTS.md`. {agent/model}
