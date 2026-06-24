# Belimbing

An open-source application platform built entirely by AI and designed for AI-assisted development. Install the business domains you need. Customize with extensions.

## The Business Platform for Builders

Belimbing is designed for AI agents to quickly build the software a business needs. The common wiring and plumbing are already part of the platform, so new work can focus on domain rules, workflows, integrations, and UI instead of starting from scaffolding.

**Business domains are the shared contribution path.** Reusable areas such as People, Commerce, and Operations live as domain modules. They are meant for open-source capability that many deployments can install, improve, and share.

**Extensions are the proprietary path.** Licensee- and project-specific behavior belongs under `extensions/{licensee}/{module}`. Private rules, integrations, and UI changes can evolve in their own boundary while upstream Belimbing stays clean and practical to update.

Discovery is convention-driven: routes, menus, settings, migrations, views, tests, and providers wire in through module contracts. See [docs/architecture/module-system.md](./docs/architecture/module-system.md).

## Platform Capabilities

A fresh Belimbing install provides the platform services that business domains and extensions can build on.

- **Start small, grow cleanly.** Add domains only when the organization needs them.
- **Designed for AI-assisted building.** Common wiring for modules, menus, settings, permissions, workflows, records, migrations, and UI conventions is handled by the platform, so agents can focus on business capability.
- **Install domains from the platform.** Business areas are versioned modules managed from Administration, not separate apps stitched together later.
- **Customize without forks.** Local rules, integrations, and UI changes live in extensions, so the upstream platform stays practical to update.
- **Share the business foundation.** Companies, people, users, addresses, and reference data belong to Core, giving every module the same source of truth.
- **Govern people and AI together.** Human users, Lara, agents, tools, and approvals use one permission model instead of separate AI exceptions.
- **Build around workflow.** Domains can share lifecycle, approval, status, history, and board patterns instead of inventing a new process engine each time.
- **Run with built-in stewardship.** Administrators can inspect modules, updates, health, background work, database state, changes, and failures from inside the app.
- **Make work traceable.** See who acted, what changed, and why access was allowed or denied.
- **Own the foundation.** Run BLB on your infrastructure with your code, data, models, and business rules under your control.

## Status

Belimbing is in active development. Human and AI agents are welcome to look around, install, and test. The setup script creates sample companies, employees, and reference data so you have something to explore out of the box.

## Getting Started

### Prerequisites

- Linux, MacOS, or Windows
- 2 GB RAM, 10 GB disk, internet connection

### Quick Install

Setup will take about an hour. A fresh platform clone starts with Base and Core. Optional domains can be installed later from Administration > System > Software > Modules or by mounting the relevant domain distribution at `app/Modules/{Domain}`.

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

[GNU Affero General Public License v3.0 (AGPL-3.0)](./LICENSE). See [LICENSE](./LICENSE) for license terms and [NOTICE](./NOTICE) for third-party attributions.
