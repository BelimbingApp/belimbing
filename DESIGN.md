---
version: stable
name: Belimbing
description: Professional, compact, warm workflow UI for long operational sessions.
---

# DESIGN.md

## Overview

BLB should feel professional, compact, warm, and trustworthy. It is workflow software for long sessions: dense enough for operations, calm enough for judgment, and polished enough that users trust it. Success means users finish real work faster and leave — not more time in the app. The interface is the brand: deliberate product software with intentional taste, not a marketing site, consumer novelty, or generic enterprise gray.

Less is more, but better — every surface, label, and control must earn its place.

Before designing a page, invoke DHH. Ask what he would cut, what he would make obvious, what he would refuse to configure, and where the page should feel more like a confident product than a pile of components. Do not answer with a predictable rubric; sit with the page until a simpler, warmer, more opinionated shape appears.

## Semantic color roles

Semantic roles only in Blade; warm operational base, accent for primary action, status for real feedback. Values: `resources/core/css/tokens.css`.

## Compact typography

Instrument Sans only; compact, competent type; tabular numerals where scanning matters.

## Compact layout

Compact, high-signal layouts; responsive on narrow screens. Compact does not mean cramped.

## Subtle depth

Subtle contrast, borders, and shadows; motion clarifies state at 60fps.

## Reuse components

Reuse `x-ui.*` and `<x-icon>` before inventing new markup. Inventory:
`resources/core/views/AGENTS.md` and UI Reference.

- `x-ui.datetime` for every user-visible date, time, or timestamp; use `format="date"` for date-only values.
- `x-ui.record-history` for audit history on auditable detail pages.
- `x-ui.sortable-th` for table columns users naturally compare or scan by.

## Gestalt grouping

- **proximity** — related controls and labels stay close
- **similarity** — same role shares look and behavior
- **common region** — related work lives inside one surface
- **visual hierarchy** — the primary path reads first at a glance

## Scan before reading

Users scan before they read. Favor bullets, short paragraphs, and meaningful icons so key facts and actions surface at a glance — dense, not verbose.

- Design the scan layer first: headings, icons, short action labels, badges, counts, and table structure must reveal the workflow without supporting prose.
- Do not explain what a visible label, icon, badge, column, or state already says. If removing a sentence leaves the interface clear, remove it.
- Put essential safety or mode information in the control itself when it changes the decision, for example **Read-only Review**, instead of asking the user to find an explanatory paragraph.
- Reserve sentences for consequences, exceptions, recovery, or genuinely unfamiliar concepts; progressively disclose detail that is not needed for the next action.
- Before shipping, perform a no-prose scan: read only headings, controls, badges, and table labels. If the next action or current state is unclear, improve that scan layer before adding copy.

## Put information where it acts

- A page title or subtitle describes the whole page and must stay true across every tab.
- Put workflow-specific purpose, consequences, and guidance inside the tab where they affect the user's decision.
- If page-level copy joins sibling workflows with “or,” split it at those workflow boundaries.

## Stay consistent

Same thing, same look, same place — reuse established patterns, placement, and labels across modules; variation needs a user-visible reason.

## Norman feedback

Users always know what's happening and what happened. Show work in flight — loading, waiting, blocked; give every action visible, timely response; outcomes stay honest and transparent; never fail silently.

## Fail well

Never render a bare 500 if we can help it. A failed action should leave the user where they were, not replace their screen with an error page — `App\Base\Livewire\RecoverFromActionFailure` does this for every Livewire action, so do not hand-roll a `try/catch` to say the same thing. Reserve a full error page for failures that genuinely end the request; those live in `resources/core/views/errors/`.

Every failure message answers three things, in this order:

- **What happened**, in the user's terms — the screen, the action, the thing they were trying to do; never the exception class.
- **Whose fault it was and what it cost.** Usually neither theirs nor anything: say so plainly. Silence here is what makes users assume the worst.
- **What to do next** — one concrete action, plus who to ask when the fix is not theirs to make.

- **Recover before you report.** If the system can complete the request, complete it and log the defect where a developer will see it. Users are not an error-reporting channel.
- **Name the one thing that unblocks the next step.** An identifier a user can read aloud to their administrator earns its place; a stack trace does not. Everything else belongs in the log and the diagnostics buffer.
- **Write for every role at once.** One honest message for whoever is looking, and an operator's next step alongside it when there is one — not a technical page and a friendly page.
- **One failure, one page.** No second empty card, no action that just replays the error.
- **Promise only what you can verify.** An action that failed partway did not necessarily leave the data untouched — say it did not finish, not that nothing changed.

## Reduce anxiety

Calm software reduces anxiety; it does not manufacture urgency or FOMO. No nagging, badge spam, false scarcity, or engagement dark patterns — trust comes from steady, honest state, not stimulation.

## Write for humans

Make it human: plain, respectful operational language. Write for the person doing the work, not for enterprise theater or system internals.
