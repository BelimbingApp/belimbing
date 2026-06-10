# Review: `docs/modules/workflow/design.md`

## Scope

- Reviewed document: `docs/modules/workflow/design.md`
- Cross-checked against:
  - `docs/modules/workflow/plan.md`
  - `docs/modules/workflow/status_config.md`
  - `app/Base/Database/AGENTS.md`
  - `docs/architecture/database.md`
  - `docs/architecture/authorization.md`
  - `docs/architecture/module-system.md`

## Summary

The design is directionally strong, but it still has a few high-signal inconsistencies and implementation gaps that should be resolved before coding starts.

## Findings

### 1. Base-table naming is internally inconsistent

The document resolves Workflow as a Base module with `base_workflow_*` table names in the open-questions section, but most of the document still uses unprefixed table names.

- Says Base prefix is required: `docs/modules/workflow/design.md:646`
- Still uses unprefixed names:
  - `workflow_status_transitions`: `docs/modules/workflow/design.md:21`, `:46`, `:175`
  - `workflow_status_history`: `docs/modules/workflow/design.md:224`, `:360`
  - `workflow_kanban_columns`: `docs/modules/workflow/design.md:492`

This also conflicts with repository database conventions for Base modules:

- `app/Base/Database/AGENTS.md:7-13`
- `docs/architecture/database.md:56-65`

### 2. `flow` values are inconsistent for the order example

The process registry example uses `order_fulfillment`, while later transition/history/authz examples use `order`.

- Registry example: `docs/modules/workflow/design.md:100`
- Transition/authz examples: `docs/modules/workflow/design.md:252-255`, `:276-277`
- History schema example: `docs/modules/workflow/design.md:362`

This should be normalized to one canonical process code.

### 3. “What’s Next” still refers to an already-resolved question

Open question #1 is marked resolved, but the next-steps section still says to resolve it.

- Question resolved: `docs/modules/workflow/design.md:646`
- Still listed as pending next step: `docs/modules/workflow/design.md:659`

That makes the document look stale even though the decision has already been made.

### 4. Transition execution flow is missing failure/transaction semantics

The call flow updates model state, writes history, runs actions, and fires hooks, but the document does not define what happens if one of those steps fails.

- Call flow sequence: `docs/modules/workflow/design.md:586-595`
- Reverse-transition note explicitly says cleanup is manual: `docs/modules/workflow/design.md:338`

The design should explicitly state:

- which steps run inside a DB transaction
- whether `action_class` runs before or after commit
- whether hooks are best-effort, blocking, queued, or transactional
- how partial failure is surfaced

### 5. Guard/action placement is implied but not concretely specified

The document says business modules own process-specific pieces, and guard/action classes are resolved from FQCNs through the container, but it does not pin down where those classes live or what the recommended module structure is.

- Business modules own process-specific logic: `docs/modules/workflow/design.md:646`
- Cross-module orchestration via listeners/actions: `docs/modules/workflow/design.md:153`, `:647`
- Guard/action resolution: `docs/modules/workflow/design.md:208`, `:221`, `:314`

The doc should explicitly say something like:

- engine contracts live in `app/Base/Workflow/...`
- process-specific guards/actions live in the owning business module
- FQCNs stored in config rows must point to container-resolvable classes in those modules

### 6. Actor and assignee semantics need tighter definition

The history model says `actor_id` references users and `assignees` stores delegated users, but the doc leaves some important semantics open.

- `actor_id` meaning: `docs/modules/workflow/design.md:399-400`
- assignees JSON: `docs/modules/workflow/design.md:402-403`

Missing details include:

- whether assignees can be Agents or only human users
- whether company scoping is enforced for assignees
- whether assignment is advisory or required for completing a status

## Recommended edits before implementation

1. Rename all workflow tables in the doc to the Base naming convention consistently.
2. Pick one order process code and use it everywhere.
3. Remove stale “resolve #1” text from the next-steps section.
4. Add a short “transaction and failure policy” subsection to the engine design.
5. Add one explicit subsection describing where process-specific guards/actions live.
6. Tighten the actor/assignee rules so AuthZ and workflow behavior line up cleanly.
