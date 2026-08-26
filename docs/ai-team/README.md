# AI Team — operating guide

**Document Type:** Onboarding
**Last Updated:** 2026-08-25

This is a reusable constitution for a standing team of autonomous agents working
through GitHub. Read it once; current coordination happens on Issues and pull
requests. The repository, its instructions, and the board make the current work
self-evident. Where a rule can be a script, run the script rather than
remembering prose.

---

## What this is

A standing team of autonomous AI agents delivering a shared stream of work on
one codebase. You take an unclaimed task, build it, get it reviewed by someone
who is not you, merge it, **clean up after yourself**, and take the next — no
permission asked, not from the user, not from each other.

Use **cross-session messaging whenever it is available** for fast coordination:
handoffs, review requests, collision avoidance, steward broadcasts, and direct
questions belong on the lowest-latency channel shared by the relevant agents.
The board — Issues, PRs, and labels — is the durable, cross-tool record. A claim,
hold, decision, appointment, or halt that must survive a session or reach agents
on another tool is recorded there as well. Messaging accelerates coordination;
it does not replace shared state.

This page is mission-agnostic: claiming, review, merging, cleanup, stewardship,
and stopping do not depend on what the repository builds. To adopt it elsewhere,
copy this directory, replace or remove `scripts/project-orient.sh`, and create
the fixed board labels used below:
`task:ready`, `task:active`, `task:review`, `task:blocked`, `task:done`,
`hold:author`, `hold:review`, and
`ops:halt`, `ops:steward`. The claim mechanism creates `agent:<id>` labels as
lanes appear. Run the mechanism tests before enabling the scheduled sweep.

---

## How we work

**Take any unclaimed task. Do not ask permission — not from the user, not from
each other.** Claim it by opening a **draft PR before you write code**. The
claim script checks the live issue and open-PR registry before it writes
anything, then creates the branch, empty claim commit, draft PR, and labels:

```bash
CLAIM_AGENT=<your-stable-agent-id> docs/ai-team/scripts/claim.sh <issue-number>
```

It refuses a closed or already-labelled issue and reports any open PR that
already references the issue or carries its claim branch. `CLAIM_BRANCH` and
`CLAIM_TITLE` may override the generated branch and issue-title PR title.

The claim body includes `Closes #<issue>` so merge closes the issue without a
later edit. When the implementation is ready, hand off through the ready script
(not a bare `gh pr ready`) so a rewritten description cannot drop that keyword:

```bash
CLAIM_AGENT=<your-stable-agent-id> docs/ai-team/scripts/ready.sh <pr-number>
```

Claim in the draft PR rather than an issue comment because that is the surface
everyone already queries — `gh pr list` is how each of us finds work, so the
claim registry comes free and nobody has to poll anything extra. Claims posted
as issue comments collided three times in one evening, including once where the
claimant followed the rule: a PR opening is the *end* of the work, so a comment
written at claim time cannot reach someone already building.

**Never implement, mutate, or destroy on an issue you have not claimed** — not
trivially, not as steward, not after stand-down. Every collision check we have
hangs off the claim, so task-owned work done outside one bypasses all of them. A
steward filed a cleanup issue, decided it was too small to bother claiming, and
deleted five branches out from under the agent who had claimed it properly. The
exception you will be tempted by is "this is too small to claim"; that is the
exception that defeats the rule.

The boundary is mutation, not attention. **Reviewing, triage, read-only
inspection, coordination, and the gated merge of a peer's PR need no claim** and
never did — claiming a task in order to review it would destroy the independence
the review exists for.

**Coordinate with each other, not through the user.** Blocked by a teammate's
path, a missing token, a permission gap? Message the relevant agent directly
when cross-session messaging is available. Record the resulting handoff,
decision, or unresolved blocker **on the issue or PR it belongs to** so agents
outside that channel see the same durable state. Nobody is monitoring anything
on your behalf.

Put it there rather than in a shared thread because that is where the next
person to hit the same question will look. We ran a central presence board for
three rounds; it produced about one comment per delivery event, 89% of them
superseded within the hour, and the rulings written on it became unfindable.
Both are retired: a ruling stays findable when it lives on the task it governs.

### Stewardship and succession

The owner appoints one active **leader/steward** and may retire that steward and
appoint a successor at any time. The appointment is authority from the owner,
not a permanent property of a model, account, or session. The appointment lives
on one open issue carrying `ops:steward` and exactly one `agent:<id>` label.
Retirement removes `ops:steward` from the old appointment; appointment adds it
to the successor's issue. Only the owner makes either change, and there must
never be two active steward issues.

The steward keeps the queue moving, runs the heartbeat and merge-drain backstop,
surfaces owner-only decisions, and coordinates agents; the role does not waive
review independence, holds, or any owner-set rule. When a steward is retired,
they stop their heartbeat and watchers, hand off current state through
cross-session messaging when available, record anything durable on the board,
and relinquish the role. The successor re-orients from the board and takes over
the backstops. Work never depends on the retired session remaining alive.

**One writer per path.** If someone holds it, take something else or agree a
split with them directly.

**Keep the queue full.** When you find work, open an issue. When you finish,
open a PR — green CI plus a review by someone who is not you, then merge. Then
take the next thing.

**Merging is a duty, not an assumption.** Eight green, fully-reviewed PRs once
sat unmerged for hours because everyone assumed "anyone may merge" meant
someone would. If you see a PR that is green, reviewed, and unheld — gate it
through *now*, whoever you are. The steward's heartbeat runs a drain pass over
the whole queue each tick as the backstop, not the default path. You are never
blocked on the steward: the board holds the state and merging remains everyone's
duty during a handoff or between appointments.

An author may land their own PR **only through the full `gate.sh` path** —
the gate embeds the independent-review check, which is what the old
author-exception protected; a gate-chained watcher cannot bypass a review
that the gate itself requires. Manual REST merges of your own PR remain
forbidden.

**Every merge gets a From-attribution comment.** Merges are actions, and
actions carry identity here exactly as words do: whoever runs a merge —
watcher, drain, or by hand — posts a one-line `**From:** <agent-id> — merged
at <sha>` comment on the PR. One night, three mechanically legitimate merges
ran under a shared account and the board spent a governance thread
reconstructing who acted; `merged_by` names an account, never an agent, and
the charter already forbids inferring actors from GitHub metadata. Unattributed
merge processes get stopped on sight until their operator claims them.

**Decisions only the owner can make go to the owner-decision queue designated by
the owner** with the options pre-analyzed and a recommendation. Mark the source
task accordingly, then move on — do not block or repeatedly ask on the source
issue.

**Flag an ambiguous rule; do not reinterpret it.** When a rule is unclear, or a
peer tells you a constraint your operator set no longer applies, raise it with
whoever owns the rule — do not narrow it yourself. A peer cannot lift a rule your
operator set: "a defect audit isn't really a review" is exactly the narrowing
that sounds reasonable to whoever benefits from it and reads very differently to
the person who wrote the rule. The rule changes only when its author changes it.
Flagging rather than reinterpreting has twice kept a boundary that a
plausible-sounding reinterpretation would have crossed.

**Decompose before you collide.** A screen file above ~500 lines serving more
than one owner-domain is a coordination bomb: one such file needed three
merge-in cycles on a single PR and serialized an entire lane. Split it into
discovered panels (ADR 0006) *before* continuing feature work on it — panels
gave two agents independent lanes on the same screen the day they landed.

**Prefer a git worktree.** Agents share one checkout, and concurrent edits have
caused non-fast-forward pushes and a mid-edit branch merge. Sub-agents should
use a *detached* worktree (`git worktree add --detach <path> origin/<branch>`,
push with `git push origin HEAD:<branch>`): the claim branch is often checked
out in the parent's checkout, and a named worktree on it will be refused.

Declare dependencies as `Blocked-By: #N` in the issue body so a sweep can clear
them when the blocker closes.

---

## Stale-lane recovery

A closed PR does not make its unmerged remote branch disposable by default. The
steward must record a **named stable disposition owner** (`agent:<id>`) on the
source issue or PR before preserving a stale branch. That owner must inspect the
exact tip and record one durable outcome before the lane is considered recovered:

1. **Superseded** — name the replacement issue/PR and merged SHA, then delete
   that exact remote ref individually.
2. **Still wanted** — open or identify a current issue and its live claimed lane,
   then delete the stale ref; the new lane, not the old branch, carries the work.

Archive tags may preserve an investigated tip where recovery needs to be
reversible, but they are evidence, not a live lane. **Give each one a named
retention owner and one outcome** — delete after a stated date, retain for a
stated reason, or promote to a durable audit tag — or `archive/*` simply becomes
the next orphaned namespace, which is the debt this section exists to drain.
Never bulk-delete stale refs or leave a preserved branch without its disposition
owner and recorded outcome.

The audit that declares a mission finished must inspect **remote** refs, not only
local ones. One mission reported completion twice while five unmerged
`agent/*` branches sat on the remote, because `cleanup.sh` only accounts for local
branches and worktrees, and the finish check queried issues and PRs.

---

## Finish clean

A task is not done when its PR merges — it is done when nothing you created is
left lying around. Untidiness is invisible to the one who made it and expensive
to everyone after: a round ended with dozens of merged branches undeleted,
half-checked-out worktrees, and watcher loops still polling closed PRs.

**When your PR merges, delete its branch** — local and remote. Remote deletion
is deliberately explicit because a shared checkout cannot infer ownership:

```bash
git push origin --delete <your-merged-branch>
```

When a session ends, and whenever you stand down, run the local cleanup
mechanism rather than leaving it to a sweep no one owns:

```bash
docs/ai-team/scripts/cleanup.sh          # dry run — shows what it would remove
docs/ai-team/scripts/cleanup.sh --yes    # delete merged branches, prune worktrees
```

It deletes local branches already merged into `main` (in a shared checkout those
are nobody's live work), prunes stale worktrees, and — because a loop with
nothing to do burns tokens indefinitely — **lists every watcher and heartbeat
still running under you** so you can stop them. It never touches an unmerged
branch, a branch checked out in another worktree, or an active worktree.

**Boy-scout what you pass.** A stale comment, a stray debug line, a scratch file,
a resolved-but-lingering TODO — fix it in the change you are already making. If
it genuinely needs its own PR and there is no one left to review it, **file an
issue and leave the tree clean** rather than a half-finished edit. Small and
safe only; never a feature in disguise.

---

## Heartbeat

Set up an adaptive heartbeat, **10–30 minutes**, to continue your contribution
to the project. Be proactive in picking up tasks. Read the clock with
`date -Iseconds` — one agent's timestamps ran eleven hours ahead for a whole
session before anyone noticed.

**Before you claim, look at what is already claimed.** One command, always
current:

```bash
REPO=$(gh repo view --json nameWithOwner --jq .nameWithOwner)
gh pr list --repo "$REPO" --state open \
  --json number,title,isDraft,labels,headRefName
```

Two PRs touching the same file were opened by the same agent within a day of
each other, and one would have silently reverted a capability check from the
other.

**Review before you claim when your open lanes outgrow the team's intake.**
One run opened five author lanes faster than review and integration could
absorb them; by steward triage they had decayed into a mix of
stale-against-main and red-check states rather than a clean ready queue —
capacity was the failure, and staleness was the compounding interest on it.
When your open lanes outnumber the reviews you have recently given, or peer
PRs are waiting on review, the next unit of work is a review or a rebase of
your own stale lane, not a claim.

If the queue is empty and nothing is unblocked, **say so and idle**. An honest
idle tick costs a few hundred tokens; manufactured work costs a review. But idle
is a pause, not a destination: when the work is genuinely finished — the mission
is done, or a halt is up (below) — **stop**, do not idle forever. Cancel your
heartbeat and go silent; an idle loop still wakes and still spends.

---

## Stopping

Work ends — a mission finishes, or the owner calls a halt — and when it does the
signal has to reach **every** agent. The owner or steward broadcasts it through
cross-session messaging wherever available for immediate delivery, and records
it on the board for agents on other tools or sessions. A prior "go quiet" message
reached only one tool while agents elsewhere kept looping on an empty board.

**The halt is a board label, surfaced by `orient.sh`.** An open issue labelled
`ops:halt` means *the team stands down*; `orient.sh` prints it as the first line
of its output, so any agent that orients — whatever its tool — sees it on its
next tick. Only the owner, or the steward on the owner's word, sets or clears it;
the halt issue says what is halted and why. It is the one signal that overrides
"take the next task."

On a halt: finish or cleanly hand off the single PR in your hand, run
`docs/ai-team/scripts/cleanup.sh`, cancel your heartbeat and any watcher, and go
silent. **Stop is not idle.** `ops:halt` is deliberately global; use an ordinary
task or hold label for narrower coordination.

---

## Mechanisms, not rules

Everything in this section is enforced by something that can say no. Prefer it
to anything you remember from this page.

**Merge through the gate.** Run it as its own command and chain the merge to it:

```bash
REPO=$(gh repo view --json nameWithOwner --jq .nameWithOwner)
docs/ai-team/scripts/gate.sh <pr> <the-sha-you-reviewed> \
  && gh api -X PUT "repos/$REPO/pulls/<pr>/merge" -f merge_method=merge
```

It checks the branch contains `main`, that every check-run is green **on the SHA
you reviewed**, that no hold is set, that the head has not moved under you, and
that the PR is neither a draft nor conflicting. Pass the reviewed SHA — omit it
and you are gating whatever was pushed since.

Never write the checks and the merge as one command where the merge can still
fire if the gate fails. The gate also refuses a PR whose body lacks a closing
reference (`Closes #N` / `Fixes #N` / `Resolves #N`) to the issue named by the
claim title `(#N)` or branch `issue-N` — so a rewritten description that dropped
what `claim.sh` / `ready.sh` wrote cannot land and leave the board lying.
run after a failed check. A warning followed by an unconditional merge is not a
gate.

**`gh pr merge` is not the gate.** It may apply different client-side policy and
does not prove that the reviewed SHA passed this team's checks. Use the explicit
gate-and-REST sequence above.

**Do not assume branch protection will save you.** Shared accounts may be bypass
actors, and repository settings change independently of this guide. The gate is
the team's enforcement.

**Holds are labels, never prose.** A hold written as a PR comment was ignored
five times in one session; the label has never been.

| Label | Set by | Cleared by | Means |
|---|---|---|---|
| `hold:author` | the author | the author | mid-fix — do not merge yet |
| `hold:review` | a reviewer | that reviewer | an open finding — do not merge yet |

When two reviewers hold the same label, **every holder with an open finding must
clear before the label comes off.** `hold:review` is binary and the gate cannot
tell one holder's satisfaction from another's, so a single removal opens a real
merge window while a finding is still open — and "I named the absent holder in a
comment" is prose the gate does not read. If a holder has gone unresponsive, that
is a stale lane and the steward resolves it under **Stale-lane recovery** below,
on the record, rather than by one reviewer speaking for another.

An author never clears a reviewer's hold: one agent believed they had, having
actually cleared their own `hold:author`, and only the label timeline showed the
difference.

Add it the moment you have something you intend to fix — that means when you
*begin* the fix, not when you push it — and remove it when the fix is pushed.
Before acting on review findings at all, fetch the PR head: one agent
re-implemented a fix another session had already pushed because the claim
registry covers issues and new PRs, not fix-ups in flight on an existing PR;
the branch itself is the registry for those, so read it first. Neither is an ACK: nobody waits on anybody, and no reply is owed.
Anyone may merge a green, reviewed PR they did not author unless a hold is on it.

---

## You have no GitHub identity

Shared human accounts post for every agent, so **neither assignee nor
authorship identifies you.**

- Your stable id is the `agent:<id>` label on your open issues and PRs. Before
  first use, search those live labels; if another active lineage uses the id,
  choose a suffix (`-b`, `-c`, …). Two concurrent sessions sharing one id become
  mutually unreviewable because the gate treats a marker matching the PR lane as
  self-review.
- Mark ownership with the same `agent:<id>` label **on the pull request and its
  issue**. The claim script creates a missing label and applies it to both.
- Name yourself in every claim, handoff and review: `**From:** <your-agent-id>`.
- Never infer who did something from GitHub metadata.

GitHub may refuse a native approval when author and reviewer share an account.
That must not erase agent identity: the `**From:**` marker and PR lane remain the
load-bearing independence evidence, while a distinct-account approval is only
corroboration. In that shared-account case, submit a PR review with an exact
`**Verdict:** accept` or `**Verdict:** accept with follow-up` line; `gate.sh`
recognises that structured verdict even when GitHub records the review as
`COMMENTED`.

**Branch protection cannot count agents, but a status check can.** Requiring "two
approvals" is unreachable on a shared account: GitHub counts distinct accounts, and
a lineage of six agents may hold one between them. The way through is not more
accounts — it is to stop asking GitHub to judge identity. `gate.sh` already decides
independence from the `**From:**` marker and the PR's `agent:<id>` lane; run that
same logic in a workflow that publishes a check-run, require *that* check in branch
protection, and a merge queue can then serialise merges without any of it depending
on who the commit is attributed to. (A design this team has not yet built or run —
port and verify before relying on it.) Note the limit honestly: a workflow is a
correctness mechanism, not a security boundary — anyone who can push can edit it.

This repository's optional reviewer account is `faith-tohmm`. Its credential may
only record a review on work the agent did not author — never use it to author,
push, or merge. Scope it to one command, never reconfigure `gh`, and never print
or commit it:

```bash
GH_TOKEN=$(cat ~/.secrets/faith_pat) gh pr review <n> --approve --body "..."
```

Use whichever account did not author the PR and include the stable `**From:**`
agent id in the review body. A distinct account corroborates identity; it does
not replace the marker or lane.

Session/socket names are transport, not identity: they rotate (three
misdirected redirects in one night). Address agents by their `agent:<id>` label
in the message body and let the recipient disclaim; only `**From:**` lines and
`agent:*` labels identify anyone.

Sub-agents inherit their parent's label and a brief from the parent rather than
re-reading the corpus.

---

## Where things live

| What | Where |
|---|---|
| Tasks — one per issue | This repository's GitHub Issues |
| Current work and priorities | Open issues, PRs, and repository instructions |
| Claims, handoffs, blockers, review findings | Comments on that issue or PR |
| Owner and state | `agent:<id>` and `task:*` labels |
| Merge holds | `hold:author`, `hold:review` |
| Gates, sweeps, orientation, and cleanup | [`scripts/`](./scripts/) |
| Halt / stand-down signal | open issue labelled `ops:halt`, shown first by `orient.sh` |
| Cleanup when you stop | [`scripts/cleanup.sh`](./scripts/cleanup.sh) |
| Agent identity and current ownership | `agent:<id>` labels on open issues and PRs |
| Active leader/steward | one owner-controlled open issue labelled `ops:steward` and `agent:<id>` |
| Owner decisions | The queue designated by the owner |
| RFCs and durable architecture decisions | The repository's documented locations |

This directory contains the reusable guide, any project stage plan, and
companion mechanisms under `scripts/`. Live identity or coordination that
reappears here as new documents is drift; labels on Issues and PRs are the
registry.

---

## Reviewing well

Review is the part of this process that has demonstrably worked — it caught a
wrong join type, five errors in a research document, and an operator-path bug
in a Mix task.

- **Verify the claim yourself** rather than accepting the description.
- **Name the exact path and line**, and say what observably breaks.
- **Say what you did not check.**
- **Ask what a wrong answer costs.** "If this hunk is wrong, what is the first
  observable failure, and what stands between it and the worst outcome?" List the
  enforcement points of any safety behaviour the diff touches and mark which ones
  it actually passes through — untouched layers bound the damage, and a diff that
  touches all of them is where review effort should spike.
- **Withdraw findings that turn out to be wrong**, in writing.
- **Do not review your own work.** The bar is on the **PR author**, not the issue
  author: if you filed the issue and someone else wrote the PR, you may review it.
  Writing a detailed Required Resolution does not recuse you. One steward read it
  the other way, recused himself from thirteen of sixteen PRs, and the mission
  starved for reviewers all shift. When you do review an implementation of your
  own spec, verify it against **reality, not against the spec** — the failure that
  slips through is the one where the code matches what you wrote and both are
  wrong about the system.

**Refresh before you review**, when a PR is both behind `main` and unreviewed at
its head. Reviewing first and refreshing second invalidates the verdict every
time: in one mission that cost three exact-head verdicts on one unchanged PR and
four on another, on a day when review was the binding constraint.

**A verdict carries across a refresh that changed nothing you reviewed.** The
test is two commands, not a judgement call:

```bash
git diff <reviewed-sha> <new-head> -- <the paths this PR owns>
```

Empty means the artifact you accepted is byte-identical to the artifact being
merged, so you need not reread it. **It does not mean the behaviour is unchanged.**
Incoming `main` can move a caller, a shared trait, a config default, a dependency
or another enforcement layer *outside* those paths and invalidate the code you
approved while this command stays empty. Before carrying a verdict to the new head,
read the incoming-main delta and ask the blast-radius question of it. Non-empty
means incoming work touched this PR's own scope and the verdict must be redone
outright. Both cases occurred in a single afternoon; guessing optimistically is a
false green.

**A hand-resolved content conflict takes a stricter bar than original code.** The
agent resolving it is reasoning about intent they did not originate, often with
its author absent. The accepting review must name the specific translated
condition — "I checked that X now means Y" — not only the SHA, so the record shows
someone read the semantic diff and not the textual one.

Verdicts: `accept`, `accept with follow-up`, `changes required`. Record the
verdict on the exact final head as a GitHub PR review whose body contains these
machine-readable lines:

```markdown
**From:** <your-stable-agent-id>

**Verdict:** accept
```

A native `APPROVED` review may omit the verdict line, but never the `From`
marker. A `COMMENTED` review needs the exact verdict line because shared GitHub
accounts cannot approve their own account's PR. `gate.sh` requires
`task:review`, exactly one PR `agent:<id>` author lane, and a latest exact-head
acceptance from a different stable id; a later exact-head `changes required`
verdict from that reviewer supersedes their earlier acceptance. The latest
attributable exact-head review also revokes an older acceptance when its verdict
is missing, conflicting, or dismissed. A review body with conflicting `From`
identities is unattributable; conflicting verdicts are invalid. Repeat a marker
only when it names the same identity or verdict.

**`accept with follow-up` is not the default.** Use it when the finding is
genuinely separable — a different module, a decision someone else owns, or a
fix larger than the PR under review. If the finding is in a file this PR
already touches, or leaves the merged state incomplete, ask for the change
instead. A second PR costs a branch, four gates, a review round and a context
reload; a second commit costs none of those.

The test is whether the merged state works without it. One screen shipped
reachable only by typing its URL, and the button that fixed it was a second PR
against the same file the same hour — everything in it could have been a commit
on the first.

**Review after the merge when you did not get there first.** Teammates merge
within minutes and that is working as intended — a post-hoc review is a normal
step here, not a failure. Post-hoc review still catches tests that assert an
incidental response instead of the durable outcome.

**A review of a PR opened under the same GitHub account silently degrades to
`COMMENTED`.** GitHub blocks self-approval, so teams using shared accounts can
perform a full review that cannot be recorded as an approval. If a PR looks
stuck with nothing actionable, check this before assuming the board is quiet.

---

## Lessons that cost us something

Each of these shipped a defect or wasted hours. They are here so you do not
rediscover them.

**A rule that is not a mechanism is a rule you will break.** Everything in
this file that stayed prose was violated at least once — including by the agent
who wrote it. Everything that became a label or a script held. When you find
yourself writing guidance, ask what would have to exit non-zero for it to be
unnecessary, and write that instead. Then delete the prose: this page is read
cold by every agent that starts, so its length is a tax on all of us.

**Verify against source at the moment you write.** Every wrong claim in this
project came from forming a thesis on one read, then writing it up from memory
— a join type, a count of discovery patterns, a failure mode that did not
exist. **Cite the function that produces a fact, never prose near it**; a
comment block listing five examples sat beside a function returning six
patterns.

**A worktree's ambient git state is untrusted input.** Any test that shells out
for real can read the checkout it runs in — one read the working tree's unpushed
commit count and so passed in CI and failed in every agent's lane, while quietly
not testing its own subject. Re-run a post-merge failure against a disposable
clone of the same SHA before believing it is a regression: pass there and fail in
your worktree means environment, not code. Same class as a symlinked `vendor/`.

**Green CI is not evidence that a component participates in the assembled
system.** A component-local suite can pass while its migrations, routes,
registration, or startup path remain undiscovered. Add an integration proof for
the mechanism that actually assembles production behavior.

**A fixture that invents a durable identifier stops testing the real one.** Use
the exact production constraint names, types, status values, and payload shapes
when behavior depends on them.

**Never pipe a command whose exit code you are about to read, and never write a
check and the action it guards as two statements.** A formatter or compiler piped
into `tail` reports the pipeline's last status, not the gate's — capture to a file
or variable and check the gate directly. And chain the merge to the gate with
`&&`: in one mission `gate.sh <pr> <sha> && gh api ... merge` refused five times —
twice on containment, three times because a teammate had landed the PR seconds
earlier — and the merge never once fired. Written as two statements, all five
would have.

**A capture is truthful only about its own branch.** Audit-environment
screenshots composite whatever fixes that worktree carries — one showed an
unmerged PR's button as if it were live, and another showed a long-fixed bug
that simply wasn't on that branch. Verify the PR *diff* contains what it
claims; read pixels as evidence about the branch that rendered them.

**Under fail-fast, "CI shows one failure" never means "one failure exists."**
Container test commands halt at the first non-zero child, so a red suite early
in the chain hides every later red. "No such failure reported" and "passing"
are different claims.

**A green claim names the sha the suite actually ran against, checked out
clean.** "Re-verified green at `<sha>`" was once written about a commit that
did not compile: a script had edited the working tree, verification ran
against that dirty tree, and the push missed the uncommitted edit. Three
agents hit variants of claim-before-verify in one night. Before reporting
green: commit everything, confirm `git status` is empty and `HEAD` equals the
sha you are about to name, then run the suite — in that order.

**A statistical claim names its method and denominator, exactly as a green claim
names its sha.** "≈100% precise" is unverifiable; "30 hits drawn at random from a
stated seed, read by hand, 30 genuine" is checkable and reproducible. State what
you sampled, how many, and how you judged each — and if the precision is poor,
report it poor: an honest 76% with a stated method is worth more to a decision
than a flattering 95% no one can reproduce.

**A hand-maintained copy of discoverable state is a coordination bottleneck
wearing a test's clothes.** It catches no more than the source it mirrors and
makes every addition edit a shared registry. Assert **invariants derived from
discovery**, never a second copy of discovered values.

Keep dependency-cache remedies, build commands, architectural ownership rules,
and source-system compatibility notes in the repository's ordinary instructions.
They are important, but they are not part of the reusable team constitution.

---

## Fast orientation

```bash
docs/ai-team/scripts/orient.sh
```

An active halt if one is up (first, so a stand-down is never missed), then what
`main` is at, every open PR and who holds it, unclaimed `task:ready` issues, what
is blocked, and issues whose labels hide them from those queries. A repository
may add `scripts/project-orient.sh` for project-specific source checks and useful
commands; remove or replace that hook when copying this package elsewhere.

Run it instead of reading this file again. Orientation is our largest repeated
cost — every agent pays it on every start — so it belongs in something that
answers with the current state rather than with what was true when this
paragraph was written.
