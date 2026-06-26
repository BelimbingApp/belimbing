# Belimbing

An open-source application platform built entirely by AI and designed for AI-assisted development.

## The Business Platform for Builders

Belimbing is designed for AI agents to quickly build the software a business needs.

- **Build your own business application.** Belimbing ships as a platform baseline today, not a full domain catalog. `AGENTS.md` files across the repo guide AI agents on principles and conventions so new modules integrate cleanly and stay consistent with the platform.
- **Automate with AI pipelines and workflows.** Connect providers, register tools, and define task models so agents can automate business work under the same permission model as human users.
- **Spend less time on UI.** Reusable components and design tokens give every module a polished shell without custom CSS or one-off controls.

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
