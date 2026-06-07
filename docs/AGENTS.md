# Documentation Guide

Use this file to decide whether a change needs docs and where those docs belong.

## Read First

| Topic | Read |
|-------|------|
| PHP conventions, dev philosophy, coding style | Root `AGENTS.md` |
| Active plans and implementation tracking | `docs/plans/AGENTS.md` |
| Architecture docs under `docs/architecture/` | `docs/architecture/AGENTS.md` |
| Tutorials and crash-course docs under `docs/tutorials/` | `docs/tutorials/AGENTS.md` |
| Database CLI (`migrate`, `--seed`, `--module`, `--seeder`) | `app/Base/Database/AGENTS.md` |
| UI / Blade / Tailwind / Alpine | `resources/core/views/AGENTS.md` |

## Place Docs Here

| If the doc is... | Put it in... |
|-------------------|--------------|
| vision or framework principles | `docs/brief.md` |
| a live plan or implementation tracker | `docs/plans/` |
| stable architecture or ownership | `docs/architecture/` |
| installation or first-run setup | `docs/installation/` |
| task-oriented how-to guidance | `docs/guides/` |
| module-specific concepts or contracts | `docs/modules/` |
| tutorial or domain teaching material | `docs/tutorials/` |
| operations or maintenance procedure | `docs/runbooks/` |
| reference or evaluation material | `docs/reference/` |
| temporary notes | `docs/scratch/` |
| ongoing Lara ideation thread | `docs/ktoh/` |

## Update Docs When

- a stable contract, boundary, or system shape changes
- a user, operator, or contributor workflow changes
- a module gains behavior or ownership rules worth preserving
- a plan is the active source of truth for the task

Do not add docs just to narrate an obvious code change.

## Rules

- Keep the smallest durable doc that preserves the decision, contract, or workflow.
- Keep status tracking in `docs/plans/`, not in architecture or guide docs.
- Prefer links to owning code or sibling docs over duplicated prose.
