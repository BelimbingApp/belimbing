---
name: blb-code-review
description: Use this skill when reviewing BLB code changes, PRs, commits, or another agent's work; when the user asks for a review, code review, low-entropy pass, regression-risk check, or whether changes merit a commit. Applies to review-only work and to review-then-fix/commit requests.
---

# BLB Code Review

Review BLB changes as product and architecture work, not only as patch inspection. Start with correctness risks, then deliberately look for low-entropy improvements that make the system clearer, smaller, more truthful, or easier to operate.

## Load First

- Root `AGENTS.md`.
- Any nested `AGENTS.md` that governs touched paths.
- `DESIGN.md` for UI, Blade, Livewire, Tailwind, interaction, status copy, or admin workflow changes.
- `tests/AGENTS.md` when tests are touched or missing coverage is part of the review.
- `docs/plans/AGENTS.md` when plan docs are touched or the review discovers larger work that should be planned.
- `docs/architecture/module-system.md` when changes add or move module assets, views, config, migrations, seeders, extension code, or framework-owned code.

## Establish Scope

1. Identify exactly what is being reviewed: working tree, named commit(s), branch diff, PR, or specific files.
2. Read the changed code and enough surrounding code to understand the contract it participates in.
3. For UI changes, inspect the rendered behavior when practical. For backend changes, trace the caller, persistence boundary, error path, and user-visible result.
4. Separate pre-existing unrelated issues from review findings unless they are made worse by the change.

## Review Lenses

Prioritize concrete user or system harm:

- Correctness: broken behavior, stale state, race conditions, bad assumptions, wrong branch logic, missing validation, data loss, migration hazards.
- Boundaries: module placement, domain ownership, framework-vs-module leakage, public contract drift, authz/audit/AI/security boundary mistakes.
- Exceptional Experience: confusing states, misleading labels, missing feedback, inaccessible controls, cramped or wasteful layout, non-truthful status copy, design-token violations.
- Low Entropy: duplicated logic, needless branches, dead code, vague names, repeated strings that should be shared, unnecessary abstractions, missing small refactors near the touched code.
- Honesty: names, persisted values, API responses, docs, and UI copy must match what the code and data actually do.
- Tests: missing regression coverage for a concrete bad change, weak proxy assertions, flaky isolation, or tests that only restate framework behavior.
- Operations: logs, deployment/update flows, cache invalidation, reload behavior, retry/idempotency, and failure recovery should be understandable to an operator.

## Low Entropy Pass

After the bug-risk pass, ask:

- What small cleanup belongs with this change because the touched artifact's purpose is now clearer?
- Is any new concept named once in docs, UI, code, and persisted data with the same meaning?
- Is there a repeated shape that now has three or more uses and would benefit from a local helper or shared type?
- Did the change leave behind stale comments, dead fallback paths, obsolete tests, or duplicated explanatory copy?
- Is a larger improvement real but too broad for this review? If so, propose or create a `docs/plans/` entry rather than burying it in a final note.

Do not inflate review output with speculative rewrites. Prefer one or two high-signal opportunities over a laundry list.

## When To Edit

- If the user asks for review only, do not change code. Report findings and opportunities.
- If the user asks to fix, commit, or decide whether changes merit a commit, apply small, clearly merited fixes after review and validate them.
- Fix tiny nearby entropy problems when already editing the same artifact and the change is obviously safe.
- For larger or debatable redesigns, write or update a plan under `docs/plans/` before implementation.
- Never revert user or other-agent work unless explicitly asked.

## Merit To Commit

A reviewed change merits a commit when it is cohesive, truthful, validated at the right level, and has no unresolved blocker. Do not commit when:

- The review finds a correctness, data, security, or UX blocker.
- The change's purpose is unclear or mixes unrelated work.
- Required docs, plans, or tests are stale.
- Validation failed and the failure is not understood.

When committing, keep the commit scoped to the reviewed work and follow the repository workflow.

## Validation

Choose focused proof:

- Run Pint for changed PHP files when formatting may be affected.
- Run the narrowest meaningful Pest slice for behavior changes.
- For UI changes, use browser or screenshot checks when layout, spacing, state, or copy matters.
- For docs-only changes, validate links, terminology, and consistency with the governing docs.

If validation is skipped, say why and name the residual risk.

## Response Shape

For a review-only response:

1. Findings first, ordered by severity. Each finding needs a file/line reference, impact, and the specific condition that triggers it.
2. Low Entropy Opportunities, only if there are worthwhile non-blocking improvements.
3. Open Questions or Assumptions.
4. Verification or gaps.

For review-then-fix responses:

1. State the review result and what changed.
2. Name any fixed issues and remaining opportunities.
3. Include validation.
4. Include commit/push details when performed.

If no issues are found, say that directly. Do not pad the review with weak concerns.
