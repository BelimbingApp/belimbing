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

## Feature shard membership and coverage

[PR #674](https://github.com/BelimbingApp/belimbing/pull/674) replaced the closed,
unmerged #610. The platform matrix runs `Unit`, `Feature-a`, and `Feature-b`
concurrently, one Pest process per invocation. Feature remains one logical suite
in `phpunit.xml`; CI divides its first-level directories using the committed
[membership map](../../scripts/ci/platform-feature-shards.json). The Unit lane
also runs the combined Core/Domain/Extension invocation. This does not compose
optional Domains into the platform checkout or change the Domain caller's tests.

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
to rewrite the summary from Feature-* suite timing artifacts and open a PR
with the rebalanced shard map ([#695](https://github.com/BelimbingApp/belimbing/issues/695)).

Each matrix lane uploads `platform-coverage-<suite>`. The aggregate `ci` job
requires `coverage-unit.xml`, `coverage-feature-a.xml`, `coverage-feature-b.xml`,
and `coverage-modules.xml`, then supplies all four paths to Sonar. `ci` remains
the aggregate check; a failed, skipped, or cancelled matrix lane does not become
a successful aggregate. Preserve the complete report set when changing shards.
The PostgreSQL mirror remains a separate job with its own driver-sensitive set.

## Pending CI composition work (not yet on main)

These follow-ups belong next to the pin and composition docs once they land; do not treat open PRs as
established procedure:

- Composed-application smoke test at the pinned People and PeopleConnector refs: [issue #600](https://github.com/BelimbingApp/belimbing/issues/600) / [PR #675](https://github.com/BelimbingApp/belimbing/pull/675), replacing closed, unmerged [PR #604](https://github.com/BelimbingApp/belimbing/pull/604).
