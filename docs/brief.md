# Project Brief: Belimbing

**Document Type:** Project Brief
**Purpose:** High-level overview of Belimbing's vision, principles, and approach
**Audience:** AI Coding Agents, contributors, potential adopters and adoptees, and stakeholders
**Specific:** Laravel
**Last Updated:** 2026-04-25

---

## Founder's Note

I'm Kiat, an indie developer. Belimbing is where my focus is now.

I want it to be a framework that small businesses can run their operations on, and that indie developers can earn a decent living building on — the shops too small for the ERP industry to bother with, and the builders who would otherwise have to take a salaried job to make rent.

Belimbing is to serve the unserved.

---

## Executive Summary

Belimbing is a **general business framework** designed to democratize enterprise-grade capabilities for businesses of all sizes. Born from the recognition that the current ERP/BPM landscape is fundamentally broken — expensive, inflexible, and riddled with vendor lock-in — Belimbing provides a radically different approach: an open-source, AI-native framework that empowers businesses to build, customize, and own their operational systems.

Belimbing exists to remove the SMB digitization bottleneck: making it practical to ship production-grade operational systems without hiring a large software team.

**What Belimbing Is:**
- A **framework** for building customizable business processes (ERP, CRM, HR, logistics, or custom processes)
- **Not** a SaaS platform that hosts your data
- **Not** chasing speed to market at the expense of quality
- **DIY-enabling** - Belimbing’s built-in coding agent enables businesses (and the developers they partner with) to build their own operational systems instead of buying inflexible off-the-shelf solutions

**What Makes Belimbing Different:**
- **Open Source Forever (AGPLv3)**: Self-hosted, transparent, free from licensing fees and vendor lock-in
- **AI-Native Architecture**: Built from the ground up to leverage AI in development, customization, and operation
- **Quality-Obsessed**: Adoption of Ousterhout's software design principles, performance-first architecture, exceptional user experience
- **Git-Native Workflow**: Development → Staging → Production managed through version control for safety and transparency
- **Customizable Framework**: Deep extension system with hooks at every layer—businesses build what they need

**Core Philosophy:**

Belimbing is a **long-term commitment** to changing how businesses implement operational systems. We reject the "move fast and break things" mentality in favor of building with patience, excellence, and unwavering commitment to our core principles. Quality and architectural integrity take precedence over speed to market.

---

## The Problem

The current business process management landscape is fundamentally broken:

### For Businesses

**Prohibitive Costs:**
- SMBs priced out at $50K-$500K+ annually for ERP systems
- Endless expensive customization cycles
- Heavy maintenance burden requiring specialized IT teams
- Escalating subscription fees with cloud platforms

**Digitization Bottleneck:**
- SMBs often cannot hire and manage a full software team to digitize operations
- Off-the-shelf software rarely matches unique processes without painful workarounds
- Custom software is too slow and costly for day-to-day operational change

**Vendor Lock-In:**
- Trapped in proprietary ecosystems
- Data held hostage by SaaS providers
- Limited ability to switch platforms
- Forced upgrades and feature changes

**Inflexibility:**
- Business environments change faster than systems can accommodate
- One-size-fits-all solutions require extensive modifications
- Slow customization cycles prevent rapid adaptation
- Poor performance and clunky user interfaces frustrate users

### For Developers

**Poor Code Quality:**
- Existing platforms suffer from architectural issues
- Technical debt makes customization painful
- Lack of modern development practices
- Frustrating developer experience

**Limited Innovation:**
- Rigid platforms constrain what's possible
- AI capabilities bolted on as afterthought
- Can't leverage modern hardware and techniques

### The Market Gap

What businesses actually need is a **framework that lets them DIY their own business application** at low costs while still reaching production-grade quality. They need:
- Modern AI assistance (making sophisticated customization accessible without deep technical expertise)
- Clean, maintainable architecture
- Full ownership and control
- Incremental adoption
- World-class performance

No existing solution provides this combination.

---

## The Solution

Belimbing addresses these problems through five immutable core principles:

### 1. Customizable Framework

**A foundation for building business application.**

- Deep extension system with hooks at every layer (events, dependency injection, middleware, schema extensions, rule engines, workflows, DSL scripting, data transformers)
- Scope-based configuration for multi-company/multi-department scenarios
- Extension APIs with contracts that balance type safety and runtime flexibility
- Dynamic schema extensions without modifying core
- Businesses build what they need, nothing more, nothing less

### 2. Open Source Forever (AGPLv3)

**Transparent, community-driven, free from vendor lock-in.**

- **Self-hosted only** - Businesses must own their code and infrastructure
- **No SaaS, ever** - Each business deploys their own instance
- **AGPLv3 License** - Protects against cloud giants exploiting the project without contributing back
- **Open-source dependencies only** - No proprietary or freemium libraries allowed
- Zero licensing fees
- Complete data sovereignty
- Freedom to modify and extend

### 3. AI-Native Architecture

**AI is the foundation, not a feature.**

- **Git-Native Workflow**: Development → Staging → Production branches with upstream sync
- **AI-Generated Code Safety**: All AI code starts in development, passes tests, requires review before production
- **Local AI Models**: Run open-source models locally (no mandatory cloud dependencies)
- **Code Generation**: Templates and boilerplate customization powered by AI
- **Small Model Focus**: Optimized for efficient models running on modest hardware
- **Built-in CI/CD**: Admin panel deployment chain from dev to production

### 4. Quality-Obsessed

**Speed and taste drive quality. Every millisecond matters. Every pixel matters.**

**Ousterhout's Software Design Principles:**
- Deep modules with simple interfaces hiding complex implementation
- Manage complexity through elimination and encapsulation
- Strategic programming over tactical shortcuts (invest 10-20% in design)
- Zero-tolerance for technical debt
- Define errors out of existence through good design
- Continuous refactoring to maintain architectural integrity

**Performance Targets:**
- API responses: p50 < 50ms, p95 < 150ms, p99 < 300ms
- UI interactions: < 16ms per frame (60 fps)
- Initial page load: < 1.5s
- Near-bare-metal performance through careful architecture choices

**Code Quality:**
- Type-safe with full inference
- Test coverage for critical paths
- Automated static analysis (linting, type checking, security scanning)
- Domain-Driven Design for business logic
- Beautiful, accessible, responsive UI with design system

### 5. Continuously Improved

**Users are partners in development. The feedback loop is the product.**

- **Built-in Feedback System**: One-click feedback from any screen, converts to GitHub issues
- **Direct User Engagement**: Public roadmap, voting, discussions
- **Rapid Iteration**: Feature flags, A/B testing, incremental rollout
- **Git-Based Evolution**: Every change tracked, auditable, reversible
- **AI-Assisted Contributions**: Lower barrier to community participation

---

## Target Market

### Primary: Businesses Seeking Operational Freedom

**Small to Medium Businesses:**
- Currently priced out of enterprise-grade capabilities
- Want to escape expensive ERP/SaaS ecosystems
- Need cost-effective, flexible solutions
- Need to digitize and iterate on operations without building a large IT org
- Value infrastructure and data control

**Enterprises with Technical Vision:**
- Frustrated with vendor lock-in and inflexibility
- Want to own their business logic and data
- Have or can hire technical capability
- Value long-term architectural quality

### Secondary: Independent Developers & Software Agencies

**Value Proposition:**
- **Enable SMB DIY:** Bridge the gap between non-technical businesses and sophisticated business system building
- Build custom business solutions efficiently on solid foundation
- Deliver exceptional value to clients cost-effectively
- Differentiate through implementation excellence
- Contribute to and benefit from community innovations

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

**3. Linux-First for Production**
- Optimized for Linux servers (primary deployment)
- Simple native installation with automated dependency management
- Containers available as optional deployment method
- Focus quality on single platform rather than spread thin

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

### Platform (Current)

- **Backend:** Laravel 13 (PHP 8.5+)
- **UI:** Livewire + Tailwind CSS + Alpine.js
- **Frontend tooling:** Vite
- **Module system:** `app/Modules` with module-aware migrations/seed registry

---

## Deployment & Operations

### Deployment Philosophy

**No SaaS, Ever:**
- Belimbing is self-hosted only
- Businesses must own their code and infrastructure
- Each business deploys their own instance
- Single business, single instance (scope-based config for multi-store/department within same business)

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
- Linux server (modest hardware supported)
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
5. **SaaS model** - Businesses must own their code

---

## Risks & Mitigation

### Technical Risks

**Risk: Emerging language/technology choices might not pan out**
- Mitigation: Evaluate based on clear trajectory, not just current state; AI field evolution supports this approach
- Acceptable: We build for 2026-2027 landscape, not 2025

**Risk: AI models evolve faster than we can adapt**
- Mitigation: Opinionated single backend allows deep optimization; can swap when necessary
- Acceptable: Focus on small, efficient models reduces dependency on cutting edge

**Risk: Performance targets too aggressive for business process complexity**
- Mitigation: Differentiate by operation type if needed; architecture choices enable performance
- Acceptable: Aggressive targets drive quality; can adjust based on real-world data

### Market Risks

**Risk: Businesses prefer buying applications over building on frameworks**
- Mitigation: Target frustrated businesses seeking escape from vendor lock-in; incremental adoption lowers barrier
- Acceptable: We're building for long-term paradigm shift, not short-term market

**Risk: Requires more technical capability than target businesses have**
- Mitigation: Simple deployment, remote management by developers, AI assistance lowers barriers
- Acceptable: Independent developers/agencies bridge gap for non-technical businesses

**Risk: Open source community doesn't materialize**
- Mitigation: AGPLv3 protects project; quality codebase attracts contributors; patience in building community
- Acceptable: Can build incrementally even with small community; quality attracts over time

### Execution Risks

**Risk: Perfectionism leads to analysis paralysis**
- Mitigation: "Design it twice" then commit; incremental feature delivery; ship when quality bar met
- Acceptable: Better to ship excellent features slowly than mediocre features quickly

**Risk: Scope creep from "framework for everything"**
- Mitigation: Focus on core framework first; business processes added incrementally; community can build on foundation
- Acceptable: Vision is broad but execution is focused and incremental

---

## Open Questions

### Technical Decisions (TBD)

- **Programming Language**: Final choice based on AI ecosystem, performance, metaprogramming (candidates: Mojo, Python, Rust, others)
- **Database**: PostgreSQL likely, but evaluate based on performance and feature needs
- **AI Model Backend**: Benchmark llama.cpp, ONNX Runtime, vLLM, Candle, others
- **Frontend Framework**: TBD based on WebAssembly support, performance, developer experience
- **Debugging Tools**: Evaluate need for built-in business-specific inspectors vs language/framework tools

### Strategic Questions

- **Initial Business Process Focus**: Which process to build first (ERP modules, CRM, custom process)? TBD based on market feedback
- **Extension Marketplace**: How to curate quality while remaining open? Community standards + validation
- **Contributor Onboarding**: How to lower barrier while maintaining quality? AI-assisted contribution workflow
- **Documentation Strategy**: Balance between code-as-documentation and explicit guides
- **Localization/i18n**: When to tackle internationalization? After core framework stable

---

## Next Steps

### Foundation Phase

1. **Finalize Technology Stack**
   - Benchmark and choose programming language
   - Select database, AI backend, frontend framework
   - Validate performance assumptions

2. **Core Architecture**
   - Implement git-native workflow (branch management, CI/CD)
   - Build extension system foundation (APIs, validation, registry)
   - Create scope-based configuration system
   - Establish database migration framework

3. **AI Infrastructure**
   - Integrate chosen AI model backend (single opinionated choice optimized for performance)
   - Build code generation templates and boilerplate customization
   - Implement safety sandboxing (dev/staging/prod isolation with mandatory testing and review)
   - Create AI-assisted development workflows
   - Support local open-source models (no mandatory cloud dependencies)

4. **Developer Experience**
   - Set up development environment tooling
   - Implement hot reload for development
   - Create debugging and profiling tools (if needed)
   - Establish testing framework

### Community Building

1. **Documentation**
   - Architecture documentation
   - Contribution guidelines
   - Extension development guide
   - Deployment instructions

2. **Repository Setup**
   - GitHub repository with AGPLv3 license
   - Issue templates and PR workflows
   - CI/CD pipelines
   - Community guidelines

3. **Initial Outreach**
   - Share vision and principles
   - Engage with potential early adopters
   - Build relationships with independent developers
   - Foster initial contributor community

---

## Conclusion

Belimbing exists to democratize business process management—to make enterprise-grade capabilities accessible to businesses of all sizes, without the traditional barriers of cost, complexity, and vendor dependency.

We achieve this through unwavering commitment to our five core principles:
1. **Customizable Framework** - Deep extension system at every layer
2. **Open Source Forever** - AGPLv3, self-hosted, community-driven
3. **AI-Native** - Built from ground up to leverage AI assistance
4. **Quality-Obsessed** - Ousterhout's principles, performance-first, exceptional UX
5. **Continuously Improved** - Users as partners, rapid iteration, git-based evolution

This is a **long-term commitment** to changing how businesses implement operational systems. We build with patience, excellence, and community—rejecting shortcuts, vendor lock-in, and technical debt.

We will:
- Stay true to our principles
- Build with patience and excellence
- Welcome all who share our values
- Create lasting value for the community

This is our vision. This is our commitment. This is Belimbing.

---

**Document Status:** Living document
**Last Updated:** 2025-11-10
**Steward:** Project Founder
**Review Cycle:** Quarterly, or when strategic questions arise

*"Build it right, build it together, build it to last."*
