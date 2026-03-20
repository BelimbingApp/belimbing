# Playbooks

Targeted implementation context so agents can build features without broad codebase sweeps.

## Agent Workflow

1. Match the task against the Playbook Index checklists below.
2. If two playbooks match, apply Tie-Breakers.
3. Load the matched playbook and read its Minimal File Pack.
4. Implement using the playbook's contract, invariants, and skeletons.
5. If no playbook matches, implement using the nearest existing pattern and create a new playbook afterward.

## Tie-Breakers

- If `FEAT-NEW-BUSINESS-MODULE` matches (new module from scratch), choose it first — it sequences phases internally.
- If `FEAT-WORKFLOW-CONSUMER` and `FEAT-MODULE-FEATURE` both match, choose `FEAT-WORKFLOW-CONSUMER` first.
- If `FEAT-DISCOVERY` matches with any feature playbook, choose `FEAT-DISCOVERY` only when infrastructure behavior must change for multiple modules.

## Architecture Anchors

Load the relevant anchor when the task touches that domain. These do not replace playbooks — they provide cross-cutting conventions.

- UI conventions: `resources/core/views/AGENTS.md`
- Database conventions: `app/Base/Database/AGENTS.md`
- Authz conventions: `app/Base/Authz/AGENTS.md`
- Foundation provider ordering: `app/Base/Foundation/AGENTS.md`

## Playbook Index

- id: FEAT-NEW-BUSINESS-MODULE
  intent: create a complete new business module from scratch using the IT Ticket pattern
  checklist:
    - task creates a new module directory under app/Modules/
    - task requires model, migration, CRUD pages, routes, menu, and authz
  load: .agents/playbooks/feat-new-business-module.md

- id: FEAT-MODULE-FEATURE
  intent: add a permissioned CRUD feature to a module — route, authz, Livewire, Blade, tests
  checklist:
    - task adds or changes a feature page with route, authz, and UI
    - task includes Livewire component with Blade view and authorization
  load: .agents/playbooks/feat-module-feature.md

- id: FEAT-MODULE-SCHEMA
  intent: implement module schema or seeder changes using module-aware migration flow
  checklist:
    - task creates or edits migration in app/Base or app/Modules
    - task adds, removes, or changes module seeder registration
  load: .agents/playbooks/feat-module-schema.md

- id: FEAT-WORKFLOW-CONSUMER
  intent: integrate a module model with the BLB workflow engine for status lifecycle
  checklist:
    - model needs status transitions with history and guards
    - module requires workflow seeder with statuses, transitions, and kanban columns
  load: .agents/playbooks/feat-workflow-consumer.md

- id: FEAT-LW-INLINE-EDIT
  intent: implement inline validated field saves on Livewire detail pages
  checklist:
    - task updates single fields without full form submit
    - task requires per-field validation before persistence
  load: .agents/playbooks/feat-livewire-inline-edit.md

- id: FEAT-CONSOLE-BLB
  intent: add BLB commands or Laravel command overrides with framework naming rules
  checklist:
    - task adds new artisan command in BLB scope
    - task overrides or extends Laravel migration/console command wiring
  load: .agents/playbooks/feat-console-command-blb.md

- id: FEAT-DISCOVERY
  intent: extend framework auto-discovery for providers, routes, menus, or Livewire components
  checklist:
    - task changes discovery scan patterns or registry behavior
    - task affects bootstrapping for more than one module
  load: .agents/playbooks/feat-discovery-driven-infrastructure.md

## Maintenance

- When implementing a task using a playbook, if you discover a better pattern, a new invariant, or a corrected reference path, update the playbook in the same change. Playbooks are living documents — they evolve with the codebase.
- Keep reference paths and symbols accurate.

## Creating a New Playbook

When no playbook matches a task, create one after implementation.

**Prefer compositional over fragmented.** A playbook should cover the full vertical of a task — not a single concern that always requires loading other playbooks alongside it. If a pattern always needs authz + route + UI together, that's one playbook, not three.

**Minimal File Pack rule:** Only list files the agent must actually read as templates. If the playbook's skeleton already shows the pattern, don't list the source file. Aim for ≤ 5 files.

**Required sections:**

```markdown
# FEAT-{ID}

Intent: one sentence.

## When To Use
## Do Not Use When
## Minimal File Pack
## Reference Shape
## Required Invariants
## Implementation Skeleton
## Test Checklist
## Common Pitfalls
```

After creating the playbook file in `.agents/playbooks/`, add its entry to the Playbook Index above with `id`, `intent`, `checklist`, and `load`.
