# Plans Docs Guide

## Purpose

`docs/plans/` holds active design-and-build plans for agents and humans. The file is the **status surface**—no parallel observability doc unless operationally necessary.

Plans are **prose only** (no implementation code, patches, or full-file dumps—describe contracts and behavior in words). They support discussion, top-down design, and progress tracking without extra reporting.

Norms: plan lives **in-repo** as the single source of truth; **recommendation-driven** copy; stable section names (`Problem Essence`, `Phases`, …); **preamble** for quick orientation.

## Workflow and visibility

1. User opens a discussion (problem, goal, constraint).
2. Agent records a coherent plan when asked—recommendations and tradeoffs stated plainly, not questionnaires or “Decision Needed” dumps.
3. User reacts; agent updates the doc.
4. User approves implementation; agent implements and verifies locally.
5. **Do not** commit or push unless the user asks.

Hidden session/tool-only plans are fine as scratch **only** if the same execution truth is **mirrored** into this file. Anyone opening the plan should see design, current phase, done vs. open work (via **Phases** checklists), and what changed.

## Document shape

**Title:** the **filename** (and path under `docs/plans/`). Optional lone `#` matching the filename if your renderer needs it—no filler “Plan” / “Notes” sections.

**Preamble** (substantive plans): compact block with **Status** (e.g. Identified, In Progress, Blocked, Complete, or phase label), **Last Updated** (`YYYY-MM-DD` when narrative or status meaningfully changes), **Sources** (issues, ADRs, parent plans, paths—or `None`). Preamble = compass; do not paste the whole **Phases** section there.

**Body** (order when sections exist; early drafts may stop after the first two):

1. **Problem Essence** (required) — one or two sentences; if fuzzy, design is fuzzy.
2. **Desired Outcome** (required) — what “done” achieves.
3. **Public Contract** — when the surface/promises are clear enough to state.
4. **Top-Level Components** — when responsibilities are nameable.
5. **Design Decisions** — chosen direction and why.
6. **Phases** — when work can be chunked; may stay thin until the how firms up.

Add later sections only when there is real content—no filler. Flow design → execution; never open with low-level tasks before (1) and (2). **Reserve `Build Sequence` only** to match an external artifact’s wording; otherwise use **`Phases`** so every plan uses one predictable label.

## Phases = build sheet

Under `Phase 1`, `Phase 2`, … use markdown tasks for actionable work: `- [ ]` open, `- [x]` done (tick only when truly finished; remove stale unchecked items for merged work). **Checklists are the source of truth** for remaining work. Grouping headings (`Goal`, `Scope`, …) get **no** checkboxes—only lines beneath them.

Refine as you build: split big items, add sub-tasks and new rows when scope sharpens; keep narrative beside the list, not instead of it. Optional per-phase: `Goal`, `Scope`, `Assumptions`, `Risks`, `Evidence` (proof). Avoid a redundant `Progress` subsection.

**Progress hygiene:** refresh preamble when status/sources shift; delete abandoned prose and dead checklists; evidence for claims (files, tests). Coupled follow-ups: handle inline; independent work: finish current phase first. Prefer “we should X because Y” and one main path with tradeoffs; attach risks/assumptions to the phase they belong to.

## Splitting and migration

Prefer one file until it is hard to use. **Split:** (a) **master**—essence, outcome, contract, components, decisions; (b) **companion** or **per-phase files**—each file a short build sheet with checklists. Large work: e.g. `docs/plans/<topic>/phase-1.md`, …—master indexes them in **Sources** or a phase index; each phase file links back. Name paths predictably.

**Legacy:** new plans live here. Unmigrated work stays under `docs/todo/` per `docs/todo/AGENTS.md`. On move: git history, fix links, optional **Sources** to old path.

## Do not

Questionnaire-style plans; observability-only sections duplicating the doc; plan only in agent session state; autopush/autocommit; stale or contradictory content; neutral option-padding when a recommendation exists; omit **Last Updated** / **Sources** on substantial plans; **Phases** as prose-only when steps are concrete—use checkboxes; code in the plan.

## Litmus

Can a reader quickly answer: what problem, what outcome, what design, what’s open vs done (checkboxes), what changed, where to push back, where constraints came from (**Sources**)? If not, fix the doc before piling on detail.
