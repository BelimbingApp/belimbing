---
name: blb-code-review
description: Use when reviewing BLB changes, PRs, commits, or another agent's work; includes review-only, low-entropy, regression-risk, and review-then-fix/commit requests.
---

# BLB Code Review

Review for real harm first, then entropy. Bugs lead; cleanup earns its place.

## Load

- Root `AGENTS.md` and nested `AGENTS.md` for touched paths.
- `DESIGN.md` for UI, Blade, Livewire, Tailwind, interaction, or status copy.
- `tests/AGENTS.md` when tests are touched or coverage matters.
- `docs/plans/AGENTS.md` when plans are touched or larger follow-up is real.
- `docs/architecture/module-system.md` for module placement or ownership.

## Low Entropy Opportunitiy (LEO) Flow

1. Identify scope: working tree, commit(s), branch diff, PR, or files.
2. Read changed code plus enough callers, callees, data flow, and UI to understand the contract.
3. Check correctness, module boundaries, UX truth, tests, operations, and naming honesty.
4. Run the Low Entropy pass: small safe cleanup, consistent terms, repeated shape, stale comments, dead paths, weak tests, or plan-worthy larger work.
5. Keep unrelated pre-existing issues separate unless this change worsens them.

## Edit Rules

- Default to review-only unless the user explicitly asks to fix, commit, or otherwise edit; in review-only mode, report and do not edit.
- Fix/commit/merit-commit request: make small merited fixes, validate, and commit only if cohesive with no blocker.
- Fix tiny nearby entropy problems only when already in an explicit edit/fix path for the same artifact and the change is obviously safe.
- Put larger or debatable redesigns in `docs/plans/`.
- Never revert user or other-agent work unless explicitly asked.

## Output

- Findings first: severity order, file/line, impact, trigger.
- Low Entropy Opportunities only when useful.
- Open questions or assumptions.
- Verification or gaps.
- If no issues, say so directly. No padding.
- For fix/commit work, include changes, validation, and commit/push details.

## Validation

Use focused proof: Pint for PHP formatting, narrow Pest for behavior, browser/screenshot for UI, docs consistency for docs-only. If skipped, say why and name the risk.
