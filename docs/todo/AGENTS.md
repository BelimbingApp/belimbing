# TODO Docs Guide

## Purpose

`docs/todo/` holds active design-and-build documents.

These documents are not passive notes. They are working artifacts for:

- top-down design
- discussion between the user and coding agents
- tracking build progress in a form that is readable without extra reporting

The todo document itself is the status surface. Do not create a separate observability layer unless the work has a concrete operational need that the document cannot cover.

## Core Workflow

The expected interaction model is:

1. The agent writes a coherent recommendation.
2. The document states the design, structure, assumptions, and plan clearly.
3. The user reads it and pushes on concerns, weak reasoning, missing constraints, or bad tradeoffs.
4. The agent updates the document to absorb that feedback and sharpen the plan.

For work driven by documents in this directory, stop after implementation and local verification unless the user explicitly asks for a commit or push. The user is expected to review the changes and may want to give feedback before anything is committed or published.

Do not treat the user as a prerequisite decision engine.

- Do not write TODO docs as questionnaires.
- Do not create explicit "Decision Needed" sections that offload design work to the user.
- Do not ask the user to answer speculative design questions they may not yet have enough context to answer.
- Do make recommendations.
- Do state assumptions and tradeoffs plainly enough that the user can challenge them.
- Do revise the document when discussion changes the direction.

The goal is a document the user can react to, not a document that asks the user to finish the thinking.

## Visible Planning Rule

When work is driven by a document in this directory, that document should be the visible plan the agent is using to implement the work.

Some agent harnesses may keep internal session plans, scratch state, or tool-managed progress outside the repository. That is acceptable only as private working memory. It must not become the only actionable plan.

The user should be able to open the todo document and understand:

- the recommended design
- the current phase
- what is being worked on now
- what changed since the last update

If the agent uses internal planning machinery, mirror the relevant execution plan back into the todo document. Do not rely on hidden session storage, opaque plan files, or tool-specific state as the sole source of truth for implementation progress.

## Top-Down Rule

Organize todo docs from high-level design to execution detail.

Start with the essence of the problem and the intended outcome. Define the public contract and top-level structure before dropping into implementation tasks. Preserve the distinction between architecture, policy, and checklist work.

If a document starts with low-level tasks before the design is clear, rewrite it.

Use `Phases` as the standard heading for major work chunks in this directory. Do not alternate between `Phases` and `Build Sequence` unless there is a specific reason to mirror an existing external artifact.

When a todo document is being used as the implementation plan, apply the root top-down planning rule concretely:

- State the problem's essence in one sentence. If this is fuzzy, the design is still fuzzy.
- Define the public contract first. State what the system does, what it promises, and what it will not do.
- Decompose the work into a small number of major responsibilities. Do not begin with low-level tasks.
- Sketch each component's contract in terms of inputs, outputs, and invariants before implementation details.
- Define module-level policies when they matter, especially retries, error propagation, wrapping, and fallback behavior.
- Identify expected usage and call patterns so the interface matches real callers.
- Call out complexity hotspots early, such as growth edges, cross-cutting concerns, and tricky failure modes.
- Stop planning at structure and contracts. The phases should flow from the design, not replace it.

## Default Document Shape

Use this order by default for substantial todo documents:

1. `Problem Essence`
2. `Status`
3. `Desired Outcome`
4. `Public Contract`
5. `Top-Level Components`
6. `Design Decisions`
7. `Phases`

Not every document needs every heading, but the structure should still flow from design to execution.

### Section Intent

**Problem Essence**

State the core problem in one or two sentences. If this cannot be stated cleanly, the design is still fuzzy.

**Status**

Use simple states such as `Proposed`, `In Progress`, `Blocked`, `Complete`, or a phase-oriented equivalent.

**Desired Outcome**

Describe what the finished state should accomplish, not just what work will be performed.

**Public Contract**

Define the interface, capability surface, or externally visible behavior first. For planning docs, this matters more than internal mechanics.

**Top-Level Components**

Name the major responsibilities or modules and what each owns. Keep this at the architectural level.

**Design Decisions**

Record the chosen direction and the reasoning behind it. This is where the agent shows design judgment.

**Phases**

Break the work into major chunks using `Phase 1`, `Phase 2`, and so on. A phase may still describe ordered implementation steps, but the heading should stay consistent.

Each phase should carry its own relevant execution context instead of pushing that detail into separate trailing sections.

Use phase-local subsections only when they add signal, for example:

- `Goal`
- `Scope`
- `Assumptions`
- `Risks`
- `Progress`
- `Evidence`

Do not add all of these mechanically. Include only what helps the user understand the current phase and react to it.

## Recommendation-Driven Writing

TODO docs in this directory should be recommendation-driven.

- Prefer "we should do X because Y" over listing open-ended possibilities.
- Prefer one recommended path with stated tradeoffs over three equal-weight options.
- Prefer crisp assumptions over hedging language.
- Prefer explicit boundaries and non-goals when scope is likely to sprawl.
- Keep assumptions, risks, and progress attached to the phase they affect.

The user will identify concerns by reading the plan. The agent's job is to give them something concrete and defensible to react to.

## Progress Discipline

The todo document should remain readable as the current truth, not a historical dump.

- Update phases directly as work progresses. Do not create detached status sections.
- Delete stale content — abandoned approaches, dead checklists, contradictory prose. If it is no longer the plan, remove it.
- Back progress claims with evidence: touched files, tests run, behavior now working.
- Use `- [ ]` / `- [x]` for actionable tasks within phases. Description headers that group sub-tasks get no checkbox — only the concrete items beneath them.
- Add tasks and sub-tasks freely during implementation. Scope sharpens as you build — the boy-scout rule surfaces improvements, new opportunities emerge. Capture them in the doc; the initial plan is a starting point, not a ceiling.
- When a new task emerges mid-build, decide by proximity: if it touches the same file or is tightly coupled to the current work, handle it inline — switching back later costs more. If it is independent or in a different area, finish the current phase first, then address it.

## When To Split Documents

Default to one document when it can cleanly carry both design and progress.

Split only when the work becomes large enough that a single file becomes harder to use. The common split is:

- a master design document
- a phase-tracking companion for ordered implementation tracking

If you split, make the relationship explicit near the top of both files.

## Style Expectations

- Write in concrete, direct language.
- Prefer short sections over dense walls of prose.
- Avoid filler headings that restate obvious things.
- Avoid speculative branches that have no recommendation.
- Keep the document useful to both the agent and the user during active work.

## Anti-Patterns

Do not do the following:

- turn the doc into a questionnaire for the user
- create a separate observability section when the document itself can carry status
- split assumptions, risks, and progress away from the phase they belong to unless the document has a strong reason
- keep the real implementation plan only in hidden agent/session state while the todo doc drifts behind
- commit or push automatically after implementation — wait for the user to ask for a commit, even when all tests pass
- mix architecture, task order, and low-level notes into an unreadable blob
- leave stale content after the plan changes
- pad the document with neutral options when one recommendation is already defensible

## Litmus Test

A good todo document in this directory lets the user answer these questions quickly:

- What problem are we solving?
- What design is being recommended?
- What is the shape of the solution?
- What is happening now?
- What changed since the last read?
- Where should I push back if I think the plan is wrong?

If the document cannot answer those questions clearly, improve the document before adding more implementation detail.