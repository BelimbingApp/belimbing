# Project Brief: Belimbing

**Document Type:** Project Brief
**Purpose:** High-level overview of Belimbing's vision, principles, and approach
**Audience:** AI Coding Agents, contributors, potential adopters and adoptees, and stakeholders
**Specific:** Laravel
**Last Updated:** 2026-05-19

---

## Founder's Note

I'm Kiat, an indie developer. Belimbing is where my focus is now.

I want it to be a framework that small businesses can run their operations on, and that indie developers can earn a decent living building on — the shops too small for the ERP industry to bother with, and the builders who would otherwise have to take a salaried job to make rent.

Belimbing is to serve the unserved.

---

## Executive Summary

Belimbing is a **general business framework** designed to democratize enterprise-grade capabilities for businesses. Business software is expensive, often inflexible, and carries vendor lock-in — Belimbing is an open-source, AI-native framework that empowers businesses to build, customize, and own their operational systems, removing the SMB digitization bottleneck so it is practical to ship production-grade systems without hiring a large software team.

**What Belimbing Is:**
- A **framework** for building customizable business processes (ERP, CRM, HR, logistics, or custom processes)
- **Not** chasing speed to market at the expense of quality
- **DIY-enabling** — the codebase embeds conventions, module boundaries, and in-repo agent guidance so coding agents produce higher-quality changes

**What Makes Belimbing Different:**
- **Open Source Forever (AGPLv3)**: Self-hosted, transparent, free from licensing fees and vendor lock-in
- **AI-Native Architecture**: Built from the ground up to leverage AI in development, customization, and operation
- **Quality-Obsessed**: Adoption of Ousterhout's software design principles, performance-first architecture, exceptional user experience
- **Git-Native Workflow**: Development → Staging → Production managed through version control for safety and transparency
- **Customizable Framework**: Build your own plugins and extensions

**Core Philosophy:**

Belimbing is a **long-term commitment** to changing how businesses implement operational systems. We reject the "move fast and break things" mentality in favor of building with patience, excellence, and unwavering commitment to our core principles. Quality and architectural integrity take precedence over speed to market.

---

## Audience

- **SMBs** that need production-grade operational systems without staffing a large in-house software team.
- **Independent developers and agencies** that build and maintain those systems on a solid, ownable foundation.

## Where Detail Lives

| Topic | Read |
|-------|------|
| Agent philosophy, PHP conventions, destructive evolution | Root `AGENTS.md` |
| Architecture (layers, database, modules, AI) | `docs/architecture/` |
| Migrations, seeding, `is_stable` | `app/Base/Database/AGENTS.md` |
| Active planning | `docs/plans/` (`docs/plans/AGENTS.md`) |
| Agent onboarding | `docs/development/agent-context.md` |

---

## What Success Looks Like

### For Businesses

- **Transition from buying software to building their own operational systems**
- Ship faster than traditional ERP projects, aiming to match or exceed commercial systems in quality
- Implement enterprise-grade business processes at 10% of traditional costs
- Own infrastructure and data completely
- Adopt modern development practices (git, dev/staging/prod environments)
- Build sustainable competitive advantage through custom business logic
- Leverage AI for rapid development without sacrificing security and quality

### For Independent Developers

- Build solutions efficiently on proven framework
- Focus on business logic, not infrastructure
- Deliver high value to clients affordably
- Participate in thriving ecosystem

### For the Community

- Vibrant open-source project with active contributors
- Protection from commercial exploitation (AGPLv3)
- Continuous innovation through collective effort
- High-quality codebase that's a joy to work with

### For the Future

- Changed paradigm: businesses build rather than buy operational systems
- AI assistance democratizes software development
- Quality and ownership valued over convenience and speed
- Community-driven evolution creates lasting value

---

## Technical Approach

### Architecture Foundations

**Current Implementation (Laravel-Based)**
- PHP 8.5+ on Laravel 13 (Livewire + Tailwind CSS + Alpine.js)
- Deep modular structure under `app/Modules/*/*` (domain modules with models, migrations, seeders)
- Base framework extensions under `app/Base/*` (e.g., module-aware migrations and seeding)

**1. Git-Native Architecture**
- All code management through git (development → staging → production → main for upstream)
- Built-in CI/CD in admin panel
- Complete audit trail and rollback capability
- Foundation for AI safety and deployment workflow

**2. Single Source of Truth**
- Database + Redis for all configuration
- Environment variables only for bootstrap (DB and Redis connections)
- Scope-based configuration with hierarchical fallback
- Scriptable database updates (migrations, seeding, config changes)

**3. FrankenPHP Native Runtime**
- **FrankenPHP** (required) — BLB's PHP worker model; native binaries on Linux and Windows
- **Linux** — primary production target; system Caddy ingress + per-instance FrankenPHP (see `docs/architecture/caddy-frankenphp-topology.md`)
- **Windows** — supported for development and native deployment (`scripts/start-app.ps1`)
- Containers remain an optional deployment method

**4. Extension Management System**
- Pre-installation validation (compatibility, dependencies, security)
- Runtime safety (resource limits, permissions, crash isolation)
- Laravel-first extension surface area (Service Providers, config overrides, module migrations/seeders)
- Registry and marketplace integration
- Rollback capability for failed extensions

### Performance & Quality

- Performance-first Laravel through architecture choices (query discipline, caching, background jobs)
- Aggressive caching (memory, disk, distributed)
- Lazy loading and database optimization
- Beautiful, accessible UI with 60fps interactions

---

## Deployment & Operations

### Deployment Philosophy

**Simple Installation:**
- One-command deployment (database, backend, frontend, AI services)
- Single installer script handles all dependencies
- Zero-config start with sensible defaults
- Installation package < 500MB, startup < 30 seconds, memory < 2GB for small deployments

**Remote Management:**
- Built-in secure tunneling for developer support
- Remote diagnostics and health monitoring
- Push updates and patches remotely with rollback
- Session recording for debugging (privacy-controlled)

**Lifecycle Management:**
- Automated backups and health monitoring
- Database migrations with zero-downtime
- One-click rollback to previous state
- Cleanup automation for logs and orphaned data
- Self-healing for common failures

### Requirements

**Infrastructure:**
- Linux or Windows host with FrankenPHP (modest hardware supported)
- Database (PostgreSQL likely; any Laravel-supported database possible)
- Redis for caching
- Internet connection for git, updates, AI models (works behind corporate firewalls with outbound HTTPS)

**Technical Knowledge:**
- Minimal for basic deployment
- Can be remotely managed by independent developers
- Sophisticated tooling reduces operational burden

---

## Constraints & Trade-offs

### What We Optimize For

1. **Performance** over convenient frameworks
2. **Customizability** over out-of-the-box features
3. **Quality** over speed to market
4. **Long-term maintainability** over short-term productivity
5. **User empowerment** over vendor control

### What We Accept

1. **Steeper initial learning curve** - AI assistance mitigates this
2. **Smaller initial ecosystem** - We build what we need with quality
3. **More initial development effort** - Quality requires investment
4. **Non-mainstream choices** - Choose based on merit, not popularity
5. **Longer time to "feature completeness"** - Quality over speed

### What We Reject

1. **Technical debt** - Fix it now, not later
2. **Vendor lock-in** - At any layer
3. **Performance compromises** - Every millisecond matters
4. **Closed platforms** - Open source or nothing

---

**Document Status:** Living document
**Last Updated:** 2026-05-19
**Steward:** Project Founder
**Review Cycle:** Quarterly, or when strategic questions arise

*"Build it right, build it together, build it to last."*
