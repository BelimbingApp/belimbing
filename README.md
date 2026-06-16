# Belimbing

An open-source application platform with a minimal core. Install the business domains you need. Customize with extensions. Built to be operated and extended with AI.

## The Platform Model

Belimbing starts bare minimum. A fresh install ships **Base** (framework infrastructure) and **Core** (shared business foundations such as Company, Employee, User, AI, and Workflow). That is the platform — identity, authorization, settings, module discovery, updates, and the application shell.

**Business domains plug in.** Optional areas such as People, Commerce, Operations, Finance, Sales, and Procurement install as versioned distribution bundles when a deployment needs them. Install only what the business uses; disable or remove domains without forking the platform.

**Customization lives in extensions.** Licensee- and project-specific behavior belongs under `extensions/{licensee}/{module}`. Local rules, integrations, and UI extensions evolve in their own boundary — upstream Belimbing stays clean, and upgrades stay practical.

Discovery is convention-driven: routes, menus, settings, migrations, views, tests, and providers wire in through module contracts. See [docs/architecture/module-system.md](./docs/architecture/module-system.md).

## What You Get

- **Minimal core, installable domains** — Start with Base and Core; add business capability as installable domains from Administration → System → Domains.
- **Extensions without forks** — Ship licensee-specific behavior under `extensions/{licensee}/{module}` with the same discovery contracts as first-party modules.
- **Self-hosted, open source** — Your code, your data, your infrastructure. No vendor lock-in, no per-seat fees.
- **AI-native workforce** — Agents are employees in the same org and authorization model as humans. Meet **Lara**, Belimbing's resident system agent — she can explain, change, and verify the platform (including domains and extensions) within the logged-in user's authority.
- **Bring your own model** — OpenAI, Anthropic, Google, Ollama, or any compatible endpoint. Mix providers across agents. Ordered fallback built in.
- **Real tools, real guardrails** — Agents can read and edit files, run commands, query data, search the web, navigate the UI, and more. Every action is gated by the same authorization system that governs human users.
- **Built on Laravel 13** — PHP 8.5+, PostgreSQL. Battle-tested stack, massive ecosystem.

## Status

Belimbing is in active development. Human and AI agents are welcome to look around, install, and test. The setup script creates sample companies, employees, and reference data so you have something to explore out of the box.

### What's Built

**Module system** — Base and Core ship in the main repo. Non-Core domains are installable distributions managed from Administration → System → Domains. Licensee extensions live under `extensions/{licensee}/{module}`. Providers, routes, menus, settings, migrations, tests, views, and manifests integrate through discovery contracts; installed-module dependency health is surfaced in the admin plugin dashboard.

**Authorization system** — Capability-based RBAC with delegation constraints, principal types for both humans and agents, policy engine, and middleware. The same system that governs human users governs AI agents — no separate permission model.

**Workflow engine** — Status lifecycle management with configurable statuses, guarded transitions, full history tracking, and kanban column mapping. Any business module can plug into the workflow system for auditable state machines.

**Agent tool surface** — Shell execution, data operations, web capabilities, browser automation, multi-channel messaging, memory and knowledge, delegation, media analysis, and system operations. Repository read/write tools are part of Lara's architecture direction. Every tool is authz-gated so administrators control exactly which tools each agent can use.

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
