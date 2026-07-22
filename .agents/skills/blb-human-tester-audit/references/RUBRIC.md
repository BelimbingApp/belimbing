# Audit rubric

## Categories

Use one primary category per finding and optional secondary tags:

- `broken` — crash, exception, dead control, wrong destination, data loss, or
  a task that cannot complete.
- `workflow` — the task completes but is illogical, misleading, unnecessarily
  long, context-losing, or requires avoidable guessing.
- `design` — concrete violation of `DESIGN.md` or an established BLB pattern
  that harms comprehension, trust, or scanability.
- `feedback` — missing, late, stale, contradictory, or dishonest system
  response.
- `accessibility` — keyboard, focus, labeling, contrast, semantics, or
  assistive-technology barrier observed in the tested surface.
- `responsive` — narrow/wide viewport obstruction or loss of task clarity.
- `validation` — invalid, empty, duplicate, boundary, or recovery state is
  missing or unsafe.
- `data-safety` — permission, identity, scope, privacy, or destructive-action
  risk.
- `performance` — user-visible slowness, blocking, or instability.

## Severity

- `critical`: data loss/corruption, privacy or authorization breach, security
  exposure, or a core task is impossible for the target role.
- `high`: a primary task is blocked, a destructive action can happen
  accidentally, or the result is materially wrong and likely to mislead users.
- `medium`: the task is possible but a common path is confusing, error-prone,
  context-losing, inaccessible, or visibly inconsistent with the product
  contract.
- `low`: a localized polish or clarity issue with limited task impact.

Severity describes user impact, not implementation effort. Raise severity for
frequency and risk; do not raise it only because the issue is easy to fix.

## Confidence

- `confirmed`: reproduced from a stated starting state at least once, with
  direct evidence.
- `likely`: observed or strongly indicated, but reproduction or causal detail
  is incomplete.
- `tentative`: a useful suspicion needing product or engineering confirmation.

## Minimum finding fields

Every finding should include:

`id`, `title`, `category`, `severity`, `confidence`, `page_ids`, `summary`,
`steps`, `expected`, `actual`, `impact`, `recommendation`, and `evidence`.

Use concrete titles: `Saving a filtered list drops the user's working scope`,
not `Bad UX`. Recommendations should state the user-centered change, not
prescribe a speculative implementation.
