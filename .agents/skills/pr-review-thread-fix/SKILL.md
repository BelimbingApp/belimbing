---
name: pr-review-thread-fix
description: >
  Use this skill when the user wants unresolved GitHub PR review comments fixed,
  Copilot/review-agent feedback addressed, or the latest open PR cleaned up
  after automated review. Activate even if they do not explicitly mention
  review threads, GitHub CLI, or a PR number, and instead say things like "fix
  the latest PR comments," "handle Copilot feedback," or "resolve the review
  notes."
---

# PR Review Thread Fixer

Resolve unresolved review-agent comments on the target pull request end-to-end:
identify the PR, fetch review comments plus thread IDs, triage validity, fix
the branch, validate, resolve the threads, and push to the PR's real head
branch. Do it in one pass unless a blocking ambiguity remains.

## Gotchas

- `gh pr view --json comments,reviews` is insufficient on its own. Use GraphQL
  `reviewThreads` to get thread IDs for resolution.
- The PR head repository may be a fork. Pushing to upstream does not update the
  PR unless the PR head repo is upstream.
- BLB validation may expose unrelated existing failures. Fix regressions you
  introduced; report unrelated failures instead of hiding them.

## Target PR Resolution

Prefer this order:

1. If the user gives a PR number, use it.
2. Otherwise, if there is exactly one open PR, use that PR.
3. Otherwise, choose the most recently updated open PR only if the user clearly
   asked for the "latest open PR".
4. If multiple open PRs remain plausible, ask the user which PR to use.

Helpful commands:

```bash
# Explicit PR
gh pr view 31 -R BelimbingApp/belimbing

# Latest open PR in repo
gh pr list -R BelimbingApp/belimbing --state open --limit 1 --json number,title,updatedAt

# If you need to see whether there is only one open PR
gh pr list -R BelimbingApp/belimbing --state open --limit 20 --json number,title,updatedAt
```

## Workflow Checklist

- [ ] Resolve the target PR
- [ ] Check out the PR branch and capture the head repo owner
- [ ] Fetch unresolved review comments and thread IDs
- [ ] Triage each unresolved thread
- [ ] Read only the local context needed for the touched files
- [ ] Apply one coherent batch of fixes
- [ ] Validate with focused tests, then repo validation
- [ ] Commit and push to the PR head branch
- [ ] Resolve review threads
- [ ] Report results

## Step 1: Inspect repo and check out the PR branch

```bash
git --no-pager status --short
gh pr view <PR> -R BelimbingApp/belimbing --json number,title,headRefName,baseRefName,headRefOid,headRepositoryOwner,url
gh pr checkout <PR> -R BelimbingApp/belimbing
git branch --show-current
```

Rules:

- Do **not** disturb unrelated local changes.
- Work on the checked-out PR branch, not on `main`.
- Record the PR head repository owner early. That determines where the final
  push must go.

## Step 2: Fetch unresolved review comments and thread IDs

Use both REST and GraphQL:

```bash
# Line comments / review comments
gh api repos/BelimbingApp/belimbing/pulls/<PR>/comments?per_page=100

# Review threads with thread IDs
gh api graphql -f query='
query {
  repository(owner: "BelimbingApp", name: "belimbing") {
    pullRequest(number: <PR>) {
      reviewThreads(first: 100) {
        nodes {
          id
          isResolved
          isOutdated
          path
          line
          originalLine
          comments(first: 100) {
            nodes {
              databaseId
              body
              url
              author { login }
            }
          }
        }
      }
    }
  }
}'
```

Capture for each unresolved thread:

- thread ID
- file path
- line number
- comment body
- comment database ID (if useful for cross-reference)

## Step 3: Triage each comment before editing

For every unresolved thread, classify it as:

- **Valid** — fix it
- **Needs judgement but worth fixing** — prefer the structural fix
- **False positive / not fixing** — only if the comment is clearly wrong or
  would push the code away from BLB principles

BLB triage guidance:

- Prefer structural fixes over tactical patches.
- Preserve behavior unless the review comment identifies an actual bug.
- Fix tightly coupled issues discovered while touching the same code.
- Avoid "success-shaped" fallbacks that hide write or runtime failures.
- For accessibility comments in Blade, prefer semantic fixes such as
  `aria-hidden="true"` for decorative glyphs.

## Step 4: Read enough context before changing code

Read only what the fix needs:

- touched file
- nearest `AGENTS.md`
- contract-defining support code
- relevant tests or nearby examples

Typical pairings:

- model + migration for nullability questions
- Actor/authz DTOs for identity attribution
- existing tool tests for result and error conventions

## Step 5: Implement fixes in one coherent batch

Common fixes in this workflow:

- null-safe metadata reads (`data_get()` is often the cleanest option)
- moving cleanup into `try/finally`
- returning `ToolResult::error(...)` instead of pretending writes succeeded
- aligning PHPDoc with actual schema nullability
- fixing principal attribution so agent actions use employee/agent identity,
  not a human user ID
- adding small, durable regression tests for the corrected behavior

## Step 6: Validate

Use a validation loop:

```bash
# Focused tests first when iterating

vendor/bin/pint --dirty
php artisan test --stop-on-failure
npm run build
```

If the full suite fails:

- determine whether the failure is caused by your change or is pre-existing
- fix your failures before proceeding
- if one unrelated pre-existing failure remains, note it explicitly in the
  final summary instead of pretending validation was fully green

Do not resolve review threads until the touched area is validated.

## Step 7: Commit and push to the PR's actual head branch

Inspect the PR head first:

```bash
gh pr view <PR> -R BelimbingApp/belimbing --json headRefName,headRefOid,headRepositoryOwner,url
```

Push to the repository that owns the PR head branch:

```bash
# Example: PR head lives in SB-Tape/belimbing
git remote add pr-head https://github.com/SB-Tape/belimbing.git  # if missing
git push pr-head HEAD:refs/heads/<headRefName>
```

Verification:

```bash
gh pr view <PR> -R BelimbingApp/belimbing --json headRefOid,headRefName,headRepositoryOwner
```

The PR is updated only when `headRefOid` matches your commit SHA.

## Step 8: Resolve review threads

Resolve each addressed thread with GraphQL:

```bash
gh api graphql -f query='
mutation {
  resolveReviewThread(input: {threadId: "THREAD_ID"}) {
    thread { id isResolved }
  }
}'
```

After resolving all threads, verify none remain unresolved:

```bash
gh api graphql -f query='
query {
  repository(owner: "BelimbingApp", name: "belimbing") {
    pullRequest(number: <PR>) {
      reviewThreads(first: 100) {
        nodes { id isResolved }
      }
    }
  }
}' | jq "[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved == false)] | length"
```

## Step 9: Report results

Use this template:

```text
Done: resolved review-agent feedback on PR #<PR>.

- Fixed <N> valid review comments and resolved <N> review threads
- Pushed commit <SHA> (`<message>`) to <owner>:<branch>
- Validation: <result summary>
- Caveats: <unrelated failure or untouched local files, if any>
```

## Final Handoff Checklist

- target PR identified correctly
- branch checked out
- unresolved review threads fetched with IDs
- each comment triaged
- valid comments fixed
- relevant tests added or updated
- `vendor/bin/pint --dirty` run
- `php artisan test --stop-on-failure` run
- `npm run build` run if applicable
- commit created
- commit pushed to the PR head branch
- review threads resolved
- results reported clearly to the user
- final summary includes any unrelated existing failures or untouched local files
