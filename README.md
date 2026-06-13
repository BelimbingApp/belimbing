# Belimbing

An open-source application platform designed to help build, operate, and extend itself with AI.

## The Workforce Model

Belimbing treats AI agents as employees. Agents are issued capabilities within the same authorization system that governs human users — no separate permission model, no elevated trust by default. Every action is auditable.

Meet **Lara**, she is a Belimbing AI resident.

## What You Get

- **Self-hosted, open source** — Your code, your data, your infrastructure. No vendor lock-in, no per-seat fees.
- **Bring your own model** — OpenAI, Anthropic, Google, Ollama, or any compatible endpoint. Mix providers across agents. Ordered fallback built in.
- **Real tools, real guardrails** — Agents can read and edit files, run commands, query data, search the web, navigate the UI, and more. Every action is gated by the same authorization system that governs human users.
- **Self-building direction** — Lara gives Belimbing a coding-agent interface to its own repository: explain the system, change the system, run verification, and prepare work for review.
- **Convention-driven codebase** — Foundational modules (Company, Employee, User, AI, Workflow) and a structured architecture designed to be extended by you, Lara, or another coding agent.
- **Built on Laravel 13** — PHP 8.5+, PostgreSQL. Battle-tested stack, massive ecosystem.

## Status

Belimbing is in active development and not yet ready for production. However, you are welcome to look around, install, and test. The setup script creates sample companies, employees, and reference data so you have something to explore out of the box.

### What's Built

**Agent tool surface** — Shell execution, data operations, web capabilities, browser automation, multi-channel messaging, memory and knowledge, delegation, media analysis, and system operations. Repository read/write tools are part of Lara's architecture direction. Every tool is authz-gated so administrators control exactly which tools each agent can use.

**Workflow engine** — Status lifecycle management with configurable statuses, guarded transitions, full history tracking, and kanban column mapping. Any business module can plug into the workflow system for auditable state machines.

**Base and Core modules** — Required platform infrastructure and integrated business foundations live in the main repo under `app/Base/{Module}/` and `app/Modules/Core/{Module}/`.

**Domain, plugin, and extension system** — Base and Core ship in the main repo; non-Core domains are installable distributions managed from Administration → System → Domains; licensee extensions live under `extensions/{licensee}/{module}`. Nested git is the current default distribution model, while the module contracts remain compatible with future Composer/package installation. Providers, routes, menus, settings, migrations, tests, views, and manifests integrate through the module-system discovery contracts, with installed-module dependency health surfaced in the admin plugin dashboard.

**Authorization system** — Capability-based RBAC with delegation constraints, principal types for both humans and agents, policy engine, and middleware. The same system that governs human users governs AI agents — no separate permission model.

## Getting Started

### Prerequisites

- Linux, MacOS, or Windows
- 2 GB RAM, 10 GB disk, internet connection

### Quick Install

Setup will take about an hour. A fresh platform clone starts with Base and Core. Optional domains can be installed later from Administration → System → Domains or by mounting the relevant domain distribution at `app/Modules/{Domain}`.

The easiest way to get started is with an AI Agent prompt:
```text
Clone https://github.com/BelimbingApp/belimbing.git into a directory called belimbing.
Run ./scripts/setup.sh or ps1 to install the dependencies and configure the environment.
```

Manual installation:
```bash
git clone https://github.com/BelimbingApp/belimbing.git
cd belimbing
# The setup script is idempotent, so you can run it multiple times.
# Linux or MacOS:
./scripts/setup.sh
# Windows:
./scripts/setup.ps1
```

## Documentation

| Topic | Link |
|-------|------|
| Project vision & principles | [docs/brief.md](./docs/brief.md) |
| Privacy policy | [PRIVACY.md](./PRIVACY.md) |
| Module system, architecture & directory conventions | [docs/architecture/module-system.md](./docs/architecture/module-system.md) |
| Other architecture docs | [docs/architecture/](./docs/architecture/) |
| Development environment setup | [docs/guides/development-setup.md](./docs/guides/development-setup.md) |
| Guides (theming, extensions) | [docs/guides/](./docs/guides/) |
| Module documentation | [docs/modules/](./docs/modules/) |
| Tutorials (Caddy, Vite, Livewire) | [docs/tutorials/](./docs/tutorials/) |

## Contributing

1. Fork the repository
2. Create a feature branch
3. Open a Pull Request

All contributors must agree to the [CLA](./CLA.md).

For the complete workflow and remote strategy, see [docs/guides/contributing.md](./docs/guides/contributing.md).

## License

[GNU Affero General Public License v3.0 (AGPL-3.0)](./LICENSE) — see [LICENSE](./LICENSE) for license terms and [NOTICE](./NOTICE) for third-party attributions.
