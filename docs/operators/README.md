# Operator handbook

Use this index to install, run, recover or update Belimbing and its composed Domains. It includes platform runbooks, CI procedures, and installation, deployment, diagnostic and security guides. Developer implementation recipes, plans, reports and agent logs are outside this operational inventory.

Each local destination appears once. Inclusion does not certify every historical command against the current release. Both existing quickstart documents are listed. Origins link introducing PRs where available; older direct commits are identified explicitly.

## Platform pages

| Page | When to use it | Origin |
|---|---|---|
| [AI Team adopter rules](../ai-team-adopter.md) | Run author lanes under this repository's installed gate rules. | [#583](https://github.com/BelimbingApp/belimbing/pull/583) |
| [Module composition](../architecture/module-system.md) | Identify ownership and interpret route, table and provider-order refusals. | [commit d351578c](https://github.com/BelimbingApp/belimbing/commit/d351578ced0fda4c24cc6a164ea83e8b6dea4e2b); refusal documentation [#602](https://github.com/BelimbingApp/belimbing/pull/602) |
| [Composed-application recovery](../ci/composed-app-runbook.md) | A Domain pin advance makes boot or migration preflight fail; find the message and which pin to correct. | [#643](https://github.com/BelimbingApp/belimbing/pull/643) |
| [Advance Domain CI pins](../ci/domain-pins.md) | Select and prove compatible immutable dependency and caller revisions. | [#595](https://github.com/BelimbingApp/belimbing/pull/595) |
| [Adopter fork updates](../guides/adopter-fork.md) | Operate a fork's stable branch and understand its upstream update lanes. | [#483](https://github.com/BelimbingApp/belimbing/pull/483) |
| [Migration command examples](../guides/artisan-commands.md) | Scope migration commands while retaining global dependency preflight. | [commit 3294ae5e](https://github.com/BelimbingApp/belimbing/commit/3294ae5e5d337b1d42164a36b5770ac0f7bd549e) |
| [Private Extension repositories](../guides/extensions/private-extension-repositories.md) | Keep deployment-owned code private while composing it with the platform. | [commit c1e98ed0](https://github.com/BelimbingApp/belimbing/commit/c1e98ed0844925f49ac8950f17fc1372b3a188f7) |
| [Installation quickstart, nested path](../guides/installation/quickstart.md) | Follow the installation-directory copy of native/Docker setup from an existing link. | [commit 7654c48f](https://github.com/BelimbingApp/belimbing/commit/7654c48fee45278374a4a02d3529d1266c36c15a) |
| [Native Windows installation](../guides/installation/windows.md) | Prepare a native Windows instance; WSL follows the Linux setup path. | [commit 3cb0cd0f](https://github.com/BelimbingApp/belimbing/commit/3cb0cd0ff9df3d7ae9bb6a81f2f0705281589812) |
| [PDF runtime prerequisites](../guides/pdf-rendering.md) | Prepare or diagnose Chromium and PDF tools for document-producing Modules. | [commit b951ba8b](https://github.com/BelimbingApp/belimbing/commit/b951ba8b5b4e50392b19f4bf768d456c8071ca7b) |
| [Quickstart](../guides/quickstart.md) | Choose native or Docker installation and start a new environment. | [commit 7654c48f](https://github.com/BelimbingApp/belimbing/commit/7654c48fee45278374a4a02d3529d1266c36c15a) |
| [Troubleshooting](../guides/troubleshooting.md) | Investigate installation, service or connectivity failures. | [commit 7654c48f](https://github.com/BelimbingApp/belimbing/commit/7654c48fee45278374a4a02d3529d1266c36c15a) |
| [Trusted local HTTPS](../guides/trusted-local-https.md) | Repair local browser certificate trust warnings. | [commit 66c2a17c](https://github.com/BelimbingApp/belimbing/commit/66c2a17cef35922e8b1d916a883857b66b5e0260) |
| [Visual installation guide](../guides/visual-guide.md) | Compare installation paths using setup flow diagrams. | [commit 7654c48f](https://github.com/BelimbingApp/belimbing/commit/7654c48fee45278374a4a02d3529d1266c36c15a) |
| [Data Share](../runbooks/data-share.md) | Publish, inspect, plan and apply a reviewed one-way transfer between instances. | [commit ee5c5607](https://github.com/BelimbingApp/belimbing/commit/ee5c5607bf336fdd07503635265fb2ca8853994a) |
| [Database backup and restore](../runbooks/database-backup.md) | Choose encryption, run restore drills, rotate keys or handle suspected compromise. | [commit 2b346d95](https://github.com/BelimbingApp/belimbing/commit/2b346d95a3103955ddc44ba528736f9060048dbf) |
| [Development Table Mirror](../runbooks/development-table-mirror.md) | Onboard a provider or hand off selected complete tables between development databases. | [commit cc59f142](https://github.com/BelimbingApp/belimbing/commit/cc59f1424a64f74b5130bff3c9e50c7154c80874) |
| [Payroll source extraction](../runbooks/payroll-plugin-extraction.md) | Consult the unexecuted procedure only after the slot decision and repository are approved. | [commit cf66abc0](https://github.com/BelimbingApp/belimbing/commit/cf66abc0542387c915098686f1e2b1be6963d394) |
| [PeopleConnector CI dispatch](../runbooks/people-connector-ci-dispatch.md) | Trace drift notifications and the exact platform revision tested by the receiver. | [#552](https://github.com/BelimbingApp/belimbing/pull/552) |
| [Performance instrumentation](../runbooks/performance.md) | Inspect request, queue-job and command timings and slow-query evidence. | [commit 152b9a50](https://github.com/BelimbingApp/belimbing/commit/152b9a5089b08e579e9b5b52e3f54194ee46517d) |
| [Windows supervised runtime](../runbooks/windows-runtime.md) | Start, verify, deploy or recover a configured native Windows staging/production instance. | [commit 260665b6](https://github.com/BelimbingApp/belimbing/commit/260665b690c14753e7ff17ad69631775836eee00) |
| [Dependency advisory exceptions](../security-advisories.md) | Check the recorded advisory policy and accepted exceptions when dependency audit fails. | [#176](https://github.com/BelimbingApp/belimbing/pull/176) |

## Domain-owned page

| Page | When to use it | Origin |
|---|---|---|
| [Connector reconciliation runbook](https://github.com/BelimbingApp/blb-people-connector/blob/f13758f8827600b35aa49ba8c18ae17721167201/docs/operators/reconciliation-runbook.md) | Distinguish resolving with a note from retry, remap, merge and privacy actions. This link pins the introduced document; use the version matching the installed Connector for current behavior. | [Connector #189](https://github.com/BelimbingApp/blb-people-connector/pull/189) |

## Requested surfaces still awaiting delivery

The [rate-limit playbook PR #625](https://github.com/BelimbingApp/belimbing/pull/625) is still open: the existing adopter page above does not yet contain that addition on main. The [operator audit viewer issue #650](https://github.com/BelimbingApp/belimbing/issues/650) is also open with no delivered page to index. Check their merged status before treating either as an installed operator surface.
