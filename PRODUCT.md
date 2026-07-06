# Product

## Register

product

## Users

- **SMB operators and staff** running day-to-day business processes (ERP, CRM, HR, payroll, logistics) in long working sessions. They are in a task, often for hours; the interface is their workbench, not a destination.
- **Independent developers and agencies** who build, customize, and maintain those systems on the platform for clients.
- **AI coding agents** are first-class builders: the codebase embeds conventions and guidance (`AGENTS.md` files) so agent-produced modules stay consistent with the platform.

Context: self-hosted, ownable business software. Users chose it to escape vendor lock-in and expensive inflexible ERP; they expect production-grade quality, not a prototype.

## Product Purpose

Belimbing is an open-source (AGPLv3), AI-native application platform for building ownable business systems. It removes the SMB digitization bottleneck: businesses ship enterprise-grade operational processes at a fraction of traditional cost, own their infrastructure and data completely, and customize without a large software team.

Success means users finish real work faster and leave — not more time in the app. The platform matches or exceeds commercial systems in quality while staying transparent, self-hosted, and free of licensing fees.

## Brand Personality

**Calm, opinionated, trustworthy.** Basecamp/Rails DNA: confident workflow software built for the long haul. Professional, compact, and warm — warmth lives in undertone (the Arid palette), not decoration. The interface is the brand: deliberate product software with intentional taste. Voice is plain, respectful operational language written for the person doing the work.

Reference feel: Basecamp's calm confidence and refusal to manufacture urgency; the density-with-composure of the best operations tools.

## Anti-references

- **Generic enterprise gray** — the soulless admin-template look (endless zinc, default Bootstrap/AdminLTE energy).
- **Marketing-site theatrics** — hero sections, scroll choreography, gradient text, consumer-app novelty. This is a workbench, not a landing page.
- **Engagement dark patterns** — nagging, badge spam, false scarcity, manufactured urgency or FOMO. Calm software reduces anxiety.
- **Enterprise theater** — jargon-heavy copy written for systems or procurement decks instead of the human doing the work.
- **Component-pile pages** — screens assembled from parts without an opinion about what matters.

## Design Principles

1. **Less is more, but better** — every surface, label, and control must earn its place; invoke DHH before designing a page (what would he cut, refuse to configure, make obvious?).
2. **Dense enough for operations, calm enough for judgment** — compact, high-signal layouts for long sessions; compact never means cramped.
3. **Same thing, same look, same place** — reuse established patterns, components (`x-ui.*`), placement, and labels across modules; variation needs a user-visible reason.
4. **Honest, visible state (Norman feedback)** — users always know what's happening and what happened; show work in flight; never fail silently.
5. **Scan before reading** — key facts and actions surface at a glance; users leave faster, and that's success.

## Accessibility & Inclusion

- WCAG AA contrast on every surface (the token palette is tuned for it — muted text is deliberately darkened to clear AA).
- Extended-session eye comfort is a first-class requirement: desaturated warm surfaces, subtle depth, 60fps motion that clarifies state.
- Reduced-motion alternatives for all animation; motion conveys state, never decoration.
- Tabular numerals and sortable, scannable tables where users compare data.
