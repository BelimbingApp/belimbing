# Documentation Guide

## For Agents

| Topic | Read |
|-------|------|
| PHP conventions, dev philosophy, coding style | Root `AGENTS.md` |
| Database CLI (`migrate`, `--seed`, `--module`, `--seeder`) | `app/Base/Database/AGENTS.md` |
| UI / Blade / Tailwind / Alpine | `resources/core/views/AGENTS.md` |
| AI tool framework (Tool contract, AbstractTool, schema builder) | `docs/Base/AI/tool-framework.md` |
| Log Viewer (admin/system/logs) | `docs/Base/Log/log-viewer.md` |
| Table Browser (admin/system/database-tables) | `docs/Base/Database/table-browser.md` |
| Scratch / temporary work | `docs/scratch/` |

## For Developers & Licensees

| Topic | Read |
|-------|------|
| Project vision & principles | `docs/brief.md` |
| Architecture specs (layers, database, domain model, broadcasting) | `docs/architecture/` |
| How-to guides (setup, theming, extensions) | `docs/guides/` |
| Module documentation (Company, User, Employee, Geonames, Quality) | `docs/modules/` |
| Tutorials (Caddy, Vite, Livewire, logging) | `docs/tutorials/` |
| Reference (package evaluation) | `docs/reference/` |
| Planning (active) | `docs/plans/` (`docs/plans/AGENTS.md`) |
| Planning (legacy, migrating) | `docs/todo/` (`docs/todo/AGENTS.md`) |

## Directory Structure

```
docs/
├── Base/                  # Framework infrastructure documentation (Base/AI, Base/Database, etc.)
├── architecture/          # System design specs (layers, database, domain model, broadcasting, Agent)
├── guides/                # Task-oriented how-to guides
│   └── extensions/        # Extension development guides (migrations, config)
├── modules/               # Per-module documentation (overviews, APIs, design decisions)
├── tutorials/             # Learning-oriented tutorials (Caddy, Vite, Livewire, logging)
├── reference/             # Lookup tables, evaluations
├── plans/                 # Active planning documents (preferred)
├── todo/                  # Legacy planning documents (migrating to plans/)
├── scratch/               # Temporary agent workspace (discard after task)
└── brief.md               # Project vision & principles
```
