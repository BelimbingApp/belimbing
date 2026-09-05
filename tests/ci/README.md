# CI contract tests

Run the existing shell checks with bash tests/ci/test-ci-scripts.sh.
Run the required-check audit with bun test tests/ci/required-checks.test.ts;
the quality job runs it after installing its pinned Bun runtime.

The required-check test reads real workflow YAML and
fixtures/protect-main.ruleset.json, captured from active ruleset 11722555 on
2026-09-06. Refresh that JSON from the GitHub ruleset API whenever the repository
policy changes; CI performs no network policy lookup or policy mutation:

    gh api repos/BelimbingApp/belimbing/rulesets/11722555 > tests/ci/fixtures/protect-main.ruleset.json

The audit checks producers triggered by PRs targeting main, including branch
inclusion, exclusion, and ordered negation filters; job display names (falling back
to job IDs); and the required GitHub App identity. SonarCloud Code Analysis is produced
by the Sonar App's scan action, not by a job with the same label. Its actual scan
step must remain in a PR workflow and target SonarCloud. Literal false job and
scan conditions are rejected, including the expression form. Arbitrary runtime
conditions and path filters are not evaluated by this static audit.

Matrix and reusable-call names are not guessed: they cannot satisfy a bare
static required name. Extend the producer resolution alongside any intentional
move of a required check into those mechanisms. This audit detects static
name/producer drift; CI execution and the live merge gate remain the proof that
a required check actually ran and passed.
