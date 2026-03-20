# Belimbing (BLB)

An open-source business process framework where AI is a first-class citizen.

## The Workforce Model

Belimbing treats AI agents as employees. Assign them roles, supervisors, and permissions. They follow your org structure, respect delegation rules, and can never exceed their supervisor's authority. Every action is auditable.

Meet **Lara** — the built-in system agent. She is the orchestrator: managing your AI team, guiding setup, and keeping operations running.

## What You Get

- **Self-hosted, open source** — Your code, your data, your infrastructure. No vendor lock-in, no per-seat fees.
- **Bring your own model** — OpenAI, Anthropic, Google, Ollama, or any compatible endpoint. Mix providers across agents. Ordered fallback built in.
- **Real tools, real guardrails** — Agents can run commands, query data, search the web, navigate the UI, and more. Every action gated by the same authorization system that governs human users.
- **Convention-driven codebase** — Foundational modules (Company, Employee, User, AI, Workflow) and a structured architecture designed to be extended — by you, by your coding agent, or both.
- **Built on Laravel** — PHP 8.5+, PostgreSQL. Battle-tested stack, massive ecosystem.

## Status

Belimbing is in active development and not yet ready for production. Everyone is welcome to look around, install, and test.

## Getting Started

### Prerequisites

- Linux (Ubuntu 22.04+, Debian 12+) or WSL2
- 2 GB RAM, 10 GB disk, internet connection

### Quick Install

```bash
git clone https://github.com/BelimbingApp/lara.git belimbing
cd belimbing
./scripts/setup.sh local
./scripts/start-app.sh
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

## License

[GNU Affero General Public License v3.0 (AGPL-3.0)](./LICENSE) — see [LICENSE](./LICENSE) for license terms and [NOTICE](./NOTICE) for third-party attributions.
