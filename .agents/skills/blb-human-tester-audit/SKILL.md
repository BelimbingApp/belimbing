---
name: blb-human-tester-audit
description: >
  Tests a specified BLB page, or every page and meaningful interactive state
  reachable from a specified menu, including workflows that require switching
  between multiple user roles, as a skeptical human user. Checks DESIGN.md
  fit, task flow quality, feedback, accessibility, responsiveness, data safety,
  failure recovery, and finite control/branch permutations, then produces a
  screenshot-backed HTML audit with reproducible issue records. Use when the
  user asks to test, QA, audit, inspect, walk through, or find UX and UI
  problems in a BLB page, menu, module, section, or its sub-pages.
---

# BLB Human Tester Audit

Run a bounded functional and UI/UX audit through a target page or menu tree.
The output is an evidence-backed audit, not a code review and not a claim that
the application is defect-free. This is a generalist human-product audit, not a
specialist security, penetration, load, or accessibility certification.

## Load before testing

- `DESIGN.md` — the governing product and visual contract.
- `references/RUBRIC.md` — issue categories, severity, confidence, and evidence
  rules.

Honor the repository `AGENTS.md` instructions already supplied by the runtime;
read the root file only when they are not already in context. Read a target
module's local `AGENTS.md` only when it contains user-facing behavior, test-data
rules, or module-specific safety guidance. Do not load
`resources/core/views/AGENTS.md`, module-placement documentation, route files,
or feature tests by default. Inspect them only to resolve an ambiguity that the
visible UI cannot answer. Treat the UI and a normal user's goal as the primary
test surface; do not turn the audit into a source-code checklist.

## Inputs and output

Ask for missing information only when a safe assumption would change the
scope. Otherwise use these defaults:

- Target: a user-specified single page, or a named menu item and all pages,
  tabs, drawers, dialogs, and meaningful states reachable from it. If the user
  does not specify a target, ask for one before opening the site or testing;
  never assume the whole application, dashboard, or current browser page is in
  scope.
- Roles: the current authenticated test user plus every additional actor needed
  by the workflow; record role aliases, session switches, and coverage in the
  report.
- Viewports: desktop `1440x900` and narrow `390x844`, unless the user gives a
  different supported range.
- Data: existing local/dev data; create only reversible test data and never
  delete or mutate real records without explicit authorization.
- Tester identity: the exact active `{provider}/{model}-{effort}`, for example
  `codex/sol-high`. Use runtime-provided identity when available. Never infer a
  more capable model or effort than the active run. If any component is not
  exposed, use `unknown` for that component, for example
  `codex/unknown-unknown`, rather than guessing.
- Output: `storage/app/qa/human-tester-audit/<target-path>/<run-stamp>/` with
  `<target>-<run-stamp>-<provider>-<model>-<effort>.html`, `manifest.json`, and
  `screenshots/`. Display the unsanitized identity in the report as `Tested by`;
  replace `/` and other filename-unsafe characters with `-` in the filename.
  Mirror the visible menu or page hierarchy in `target-path` using safe
  lowercase kebab-case segments, for example `people/leave-requests`; never put
  emails, record IDs, or other sensitive values in the path. Format
  `<run-stamp>` as local time `YYYYMMDD-HHmm`; append a numeric suffix only if
  two runs collide within the same minute.

## Browser selection and recovery

Prefer the in-app Browser because it preserves the visible session and supports
human login handoffs. Do not confuse the absence of a tool literally named
`Browser` with Browser being unavailable. In Codex Desktop, control is commonly
exposed through the Browser skill and its JavaScript runtime.

1. If the Browser control skill is listed for the session, read and follow it
   before declaring the in-app Browser unavailable. Attempt its documented
   setup, browser selection, and troubleshooting. An actual connection or
   discovery error is evidence of unavailability; scanning visible tool names
   is not.
2. If the in-app Browser is genuinely unavailable or still fails after the
   documented recovery attempt, continue automatically with standalone
   Playwright or another capable browser driver. Do not ask the user to choose
   between available drivers. If the user explicitly required the in-app
   Browser, respect that constraint and pause instead of falling back.
3. Apply the same workflow, role, permutation, viewport, and screenshot
   requirements to every driver. A fallback driver does not make coverage
   partial; tested, partial, and blocked statuses describe executed coverage,
   not tooling preference.
4. Record the exact selected driver in `meta.browser_tool`. When using a
   fallback, also record `meta.browser_fallback_reason`, including the concrete
   setup or connection failure. Do not claim Browser was unavailable without
   this evidence.

Never replace browser interaction with direct HTTP crawling, DOM-only URL
censuses, or guessed routes. Preserve the existing signed-in session when the
selected driver supports it. Never inspect or expose cookies, storage,
passwords, or secrets. For local BLB login, follow the repository `AGENTS.md`
policy and keep credentials out of all evidence and output.

## Environment and mutation policy

Classify the environment before any action that could change data. Read
`APP_ENV` from `.env` and corroborate it with the target URL. Treat only an
explicitly identified local/development/testing environment as development
scope. Treat `production`, an external production-looking URL, and any
ambiguous environment as read-only until the user explicitly clarifies scope.

- **Development:** create clearly marked test users and test records when they
  are needed to complete the workflow. Use unique test prefixes, record the
  aliases/IDs in the manifest, and clean up generated data when safe and when
  cleanup will not erase useful evidence.
- **Production:** do not create users or records and do not submit mutations:
  no create, edit-save, approve, reject, cancel, delete, status transition,
  upload, or settings change. Inspect screens, open dialogs, select options,
  and fill without submitting only when doing so is safe. Use only authorized
  existing accounts and report workflow branches that could not be executed.
- **Unknown or mixed:** pause before mutation and ask the user to confirm the
  environment and permitted test-data scope.

Never use a production account or real person as a disposable test actor.


## Launch and authenticate before testing

Do this before writing the inventory or exercising any protected page:

1. Determine the base URL from the user's request or, for local BLB, the
   repository `.env` `APP_URL`. Open the base site with the selected browser
   driver, reusing the current tab only when its URL is the same target. Prefer
   beginning at the login screen and first dashboard screen; navigate to the
   requested page or menu through the normal UI after authentication. Use a
   direct target URL only when the user explicitly supplied it or normal
   navigation cannot reach the requested single-page target.
2. Verify the session from visible UI state. A login form is unauthenticated;
   a dashboard or the requested protected surface is authenticated. Do not
   treat a successful navigation alone as proof of login.
3. If the local test credential file exists and contains the expected values,
   use it once for the local login. Read
   `C:\Users\tohmm\.codex\secrets\blb-ai-agent-login.env` only as allowed by
   the repository `AGENTS.md`; never print, screenshot, store, or put its
   values in the manifest. Do not guess credentials or try external accounts.
4. If credentials are missing, unreadable, incomplete, rejected, or the page
   requires CAPTCHA, OTP, MFA, approval, or another human-only step, pause
   immediately. Ask the human to log in in the available visible browser
   session and tell you when it is ready. Keep the same session and do not
   continue to inventory or test protected pages until the human says it is
   ready. If the selected fallback cannot present a session the human can use,
   explain that concrete authentication blocker rather than asking the user to
   choose a different driver.
5. After the human says it is ready, take a fresh DOM snapshot, verify a
   dashboard or the requested protected surface is visible, and only then
   continue. Record the authentication state and any pause/block in the report
   without recording secrets.

## Multi-role workflow execution

Before page-by-page exploration, identify whether the target contains a
workflow that crosses actors. Read visible copy, available actions, and nearby
feature documentation to build a role graph, for example:

`employee submits leave → supervisor approves/rejects → employee sees outcome`

For each role:

1. Obtain an authorized existing account, or create a clearly marked test user
   in development. Give every actor a short alias such as `employee-A` and
   `supervisor-A`; do not put real email addresses or passwords in the report.
2. Use isolated sessions where the browser supports them. Otherwise log out and
   switch deliberately, then verify the visible identity and dashboard before
   continuing. Never assume a role switch succeeded, and never let one actor's
   open form or cached page stand in for another actor's view.
3. Start each actor from login/dashboard when practical, follow the normal UI,
   and capture the handoff state before switching roles.
4. After every actor action, verify the next actor can see the expected task,
   notification, status, data scope, or denial. Verify that an actor cannot
   perform actions outside its role.
5. Execute every meaningful terminal branch exposed by the workflow: approve,
   reject, cancel, return-for-changes, save-draft, resubmit, reopen, and any
   equivalent action. For each branch, verify both the acting role's result and
   the other role's resulting state.

Maintain a workflow matrix with at least: path ID, actor sequence, starting
state, action/branch, expected handoff, observed handoff, final state, and
status (`tested`, `partially-tested`, or `blocked`). Do not mark a workflow
covered merely because its first actor completed a form.

Record a top-level workflow assessment even when no cross-role workflow exists.
Set `workflow_assessment.applicable` to `false` only after inspecting the target
and explain why in `rationale`. If it is applicable, the workflow matrix and all
required role sessions must be present; an empty matrix is an incomplete audit.

## Test workflow

### 1. Establish the test contract

Write a one-paragraph scope before browsing: target page or menu, target type,
role, base URL, viewport(s), data assumptions, excluded actions, and the user
outcome the target should support. Identify the likely primary job in plain
language.

### 2. Inventory from the UI

For a single-page target, record that page as the only in-scope page and include
its meaningful tabs, drawers, dialogs, and states. For a menu target, start at
the menu, expand it if needed, and record every reachable destination. Include
nested sub-pages, table/detail links, tabs that change the working surface, and
dialogs/drawers that contain a real task. Exclude duplicate links and utilities
that do not change the page or state, but record why they were excluded. Give
each page a stable ID such as `people.index` or `people.show`.

Do not rely on guessed URLs to declare coverage. A route list may supplement
the inventory, but menu navigation and visible links establish reachability.

### 3. Exercise a human task on every page

For each page, first take a clean baseline screenshot and note page title,
location, role, visible primary action, and any loading/error state. Then do
the smallest realistic task that gives the page a reason to exist:

- index/list: orient, scan, search/filter/sort, open a record, and recover from
  an empty or no-result state when available;
- detail: understand identity/status, use the primary next action, inspect
  related information, and return without losing context;
- create/edit: enter valid data, trigger required-field validation, correct a
  recoverable error, save, and verify the persisted outcome;
- destructive/status action: inspect confirmation, cancel safely, execute only
  with safe test data, and verify the resulting state;
- settings/configuration: change one reversible setting and verify where the
  effect is visible.

Use the UI as a person would: read labels, follow visual hierarchy, use the
obvious path, and note every moment that requires guessing, backtracking,
remembering hidden state, or interpreting internal jargon. Do not invent a
task merely to touch a control.

For every finite dropdown, radio group, checkbox group, tab set, filter, and
status selector, exercise each meaningful option at least once where the
environment policy permits. Check dependent fields and the first/last/boundary
values. For multi-field forms, cover each validation branch and meaningful
cross-field dependency; use a documented decision matrix when the full
Cartesian product would be unbounded. Record combinations that remain
untested instead of implying exhaustive coverage.

Do not equate opening a URL or taking a baseline screenshot with testing a
page. A page may be marked `tested` only after completing and verifying its
primary task, recording the interaction steps, states/controls exercised, the
observed outcome, and screenshot evidence. If no safe interaction can be
completed, mark it `partially-tested` or `blocked` with the reason. The phrase
“no safe interaction found” is evidence of incomplete coverage, never a tested
status.

### 4. Probe the quality dimensions

After the primary task, check the page and its nearby states for:

- **Design fit:** semantic colors, Instrument Sans, compact-but-breathable
  density, hierarchy, grouping, consistency, reuse of established patterns,
  truthful copy, and calm visual tone from `DESIGN.md`.
- **Navigation and IA:** clear current location, sensible menu/sub-page
  grouping, predictable back/return behavior, preserved filters or context,
  and no dead ends or duplicate concepts.
- **Feedback and state:** visible loading/waiting/blocked/success/error
  feedback, disabled/busy controls while work is in flight, honest outcomes,
  and no silent failure or stale data after a mutation.
- **Accessibility:** keyboard reachability and focus order, visible focus,
  usable labels, dialog escape/cancel behavior, error association, contrast,
  status announcements where relevant, and controls that do not depend on
  color, hover, or icon-only interpretation.
- **Responsive behavior:** narrow viewport layout, horizontal overflow,
  clipped controls, table strategy, touch target practicality, and whether the
  primary task still has a clear path.
- **Validation and recovery:** boundary/empty/no-result states, invalid input,
  duplicate submission, expired/stale context, permission denial, network or
  server failure when safely simulatable, and recovery without data loss.
- **Data and safety:** correct record identity, scope/role visibility,
  confirmation before irreversible actions, no accidental destructive default,
  and no sensitive data leakage in UI or report screenshots.
- **Performance perception:** obvious unnecessary waiting, layout shifts,
  blocked interaction, or repeated work that a human would reasonably notice.

Use these dimensions to find issues, not to manufacture one finding per
dimension. Record a finding only when there is a concrete user impact or a
clear contract/design violation.

### 5. Capture and reproduce findings

For every suspected issue:

1. Finish the current interaction or return to a safe state.
2. Reproduce it once from a known starting state.
3. Capture the smallest useful screenshot(s): context before, failure/state,
   and outcome when the comparison matters. Use descriptive filenames such as
   `people-index-no-results.png`.
4. Record exact steps, expected result, actual result, affected page/state,
   severity, confidence, category, and the relevant DESIGN.md principle.
5. Distinguish observed facts from hypotheses. If a behavior cannot be
   reproduced, mark it as an observation with lower confidence rather than a
   confirmed defect.

Do not include credentials, tokens, private customer data, or broad full-page
captures when a tighter crop proves the issue. If evidence cannot be safely
captured, describe the observable state and omit the screenshot.

## Issue judgment

Use `references/RUBRIC.md` for severity and confidence. Prefer a few deep,
actionable findings over a noisy list of stylistic preferences. Consolidate
duplicates across pages when the same shared component causes the same impact,
but list all affected page IDs in the finding.

Before finalizing, check that each high-severity finding has a reproducible
path and evidence, and that every inventory item is marked `tested`,
`partially-tested`, or `blocked` with a reason.

Zero findings is possible but exceptional for a non-trivial menu audit. When it
occurs, record a concrete `meta.no_findings_rationale` describing the tasks,
states, branches, roles, and viewports exercised. Never use an empty findings
array to mean that the target was only browsed.

## Completion gate

Do not optimize this audit for a short runtime. A multi-page, multi-role target
normally takes substantially longer than a page census and may span many agent
turns. Continue until the declared permutations are exercised or honestly
marked partial/blocked.

Before generating the final report, verify all of the following:

- the manifest identifies the tester and actual browser driver, and records a
  concrete fallback reason when the preferred in-app Browser was not used;
- every tested page has task steps, tested states/controls, an observed outcome,
  and existing screenshot evidence;
- every partial or blocked page explains the missing coverage;
- workflow applicability is explicitly assessed;
- every applicable branch has starting state, actor sequence, expected and
  observed handoff, final state, status, and evidence;
- every required role has a verified session entry;
- each confirmed finding has reproducible steps and screenshot evidence;
- a zero-finding report contains the required detailed rationale.

`build_report.py` enforces these gates. Do not weaken, bypass, or manually
replace the generator when validation fails; finish the audit or report the run
as incomplete to the user.

## Build the HTML report

Initialize the run directory with the bundled scaffold. It creates the
hierarchical output path, `screenshots/`, and a manifest skeleton; it also
reads `.env` defaults when the URL/environment are not supplied:

```powershell
python .agents/skills/blb-human-tester-audit/scripts/init_audit.py `
  --target "Leave requests" `
  --target-path people/leave-requests `
  --tester-identity "codex/sol-high" `
  --browser-tool "in-app Browser"
```

Maintain the generated JSON manifest while testing. Use the bundled report
generator rather than hand-authoring HTML or CSS:

```powershell
python .agents/skills/blb-human-tester-audit/scripts/build_report.py `
  --manifest storage/app/qa/human-tester-audit/people/leave-requests/<run-stamp>/manifest.json
```

The HTML/CSS layout is a prebuilt skill asset in `build_report.py`; agents
should supply structured audit data and screenshots, not reinvent the report
markup. The script derives the identity-bearing report filename, validates the
completion gate, and embeds page, workflow, and finding screenshots as data
URIs, so the generated HTML remains portable when copied away from the
repository. The manifest shape and an example are in
`references/MANIFEST.md`. Validate the generated report by opening it locally,
checking that screenshots load, issue anchors work, and blocked/partial pages
are visible. Mention the report path, tester identity, coverage summary, and
any blocked scope in the final response.

## Stop conditions

Stop and ask before performing irreversible, externally visible, or
production-affecting actions; before testing a role or account not authorized
by the user; or when required test data cannot be obtained safely. If protected
authentication is unavailable, pause and ask the human to log in, then verify
the authenticated state before continuing; do not silently downgrade the audit
to unauthenticated coverage. Stop for browser tooling only when no capable
driver remains, when the user explicitly required a failed driver, or when a
human-only authentication step cannot be presented safely.
