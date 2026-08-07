# software-source-repository-decomposition

Status: Proposed
Last Updated: 2026-08-07
Sources: PR #232 (BelimbingApp/belimbing, `quality/sonarqube-fix`); SonarCloud finding `php:S1448` on `app/Base/Software/Services/SoftwareSourceRepository.php`; `AGENTS.md` (Low Entropy, Deep Modules); `docs/architecture/module-system.md`
Agents: claude/claude-sonnet-5

## Problem Essence

`SoftwareSourceRepository` (692 lines) sits at 23 methods, over SonarQube's 20-method-per-class threshold (`php:S1448`, "Split it into smaller classes"). PR #232 pushed it from 20 to 23 by extracting `metadataRequestsNeedingDate`, `requestLatestCommitMetadataWithAuthRetry`, and `applyLatestCommitMetadataResponses` out of `resolveLatestCommitMetadata` to fix that method's cognitive complexity — a real improvement that traded one Sonar finding for another. The class already carries several loosely related concerns: GitHub token/credential management (`tokenFor`, `saveToken`, `testOwner`, `owners`), local source discovery and pull (`sources`, `pull`, `verifyTargets`, `localStatus`), and remote commit/metadata resolution (`queueLatestCommitRequest` through `githubGet`, 9 methods).

## Desired Outcome

The remote commit/metadata resolution concern — the exact cluster PR #232 just reorganized and gave clean internal structure — lives in its own class with a single, nameable responsibility. `SoftwareSourceRepository` drops back under the method-count threshold with headroom, its three existing consumers (`DeploymentService`, `SoftwareInventoryService`, `app/Base/Software/Inventory/InstalledSource.php`) see no change to `SoftwareSourceRepository`'s public contract, and behavior is identical (proof: existing `DeploymentUpdateTest.php` and `GitHubAccessTest.php` coverage stays green).

## Top-Level Components

- `SoftwareSourceRepository` — retained as the façade for source listing, working-tree status, and pull; delegates commit-metadata resolution to the new collaborator.
- A new class (tentatively `LatestCommitResolver`, final name TBD at implementation time) owning `queueLatestCommitRequest`, `applyLatestCommitResults`, `fetchLatestCommits`, `resolveLatestCommitMetadata`, `metadataRequestsNeedingDate`, `requestLatestCommitMetadataWithAuthRetry`, `applyLatestCommitMetadataResponses`, `requestLatestCommitMetadata`, `applyLatestCommit`, and `githubGet` — 9 of the 23 current methods, already cohesive (all reachable only from `fetchLatestCommits`'s call chain) and already using `SoftwareSourceGitReader` and a token lookup as their only real dependencies.

## Design Decisions

- **Option A — Accept the finding.** Mark `php:S1448` as an accepted/false-positive Sonar finding on the dashboard, the same treatment PR #232 already gave `SoftwareMaintenanceHealCommand`'s `php:S1142`. Zero risk, zero effort. But unlike the guard-clause-returns case, a 20+ method class mixing three concerns is not a linter false positive — it is the thing the rule is for — so this just defers real entropy.
- **Option B — Extract the commit-resolver cluster only (recommended).** Move the 9 already-cohesive remote-commit-metadata methods into a new class in `app/Base/Software/Services/`, injected with `SoftwareSourceGitReader` and a token lookup. This directly reverses the method-count regression PR #232 introduced, targets code with proven internal cohesion that was just touched (lower review risk than picking a new boundary cold), and leaves the still-sizeable token/status/pull surface for a later pass if it ever earns one.
- **Option C — Full decomposition now.** Also split token management and status/pull into their own classes in the same pass. Fixes the class more completely but touches more of the tested public surface at once, with more places for behavior to drift, for a class that is not yet flagged on those other axes.

Recommend **Option B**: wins on entropy (undoes what this PR caused, not a new speculative boundary) and Deep Modules (the extracted class has one clear job — resolve latest-commit metadata via `git ls-remote` plus GitHub API fallback with auth retry) while keeping blast radius small, per root `AGENTS.md`'s Strategic Programming guidance to invest where the boundary is already clear and cost-now is low.

## Phases

Not started — halted pending approval per `docs/plans/AGENTS.md`.
