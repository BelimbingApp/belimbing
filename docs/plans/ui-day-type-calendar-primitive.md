# ui-day-type-calendar-primitive.md

Status: In Progress
Last Updated: 2026-05-17
Sources: `docs/plans/people/15_attendance-roster-builder-ux.md`, `app/Modules/People/Attendance/Services/AttendanceCalendarResolver.php`, `resources/core/css/tokens.css`, `app/Modules/People/Attendance/Views/livewire/people/attendance/partials/rosters-grid.blade.php`, `resources/core/views/AGENTS.md`
Agents: {claude/opus-4.7}

## Problem Essence

The Attendance roster grid teaches a small but durable vocabulary — Normal / Rest / Off / Holiday — and renders each day with consistent tints (`bg-day-rest`, `bg-day-off`, `bg-day-holiday`) and inks (`text-day-{rest,off,holiday}-ink`). Leave, Claim, Timesheets, and any future calendar surface (audit timelines, leave conflict overlays, payroll period pickers) all want the same vocabulary, but today each surface would have to re-derive day-type colours and labels from scratch. Without a primitive, the next surface either invents its own palette or copies the one we just shipped.

## Desired Outcome

Any Livewire view in BLB that renders a calendar can compose a day tile, a week strip, or a month grid using shared components and semantic tokens. The day-type vocabulary stays single-sourced; a change to "Holiday" tint or label updates everywhere. New calendar surfaces in People, Payroll, or Operations can be assembled by reading rows out of `AttendanceCalendarResolver` (or its successor) and handing them to the primitives — they should not need to re-invent the cell pill, the "on Rest/Off/Holiday" subscript, or the colour mapping.

## Top-Level Components

**`x-ui.day-tile`** — One cell. Props: `dayType` (normal|rest|off|holiday), `label`, optional `slot` for inline content (a shift code, a leave chip, a punch icon). Encodes the day-type tint and ink. Carries the `title` tooltip and the accessible label so screen readers get the day-type without seeing the colour.

**`x-ui.day-strip`** — A horizontal row of `x-ui.day-tile`s — a week, a pay period, or any short date range. Handles the date headers (day-of-week + date label), today/weekend highlighting, and optional sticky labels.

**`x-ui.calendar-grid`** — An employees × dates grid of `x-ui.day-tile`s. Optional row labels and group separators. The roster grid is the first consumer; Leave calendar and Claim period picker are likely the next.

**Shared day-type vocabulary helper.** A small PHP helper (probably an enum or a value-object on the AttendanceDay model) that returns label + tint token + ink token for a given day type, so Blade components and Livewire data layers agree on the mapping without scattering match() ladders.

## Design Decisions

**Tokens already live in `resources/core/css/tokens.css`.** The day-type tints (`--color-day-{rest,off,holiday}` and ink variants) were added during the Attendance day-type layer work. The primitive plan does not re-define them; it only uses them. New surfaces that need additional day-types (e.g. weekend variants, training days) add tokens here first.

**Day-type resolution stays in Attendance, exposed by contract.** `AttendanceCalendarResolver::dayType($employee, $date)` already returns the canonical value; cross-module callers consume that contract rather than reading work-calendar metadata themselves. A future cross-cutting Calendar service can absorb this once a second module needs it.

**Components live under `resources/core/views/components/ui/`** alongside `x-ui.badge`, `x-ui.card`, `x-ui.tabs`, etc. Follow the conventions in `resources/core/views/AGENTS.md` — `@props`, `$attributes->class([])`, semantic tokens only, explicit `id` on form controls, `__()` for any user-facing strings.

**Treat the roster grid as the reference implementation.** When extracting the primitives, walk the existing `rosters-grid.blade.php` and pull out the tile + strip + grid layers; the roster grid's own callsite becomes a thin composition. The day-type tooltip, override affordance, and state-border encoding stay configurable via slots or props so other consumers can opt in/out.

**Do not break BLB list-first convention.** The primitive answers "render this calendar surface"; it does not prescribe page structure. Each consuming page still chooses its list/form modes and its CTA placement per `feedback_blb_list_first_convention`.

## Public Contract

A Blade consumer should be able to render any calendar surface as:

- `<x-ui.day-tile :day-type="$type" :label="$label">...</x-ui.day-tile>` for a single cell, with the inner slot holding the surface-specific content (shift code, leave chip, punch icon).
- `<x-ui.day-strip :days="$days">...</x-ui.day-strip>` for one row (week / period / strip).
- `<x-ui.calendar-grid :rows="$rows" :days="$days">...</x-ui.calendar-grid>` for an employees × dates grid.

A PHP consumer should be able to resolve day-type metadata as a single object with `label()`, `surfaceClass()`, and `inkClass()`, avoiding inline `match()` ladders in Blade and Livewire.

## Phases

### Phase 1 — Vocabulary helper

- [x] Add a single source of truth for day-type label + surface class + ink class as a final static helper class `App\Modules\People\Attendance\Support\DayTypeVocabulary` so existing `AttendanceDay::DAY_TYPE_*` string constants stay the canonical values. {claude/opus-4.7}
- [x] Refactor `rosters-grid.blade.php` and `Rosters::dayTypeLabel()` to consume the helper instead of their inline match() ladders. {claude/opus-4.7}
- [x] Cover the helper with a focused test asserting label + classes for each day type. Dark-mode tokens flow through CSS variables so the helper stays the same. {claude/opus-4.7}

### Phase 2 — `x-ui.day-tile`

- [x] Extract the per-cell pill markup from `rosters-grid.blade.php` into `resources/core/views/components/ui/day-tile.blade.php`. {claude/opus-4.7}
- [x] Accept `dayType`, `empty`, `state` (published/draft/preview), `tooltip`, and `emptyLabel`, plus a default slot for inner content. {claude/opus-4.7}
- [x] Reuse the existing state border encoding (`border-status-success/warning/info`); component documentation lives in the blade preamble. The components inventory in `resources/core/views/AGENTS.md` should be extended once `day-strip` and `calendar-grid` also ship. {claude/opus-4.7}

### Phase 3 — `x-ui.day-strip` and `x-ui.calendar-grid`

- [x] Extract the day-header row from `rosters-grid.blade.php` into `resources/core/views/components/ui/day-strip.blade.php`. {claude/opus-4.7}
- [ ] Extract the grouped-rows scaffolding (full table with employee column, group section headers, and per-cell composition) into `x-ui.calendar-grid`. *(Deferred — the abstraction needs a second consumer before the per-cell slot API stabilises; see Phase 4.)*
- [x] Replace the inline roster grid markup with a composition of `x-ui.day-strip` + `x-ui.day-tile`. The Livewire data shape stays unchanged. {claude/opus-4.7}
- [ ] Render Leave conflict overlays and a Payroll period picker prototype using the same primitives to validate the contract before declaring it stable. *(Deferred — covered by Phase 4.)*

### Phase 4 — Cross-module adoption

- [ ] Migrate the People Leave calendar to consume the primitives. {agent/model}
- [ ] Migrate the Claim period picker to consume the primitives. {agent/model}
- [ ] Add a UI Reference page demonstrating the three primitives with sample data so other agents discover them before re-implementing. {agent/model}
- [ ] Once a second consumer exists, extract `x-ui.calendar-grid` (full table scaffolding) from `rosters-grid.blade.php`. {agent/model}
