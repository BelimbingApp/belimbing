# base-audit-entity-index.md

**Status:** Identified
**Last Updated:** 2026-05-21
**Sources:**
- `app/Base/Audit/Listeners/MutationListener.php` — global Eloquent mutation listener
- `app/Base/Audit/Database/Migrations/0100_01_17_000000_create_base_audit_mutations_table.php` — table to be extended
- `docs/plans/people/18e_roster-form-consolidation.md` — first consumer; Phase 2 built a domain-specific `people_attendance_roster_cell_log` table that this plan supersedes
**Agents:** claude/sonnet-4.6

## Problem Essence

`base_audit_mutations` captures every Eloquent model mutation globally but offers only a technical index: model class and primary key. There is no way to ask "all audit events for employee 7" or "what changed on the roster for 2026-05-15" without a full scan or application-side join. Domain modules work around this by building their own per-concern log tables — waste that compounds as modules grow.

## Desired Outcome

Three new nullable columns on `base_audit_mutations` — `object_name`, `object_id`, `subject_identifier` — create a general-purpose business-entity index. Any module can now find all audit events for a named entity (`object_name + object_id`) and, where applicable, a sub-entity slot (`subject_identifier`). The roster cell log table built in 18e Phase 2 is retired and replaced by a query against the enriched mutations table.

## Design Decisions

**Three columns, all nullable.**
- `object_name` (string) — a short, stable domain label for the parent entity: `employee`, `invoice`, `product`, `policy_group`. Lowercase, no namespace.
- `object_id` (bigint) — the parent entity's primary key.
- `subject_identifier` (string) — an optional sub-entity slot within that entity. Deliberately a string, not a date, so callers can store dates (`'2026-05-15'`), line codes (`'LINE-003'`), pattern slots (`'monday'`), config keys, or any domain-meaningful token. ISO date strings sort lexicographically, so date-range queries work correctly with string comparison.

Nullable throughout — mutations for models with no meaningful parent entity (e.g. a company-level config record) leave all three null and remain unchanged.

**Composite index on `(object_name, object_id, subject_identifier, occurred_at)`.**
Trailing `occurred_at` keeps the drawer's reverse-chronological order without a separate sort step. A partial index filtering `object_name IS NOT NULL` keeps it lean; PostgreSQL will use it even when `subject_identifier` is null in a two-column lookup.

**Two enrichment paths — model interface for assignment-level, observer pattern for sub-entity expansion.**

The `MutationListener` can populate `object_name` and `object_id` automatically for models that declare a lightweight `getAuditSubject()` method returning `['name' => '...', 'id' => ...]`. This covers the common case (one mutation row, one parent entity).

`subject_identifier` is not populated by `MutationListener` — it cannot know the domain-specific sub-entity without coupling to every module. Instead, domain observers that need per-date or per-slot rows write additional rows directly to `base_audit_mutations` with `subject_identifier` set and `old_values`/`new_values` pre-computed to human-readable summaries (shift code, policy code) rather than raw IDs. These observer-written rows are distinguishable from listener-written rows by a new `source` column value (`'observer'` vs `'listener'`).

**`source` column added alongside.**
A short string (`'listener'` default, `'observer'` for domain-written rows) distinguishes automatic global captures from domain-enriched rows. Useful for forensic queries that want only one or the other.

**Roster cell log retired.**
`people_attendance_roster_cell_log` and `AttendanceRosterAssignmentObserver` are replaced: the observer writes enriched rows directly to `base_audit_mutations` with `object_name = 'employee'`, `object_id = employee_id`, `subject_identifier = date (ISO)`. The `ManagesRosterCellHistory` Livewire concern switches its query from the cell log table to `base_audit_mutations`. The cell log migration is edited in place to drop the table (destructive migration evolution).

**`is_stable` check required before migration.**
`base_audit_mutations` has production data. Per BLB convention, confirm `is_stable` status and seek explicit user approval before running any schema change against it.

## Phases

### Phase 1 — Extend `base_audit_mutations` schema

- [ ] Check `is_stable` flag on `base_audit_mutations`; confirm with user before proceeding.
- [ ] Edit `0100_01_17_000000_create_base_audit_mutations_table.php` in place: add `object_name` (string, nullable), `object_id` (bigint unsigned, nullable), `subject_identifier` (string, nullable), `source` (string, default `'listener'`).
- [ ] Add composite index `(object_name, object_id, subject_identifier, occurred_at)` with a partial filter on `object_name IS NOT NULL`.
- [ ] Run migration on dev; verify existing rows are unaffected (all new columns null, source null or default).

### Phase 2 — `MutationListener` enrichment hook

- [ ] Define `getAuditSubject(): array|null` as a documented convention (not a formal interface — PHP duck-typing is sufficient). Return `['name' => string, 'id' => int]` or null.
- [ ] `MutationListener` calls `getAuditSubject()` on the model if the method exists; writes result into `object_name` / `object_id` on the mutation row.
- [ ] Add `getAuditSubject()` to `AttendanceRosterAssignment` returning `['name' => 'employee', 'id' => employee_id]`.
- [ ] Document the convention in a `README` or inline docblock on `MutationListener` so future model authors know to implement it.

### Phase 3 — Roster observer writes enriched per-date rows

- [ ] Update `AttendanceRosterAssignmentObserver`: instead of writing to `people_attendance_roster_cell_log`, write per-date rows directly to `base_audit_mutations` with `object_name = 'employee'`, `object_id = employee_id`, `subject_identifier = ISO date`, `source = 'observer'`, `old_values` and `new_values` as pre-computed `{shift_code, policy_code}` maps.
- [ ] Suppress the `MutationListener` during observer writes (use `MutationListener::withoutAuditing()`) to avoid a double-row for the same save.
- [ ] Update `ManagesRosterCellHistory::loadCellHistory()` to query `base_audit_mutations` instead of `people_attendance_roster_cell_log`.
- [ ] Update `RosterEmployeeHistory` component similarly.

### Phase 4 — Retire the cell log table

- [ ] Edit `0320_02_05_000006_create_attendance_roster_cell_log.php` in place to drop the table (destructive evolution; table has no production data yet so no `is_stable` concern).
- [ ] Remove `AttendanceRosterCellLog` model.
- [ ] Remove `ManagesRosterCellHistory` imports of the cell log model.
- [ ] Run migration; confirm drawer and full-history page still work against `base_audit_mutations`.
