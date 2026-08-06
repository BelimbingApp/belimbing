# Project Brief: Belimbing

**Document Type:** Project Brief
**Purpose:** High-level overview of Belimbing's vision, principles, and approach
**Last Updated:** 2026-08-05

---

## Founder's Note

I'm Kiat, a solo builder. Belimbing is named after the place where it was borned.

Belimbing is an application platform for businesses. All code is written by AI coding agents.

---

## Executive Summary

Belimbing is an **open-source, adaptable application platform** built entirely by AI for creating ownable business systems. Business software is expensive, often inflexible, and carries vendor lock-in; Belimbing empowers businesses to build, customize, and own their operational systems, removing the SMB digitization bottleneck so it is practical to ship production-grade systems without hiring a large software team.

**What Belimbing Is:**
- An **application platform** for building customizable business processes (ERP, CRM, HR, logistics, or custom processes)
- **Not** chasing speed to market at the expense of quality
- **DIY-enabling** — the codebase embeds conventions, module boundaries, and in-repo agent guidance so coding agents produce higher-quality changes

**What Makes Belimbing Different:**
- **Open Source Forever (MIT)**: Self-hosted, transparent, free from licensing fees and vendor lock-in — permissively licensed, so a business owns everything it builds on the platform (see Ownership Boundary below)
- **AI-Native Architecture**: Built from the ground up to leverage AI in development, customization, and operation
- **Quality-Obsessed**: Adoption of Ousterhout's software design principles, performance-first architecture, exceptional user experience
- **Git-Native Workflow**: Development → Staging → Production managed through version control for safety and transparency
- **Customizable Platform**: Install enterprise Domains, build new Modules, and keep deployment-specific work in flexible Extensions

**Core Philosophy:**

Belimbing is a **long-term commitment** to changing how businesses implement operational systems. We reject the "move fast and break things" mentality in favor of building with patience, excellence, and unwavering commitment to our core principles. Quality and architectural integrity take precedence over speed to market. Product ethos shares Basecamp/Rails DNA — calm, opinionated workflow software built for the long haul; detail in `DESIGN.md` and root `AGENTS.md`.

---

## Ownership Boundary

Belimbing is MIT-licensed, so there is no boundary to reason about. Everything a business and its developers build on the platform — modules, extensions, whole custom systems — belongs to them, may stay private, and may be licensed on any terms they choose. Running Belimbing creates no obligation to publish anything.

The licensing questions a business would otherwise have to answer — *is my module a derivative work, does hosting it force me to disclose source, may I keep my own operational logic private* — simply do not arise. Removing that whole category of question is the point: a licensing conversation is a cost SMBs and the agencies serving them should never have to pay.

This is a deliberate trade, stated plainly because the brief is where we keep ourselves honest. A permissive license means nothing legally stops someone from taking Belimbing, closing their fork, and selling it as a service. We make a narrower guarantee instead: the code as published stays permanently available under MIT to anyone who wants to run it themselves, and no future licensing decision of ours can retract what is already released. What keeps Belimbing worth choosing is the quality of the platform, not a clause that penalizes leaving it.

One consequence runs the other way and is easy to get wrong: a permissive outbound license makes *inbound* dependency vetting stricter, not looser. Copyleft libraries that AGPL could absorb — GPL and LGPL PHP or JS packages bundled into the distribution — would now govern the terms of the combined work and break the MIT promise. Bundled dependencies must be permissive (MIT, BSD, ISC, Apache-2.0); copyleft tools remain fine as separately installed CLIs or network services, which do not propagate.

---

## Audience

- **SMBs** that need production-grade operational systems without staffing a large in-house software team.
- **Independent developers and agencies** that build and maintain those systems on a solid, ownable foundation.

## Where Detail Lives

| Topic | Read |
|-------|------|
| Agent philosophy, PHP conventions, progressive evolution | Root `AGENTS.md` |
| UI and product intent | `DESIGN.md` |
| Architecture (database, modules, AI) | `docs/architecture/` |
| Migrations, seeding, incubating schema | `app/Base/Database/AGENTS.md` |
| Active planning | `docs/plans/` (`docs/plans/AGENTS.md`) |
| Docs placement and routing | `docs/AGENTS.md` |

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

### For Builders

- Build solutions efficiently on a proven platform
- Focus on business logic, not infrastructure
- Deliver high value to clients affordably
- Participate in thriving ecosystem

---

## Technical Approach

### Architecture Foundations

**Current Implementation (Laravel-Based)**
- PHP 8.5+ on Laravel 13 (Livewire + Tailwind CSS + Alpine.js)
- Exactly four application-code roots: `app/Base`, `app/Core`, `app/Domains`, and `app/Extensions`
- Domains contain full-stack Modules with their models, migrations, seeders, routes, views, contracts, and tests
- Base supplies framework infrastructure; Core is the required enterprise Domain; optional Domains and flexible Extensions compose additional capability

**1. Git-Native Architecture**
- All code management through git (development → staging → production → main for upstream)
- Complete audit trail and rollback capability
- Foundation for AI safety and deployment workflow

**2. FrankenPHP Native Runtime**
- **FrankenPHP** (required) — BLB's PHP worker model
- Cross-OS support

**3. Domains, Modules, and Extensions**
- Core is a required Domain and updates with the platform
- Optional Domains can be installed, enabled, disabled, updated, or uninstalled in the Admin Panel
- Modules are the full-stack ownership boundaries contained by Core, Domains, and Extensions
- Extensions remain a deployment-owned mixed bag for adapters, overlays, cross-Domain composition, and private capabilities
- Convention-based discovery integrates providers, migrations, routes, views, config, and menus in Base → Core → enabled Domains → Extensions order; adapters and slots handle deployment-specific variation

### Performance & Quality

- Performance-first Laravel through architecture choices (query discipline, caching, background jobs)
- Aggressive caching (memory, disk, distributed)
- Lazy loading and database optimization
- Beautiful, accessible UI with 60fps interactions

---

## Constraints & Trade-offs

### What We Optimize For

1. **Performance** over convenient frameworks
2. **Quality** over speed to market
3. **Long-term maintainability** over short-term productivity
4. **User empowerment** over vendor control

### What We Accept

1. **Steeper initial learning curve** - AI assistance mitigates this
2. **Smaller initial ecosystem** - We build what we need with quality
3. **More initial development effort** - Quality requires investment
4. **Longer time to "feature completeness"** - Quality over speed

### What We Reject

1. **Technical debt** - Fix it now, not later
2. **Vendor lock-in** - At any layer
3. **Performance compromises** - Every millisecond matters
4. **Closed platforms** - Open source or nothing

---

**Document Status:** Living document
**Steward:** Project Founder
**Review Cycle:** Quarterly, or when strategic questions arise

*"Build it right, build it together, build it to last."*
