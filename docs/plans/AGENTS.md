# Plans Docs Guide

## Purpose

A plan is the **whiteboard** of a live discussion: capture what's agreed and why so future readers see it. `docs/plans/` is the in-repo single source of truth and the **status surface** (no parallel observability doc). Early on it holds intent; as the *how* firms up it **becomes the task list** (Phases checklists) in place. **Prose only** for design — no code/patches/full-file dumps; describe contracts and behavior in words. Recommendation-driven copy, stable section names, a preamble for quick orientation.

## Workflow

1. User opens a discussion (problem, goal, constraint).
2. When asked, the agent records a coherent plan on an md file — recommendations and tradeoffs stated plainly, not questionnaires or "Decision Needed" dumps.
3. On user reaction, describe the proposed revision in the md file (short prose, naming affected sections + follow-on edits). Exempt: trivial mechanical fixes the user already specified.
4. **HALT** — wait for explicit approval before implementing.
5. **Never commit/push unless asked.** When asked, treat that approval as single-use and limited to the already-implemented changes currently under discussion; later work needs a fresh explicit commit/push instruction. Add the agent as co-author with the model used.

Session/tool-only plans are fine as scratch **only** if mirrored here. Anyone opening the plan should see design, current phase, done vs open (checkboxes), and what changed.

## Keep plans current

Work that implements/fixes something in a plan must **update the plan**, not just the code:
- Tick/adjust checklists (`- [x]` when truly done; suffix completed lines with `{agent}/{model}`; add/split rows on scope change; delete dropped tasks and dead prose).
- Refresh narrative in prose when reality diverges from Design Decisions / Desired Outcome (no patches/code).
- Bump **Last Updated** and set **Status** when story/status meaningfully changes.

A stale checklist or design text is a false source of truth.

## Document shape

**Title:** the filename/path (optional lone `#` matching it). No filler "Plan"/"Notes" sections.

**Preamble** (substantive plans): **Status**, **Last Updated** (`YYYY-MM-DD`), **Sources** (issues/ADRs/parent plans/paths, or `None`), **Agents** (`{agent}/{model}` contributors, kept current).

**Body** — use a section only when it has real content; flow intent → system → why → contract → execution; never open with low-level tasks:
1. **Problem Essence** (required) — 1–2 sentences.
2. **Desired Outcome** (required) — what "done" achieves.
3. **Top-Level Components** — nameable responsibilities.
4. **Design Decisions** — chosen direction + why.
5. **Public Contract** — surface/promises once clear.
6. **Phases** — chunked work. (Use `Build Sequence` only to match an external artifact's wording; otherwise always `Phases`.)

## Phases = build sheet

Markdown tasks: `- [ ]` open, `- [x]` done (tick only when truly finished; remove stale unchecked items for merged work). **Checklists are the source of truth** for remaining work. Grouping headings get **no** checkboxes.

Optional per-phase, plain `label:` form (no bold, no checkbox): `Affected pages`, `Goal`, `Scope`, `Assumptions`, `Risks`, `Evidence`.
- `Affected pages` — routes/URLs to open to verify that result in-browser.
- `Goal` — the expected result, stated as what a reviewer should observe on the `Affected pages`.
- `Evidence` — proof (files, tests).

Refine as you build: split items and add sub-tasks as scope sharpens; keep narrative beside the list; attach risks/assumptions to their phase. Coupled follow-ups inline; independent work finishes the current phase first.

## Filenames, splitting, legacy

- **Prefix** filenames with the owning module/subsystem (primary module if multi-module); cross-cutting/project-wide plans need no prefix.
- **Split** only when a file is hard to use: (a) master — essence/outcome/components/decisions/contract; (b) per-phase build sheets with checklists. Master indexes them via Sources/phase index; each phase file links back; predictable paths.

## Hard Rules

No questionnaire plans or chat prompts; no neutral option-padding when a recommendation exists; no observability-only sections duplicating this doc; no plan kept only in session state unless explicitly asked; no autopush/autocommit; no treating a prior commit request as standing permission for later work; no stale/contradictory content; no prose-only Phases when steps are concrete (use checkboxes); no code in the plan.

## Litmus

Can a reader quickly answer: what problem, what outcome, what design, what's open vs done, what changed, where to push back, where constraints came from (**Sources**)? If not, fix the doc first.
