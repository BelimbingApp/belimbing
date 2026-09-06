# Belimbing AI Team adoption — 2026-09-05

This note records Belimbing's adoption decisions and their evidence. The
[package README](ai-team/README.md) owns the full workflow and verdict grammar;
this host-owned note stays outside its subtree.

| Contract | Belimbing evidence and application |
| --- | --- |
| Native approvals are zero | Protect Main has `required_approving_review_count: 0`. The [live ruleset API](https://api.github.com/repos/BelimbingApp/belimbing/rulesets/11722555) is authoritative; this is a repository setting, not a commit. The [snapshot in PR #578](https://github.com/BelimbingApp/belimbing/blob/01e77a9f58ae7fce9a027f0e7bf38848b9dbc87c/tests/ci/fixtures/protect-main.ruleset.json) records it. Do not mistake zero native approvals for permission to skip the AI Team gate or required checks. |
| Copilot remains triage here | Current team instructions reserve Copilot requests for Belimbing, not the People repositories. However, automatic Copilot review is no longer enabled: the owner decision is recorded on [PR #570](https://github.com/BelimbingApp/belimbing/pull/570#issuecomment-5551846975), and the live ruleset contains no `copilot_code_review` rule. Resolve or decline supplied inline findings before readiness; Copilot never substitutes for the independent reviewer. |
| Trusted package pulls can omit a verdict | [PR #564](https://github.com/BelimbingApp/belimbing/pull/564), commit `299c6bd4`, introduced [.ai-team/subtree-pull](../.ai-team/subtree-pull): `BelimbingApp/ai-team package-mount docs/ai-team`. The gate verifies the upstream tree and trusted pull shape. Handwritten changes mixed into a pull end the exemption. |
| Two verdict values, one reviewer | [PR #566](https://github.com/BelimbingApp/belimbing/pull/566), commit `dc7afe0a`, imported the grammar: `accept` or `changes required`; no “accept with follow-up.” Each verdict is a PR review with unique `From` and full `HEAD reviewed` markers, bound to that commit. One reviewer handles a PR, two at most; “two verdicts” does not mean two approvals. |
| Findings become tests; authors merge | [PR #562](https://github.com/BelimbingApp/belimbing/pull/562), commit `a8a79b20`, imported the policy: reproduce a failing test, fix it in the same PR, use green CI as clearance, and have the author run `gate.sh` then `land.sh`. Only findings that cannot be tests require another read. Never bypass a script that still refuses: report the policy/mechanism mismatch. [PR #578's blocker](https://github.com/BelimbingApp/blb-people/issues/40#issuecomment-5553634606) records an exact-head acceptance mismatch. |
| Being behind main is not itself a blocker | [PR #562](https://github.com/BelimbingApp/belimbing/pull/562) introduced the file-overlap rule. Merge main before review; refresh again for an overlap named by the gate or a conflict. Disjoint changes warn and may land. [PR #571](https://github.com/BelimbingApp/belimbing/pull/571), commit `ead31cbf`, preserves acceptance through a pure main merge. Do not rebase or squash the reviewed branch. |
| Parallel lanes use separate slots | [PR #563](https://github.com/BelimbingApp/belimbing/pull/563), commit `a909d55d`, imported recycled worktrees; [PR #571](https://github.com/BelimbingApp/belimbing/pull/571) made cleanup parallel-safe. Set `CLAIM_WORKTREE` to an agent-owned repository/slot path before each `claim.sh`. Each concurrent lane owns a claim and changes disjoint files. Reuse slots and run `cleanup.sh --yes` after landing; never operate in another agent's checkout. |
| A never-reporting historical check needs an explicit exception | [PR #570](https://github.com/BelimbingApp/belimbing/pull/570#issuecomment-5551778324) records the Copilot quota failure and operator use of `GATE_ALLOW_MISSING_CHECKS=copilot-pull-request-reviewer`. This only waives that missing name in the gate's historical baseline, with a visible warning; it does not waive failed checks, independent review, or GitHub rules. Record the exact exception on the PR. Do not carry it forward once the obsolete name leaves the five-merge baseline. |

Documentation, plans and CI wiring need no reviewer only when the installed gate
recognizes a trusted shape. If the prose policy and installed mechanism differ,
keep the refusal visible and ask the steward to reconcile it at the source.


## GitHub API rate-limit playbook

On 2026-09-06, the shared account exhausted its GraphQL primary quota. For example, [Connector #191](https://github.com/BelimbingApp/blb-people-connector/pull/191) had an exact-head acceptance and green checks, but the landing script could not read the PR. The underlying error was `API rate limit already exceeded for user ID`; the script's shorter `cannot read PR` message was not a review rejection or an operational halt. The direct GraphQL response reported `remaining: 0` and reset at `2026-09-06T01:58:05Z` (09:58:05 Asia/Kuala_Lumpur). That is historical evidence, not a reusable reset schedule. A REST rate-limit summary looked unused during the same incident, so it did not establish that GraphQL reads could proceed.

### Identify the exhausted API

The following command paths were checked with GitHub CLI **2.74.1**. High-level commands can make several requests or short-circuit locally depending on selected fields; do not infer transport solely from the command's name.

| Command family | Verified API dependency | Evidence |
|---|---|---|
| `gh issue create` | GraphQL creation mutation | Upstream [IssueCreate](https://github.com/cli/cli/blob/v2.74.1/api/queries_issue.go#L262); source inspection, no diagnostic issue created. |
| `gh pr create` | GraphQL creation mutation and optional metadata mutations | Upstream [CreatePullRequest](https://github.com/cli/cli/blob/v2.74.1/api/queries_pr.go#L537); source inspection, no diagnostic PR created. |
| `gh issue comment`, `gh pr comment` | New comments use the shared GraphQL comment mutation | Upstream [shared comment handler](https://github.com/cli/cli/blob/v2.74.1/pkg/cmd/pr/shared/commentable.go#L151) and [CommentCreate](https://github.com/cli/cli/blob/v2.74.1/api/queries_comments.go#L56); no diagnostic comment posted. |
| `gh issue view`, `gh issue list`, `gh pr view`, `gh pr list` | Normal remote reads use GraphQL | Read-only request traces on this repository: issue view/list with `--json number`, PR view with `--json headRefOid`, PR list with `--json number`, all used `POST /graphql`. A PR-number-only view returned locally without a request, so that is not a transport probe. |
| `gh pr checks` | GraphQL check queries | Read-only trace used `POST /graphql`; upstream [checks query](https://github.com/cli/cli/blob/v2.74.1/pkg/cmd/pr/checks/checks.go#L281). A watch can therefore keep consuming the shared quota. |
| `gh api repos/BelimbingApp/belimbing/pulls/611` | REST | Read-only trace used `GET /repos/BelimbingApp/belimbing/pulls/611`. |
| `gh api graphql` | GraphQL | Read-only `rateLimit` trace used `POST /graphql`. The [CLI manual](https://cli.github.com/manual/gh_api) defines the endpoint selection; `gh api` is not inherently REST. |

Use the failed response's rate-limit headers when available. If they were lost by a wrapper, make one small diagnostic query with the same host and authentication context:

```bash
gh api graphql -f query='query { rateLimit { remaining resetAt used } }'
```

Use its `resetAt` for an exhausted primary quota. Do not repeatedly ask for quota or run a checks watch while waiting. For a secondary-limit response, honor `retry-after`; if absent, GitHub directs a wait of at least one minute and increasing backoff on repeated failures. These are distinct conditions: the incident's zero remaining points demonstrated primary exhaustion, regardless of a helper comment calling it secondary. See [GitHub's rate-limit guidance](https://docs.github.com/en/graphql/overview/rate-limits-and-query-limits-for-the-graphql-api).

### Continue safely and resume once

1. Save the PR number, exact accepted head, last known check result, error and reset time locally. Stop the failed polling loop and tell the steward once through an available channel; avoid turning one unavailable API into repeated board requests. Continue independent local work that does not require a new claim.
2. REST can supply read-only situational information while its own limits permit. For example, the PR read above returns the live head and merge state; it does not replace the complete gate. Missing API data means **unknown**, never an empty review queue, a green check or proof that no halt exists.
3. After the reset/backoff, re-read the PR once. If already merged, run cleanup. If still open, retry the canonical landing command at the accepted head; the script must revalidate checks, holds, closing references and review state. A changed head requires the normal workflow, not reuse of stale evidence.
4. Do not edit claim/hold labels, force a merge, switch identities, waive failing checks, or replace the canonical gate with a hand-written REST merge to get around quota exhaustion.

The deployment-local `/home/kiat/repo/laravel/.ai-team-steward-tick.sh` uses REST endpoints for its board/check scan and invokes the installed `land.sh` for landings. Its outer REST path does not make the invoked gate GraphQL-independent. That helper belongs to the appointed steward or explicitly assigned backstop, not ordinary authors; use the current role instructions for dispatch. It is not part of the mounted package and this note does not grant authority to run it. The [package workflow](ai-team/README.md#heartbeat-stopping-and-cleanup) remains the recovery boundary.
