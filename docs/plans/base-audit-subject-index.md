# base-audit-subject-index.md

**Status:** Complete
**Last Updated:** 2026-05-21
**Sources:**
- `app/Base/Audit/Listeners/MutationListener.php` — global Eloquent mutation listener
- `app/Base/Audit/Database/Migrations/0100_01_17_000000_create_base_audit_mutations_table.php` — table to be extended
- `app/Base/Audit/Database/Migrations/0100_01_17_000001_create_base_audit_actions_table.php` — paired audit action table; timestamp/user-agent/trace cleanup should stay consistent
- `app/Base/Audit/DTO/RequestContext.php` — per-request/process context source for actor, user agent, and trace metadata
- `app/Base/Support/TraceId.php` — compact 12-character Crockford Base32 trace ID generator
- `app/Base/Authz/Database/Migrations/0100_01_11_000005_create_base_authz_decision_logs_table.php` — Authz trace linkage table
- `docs/plans/people/18e_roster-form-consolidation.md` — first consumer; Phase 2 built a domain-specific `people_attendance_roster_cell_log` table that this plan supersedes
**Agents:** claude/sonnet-4.6; amp/gpt-5

## Problem Essence

`base_audit_mutations` captures every Eloquent model mutation globally but offers only a technical index: model class and primary key. There is no way to ask "all audit events for employee 7" or "what changed on the roster for 2026-05-15" without a full scan or application-side join. Domain modules work around this by building their own per-concern log tables — waste that compounds as modules grow.

## Desired Outcome

Three new nullable columns on `base_audit_mutations` — `subject_name`, `subject_id`, `subject_identifier` — create a general-purpose audit-subject index. Any module can now find all audit events for a named subject (`subject_name + subject_id`) and, where applicable, a sub-subject slot (`subject_identifier`). The Audit module remains the only writer to audit tables; business modules expose audit meaning through lightweight model conventions without importing Audit classes or writing Audit rows directly. The roster cell log table built in 18e Phase 2 is retired and replaced by a query against the enriched mutations table.

## Design Decisions

**Three columns, all nullable.**
- `subject_name` (string) — a short, stable domain label for the audited subject: `employee`, `invoice`, `product`, `policy_group`. Lowercase, no namespace.
- `subject_id` (bigint) — the audited subject's primary key.
- `subject_identifier` (string) — an optional slot within that subject. Deliberately a string, not a date, so callers can store dates (`'2026-05-15'`), line codes (`'LINE-003'`), pattern slots (`'monday'`), config keys, or any domain-meaningful token. ISO date strings sort lexicographically, so date-range queries work correctly with string comparison.

Nullable throughout — mutations for models with no meaningful audited subject (e.g. a company-level config record) leave all three null and remain unchanged.

**Composite index on `(subject_name, subject_id, subject_identifier, occurred_at)`.**
Trailing `occurred_at` keeps the drawer's reverse-chronological order without a separate sort step. If a partial index filtering `subject_name IS NOT NULL` is retained, implement it with explicit PostgreSQL DDL and a short index name; Laravel's normal schema-builder `index()` does not express a partial-index predicate.

**Audit-owned enrichment only — modules describe meaning, Audit writes rows.**

The `MutationListener` can populate `subject_name` and `subject_id` automatically for models that declare a lightweight `getAuditSubject()` method returning `['name' => '...', 'id' => ...]`. This covers the common case: one model mutation belongs to one audited subject.

For subject-slot expansion, the listener stays in control. Models may optionally expose `getAuditSubjectEntries()` returning one or more subject entries in plain arrays. This lets `AttendanceRosterAssignment` describe per-date roster cells while avoiding any dependency from People to Audit. The returned entries may include `subject_identifier` and human-readable old/new summaries (shift code, policy code) so the Audit module can write useful rows without knowing People internals.

**`source` column added alongside.**
A short string distinguishes the listener's normal raw model row from listener-written expanded rows. Use `listener` for the automatic model mutation row and `expanded` for rows emitted from `getAuditSubjectEntries()`. There is no `observer` source because business observers do not write Audit rows.

**Audit context should be lean and human-operable.**
Full browser user-agent strings are too noisy for a permanent mutation log. `RequestContext` should store a compact client label instead, such as `Chrome 148 / Windows`, `Safari / iOS`, `Firefox / Linux`, `curl`, or `unknown`. If raw user-agent retention remains useful, keep it only on short-retention action rows or in a bounded payload field, not duplicated forever on every mutation row.

`occurred_at` stays because it is the event time users sort and filter by. `created_at` is redundant for append-only audit rows unless BLB explicitly wants ingestion-lag diagnostics; remove it from the audit tables if no current consumer needs it. Any retained timestamp must be app-set with `now()` rather than a database `useCurrent()` default.

`correlation_id` is conceptually useful for joining entries emitted by the same request/process, but a raw UUID is too long and opaque for the product surface. Replace it with a human-friendly `trace_id`: a 12-character Crockford Base32 token stored without separators, displayed as 4-4-4 groups when helpful, and shared by Audit and Authz decision logs. Do not expose a UUID as the main investigation handle.

**Roster cell log retired.**
`people_attendance_roster_cell_log` and `AttendanceRosterAssignmentObserver` are retired. `AttendanceRosterAssignment` exposes audit subject metadata through `getAuditSubject()` and `getAuditSubjectEntries()`, `MutationListener` writes the enriched rows to `base_audit_mutations`, and the roster history UI queries those rows by `subject_name = 'employee'`, `subject_id = employee_id`, and `subject_identifier = date (ISO)`. The cell log migration is edited in place to drop the table (destructive migration evolution).

**`is_stable` check required before migration.**
`base_audit_mutations` has production data. Per BLB convention, confirm `is_stable` status and seek explicit user approval before running any schema change against it.

## Phases

### Phase 1 — Audit schema cleanup and entity index

- [x] Check table stability and add affected audit/Authz tables to `scripts/unstable-table-list.sh`; `base_audit_actions` and `base_authz_decision_logs` now join `base_audit_mutations` for destructive local rebuilds. {amp/gpt-5}
- [x] Choose the schema-change path explicitly before editing: destructive local rebuild after marking audit/Authz log tables unstable. {amp/gpt-5}
- [x] Edit `0100_01_17_000000_create_base_audit_mutations_table.php` in place only after the chosen schema path is approved: add `subject_name` (string, nullable), `subject_id` (bigint unsigned, nullable), `subject_identifier` (string, nullable), `source` (string, default `listener`). {amp/gpt-5}
- [x] Add the matching fields to `AuditMutation` fillable/casts or document that all enriched rows use low-level inserts intentionally. {amp/gpt-5}
- [x] Add composite index `(subject_name, subject_id, subject_identifier, occurred_at)`; if partial, use explicit PostgreSQL DDL with a short index name. {amp/gpt-5}
- [x] Remove `useCurrent()` from audit timestamps and rely on app-set `now()`. {amp/gpt-5}
- [x] Decide whether `created_at` remains on audit mutations/actions. Removed from Audit mutation/action tables; `occurred_at` is the event time. {amp/gpt-5}
- [x] Replace long stored user-agent strings with a compact client label in `RequestContext`; keep raw user-agent only if intentionally bounded to short-retention action data. {amp/gpt-5}
- [x] Replace UUID `correlation_id` with a human-friendly `trace_id` shared by Audit and Authz decision logs. Implemented as 12-character Crockford Base32, stored ungrouped, no `trc_` prefix. {amp/gpt-5}
- [x] Run migration on dev and verify destructive local rebuild semantics for the trace ID schema edits (`migrate:fresh --seed --dev`; schema check confirms trace columns exist and audit mutation `correlation_id` is gone). {amp/gpt-5}

### Phase 2 — `MutationListener` subject enrichment hook

- [x] Define `getAuditSubject(): array|null` as a documented convention (not a formal interface — PHP duck-typing is sufficient). Return `['name' => string, 'id' => int]` or null. {amp/gpt-5}
- [x] `MutationListener` calls `getAuditSubject()` on the model if the method exists; writes result into `subject_name` / `subject_id` on the mutation row. {amp/gpt-5}
- [x] Add `getAuditSubject()` to `AttendanceRosterAssignment` returning `['name' => 'employee', 'id' => employee_id]`. {amp/gpt-5}
- [x] Document the convention in `app/Base/Audit/AGENTS.md`, `docs/Base/Audit/audit.md`, or an inline docblock on `MutationListener` so future model authors know to implement it. {amp/gpt-5}

### Phase 3 — Audit-owned subject-slot expansion

- [x] Define `getAuditSubjectEntries()` as the duck-typed model convention for expanded audit rows. It returns plain arrays describing subject-slot rows: `subject_name`, `subject_id`, `subject_identifier`, `event`, `old_values`, and `new_values`. {amp/gpt-5}
- [x] `MutationListener` writes expanded rows returned by `getAuditSubjectEntries()` with `source = 'expanded'`; business modules do not import Audit classes and do not write directly to audit tables. {amp/gpt-5}
- [x] Add `getAuditSubjectEntries()` to `AttendanceRosterAssignment` so roster range and exception changes produce per-date rows with `subject_name = 'employee'`, `subject_id = employee_id`, `subject_identifier = ISO date`, and human-readable `{shift_code, policy_code}` summaries. {amp/gpt-5}
- [x] Keep the raw listener row only if it provides value for forensic model-level diffs; otherwise document why expanded rows replace it for this model and implement suppression inside Audit, not inside People. Raw listener rows are retained for forensic field-level diffs; expanded rows are additional per-cell indexes. {amp/gpt-5}
- [x] Update `ManagesRosterCellHistory::loadCellHistory()` to query `base_audit_mutations` instead of `people_attendance_roster_cell_log`. {amp/gpt-5}
- [x] Update `RosterEmployeeHistory` component similarly. {amp/gpt-5}

### Phase 4 — Retire the cell log table

- [x] Edit `0320_02_05_000006_create_attendance_roster_cell_log.php` in place to drop the table (destructive evolution; table has no production data yet so no `is_stable` concern). {amp/gpt-5}
- [x] Remove `AttendanceRosterCellLog` model. {amp/gpt-5}
- [x] Remove `AttendanceRosterAssignmentObserver` and its service-provider registration. {amp/gpt-5}
- [x] Remove `ManagesRosterCellHistory` imports of the cell log model. {amp/gpt-5}
- [x] Run migration; confirm drawer and full-history page still work against `base_audit_mutations`. Schema rebuild passed with `php artisan migrate:fresh --seed --dev`; focused roster audit tests cover expanded rows. {amp/gpt-5}

### Phase 5 — Verification and docs

- [x] Add focused tests for trace ID persistence and actor-role snapshot deduplication. {amp/gpt-5}
- [x] Add focused tests for subject enrichment, expanded roster rows, and compact client labels. {amp/gpt-5}
- [x] Update `docs/Base/Audit/audit.md` so the public module design matches the shipped global-listener architecture and no longer references removed trait/service concepts. {amp/gpt-5}
- [x] Update `docs/plans/people/18e_roster-form-consolidation.md` when the delegated audit replacement ships, ticking the superseded cell-log follow-up. {amp/gpt-5}
