# Belimbing (BLB)

An open-source **business process framework** that democratizes enterprise-grade capabilities for businesses of all sizes. Built on Laravel, Belimbing empowers businesses to build, customize, and own their operational systems — ERP, CRM, HR, logistics, or any custom process — without vendor lock-in or prohibitive costs.

Belimbing is a **framework**, not a SaaS platform. Businesses fork the repo, build on top of it, and self-host — keeping full ownership of their code, data, and infrastructure.

## What Makes Belimbing Different

- **Open Source Forever (AGPLv3)** — Self-hosted, transparent, zero licensing fees
- **AI-Native Architecture** — Built to leverage AI in development, customization, and operation
- **Quality-Obsessed** — Ousterhout's design principles, performance-first, exceptional UX
- **Git-Native Workflow** — Development → Staging → Production via version control
- **Deep Customization** — Extension system with hooks at every layer; businesses build what they need

## Tech Stack

- **Backend:** Laravel 12+ (PHP 8.2+), PostgreSQL, Redis
- **Frontend:** HTMX 2, Alpine.js, Tailwind CSS 4
- **Tooling:** Vite, Pest PHP, Laravel Pint

## Getting Started

See the **[Quick Start Guide](./docs/guides/quickstart.md)** for complete installation instructions.

### Prerequisites

- Linux (Ubuntu 22.04+, Debian 12+) or WSL2
- 2 GB RAM, 10 GB disk, internet connection
- Root or sudo access (setup scripts install all dependencies automatically)

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
| HTMX interaction contract | [docs/architecture/htmx-interaction-contract.md](./docs/architecture/htmx-interaction-contract.md) |
| Tutorials (Caddy, Vite) | [docs/tutorials/](./docs/tutorials/) |

## Contributing

1. Fork the repository
2. Create a feature branch
3. Run `./vendor/bin/pint` before committing
4. Open a Pull Request

All contributors must agree to the [CLA](./CLA.md).

## License

[GNU Affero General Public License v3.0 (AGPL-3.0)](./LICENSE) — see [LICENSE](./LICENSE) and [NOTICE](./NOTICE) for details.
