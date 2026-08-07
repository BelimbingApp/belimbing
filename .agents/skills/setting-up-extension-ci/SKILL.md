---
name: setting-up-extension-ci
description: Audits and safely installs or repairs owner-controlled CI for a Belimbing Extension. Use when an Extension owner asks to set up, verify, or improve Extension CI or platform conformance.
---

# Setting Up Extension CI

Audit first, propose the smallest honest baseline, and edit only after approval.

## Workflow

1. Read repository guidance and inventory existing workflows, Module manifests, tests, migrations, owned assets, and Extension-owned package manifests and lockfiles.
2. Run `scripts/ci/extension-conformance.sh <canonical-extension-path>` from a compatible composed Belimbing checkout. Diagnose failures before proposing edits.
3. Explain the checks already present, missing checks, files that would change, and any hosted-service or credential requirements. Preserve stricter useful jobs.
4. After explicit approval, add or narrowly repair a thin workflow pinned to an immutable platform commit. Prefer `.github/workflows/extension-conformance.yml` as the public composed entrypoint.
5. Re-run local conformance and syntax checks. Report the exact Extension and platform revisions tested and remaining owner actions.

## Minimum profile

- PHP syntax and authorable Pint check mode; never reformat immutable migrations.
- Module manifest and platform-compatibility validation.
- Composed Extension tests and owned asset build when assets exist.
- Gitleaks with redacted output.
- Migration proof when migration source changes.
- Dependency audit only for manifests and lockfiles owned by the Extension.
- Larastan only after the platform adopts it. Sonar and other hosted services stay optional.

## Safety boundaries

- Never call a result “certified” or imply platform attestation. State only that a named revision passed a named conformance contract in the owner's environment.
- Never use platform lockfile audits as evidence about Extension-owned dependencies.
- Never request, read, copy, or store secrets. Describe secret names only when an approved optional service requires them.
- Never replace unrelated workflows, weaken stricter policy, mutate remote settings or rulesets, commit, or push without separate explicit authority.
- When GitHub Actions is unavailable, leave the same commands as a local/pre-release checklist; do not invent a CI-provider abstraction.
