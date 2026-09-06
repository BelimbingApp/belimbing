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

## Pending CI composition work (not yet on main)

These follow-ups belong next to the pin and composition docs once they land; do not treat open PRs as
established procedure:

- Feature suite sharding across parallel matrix lanes: [issue #576](https://github.com/BelimbingApp/belimbing/issues/576) / [PR #610](https://github.com/BelimbingApp/belimbing/pull/610).
- Composed-application smoke test at the pinned People and PeopleConnector refs: [issue #600](https://github.com/BelimbingApp/belimbing/issues/600) / [PR #604](https://github.com/BelimbingApp/belimbing/pull/604).

