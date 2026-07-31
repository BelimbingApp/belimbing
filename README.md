# Belimbing

Belimbing is an MIT-licensed platform for building business applications. It provides the shared foundation, so builders can focus on what each application needs.

## Built for Builders

* **Start beyond the boilerplate.** Companies, users, employees, permissions, settings, audit history, integrations, and operations are ready-made.
* **Build with coding agents.** `AGENTS.md` and `DESIGN.md` tell agents how code and UI fit together.
* **Keep modules self-contained.** Each module owns its routes, views, data, tests, and assets.
* **Deliver consistent UI.** Reusable components and design tokens keep modules responsive and cohesive.

## The Control Plane

The Administration menu gives authorized users one place to configure, govern, extend, and run the system.

- **Configure and govern.** Manage companies, users, roles, capabilities, workflows, localization, settings, and audit history.
- **Integrate and automate.** Configure email, secrets, outbound exchanges, AI providers, tools, and task models under the same authorization system.
- **Operate and recover.** Inspect logs, performance, failed jobs, schedules, sessions, cache, data operations, and backups.
- **Extend and update.** Inspect modules and dependencies, manage private source access, and run controlled updates.

## Modern by Default

- **Build end to end.** Laravel and Livewire keep data, validation, permissions, and UI in one codebase.
- **Ship responsive UI.** Tailwind components and focused Alpine behavior work across desktop and narrow screens without a separate SPA.
- **Stay fast.** FrankenPHP keeps Laravel warm between requests, while page-weight tests catch heavy screens.
- **Run as one system.** One command starts the web server, queues, scheduler, and frontend build.

## Why Belimbing

- Spend development time on the business-specific requirements instead of rebuilding its foundation.
- Keep custom software maintainable through shared conventions and module boundaries.
- Reduce support effort by giving users useful visibility and control.
- Retain ordinary, version-controlled source code that can evolve with the business.

## Status

Belimbing is in active development. Human and AI agents are welcome to look around, install, and test. The setup script creates sample companies, employees, and reference data so you have something to explore out of the box.

## Quick Install

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

[MIT License](./LICENSE). See [LICENSE](./LICENSE) for license terms and [NOTICE](./NOTICE) for third-party attributions.

Use it commercially, modify it, deploy it, build proprietary modules and extensions on it — the only condition is that you keep the copyright and license notice in copies of the software. Nothing you build on Belimbing has to be published.
