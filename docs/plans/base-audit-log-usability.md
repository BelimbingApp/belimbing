# base-audit-log-usability.md

**Status:** In progress — Phases 1–3 implemented and validated; source-record history remains next.
**Last Updated:** 2026-06-18
**Sources:**
- User report and screenshot of `admin/audit/actions` showing generic action rows with raw URL/payload columns.
- `app/Base/Audit/AGENTS.md` — Audit module rules and writer-coupling constraints.
- `docs/plans/base-audit-subject-index.md` — completed mutation subject-index foundation.
- `app/Base/Audit/Database/Migrations/0100_01_17_000000_create_base_audit_mutations_table.php` — mutation fields, trace, and subject indexes.
- `app/Base/Audit/Database/Migrations/0100_01_17_000001_create_base_audit_actions_table.php` — action fields, retention flag, and trace index.
- `app/Base/Audit/DTO/RequestContext.php` — request/process trace and actor context.
- `app/Base/Audit/Middleware/AuditRequestMiddleware.php` — automatic HTTP action payload shape.
- `app/Base/Audit/Listeners/MutationListener.php` — automatic mutation writer and subject hooks.
- `app/Base/Audit/Livewire/AuditLog/Actions.php` and `resources/core/views/livewire/admin/audit/actions.blade.php` — current Actions page behavior.
- `app/Base/Audit/Livewire/AuditLog/Mutations.php` and `resources/core/views/livewire/admin/audit/mutations.blade.php` — current Data Mutations page behavior.
- `resources/core/views/livewire/admin/users/show.blade.php` and `app/Modules/Core/User/Models/User.php` — first source-record history candidate.
**Agents:** amp/gpt-5

## Problem Essence

BLB captures audit rows, but the global Actions page is a technical request/event table rather than an investigation tool; users cannot quickly answer what happened, what changed, or which steps belong to the same sequence. Mutation diffs exist globally, but source records such as `user#3` do not expose local history where operators need it.

## Desired Outcome

Audit becomes useful at two levels. The global Audit area lets an operator find a meaningful event, open its trace, and inspect the surrounding actions and mutations in chronological order. Source record pages expose a compact local history trigger that answers: what changed on this record, who changed it, when, and which broader trace/action caused it.

## Current Reality and Limits

What current data can support immediately:
- Actions already store actor, role snapshot, company, IP, compact client label, URL, event, payload, `trace_id`, retention flag, and `occurred_at`.
- Mutations already store actor context, direct `auditable_type` / `auditable_id`, old/new values, `trace_id`, and the completed subject index: `subject_name`, `subject_id`, `subject_identifier`, `source`.
- `trace_id` is indexed on both action and mutation tables, so same-request/process timelines can be built without a schema change.
- Direct model history can be queried for any model by `auditable_type` and `auditable_id`, even before that model has subject metadata.

What current data cannot fully answer:
- Many action rows are generic transport events (`http.request`, auth, console, queue). They often do not preserve the user's semantic intent, clicked control, Livewire method, business operation name, target subject, or affected-row count.
- Historical rows cannot gain semantics that were never captured; presentation can make existing rows readable, but it cannot reliably reconstruct a missing action name.
- Actions do not currently have `subject_name` / `subject_id`, so action-to-record lookup must use `trace_id`, URL/payload inference, or future semantic action capture.
- Action rows are prunable after the configured retention window unless retained; older mutation rows must remain useful even when their initiating action row is gone.
- Queue and console contexts generate their own traces today; HTTP-to-queue causality is not guaranteed unless trace propagation is added deliberately.
- Source pages will miss related-record changes unless those related models expose subject metadata. For a User page, role/capability assignments can be more important than direct `users` table updates.

The screenshot shows `/livewire...`-style URLs dominating the Actions table. Current middleware attempts to skip named Livewire routes, so Phase 1 must verify whether the screenshot reflects older data, unnamed Livewire routes, or a filter gap before changing logging behavior.

## Phase 1 Evidence Summary

Evidence captured from the local development database on 2026-06-18: `base_audit_actions` had 9,724 rows and `base_audit_mutations` had 249 rows. Counts are useful for shape and proportion, not as a production baseline.

### Actions profile

- Event mix is overwhelmingly request diagnostics: `http.request` 9,452 rows, `console.command` 163, `auth.login` 82, `auth.login.failed` 13, `queue.job.processed` 13, `auth.logout` 1.
- Trace coverage is strong: 9,724/9,724 action rows have `trace_id`; 9,598 distinct traces exist.
- Retention has not been used locally: 0 action rows are marked retained.
- HTTP status data is useful for failure filters: 9,308 rows are 200, 18 are 302, 37 are 404, and 89 are 500. Non-2xx HTTP rows total 126.
- HTTP payload shape is intentionally thin: every HTTP row only has `method`, `route`, `status`, and `duration_ms`. This is enough for route/status/duration search, not enough to reconstruct clicked controls or business intent.
- The main noise source is current, not only historical: `default-livewire.update` appears 4,487 times and maps to `/livewire-89cd7b8d/update`. The middleware skip checks route names starting with `livewire`, but the actual route name is `default-livewire.update`, so these rows still log.
- The next high-volume HTTP stream is AI turn event polling/streaming (`ai.chat.turn.events` 2,694 rows). Like Livewire updates, this should be treated as diagnostic traffic by default, not primary action history.
- Console payloads can answer command, arguments, and exit code. Queue payloads can answer job, queue, and connection. Neither records business target subject by default.

### Mutations profile

- Trace coverage is strong: 249/249 mutation rows have `trace_id`; 189 distinct mutation traces exist.
- Trace timelines are immediately valuable: 216/249 mutation rows already have at least one matching action row with the same trace. The remaining 33 mutation rows must still render as mutation-only traces, because action rows may be absent or pruned.
- Subject coverage is the main source-history gap: 0/249 mutation rows have `subject_name`, `subject_id`, or `subject_identifier` populated in the local data. Every row is currently `source = listener`; no `expanded` rows are present in this data set.
- Direct auditable fallback is therefore required for the first source-history drawer. Without it, current historical User rows would not show on source pages until new subject metadata is added and new mutations are recorded.
- Top direct mutation types are concentrated in operational areas: `ListingDraft` 98, `Setting` 52, inventory `Item` 35, IBP `ImportBatch` 13, `ProductTemplate` 8, `User` 7, `Address` 7.
- High-noise mutation fields exist. `metadata_checked_at` appears 189 times, and large/snapshot fields such as `readiness_snapshot`, `metadata`, and `mapped_aspects` appear often. Phase 5 should review model-level `auditExclude` / `auditTruncate` rules so useful diffs are not buried.

### Current data can / cannot answer matrix

| Incident | Current rows can answer | Current rows cannot fully answer | Build implication |
|---|---|---|---|
| User field update | Direct `User` mutations exist by `auditable_type` / `auditable_id`; they include actor context, time, changed field names, redacted password values, and trace. | No `subject_name = user`, so subject-only source history misses existing rows. HTTP/Livewire action row does not say which field/editor was used. | User history drawer must use direct auditable fallback first; add `User::getAuditSubject()` for future rows. |
| User role assignment | At least one `PrincipalRole` mutation exists and shares trace with the surrounding request/action. | `PrincipalRole` has no user subject metadata, so a User page cannot reliably find role changes affecting that user. | Add subject metadata to user-scoped authz assignment models before calling User history complete. |
| Failed login | `auth.login.failed` rows include event, actor type, IP, URL/client, trace, and email payload. | No reason taxonomy beyond failed login; unknown emails are not a durable subject; brute-force grouping needs search/filter UI. | Actions page can support failed-login investigation with filters now; semantic enrichment can wait. |
| Console command | Rows include command, arguments, exit code, actor/process context, and trace. | No duration or affected subject unless mutations share trace; business intent is command-specific. | Summaries and filters are useful now; subject capture is optional per command later. |
| Queue job | Rows include job, queue, connection, event type, and trace. | Current queue context generates a fresh trace, so HTTP-to-job causality is not guaranteed. No parent dispatch trace. | Do not promise cross-process sequence until trace propagation is deliberately built. |
| Roster cell change | Code has the subject-index design for employee/date expanded rows. | Local data contains no roster assignment or `subject_name = employee` rows to verify in this evidence pass. | Keep roster validation as an implementation test/manual scenario, not as proof from current data. |

### Evidence-driven plan changes

- Phase 2 must add a default diagnostic-noise policy, not just better columns. `default-livewire.update`, AI event streams, media streams, and similar transport rows should be hidden by default or captured only under diagnostic/failure conditions.
- Phase 3 is high leverage because trace coverage is complete in both tables and most mutation rows already join to an action trace.
- Phase 4 must support direct `auditable_type` / `auditable_id` fallback from day one because historical rows currently lack subject metadata.
- Phase 5 should prioritize subject metadata for User/Authz records and field-noise cleanup before broad source-page rollout.
- Phase 6 remains necessary for future business intent. Current HTTP/Livewire rows can identify transport route/status/duration, but they cannot reconstruct the semantic action a user performed.

## Top-Level Components

### Admin audit investigation surface

The Actions page should become a compact investigation table instead of a raw payload viewer. It should summarize each row from existing data, search by operational handles, and open a correlated trace timeline.

### Trace timeline drawer

A trace timeline should show every available action and mutation with the same `trace_id`, sorted by event time. It is the main way to inspect a sequence of work and understand what a request/process changed.

### Source-record history drawer

Detail pages should expose a small History trigger near the page title or header actions. The first implementation target is `admin/users/{user}`. The drawer should show local mutations, field diffs, actor, time, and trace links without leaving the record page.

### Audit read and presentation layer

Shared Audit-owned helpers should normalize actor labels, action summaries, trace formatting, event/result labels, and mutation diff rendering so the global pages and source drawers do not duplicate inline JSON/diff markup.

### Subject metadata coverage

Important source pages need subject coverage for their own model and high-value related models. For a User page this means at least `User`, user-scoped `PrincipalRole`, user-scoped `PrincipalCapability`, and likely `ExternalAccess` if access grants are expected in the user's history.

### Future semantic action capture

If exploration confirms that request-level data cannot answer user-intent questions, add explicit semantic action descriptors at Livewire/controller/service boundaries. Raw HTTP actions stay diagnostic; semantic actions become the product audit trail for “what the user intended to do.”

## Design Decisions

Use existing `trace_id` and mutation subject indexes first. The first useful version should be mostly query and presentation work; do not start with a broad schema rewrite.

Make trace the primary sequence primitive. Show trace IDs in a readable 4-4-4 form, allow copy/search by raw or formatted trace, and tolerate mutation-only timelines when old action rows have been pruned.

Promote summaries over raw payloads. The main Actions table should show an action summary, route/page/path context, result/status, actor, occurred time, trace, and retain state. Full URL, IP/client, and raw payload belong in row detail.

Hide diagnostic transport noise by default. `default-livewire.update`, AI event streams, media streams, and similar high-volume technical traffic should not dominate the primary Actions view. Keep them searchable through an explicit diagnostics filter and always show failures/non-2xx responses.

Keep “user as subject” distinct from “user as actor.” On `user#3`, the default history should mean changes affecting that user/account. A separate global actor timeline can answer “what did this user do?”

Use a drawer for local history and trace detail. A side drawer preserves record context and has enough room for a dense timeline. A full page is still useful for deep filtering/export. A modal is too cramped for the default investigation workflow.

Keep writer coupling low. Business modules should not write Audit rows directly. They may expose duck-typed `getAuditSubject()` / `getAuditSubjectEntries()` metadata; the Audit listener remains the writer.

Gate history conservatively. First iteration should show record history only to users who can view the source page and have the existing audit-log capability. If product wants ordinary record maintainers to view only local history, add a narrower capability deliberately.

Respect redaction and retention. Redacted values stay redacted everywhere. Password/secret changes should show that a protected field changed, not reveal the value. Retained sequences should prevent important action context from being pruned where the operator explicitly preserves it.

Keep the UI lazy and compact. History drawers should load on demand and paginate/limit rows. Do not render full histories into initial detail-page HTML.

## Public Contract

The source history surface accepts:
- `subject_name` and `subject_id` for domain-level history.
- optional `subject_identifier` for sub-record slots such as dates, line codes, or config keys.
- optional direct fallback `auditable_type` and `auditable_id` for models that do not yet expose subject metadata.
- a human label for the drawer heading.

The trace timeline surface accepts a `trace_id` and renders all available correlated audit rows. Missing action rows due to retention are a valid state, not an error.

Models may continue to expose audit subject metadata through plain methods only:
- `getAuditSubject(): ?array`
- `getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array`

Semantic action capture, if added, should use stable machine event names plus a human summary, subject metadata, sanitized context, outcome, and the current trace. Raw request payloads remain secondary diagnostics.

## Phases

### Phase 1 — Evidence pass and investigation map

Goal: prove which parts of the current audit data are usable now and where missing semantics block the two requested workflows.

Affected pages: `admin/audit/actions`, `admin/audit/mutations`, `admin/users/{user}`.

- [x] Profile representative `base_audit_actions` rows by event, URL/path, route payload, actor type, status/result, trace coverage, retention state, and payload keys. {amp/gpt-5}
- [x] Profile representative `base_audit_mutations` rows by subject coverage, direct auditable coverage, source (`listener` vs `expanded`), trace coverage, and high-noise fields. {amp/gpt-5}
- [x] Verify whether current Livewire HTTP actions are still being logged despite the middleware skip, and decide whether to hide, summarize, or stop capturing that noise. Current route is `default-livewire.update`, so the existing `livewire*` route-name skip misses it; hide/suppress diagnostic transport rows by default. {amp/gpt-5}
- [x] Write a “current data can / cannot answer” matrix for at least these incidents: user field update, user role assignment, failed login, console command, queue job, and roster cell change. {amp/gpt-5}
- [x] Identify the first source pages that need local history, with `admin/users/{user}` as the first build target. Evidence supports User first; Commerce item/listing and Settings surfaces are likely next because they dominate local mutation rows. {amp/gpt-5}

Validation: evidence summary recorded above; Phase 2 should start from diagnostic-noise filtering plus summary/search redesign.

### Phase 2 — Make existing action data readable

Goal: turn the Actions page into a useful investigation surface without requiring new audit-write semantics first.

Affected pages: `admin/audit/actions`.

- [x] Replace the main raw URL/payload-heavy table with compact columns for occurred time, actor, action summary, page/context, result, trace, and retain state. {amp/gpt-5}
- [x] Derive summaries for current event families: HTTP request, auth login/logout/failed login, console command, queue processed/failed, plus retained domain lifecycle actions. {amp/gpt-5}
- [x] Hide diagnostic transport rows such as `default-livewire.update`, AI event streams, and media streams by default while keeping them available through an explicit diagnostics filter and always surfacing failures. Local PostgreSQL check: default filtered Actions total dropped from about 9.7k to about 2.4k rows while Show Diagnostics retained all rows. {amp/gpt-5}
- [x] Expand search/filter support to include trace ID, actor, actor role/type, event family, route/path/URL fragment, IP/client, command/job names, auth email where safe, result status, and retained-only. {amp/gpt-5}
- [x] Move raw payload and full URL into the trace drawer detail section, not the main table. {amp/gpt-5}
- [x] Add clear empty states explaining which filters are active and whether rows may have been hidden by diagnostic filtering. {amp/gpt-5}

Validation: `php artisan test tests/Feature/Audit` passed; local PostgreSQL smoke check confirmed the filtered and Show Diagnostics queries both execute.

### Phase 3 — Add trace timelines for sequence reconstruction

Goal: let users follow a sequence of actions and mutations from one investigation handle.

Affected pages: `admin/audit/actions`, `admin/audit/mutations`.

- [x] Add an Audit-owned trace timeline query that fetches actions and mutations with the same `trace_id` and orders them deterministically by `occurred_at` and row id. {amp/gpt-5}
- [x] Add a trace drawer opened from action and mutation rows. {amp/gpt-5}
- [x] Render action summaries and mutation field diffs in the same timeline, with raw payload/diff detail available only when needed. {amp/gpt-5}
- [x] Show trace metadata: formatted trace ID, actor(s), first/last occurrence, row counts, and whether action context may have been pruned. {amp/gpt-5}
- [x] Keep the drawer useful when only mutations remain for a trace. {amp/gpt-5}

Validation: `tests/Feature/Audit/AuditLogUiTest.php` covers diagnostic hiding, raw action detail expanded by default, and opening combined action/mutation timelines from both Actions and Mutations; `php artisan test tests/Feature/Audit`, `./vendor/bin/pint --dirty`, and `git diff --check` passed. A local PostgreSQL smoke check for Actions search `users` also passed after casting `ip_address` before text search.

### Phase 4 — Add source-record history on the User page

Goal: expose mutation history at the source record without forcing users into the global Audit pages.

Affected pages: `admin/users/{user}`.

- [ ] Add an Audit-owned, lazy source-history drawer component/query that accepts subject keys and a direct auditable fallback.
- [ ] Add a compact History trigger near the User page title/header actions, with an accessible label and no initial-history HTML bloat.
- [ ] For the first User-page version, query direct `User` model mutations by `auditable_type` / `auditable_id` and any available `subject_name = user` rows.
- [ ] Show occurred time, actor, event, changed fields, old → new values, and trace links.
- [ ] Provide a full Audit-page/deep-link path for larger histories once the drawer limit is reached.

Validation: changing a user's name or email from `admin/users/{user}` results in a visible local history row showing actor, time, field, old value, new value, and trace.

### Phase 5 — Expand subject coverage for source history

Goal: make source histories include the related changes users actually care about, not just the direct model row.

Affected pages: `admin/users/{user}` first; later Employee, Company, and other high-value detail pages.

- [ ] Add `getAuditSubject()` to `User` so direct User mutations also index as `subject_name = user`, `subject_id = user.id`.
- [ ] Add subject metadata to user-scoped authorization assignment models so role and capability changes appear on the affected User page.
- [ ] Add subject metadata to `ExternalAccess` if external access grants should appear in User history.
- [ ] Review noisy/sensitive User-adjacent fields and add model-level audit exclude/redact/truncate metadata where the current diffs are not useful or safe.
- [ ] Add focused regression coverage proving direct User updates and user role/capability changes appear in source history while redacted values stay redacted.

Validation: assigning/removing a role or direct capability for `user#3` appears in `user#3` history with the affected subject, actor, time, and trace.

### Phase 6 — Add semantic action capture where current data is insufficient

Goal: capture future action intent at the right boundaries when presentation alone cannot answer “what did the user do?”

Affected pages: to be chosen from Phase 1 evidence; likely User management and one high-volume operational workflow.

- [ ] Define a semantic action descriptor contract with stable event name, human summary, source surface, subject keys, sanitized context, outcome, mutation count or linked trace, and retention expectation.
- [ ] Decide whether `base_audit_actions` needs subject columns and expression indexes, or whether semantic subject data can live in payload plus query projections for the first iteration.
- [ ] Separate raw request diagnostics from product actions: raw HTTP remains short-retention/noise-controlled; semantic actions explain business operations and should be retained long enough to support mutation investigations.
- [ ] Add trace propagation for queued jobs only if Phase 1 shows users need HTTP-to-job sequence reconstruction.
- [ ] Prototype semantic capture on one workflow, then update this plan before broad rollout.

Validation: the chosen prototype workflow records a human-readable action such as “Updated user email” or “Assigned role to user,” links to its mutations by trace, and can be found from both the Actions page and the source record history.

### Phase 7 — Verification, docs, and rollout guardrails

Goal: make the new audit surfaces reliable, fast, and safe enough for normal operations.

- [ ] Add focused tests for action search/filter behavior, action summary derivation, trace timeline correlation, source-history lookup, authorization gating, and redaction display.
- [ ] Verify page weight stays within UI guidance by lazy-loading drawers and paginating/limiting histories.
- [ ] Update Audit module docs/AGENTS guidance if new source-history or semantic-action conventions are introduced.
- [ ] Add a rollout note describing which historical questions are answerable from old rows and which require newly captured semantic actions.

Validation: targeted tests pass, a manual browser pass covers `admin/audit/actions`, `admin/audit/mutations`, and `admin/users/{user}`, and the plan status/checklist reflects completed work.
