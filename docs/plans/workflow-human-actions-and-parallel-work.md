# Workflow human actions and parallel work

**Status:** Implemented; repository delivery in progress; tenant-safe coordination and human action backend implemented, shared presentation/evidence adoption remains
**Last Updated:** 2026-09-16
**Sources:** `AGENTS.md`; `DESIGN.md`; `docs/architecture/authorization.md`; `docs/architecture/tenancy.md`; `docs/modules/workflow/design.md`; `docs/modules/workflow/workflow-design-review.md`; `docs/plans/workflow-transition-effect-delivery.md`; `app/Base/Workflow/`
**Agents:** `codex/gpt-6-astra`

## Problem Essence

Workflow has guarded single-status transitions and durable parallel process coordination, but the process coordinator is an internal execution API rather than a tenant-safe, actor-authorized human action boundary. Business modules would otherwise duplicate authorization, task joins, history presentation and evidence handling or expose infrastructure operations directly to users.

## Desired Outcome

A business module can define its lifecycle, parallel human tasks and authorized actions through a small shared contract. Users see the current result, outstanding work and permitted actions; concurrent submissions, retries and revisions preserve one truthful history. Existing status-only workflows continue to work.

## Top-Level Components

- Existing WorkflowEngine owns one lifecycle status and transactional transition history.
- Existing ProcessCoordinator owns durable dependencies, work completion and recovery; it remains an internal execution API.
- An actor-aware action service bridges human intent, module policy and durable work. Business modules own action payloads, validation, outcomes and form content.
- Shared UI renders activity, authorized action controls, modals and attachment input/display. Audit remains the authoritative technical change record.

## Design Decisions

### Lifecycle and parallelism

Options are an array of lifecycle statuses, a second module-specific task engine, or composition of the existing status engine and process coordinator. Use composition. A subject retains one lifecycle status; parallel tasks each have their own progress. Module-defined phases group statuses for presentation and are derived, never an independently mutable field. Existing StatusConfig.kanban_code and KanbanColumn already support grouping; validate their suitability before adding another phase table. Business transition guards must not depend on a freely rearrangeable board column.

### Human action boundary

Expose actor-authorized actions, not arbitrary coordinator commands. Existing availableTransitions() describes graph edges and is not proof of permission. Add a separate actor-aware discovery/execution contract with shared policy evaluation; recheck authorization and expected version under lock on execution. Preserve existing callers rather than silently changing their semantics. An action may complete a task without changing the subject status.

### Reuse and ownership

Keep generic coordination and presentation upstream. Keep scoring, business deadlines, outcome rules, specialized fields and external communication content in their owning modules. Prefer extending existing record-history and upload infrastructure after inventory over introducing a second audit store or a universal configurable form builder. This follows low entropy, deep modules and the established four-root boundary.

## Public Contract

### Definitions and scope

Definitions are registered by their owning module with stable keys and immutable versions. Persisted runs retain the version that created them. Different workflows may define different statuses, phases and tasks. The coordinator requires an acyclic graph: repeatable review loops start a linked replacement run; they do not introduce graph cycles.

Tenant-owned human work carries indexed tenant_id and fails closed without TenantContext. Migration `0100_01_15_000007_add_tenant_scope_and_human_actions_to_workflow.php` adds explicit `tenant`, `system`, and fail-closed `unresolved` run scopes and propagates tenant identity to work items, dependencies, and events. New business integrations call `startForTenant()`; actual framework jobs may call `startForSystem()`. The compatible legacy `start()` infers a current tenant, preserves subject-less background callers as system scope, and records subject-owned calls without tenant evidence as unresolved rather than granting tenant or system reach. Definition metadata remains shared; business instances do not.

### Actions and concurrency

Discovery accepts the authenticated actor and subject, returning only authorized actions with labels and availability. An authorized but blocked action may include a useful reason; unauthorized actions reveal no protected details. Execution accepts a stable action identity, expected subject/task version, idempotency key, module-validated payload and attachment references. Actor, tenant, subject ownership and business outcome are server-derived.

The same successful request is retry-safe. Reusing a key with different intent fails. Stale edits yield a recoverable conflict. Assignment is a work-routing hint unless a module explicitly makes it a guard; assignment never grants a capability.

Task completion, immutable result reference, dependency satisfaction, subject transition and durable business event commit atomically on the same database connection. Lock order is consistent and verified against nested transactions. External effects use the existing durable outbox/after-commit mechanism. The final task may satisfy an ALL join once; its submitter acquires no authority to execute a separate approval action. A system advancement records its triggering actor/request without impersonating a human approver.

Human drafts do not hold worker leases while a browser remains open. Any internal claim needed to complete work is short-lived within server processing. Generic waive, block, supersede and signal methods are never exposed as permission-free browser endpoints.

Replacement runs bind exact immutable prior results explicitly. Late signals or claims for superseded runs cannot complete current work. Infrastructure completion statuses and business task labels remain distinct.

### History, forms and files

Shared business activity presentation uses When / Action / By / Details, newest first, deterministic ordering and pagination. Each visible event carries stable identity, time, actor snapshot, business action, comment, subject/round references and authorized attachments/results. Adapt existing events and audit references; do not persist a redundant timeline solely for rendering. Filter infrastructure noise and never fabricate historical actors.

Use existing x-ui modal, table, badge, datetime and input components. Keep form bodies module-owned. Shared attachment handling must enforce size and detected type, private opaque storage keys, tenant/subject authorization on download, safe download headers, upload completion checks and orphan cleanup. Final events bind immutable attachment references; replacing draft evidence must not rewrite submitted evidence. Images and documents share the same access policy. Supplier/public access must be separately scoped; possession of an internal file ID grants nothing. Inventory existing storage/security scanning policy and reuse it where available; do not claim scanning without a configured scanner.

## Implemented integration API

Owning modules tag a `HumanActionContributor` with `HumanActionContributor::CONTAINER_TAG`. The contributor registers stable `HumanActionDefinition` values by subject model through `HumanActionRegistry`. A definition carries its key, label, capability, container-resolved handler class and, for process work, the matching executor key. Handler callbacks are not persisted in process definitions.

`HumanActionService::available(Actor, Model)` returns only authorized actions. Process-backed actions include the run ID, work-item ID and work-item version needed for a safe submit. `HumanActionService::subjectVersion(Model)` produces the subject token from the complete persisted attribute snapshot.

Execution uses `HumanActionService::execute(Actor, Model, HumanActionRequest)`. The request contains:

- `actionKey`
- tenant-scoped `idempotencyKey`
- `expectedSubjectVersion`
- module-owned validated `payload`, including attachment public IDs when applicable
- optional `processRunId`, `workItemId`, and `expectedWorkItemVersion` for process-backed actions

The service locks and re-resolves the subject, rechecks actor authorization and tenant ownership, verifies the run belongs to the exact subject, and executes the module's `HumanActionHandler`. The handler returns `HumanActionOutcome` with output, outcome, and optional immutable result reference. Process-backed completion then locks run before work item and reconciles dependencies in the same outer database transaction. A successful retry returns the durable ledger result; actor identity and intent are hashed, so another actor or changed payload cannot reuse the key.

## Inventory and migration evidence

- Direct coordinator consumers before adoption were the workflow reconciler and durable workflow tests. The first business adopter is the private Supplier Evaluation extension through `EvaluationCoordination`; browser code uses `HumanActionService`, not coordinator administrative methods.
- The development database had zero persisted process runs before the migration. No tenant backfill was therefore required locally. The additive migration still marks any pre-existing run `unresolved`; it does not assign an arbitrary tenant.
- Code-defined `ProcessDefinition` versions and their fingerprints remain immutable. Existing status-only `WorkflowEngine` callers retain their behavior.
- Shared evidence uses `PrivateAttachmentStore`, `MediaAttachment`, opaque ULIDs, subject-specific `AttachmentSubjectAuthorizer`, detected MIME and size limits, private local storage and forced-download headers. No malware scanner is configured or claimed.
- Shared media review found and fixed the submit/cleanup race: discard locks and re-reads persisted state; user-triggered removal checks the uploader and subject policy. Retained drafts and submitted evidence are excluded from orphan cleanup.

## Phases

### Checkpoint 1 — Contract and migration inventory

- [x] Inventory ProcessCoordinator callers, definitions, persisted runs and attachment facilities; record supported system scope and tenant backfill evidence. `codex/gpt-5.6-sol`
- [x] Use module-owned derived phase mapping and shared history row slots. Actor-aware discovery/execution is implemented and status-only workflow compatibility is retained. `codex/gpt-6-astra`, `codex/gpt-5.6-sol`
- [x] Add the migration and fail-closed handling for unresolvable historical ownership without assigning arbitrary tenants. `codex/gpt-5.6-sol`

Validation completed: Workflow and Authz suites pass (152 tests, 589 assertions, one existing skip). Durable workflow coverage exercises tenant, compatible system/unresolved starts and the additive migration; explicit scope assertions remain worth adding before release.

### Checkpoint 2 — Authorized human coordination

- [x] Implement the boundary with tenant/actor checks, expected versions and request idempotency. `codex/gpt-5.6-sol`
- [x] Prove atomic task completion and exactly-once business advancement through existing coordination and transition APIs. `codex/gpt-5.6-sol`
- [x] Prove supersession, explicit carried results and stale-run rejection without long-lived browser leases. `codex/gpt-5.6-sol`

Validation completed: durable workflow and the first business adopter cover all submission orders, rollback of enclosing transactions, duplicate successful requests, changed intent, actor-bound idempotency, replay authorization, other-subject run IDs, stale subject/work versions, supersession and late completion. Independent PostgreSQL process verification below proves simultaneous-final-submit serialization and replay.

### Checkpoint 3 — Shared presentation and evidence

- [x] Extend shared history presentation through module-owned row slots without regressing existing record-history users. `codex/gpt-5.6-sol`
- [x] Deliver reusable authorized action/modal conventions and attachment input/display using established primitives. `codex/gpt-5.6-sol`
- [x] Verify private file access, invalid/oversized files, retained drafts, immutable evidence and the submit/cleanup race. `codex/gpt-5.6-sol`
- [ ] Complete the shared-component keyboard/mobile accessibility matrix across consuming modules.

Validation: a minimal framework fixture demonstrates the contract without embedding a consuming module's business rules or private data.

### Checkpoint 4 — Adoption and release

- [x] Document module integration, definition upgrades and run supersession through the concrete API above and the consumer runbook. `codex/gpt-6-astra`, `codex/gpt-5.6-sol`
- [x] Complete tenancy/shared UI regression and schema upgrade rehearsal; existing business histories survive unchanged. `codex/gpt-6-astra`, `codex/gpt-5.6-sol`
- [ ] Land upstream framework changes first; consuming repositories pin/receive them before adopting the new API.

## Boundaries

No graphical workflow builder, arbitrary user-authored forms, replacement queue, company segregation policy, or business-specific status codes belong in this work. This plan does not authorize deployment or modification of production data.

### Final integration verification

Shared components render caller-owned business rows; no unused history DTO or adapter interface is stored in Media. Retained draft attachments survive scorecard reopen and are excluded from orphan cleanup. Discard rechecks persisted state under a row lock; submitted evidence cannot be deleted by a stale cleanup model. Public attachment metadata/download queries are scoped to tenant and business subject.

Combined consuming-module/framework regression passed 314 tests / 1,637 assertions with one existing skip. Independent PostgreSQL processes proved final-task serialization, one lifecycle advance and idempotent replay in an isolated disposable database (1 test / 15 assertions); the business-specific proof belongs to its private consumer repository. The scratch database was dropped. Authenticated browser verification covered the complete score/evidence/finalize/document/acknowledge/complete flow with stable supplier tabs and no JavaScript errors. No external messages were sent in acceptance testing.
