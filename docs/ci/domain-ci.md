# Domain CI composition

[scripts/ci/domain-repos.json](../../scripts/ci/domain-repos.json) lists the
Belimbing-controlled Domains that CI composes. It lists **ids only**. Each id's
repository, mount path and Sonar project key are derived from it by
[scripts/ci/domain-registry.php](../../scripts/ci/domain-registry.php):
`people-connector` is `<owner>/blb-people-connector` at
`app/Domains/PeopleConnector` with Sonar key `<owner>_blb-people-connector`.
Adding a Domain that follows the convention is one line in the descriptor.

The owner is not written down either. It comes from the checkout's own git
remotes — `origin` first, then `upstream` — so a clone of the canonical
repository resolves to the canonical org and a fork resolves to its own owner
with the forked repository as the fallback. `BLB_DOMAIN_OWNERS` overrides both,
which is how a checkout with no remotes (a tarball, a test fixture) says who
owns its Domains.

## There are no pins

CI composes every Domain at its `main`. This is a deliberate reversal of the
pinned-ref scheme that ran until [#940](https://github.com/BelimbingApp/belimbing/issues/940),
and the reasoning is worth keeping:

- A pin bought repeatability: the same revisions on every run, and with it a
  combination that had been composed and tested together. That is worth
  something — the cost of losing it is set out below — but it is not what stops
  two Domains colliding.
- The guards that refuse a collision between two Domains live in the
  application, not in CI: `RouteCollisionException`, `TableRegistry` and
  `IncubatingSchemaConflictException` refuse at boot, on a developer machine,
  in each Domain's own CI, in staging and in production. Removing the pins
  leaves every one of those intact.
- The pins cost a standing chore. Three **Domain pins stale** issues were
  opened in the five days before they were removed, each needing a dispatch, a
  bot PR and a full CI run to change two files.

What that gives up, stated plainly, and it is more than diagnosis:

- **A fixed combination that was known to work.** A pin recorded a set of
  revisions that had been composed and tested together. Without one, each run
  composes whatever the Domains' `main` branches hold at that moment, which may
  be a combination nothing has ever exercised. The boot-time collision guards
  do not replace that: they catch a Domain pair colliding, not a combination
  that is merely untried. This is the real cost of the trade, not a footnote.
- **Attribution.** When the nightly breaks, it does not say whether a platform
  change or a Domain change caused it.
- **Replay.** A run cannot be re-run against a fixed past state. If you need to
  know, compose locally at the two revisions you suspect and compare.

The judgement made here is that a standing maintenance chore, paid on a
schedule whether or not anything is wrong, costs more than those three. That
judgement is reversible: reintroducing a pin is a field in the descriptor and a
ref in the materializer.

There is also no checked-in route surface. The composed smoke asserts that the
application boots with every Domain mounted and that no migration basename is
claimed twice — not that a Domain has a particular set of route names. A
Domain's own route inventory is that Domain's business and belongs in its own
suite.

## Caller-supplied identity

[domain-ci.yml](../../.github/workflows/domain-ci.yml) is a reusable workflow.
It does not run in this repository: a Domain repository calls it, and the jobs
run in that repository's own Actions. The platform publishes the harness
because a Domain repo cannot build alone — it has no root `composer.json`, no
`vendor/`, no Laravel bootstrap and no Pest config — not because the platform
supervises Domains.

Each caller declares its own identity:

```yaml
jobs:
  ci:
    uses: BelimbingApp/belimbing/.github/workflows/domain-ci.yml@main
    with:
      domain-path: app/Domains/People
      sonar-project-key: BelimbingApp_blb-people
      sonar-organization: belimbingapp
    secrets:
      SONAR_TOKEN: ${{ secrets.SONAR_TOKEN }}
```

The descriptor is consulted only for a Domain's **siblings**: when a manifest
declares `extra.blb.requires-modules` naming a module in another Domain,
[compose-domain.php](../../scripts/ci/compose-domain.php) derives that Domain's
repository and mount path from the module's vendor segment and prints one clone
line per missing repository. A required module naming a Domain the descriptor
does not list is refused, naming it.

`platform-ref` chooses what the Domain composes against. A branch tracks the
platform; a commit SHA freezes it. A caller pinned to an older platform commit
is testing that older harness — inspect the caller before concluding anything
about what a run proved.

## Composition failures and the missing-check exception

[PR #570](https://github.com/BelimbingApp/belimbing/pull/570) refuses duplicate
route method/URI registrations and tables declared by different Modules;
[PR #588](https://github.com/BelimbingApp/belimbing/pull/588) also refuses the
table conflict at application boot. These diagnostics name the collision and
source files. Remove the obsolete owner; renaming a migration file or route
name does not remove a table or method/URI collision.

[PR #570's recorded operator exception](https://github.com/BelimbingApp/belimbing/pull/570#issuecomment-5551778324)
used `GATE_ALLOW_MISSING_CHECKS=copilot-pull-request-reviewer` for a
historical Copilot check that never reported. This is an explicit, recorded
exception for that missing baseline name, not a waiver for failed tests,
independent review, or GitHub-required checks. Do not carry the override into
ordinary maintenance after the obsolete check leaves the baseline. See the
[package gate contract](../ai-team/README.md) for current mechanics.

## Sonar quality gate alignment

[PR #607](https://github.com/BelimbingApp/belimbing/pull/607) records the shared SonarCloud gate contract in
[`scripts/ci/domain-repos.json`](../../scripts/ci/domain-repos.json) under `sonar_quality_gate`. Platform
(`BelimbingApp_lara`), People (`BelimbingApp_blb-people`), and PeopleConnector
(`BelimbingApp_blb-people-connector`) all use gate id **9** / **Sonar way**, including `new_coverage` LT **80**.
The built-in gate cannot have its conditions edited; tightening requires copying to a custom gate first.
Each Domain's derived Sonar project key must stay pointed at that shared gate rather than a looser
Domain-specific threshold.

## Workflow concurrency

[PR #636](https://github.com/BelimbingApp/belimbing/pull/636) groups the
[platform tests](../../.github/workflows/tests.yml) and
[quality](../../.github/workflows/lint.yml) runs by workflow and ref. A newer
push to the same PR cancels the older run, including that test run's SQLite
suites and PostgreSQL mirror. The workflows remain separate groups, so a
quality run cannot cancel the test workflow.

Main runs use a unique `github.run_id` group suffix and disable
`cancel-in-progress`: this preserves both running and pending main runs.
The [reusable Domain workflow](../../.github/workflows/domain-ci.yml) applies
the same policy with a `domain-ci-` prefix to distinguish its group from the
caller. A Domain adopts this behavior only when its pinned reusable workflow
revision includes #636; a platform descriptor update alone does not change an
older caller workflow. Record the exact run and attempt when comparing timings:
a cancelled older PR run is not evidence that its tests passed.

## Read the timing summary

[PR #677](https://github.com/BelimbingApp/belimbing/pull/677) replaced the closed,
unmerged #624 and added a **Per-run timing summary** to the platform `ci` job's
GitHub Actions summary. Rows identify Job, Suite, Wall (s), Tests, and Assertions
for Unit, each Feature shard, and the combined Core/Domain/Extension invocation. The
[recorder](../../scripts/ci/record-pest-timing.sh) measures each Pest process's
elapsed time, including its coverage work but excluding job setup. Its test count
includes reported passed, failed, skipped, and other footer statuses; it is not
a passed-test count. The wrapper preserves Pest's exit status.

The matrix jobs upload `platform-timing-<suite>` JSON artifacts with one-day
retention. The `ci` job downloads these and runs the
[aggregator](../../scripts/ci/aggregate-pest-timing.py). Its sum is total recorded
Pest process time, not workflow elapsed time: concurrent invocations overlap.
Use the Actions job timestamps to measure the critical path, queueing, and setup;
these rows do not time `postgres-mirror`, quality, or Domain caller jobs. Compare
the same composition, suite membership, and run attempt before claiming a gain.
The aggregate is published after the suite-success check; a failed or cancelled
run need not have a complete aggregate table.

The `ci` job then compares each recorded suite against the checked-in
[`pest-timing-baseline.json`](../../tests/ci/pest-timing-baseline.json). A suite
fails only when it is both more than 25% and more than 20 seconds slower, so
normal variance and large relative changes to short suites do not block a PR.
Missing, extra, duplicate, or malformed suite records fail closed. Refresh the
baseline from reviewed timing artifacts with
`python3 scripts/ci/pest-timing-ratchet.py timing --write-baseline --source <run-url>`
and submit the resulting baseline change through a PR; never push it directly
to `main`.

## Unit and Feature shard membership and coverage

[PR #674](https://github.com/BelimbingApp/belimbing/pull/674) replaced the closed,
unmerged #610. The platform matrix runs `Unit-a`, `Unit-b`, `Feature-a`, and `Feature-b`
concurrently, one Pest process per invocation. Feature remains one logical suite
in `phpunit.xml`; CI divides its first-level directories using the committed
[membership map](../../scripts/ci/platform-feature-shards.json). The Unit-a lane
also runs the combined Core/Domain/Extension invocation. This does not compose
optional Domains into the platform checkout or change the Domain caller's tests.

Unit uses the same validator and longest-processing-time placement with
`--suite=Unit`, its own [timing summary](../../scripts/ci/platform-unit-shard-timings.json),
and [membership map](../../scripts/ci/platform-unit-shards.json). Regenerate with
`python3 scripts/ci/platform-feature-shards.py --suite=Unit --write-balanced` and
check with `--suite=Unit --validate-only`. Directory measurements exclude coverage;
Core dominates the current Unit surface, so these indivisible directory shards
are unequal. Hosted timing artifacts are the evidence for actual CI improvement.
The initial Unit shard ratchet bounds each use the previous unsplit Unit timing,
explicitly recorded in the baseline; refresh them from successful hosted shard
artifacts with `refresh-pest-timing-baseline` after the first main run.

After adding, moving, or removing a Feature directory, add its measured
`wall_seconds` to the committed
[timing summary](../../scripts/ci/platform-feature-shard-timings.json), then
regenerate the map with
`python3 scripts/ci/platform-feature-shards.py --write-balanced` and confirm it
with `--validate-only` ([PR #692](https://github.com/BelimbingApp/belimbing/pull/692)
replaced the hand-kept map with longest-processing-time placement from that
file). The [validator](../../scripts/ci/platform-feature-shards.py) refuses
missing or unknown directories, overlap, empty test directories, loose top-level
`*Test.php` files, and any Feature test file that is not owned by exactly one
shard. Use the per-shard timing rows to refresh the summary instead of assuming
equal directory counts mean equal execution time. Operators can dispatch
[refresh-feature-shard-timings](../../.github/workflows/refresh-feature-shard-timings.yml)
to refresh both Unit and Feature summaries from their lane timing artifacts and
open one PR containing both summaries and rebalanced maps. Directory walls are
estimates allocated in proportion to prior weights (equally when no weights exist),
not direct directory measurements. The updater defaults to Feature; use
`--suite=Unit` to refresh Unit independently. A directory absent from the measured
shard map is refused by name ([#767](https://github.com/BelimbingApp/belimbing/issues/767)).

Each matrix lane uploads `platform-coverage-<suite>`. The aggregate `ci` job
requires `coverage-unit-a.xml`, `coverage-unit-b.xml`, `coverage-feature-a.xml`,
`coverage-feature-b.xml`, and `coverage-modules.xml`, then supplies all five paths to Sonar. `ci` remains
the aggregate check; a failed, skipped, or cancelled matrix lane does not become
a successful aggregate. Preserve the complete report set when changing shards.
The PostgreSQL mirror remains a separate job with its own driver-sensitive set.

Coverage baseline increases are deliberate: include a meaningful increase in the PR
that improves coverage. Use `python3 scripts/ci/platform-coverage-ratchet.py update`
with the five Clover reports listed above to calculate the new baseline; the command
refuses to lower it. CI checks the baseline but does not open baseline-update PRs.
Baseline changes receive normal review, without the bot-maintenance exemption.

The platform coverage ratchet unions Clover statement identities by source-file
name and line number, with coverage from any shard counting as covered. It does
not sum project totals: isolated shards repeat the full source inventory. Reports
must contain statement lines; aggregate-only metrics cannot prove overlap.

## Composed smoke

Bot-maintenance PRs are reconsidered by
[`land-bot-maintenance`](../../.github/workflows/land-bot-maintenance.yml) when a
relevant CI workflow completes. It fetches candidate commits without checking out
their code, repeats the trusted file policy, and requires all configured checks
to report success from their configured integrations. It excludes agent lanes,
forks, drafts, and holds. Repository merge settings, active rulesets, and classic
protection determine the merge method; GitHub still enforces approval and other
merge rules. The final merge is bound to the checked SHA and uses
`COVERAGE_BASELINE_RAISE_TOKEN`. The same raise token also opens bot-maintenance PRs from `refresh-pest-timing-baseline`, `refresh-feature-shard-timings`, and `refresh-livewire-action-baselines` (#853). If policy cannot be read, the workflow refuses.
Applying `bot-maintenance` authorizes a machine-generated maintenance PR to land on
green required checks without an independent reviewer; it is not limited to numeric
baseline refreshes. The label is the authorization, not the PR author's identity.
Rerun the trusted independent-review check to reconsider an already-green bot PR
whose last CI run predates this workflow; this does not bypass any checks.

[PR #712](https://github.com/BelimbingApp/belimbing/pull/712) (issue [#600](https://github.com/BelimbingApp/belimbing/issues/600)) landed
[`.github/workflows/composed-smoke.yml`](../../.github/workflows/composed-smoke.yml). It boots the
platform with every Domain the descriptor lists (People, Commerce, Operation, and PeopleConnector),
each at its `main`, and asserts that the application boots and that no migration basename is claimed
by two modules.

The smoke script enumerates descriptor ids; `--domains` is only an explicit subset override, and a
subset naming an id the descriptor does not list is refused before anything is cloned. It no longer
holds the boot to a checked-in route surface: that file and the `DOMAIN_ROUTE_NAME` prefix list were
removed with the pins ([#940](https://github.com/BelimbingApp/belimbing/issues/940)), because a
Domain's route inventory is not the platform's to assert.

Every pull request to `main` runs that check ([issue #627](https://github.com/BelimbingApp/belimbing/issues/627)). The workflow does
**not** use a `paths:` filter on `pull_request`. A path-filtered workflow that is also a
branch-protection required check leaves unrelated PRs waiting for a status that never reports.
Keep the job always reporting; require the `composed-smoke` context in Protect Main when the
owner wants it blocking. Recover refusals with the
[composed-app runbook](composed-app-runbook.md).

A nightly schedule ([#663](https://github.com/BelimbingApp/belimbing/issues/663)) re-runs the same
assertion against `main` and opens or updates one issue titled **Composed boot failed** on refusal;
`workflow_dispatch` accepts a dry-run input that exercises the issue body path without calling the
Issues API. Because the nightly composes each Domain at its `main`, a refusal can come from a change
in any of them: read the run's materialization output for the revisions it actually mounted before
attributing it.
