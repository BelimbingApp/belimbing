# Architecture Overview

**Document Type:** Architecture Specification
**Purpose:** Index of Belimbing architecture specifications
**Based On:** Project Brief v1.0.0, Ousterhout's "A Philosophy of Software Design"
**Last Updated:** 2026-04-02

---

## Specifications

| Document | Purpose |
|----------|---------|
| [file-structure.md](file-structure.md) | Directory layers, root layout, `app/` structure, modules, extensions, config, tests, frontend |
| [database.md](database.md) | Module-first migrations, auto-discovery, seeding registry, naming and execution order |
| [broadcasting.md](broadcasting.md) | Real-time broadcasting (Laravel Reverb, Echo), channels, usage |
| [user-employee-company.md](user-employee-company.md) | User, Employee, Company entity model and relationships |
| [authorization.md](authorization.md) | AuthZ design (principals, policies, scope), RBAC, Agent alignment |
| [timezone-display.md](timezone-display.md) | Company-default datetime rendering, Local/UTC toggle model, and timezone display policy |
| [ai/overview.md](ai/overview.md) | AI architecture index and canonical path to implemented AI behavior |
| [caddy-development-setup.md](caddy-development-setup.md) | Local Caddy setup for development |

---

## Related

- **Project vision & principles:** [docs/brief.md](../brief.md)
- **Database CLI (migrate, seed, --module):** [app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md)
- **Planning & TODO:** [docs/todo/](../todo/)
