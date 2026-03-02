# Architecture Overview

**Document Type:** Architecture Specification
**Purpose:** Index of Belimbing architecture specifications
**Based On:** Project Brief v1.0.0, Ousterhout's "A Philosophy of Software Design"
**Last Updated:** 2026-02-07

---

## Specifications

| Document | Purpose |
|----------|---------|
| [file-structure.md](file-structure.md) | Directory layers, root layout, `app/` structure, modules, extensions, config, tests, frontend |
| [database.md](database.md) | Module-first migrations, auto-discovery, seeding registry, naming and execution order |
| [htmx-interaction-contract.md](htmx-interaction-contract.md) | 7-policy HTMX contract: URL naming, validation, events, concurrency, navigation, partials, tests |
| [broadcasting.md](broadcasting.md) | Real-time broadcasting (Laravel Reverb, Echo), channels, usage |
| [user-employee-company.md](user-employee-company.md) | User, Employee, Company entity model and relationships |
| [authorization.md](authorization.md) | AuthZ design (principals, policies, scope), RBAC, Digital Worker alignment |
| [ai-digital-worker.md](ai-digital-worker.md) | Digital Worker architecture: unified employee model, delegation rules, and governance constraints |
| [caddy-development-setup.md](caddy-development-setup.md) | Local Caddy setup for development |

---

## Related

- **Project vision & principles:** [docs/brief.md](../brief.md)
- **Database CLI (migrate, seed, --module):** [app/Base/Database/AGENTS.md](../../app/Base/Database/AGENTS.md)
- **Planning & TODO:** [docs/todo/](../todo/)
