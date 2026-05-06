# Belimbing

An open-source business process framework designed to help build, operate, and extend itself with AI.

## The Workforce Model

Belimbing treats AI agents as employees. Assign them roles, supervisors, and permissions. They follow your org structure, respect delegation rules, and act through the same authorization system as human users. Every action is auditable.

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

**Layered module architecture** — Three layers: Base (framework infrastructure — AI, Authz, Workflow, Database, Settings, and 10 more), Core (governance modules — Company, User, Employee, AI, Geonames, Address, Workflow), and Business (domain modules — IT). Each layer has clear boundaries and discovery-driven registration.

**Playbook-driven development** — 7 structured playbooks that guide human developers, Lara, and other AI coding agents through common tasks (new modules, schema changes, features, workflow integration, console commands, inline editing, discovery infrastructure). Convention over configuration, enforced by the playbooks themselves.

**Authorization system** — Capability-based RBAC with delegation constraints, principal types for both humans and agents, policy engine, and middleware. The same system that governs human users governs AI agents — no separate permission model.

## Getting Started

### Prerequisites

- Linux (Ubuntu 22.04+, Debian 12+) or WSL2
- 2 GB RAM, 10 GB disk, internet connection

### Quick Install

```bash
git clone https://github.com/BelimbingApp/belimbing.git
cd belimbing
./scripts/setup.sh
```

## Documentation

| Topic | Link |
|-------|------|
| Project vision & principles | [docs/brief.md](./docs/brief.md) |
| Architecture & directory conventions | [docs/architecture/](./docs/architecture/) |
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
