# AI Team GitHub App

The blocked-by sweep and independent-review workflows mint a short-lived
installation token from a GitHub App on every run. That replaces the shared
user account / fine-grained PAT that hit GraphQL secondary rate limits under
team load (#621). Do not store a user PAT for this automation.

## Before the App exists

Both workflows detect the two secrets at run time. While they are absent the
blocked-by sweep runs on `AI_TEAM_BLOCKED_BY_SWEEP_TOKEN` and the review gate on
the default `GITHUB_TOKEN`, each with a `::warning::` annotation naming this
runbook. Nothing breaks on merge; the warning disappears the first run after
the secrets are set. The sweep still fails closed when neither credential exists.

## Permissions to grant the App

Install the App on the BelimbingApp organization with access to at least:

| Repository | Reason |
|---|---|
| `BelimbingApp/belimbing` | Board issues, PR review gate, Contents for trusted grammar |
| `BelimbingApp/blb-people` | Cross-repo Blocked-By resolution |
| `BelimbingApp/blb-people-connector` | Cross-repo Blocked-By resolution |

Repository permissions:

| Permission | Access | Why |
|---|---|---|
| Metadata | Read-only | Required for every GitHub App |
| Contents | Read-only | Fetch trusted review grammar blobs |
| Issues | Read and write | Sweep unblocks issues; review gate reads issue/PR linkage |
| Pull requests | Read-only | Exact-head review verdict inspection |

No Administration, Actions write, or Contents write.

## Owner create steps

1. Organization **Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Name it something durable such as `Belimbing AI Team automation`.
3. Homepage URL may be the org or this repository; webhook may be inactive (no events required).
4. Set the repository permissions in the table above; leave all account permissions unset.
5. Create the App, then **Generate a private key** and download the `.pem`.
6. Note the **App ID** (numeric).
7. **Install App** on BelimbingApp, selecting only the three repositories above (or all org repos if that is the deliberate inventory choice).
8. In `BelimbingApp/belimbing` Actions secrets, set:
   - `AI_TEAM_GITHUB_APP_ID` — the numeric App ID
   - `AI_TEAM_GITHUB_APP_PRIVATE_KEY` — full PEM including `BEGIN`/`END` lines
9. Dispatch **AI Team blocked-by sweep** once and confirm the job mints a token and completes.
10. On any non-draft PR, confirm **Independent review** mints the App token (workflow from `main` after this change lands).
11. Revoke `AI_TEAM_BLOCKED_BY_SWEEP_TOKEN` (and any shared user automation PATs for these jobs) only after both proofs pass.

## Adopter copies

Adopters that copy `docs/ai-team/templates/blocked-by-sweep.yml` and
`independent-review.yml` must create their own App (or install this one when
they share the BelimbingApp org) and set the same two secret names. The mint
step scopes the token to `github.repository_owner` so every repository the App
is installed on for that owner is reachable for Blocked-By resolution.
