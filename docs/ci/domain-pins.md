# Advance Domain CI pins

[scripts/ci/domain-repos.json](../../scripts/ci/domain-repos.json) is the
platform's reviewed map of controlled Domains: repository, mount path, immutable
dependency commit and Sonar identity. A Domain caller supplies the repository
under test; its required cross-Domain dependencies come from this descriptor.
The descriptor's platform `ref: main` is not proof of the platform checkout
used by a run: inspect the caller's `platform-ref` and materialization record.

1. **Select an immutable dependency snapshot.** Read the changed Domain's
   manifests and select the exact commit containing the required contract.
   Update only the intended descriptor entry, then run
   `bash tests/ci/test-ci-scripts.sh`. [Issue #567](https://github.com/BelimbingApp/belimbing/issues/567)
   and [PR #568](https://github.com/BelimbingApp/belimbing/pull/568) established
   advancing People for workforce contracts; [PR #591](https://github.com/BelimbingApp/belimbing/pull/591)
   advanced People to `0194f97782a28c42276cf52d0dce095aaa1a9c53` after
   Training relocation and boundary guards. Do not replace a SHA with a branch name.

2. **Choose a pair that can coexist.** A relocation can require both the new
   owner and removal from the old owner. [PR #574](https://github.com/BelimbingApp/belimbing/pull/574)
   added the Connector descriptor after the R4 removal;
   [PR #582](https://github.com/BelimbingApp/belimbing/pull/582) advanced its
   post-relocation ref. Existing table names and migration filenames can remain
   historical identities; retaining both source owners is not a compatibility strategy.

3. **Test the proposed descriptor, not an old caller pin.** Inspect both the
   reusable workflow ref and `platform-ref` in the consuming Domain's CI.
   An unchanged caller pinned to an older platform still tests the older
   descriptor. For [PR #591](https://github.com/BelimbingApp/belimbing/pull/591),
   the paired [Connector PR #172](https://github.com/BelimbingApp/blb-people-connector/pull/172)
   pinned both to platform commit `c8e756edadf4df6edce737ac4c8c190a5fef3bb6`.
   Test that immutable authored commit before landing; changing the branch name
   later cannot change what that run proved.

4. **Retain exact composition evidence for both drivers.** Download the
   `domain-materialization` artifact and verify its repository/ref/path rows.
   The [R8 proof run](https://github.com/BelimbingApp/blb-people-connector/actions/runs/33984235964)
   records People `0194f977...`, platform `c8e756ed...`, and the
   Connector PR merge checkout `07556df0...`. Its SQLite and PostgreSQL
   jobs both passed. A PR checkout can be GitHub's merge commit: record it as
   such, alongside its authored head, rather than calling it the branch head.
   Local domain suites complement this evidence; platform-only green checks
   do not prove optional Domains were mounted.

5. **Land in dependency order, then clean the slots.** [PR #591](https://github.com/BelimbingApp/belimbing/pull/591)
   landed the platform descriptor first, then
   [Connector #172](https://github.com/BelimbingApp/blb-people-connector/pull/172)
   landed the caller referencing its immutable commit. Each PR needs its own
   green `gate.sh`/`land.sh` result under the installed review rules.
   Run `cleanup.sh --yes` after each landing. Do not refresh reviewed
   disjoint branches merely because the other PR landed; follow the
   [adopter overlap rule](../ai-team-adopter.md).

## Validate upstream pins

`python3 scripts/ci/validate-domain-pins.py` checks every Domain in the descriptor
against GitHub. The quality job supplies `GITHUB_TOKEN`. Missing/inaccessible
commits, invalid descriptors, and API failures refuse the check; API unavailability
is not evidence that a pin is valid. A pin more than 50 commits behind `main`
prints a warning without failing. The count is the comparison's commits unique
to main, not a count inferred from local history. The validator never advances
pins. Follow the reviewed pin-update flow above to address a warning.

## Check a lane against the pin before ready

`domain-ci` composes each sibling Domain at the immutable SHA in
`scripts/ci/domain-repos.json`, while a local mount is that Domain's clone at
`main`. The pin therefore trails `main` for as long as it takes to advance it,
and a lane that calls a sibling API added in that window is green locally and
red on every composed job — a failure that looks like the author's bug and only
appears after `ready.sh` ([#927](https://github.com/BelimbingApp/belimbing/issues/927)).

Run this from the platform checkout before handing off:

```bash
php scripts/ci/pinned-mount-check.php --base=origin/main
```

It reads the changed PHP files, finds the sibling Domain classes they name, and
refuses the lane when a method those files call exists on that class at your
mounted revision but not at the pinned one — naming the class, the method, the
pin and the revision your mount is on, so the next step is a decision rather
than a bisect. A class the pin does not have at all is refused the same way.
Pass explicit paths instead of `--base` to check a subset. Exit 1 is a finding,
exit 2 is a usage error, and a mount that is not a git checkout is skipped with
a note rather than silently passing.

The fix is either to advance the pin through the reviewed flow above, or to keep
the lane on API the pin already has. Advancing the pin is the honest option when
the sibling API is the point of the change.

Two limits worth knowing, because the check does not pretend to cover them:

- **Runtime shape is invisible to it.** A test asserting an absolute count of a
  sibling's tables (33 at the pin, 40 on `main`) drifts without naming any
  method. Compose the pin and run the suite for that class of difference.
- **It compares names, not signatures.** A method that kept its name and changed
  its parameters passes this check and still fails in CI.

## Composition failures and the missing-check exception

[PR #570](https://github.com/BelimbingApp/belimbing/pull/570) refuses duplicate
route method/URI registrations and tables declared by different Modules;
[PR #588](https://github.com/BelimbingApp/belimbing/pull/588) also refuses the
table conflict at application boot. These diagnostics name the collision and
source files. Remove the obsolete owner or choose compatible pins; renaming a
migration file or route name does not remove a table or method/URI collision.

[PR #570's recorded operator exception](https://github.com/BelimbingApp/belimbing/pull/570#issuecomment-5551778324)
used `GATE_ALLOW_MISSING_CHECKS=copilot-pull-request-reviewer` for a
historical Copilot check that never reported. This is an explicit, recorded
exception for that missing baseline name, not a waiver for failed tests,
independent review, or GitHub-required checks. Do not carry the override into
ordinary pin updates after the obsolete check leaves the baseline. See the
[package gate contract](../ai-team/README.md) for current mechanics.

## Sonar quality gate alignment

[PR #607](https://github.com/BelimbingApp/belimbing/pull/607) records the shared SonarCloud gate contract in
[`scripts/ci/domain-repos.json`](../../scripts/ci/domain-repos.json) under `sonar_quality_gate`. Platform
(`BelimbingApp_lara`), People (`BelimbingApp_blb-people`), and PeopleConnector
(`BelimbingApp_blb-people-connector`) all use gate id **9** / **Sonar way**, including `new_coverage` LT **80**.
The built-in gate cannot have its conditions edited; tightening requires copying to a custom gate first.
When advancing Domain pins, keep each Domain's `sonar_project_key` pointed at that shared gate rather than
assuming a looser Domain-specific threshold.

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

## Composed smoke on pin advances

Bot-maintenance PRs are reconsidered by
[`land-bot-maintenance`](../../.github/workflows/land-bot-maintenance.yml) when a
relevant CI workflow completes. It fetches candidate commits without checking out
their code, repeats the trusted file policy, and requires all configured checks
to report success from their configured integrations. It excludes agent lanes,
forks, drafts, and holds. Repository merge settings, active rulesets, and classic
protection determine the merge method; GitHub still enforces approval and other
merge rules. The final merge is bound to the checked SHA and uses
`COVERAGE_BASELINE_RAISE_TOKEN`. The same raise token also opens bot-maintenance PRs from `refresh-pest-timing-baseline`, `refresh-feature-shard-timings`, and `refresh-livewire-action-baselines` (#853). If policy cannot be read, the workflow refuses.
Applying `bot-maintenance` also authorizes a Domain descriptor/surface pin advance
to land on green required checks without an independent reviewer; it is not limited
to numeric baseline refreshes. The label is the authorization, not the PR author's identity.
Rerun the trusted independent-review check to reconsider an already-green bot PR
whose last CI run predates this workflow; this does not bypass any checks.

[PR #712](https://github.com/BelimbingApp/belimbing/pull/712) (issue [#600](https://github.com/BelimbingApp/belimbing/issues/600)) landed
[`.github/workflows/composed-smoke.yml`](../../.github/workflows/composed-smoke.yml). It boots the
platform with every pinned Domain (People, Commerce, Operation, and PeopleConnector) at the refs in
[`scripts/ci/domain-repos.json`](../../scripts/ci/domain-repos.json) and holds the result to
[`scripts/ci/composed-surface.json`](../../scripts/ci/composed-surface.json).

Both smoke and pin-advance workflows enumerate descriptor keys. The smoke script
defaults to all descriptor entries; `--domains` is only an explicit subset override.
The surface is live route names matching `DOMAIN_ROUTE_NAME` (not a whole-table
scan and not a literal `->name()` text scan — Base `admin.integration.*` must
not count, and `Route::name()->group` / `Route::resource` assembled names must
still move the count). Domain Routes files still need a known prefix in
`DOMAIN_ROUTE_NAME` so an unconventional declaration fails loudly; add a new
Domain's prefixes there when introducing it. The `advance-domain-pin` dispatch accepts all four
Domains and regenerates the complete surface before opening its maintenance PR
with `COVERAGE_BASELINE_RAISE_TOKEN` (push stays on `GITHUB_TOKEN`; the raise token
opens and labels the PR, matching the other maintenance workflows).

Every pull request to `main` runs that check, including a pin-advance PR that edits the
descriptor ([issue #627](https://github.com/BelimbingApp/belimbing/issues/627)). The workflow does
**not** use a `paths:` filter on `pull_request`. A path-filtered workflow that is also a
branch-protection required check leaves unrelated PRs waiting for a status that never reports.
Keep the job always reporting; require the `composed-smoke` context in Protect Main when the
owner wants it blocking. Recover refusals with the
[composed-app runbook](composed-app-runbook.md).

A nightly schedule ([#663](https://github.com/BelimbingApp/belimbing/issues/663)) re-runs the same
assertion against the pins on `main` and opens or updates one issue titled **Composed boot failed**
on refusal; `workflow_dispatch` accepts a dry-run input that exercises the issue body path without
calling the Issues API. The same nightly/dispatch path runs
[`validate-domain-pins.py`](../../scripts/ci/validate-domain-pins.py) after boot and opens or updates
one **Domain pins stale** issue when a pin is more than 50 commits behind `main`. That issue lists
the Domain, repository, immutable ref, and measured count. A successful validation with no warnings
closes it automatically; validator errors fail the run and do not close an existing alert.
