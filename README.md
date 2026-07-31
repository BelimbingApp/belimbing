# Belimbing

Belimbing is an MIT-licensed, adaptable platform for building business applications. The idea is to provide a set of base modules every business would need, so that builders can focus on the applications and build in less time.

## Build Your Applications with AI

Belimbing provides a solid foundation for any builders, regardless of experience or technical knowledge, to create enterprise grade software with AI easily.

 `AGENTS.md` and  `DESIGN.md` files guide AI agents on coding principles and conventions so new modules integrate cleanly and stay consistent with the platform.

 At the core of Belimbing is a ready-made control plane that a business needs:

 - Companies, users, employees, addresses, localization
 - User roles, capabilities and decision logs
 - Workflows and audit logs
 - Email, secrets and outbound exchanges
 - Logs, performance, jobs, schedules, sessions and cache
 - Backups and data operations

## Modern by Default

- **Build end to end.** Laravel and Livewire keep data, validation, permissions, and UI in one codebase.
- **Ship responsive UI.** Tailwind components and focused Alpine behavior work across desktop and narrow screens without a separate SPA.
- **Stay fast.** FrankenPHP keeps Laravel warm between requests, while page-weight tests catch heavy screens.
- **Extend cleanly.** Each module keeps its routes, views, data, tests, and assets together.
- **AI native.** AI providers, tools, task models and control plane.

## Benefits

1. Deliver complete products sooner. Concentrate on the business requirements instead of rebuilding users, permissions, audit trails, settings, deployment, backups, diagnostics, and administration.
2. Make custom software maintainable. A Belimbing installation is not an undocumented collection of bespoke screens. Every project shares an understandable operational structure.
3. Reduce support costs. Logs, failed jobs, schedules, performance, software versions, dependency health and backups are visible in one interface. First-line support requires less terminal access and less guesswork.


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
