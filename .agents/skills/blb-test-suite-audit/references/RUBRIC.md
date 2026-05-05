# Audit Rubric

Worked BLB examples for the `keep` / `tighten` / `merge` / `delete`
dispositions defined in `SKILL.md`. Use to calibrate borderline cases and to
format each per-file decision.

## keep — concrete behavior, acceptable signal

`tests/Unit/Modules/Core/AI/Services/ProviderTestServiceTest.php` drives the
real `LlmClient` through three protocols (Chat Completions, Responses,
Anthropic Messages) and asserts both the success path and the structured-error
path (`AiErrorType::AuthError`, `HTTP 401`, diagnostic content). A regression
in protocol routing or error normalization breaks it for a concrete reason.

## tighten — add the missing path, do not rewrite

The provider test originally exercised only the success path. Tightening
layered on the structured-error dataset and per-protocol `provider_name`
assertions in
`tests/Unit/Modules/Core/AI/Services/ProviderTestServiceTest.php` rather than
replacing the original test. Tightening adds coverage; it does not start over.

## merge — collapse repeated scaffolding into deeper cases

`tests/Feature/AI/ProviderConnectionsTest.php` is the *result* of a correct
merge: two near-duplicate legacy-redirect tests collapsed into a single
dataset-driven case with rows for the `browse` and `connections` routes.
Without the merge, each route would carry its own bespoke scaffolding for the
same contract ("legacy provider routes redirect to the unified providers
page").

## delete

No current BLB example. Apply the definition in `SKILL.md`: smoke-only,
markup-only, framework-restatement, or duplicate tests not worth their CI and
maintenance cost.

## Output per reviewed file

- One disposition: `keep`, `tighten`, `merge`, or `delete`.
- One short reason tied to a specific failure mode or contract.
- An explicit follow-up if tightened or merged but not handled in the same
  pass.
