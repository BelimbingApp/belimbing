# Running Belimbing from an adopter fork

**Document type:** operator guide
**Last updated:** 2026-09-02
**Related:** Administration → System → Software → Updates, `docs/architecture/database.md`

The framework's canonical branch is `main` on `BelimbingApp/belimbing`. An
adopter deployment commonly runs from a fork whose stable branch is `master`
on its own remote (`origin`), with the framework configured as the `upstream`
remote. That is the supported shape — nothing about it needs renaming.

## The three lanes

The Updates page shows a fork's platform source as three lanes, each
answering one question with one action:

| Lane | Compares | Question | Action |
|------|----------|----------|--------|
| **Checkout** | `master ↔ origin/master` | Is this working copy current? | Pull happens as part of Update; unpushed commits block Update until pushed or reconciled. |
| **Fork stable** | `origin/master ↔ upstream/main` | Has stable integrated every vetted upstream commit? | **Create integration proposal** — an object-database merge pushed as `upstream-sync-<sha>`; a person opens and reviews the PR. |
| **Deploy** | runtime ← `origin/master` | Has this environment pulled, migrated, and reloaded since sources changed? | **Update** / **Update all**. |

The relationship indicator reads like GitHub's branch comparison: **behind
(amber, pointing down) is the only direction that ever demands work; ahead
(blue, pointing up) is informational**. A fork that is *ahead of upstream* by
its own commits is in its normal state — deployment-specific work living on
`master` is expected, not divergence. Only *behind upstream* calls for an
integration proposal.

An origin authentication failure greys only the two lanes that read origin;
the upstream head is probed with its own credentials and stays visible.

## Three workflows, and when to use each

**Day-to-day deploy.** Someone merged to `origin/master`; this environment
needs it. Use **Update** on the Deploy lane (or **Update all**): it pulls,
migrates, and reloads workers. Nothing touches upstream.

**Upstream integration (guarded).** The Fork stable lane shows upstream
updates to integrate. **Create integration proposal** builds
`master + <pinned upstream commit>` entirely in the object database — the
running checkout is never modified — and pushes a uniquely named
`upstream-sync-<sha>` branch. A person opens the pull request into `master`,
reviews, runs UAT, and merges in GitHub. The page never pushes to stable
directly, and a conflicting merge is refused with the conflicting files named
rather than half-applied.

**Local development integration.** An authorized developer may still run
`git fetch upstream && git merge upstream/main` on a local `master` and push
`origin/master` under the repository's own branch policy. This is deliberately
outside the guarded page flow; the lanes will simply report the result.

## Reading the page after each workflow

- After a teammate merges the integration PR: Fork stable returns to
  "Has every upstream update"; Checkout and Deploy go behind until this
  environment runs Update.
- After a local merge pushed to origin: same as above — the lanes do not care
  which path moved stable.
- Unpushed local commits on the deployment checkout always block Update:
  push or reconcile them first, or the update pull would have to merge into a
  moving target.
