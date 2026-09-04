# People Connector CI dispatch

The platform test workflow notifies `BelimbingApp/blb-people-connector` after
both platform test jobs succeed on `refs/heads/main`. The connector then tests
itself against the exact platform revision that emitted the event. This event
driven check is the primary platform-drift signal; the connector's twice-daily
schedule remains a backstop.

## Receiver contract

The sender posts a `repository_dispatch` event with type
`belimbing-platform-main-ci-succeeded` and these `client_payload` fields:

| Field | Contract |
|---|---|
| `platform_repository` | `BelimbingApp/belimbing` |
| `platform_ref` | `refs/heads/main` |
| `platform_sha` | The full lowercase commit SHA that passed platform CI |
| `platform_run_url` | The originating platform Actions run URL |

The receiver must validate every field before using `platform_sha` as the
platform ref for composed Domain CI. Land and verify the receiver before the
sender so a dispatched event can never disappear without a workflow run.

## Credential

Create the platform Actions secret `PEOPLE_CONNECTOR_DISPATCH_TOKEN`. Use an
owner-managed fine-grained personal access token selected for only
`BelimbingApp/blb-people-connector`, with repository permission
**Contents: Read and write**. That is the permission GitHub requires for
`POST /repos/{owner}/{repo}/dispatches`; no platform-repository write
permission is needed. Prefer a dedicated machine identity with an expiry and
recovery owner over a person's general-purpose token.

A GitHub App installation token with the same single-repository Contents write
permission also satisfies the API contract, but installation tokens are
short-lived. Mint and refresh those outside this workflow before exposing the
result as `PEOPLE_CONNECTOR_DISPATCH_TOKEN`; never store an expired installation
token as though it were a durable secret.

The workflow retains `contents: read` for its ordinary `GITHUB_TOKEN`. The
cross-repository token is available only to the dispatch step, is not written
to the payload, and is never printed. A missing token or rejected API request
fails the platform workflow visibly rather than silently disabling drift
coverage.

## Provision and rotate

1. Install the connector receiver on its default branch.
2. Create the narrowly scoped credential and record its owner and expiry in the
   organization's credential inventory.
3. Store or replace it as the `PEOPLE_CONNECTOR_DISPATCH_TOKEN` Actions secret
   in `BelimbingApp/belimbing`.
4. Merge the sender change, or re-run the latest platform `tests` workflow on
   `main` after a rotation.
5. Verify the platform `notify-people-connector` job succeeded and that the
   linked connector run reports the same `platform_sha`.
6. Revoke the superseded credential only after that end-to-end proof passes.

If the dispatch job fails, restore or rotate the dedicated credential and
re-run the failed platform workflow. Do not substitute a maintainer's broad
merge token, weaken receiver validation, or mark the job optional.
