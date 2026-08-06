# Authorable PHP formatting baseline

**Status:** Proposed
**Last Updated:** 2026-08-05
**Sources:** root `AGENTS.md`, `docs/plans/four-root-application-topology.md`, `docs/architecture/database.md`
**Agents:** codex/gpt-5.6-sol

## Problem Essence

The repository-wide Pint check is not a useful merge signal today: the four-root verification scanned 2,839 PHP files and reported 616 formatting failures, including historical migrations whose recorded hashes make content changes unsafe. Focused checks can keep newly edited files clean, but they do not prevent unrelated authorable code from retaining or adding formatting debt.

## Desired Outcome

Belimbing has one documented formatting contract that passes across every authorable PHP file in the platform and nested source repositories, preserves immutable migration history, and fails CI when new drift is introduced.

## Top-Level Components

- **Immutable-history boundary:** existing recorded migrations remain byte-identical and are verified by migration hashes rather than rewritten for style.
- **Authorable-file inventory:** all other PHP files are assigned to the platform or one nested source repository and included in a reproducible Pint scope.
- **Baseline cleanup:** current authorable findings are formatted in reviewable repository-sized changes with behavior verification.
- **Continuous enforcement:** local guidance and CI use the same scope so the formatter result stays trustworthy.

## Design Decisions

### Preserve historical migrations instead of normalizing them

Reformatting recorded migrations would trade cosmetic consistency for broken integrity evidence. The formatter scope should exclude only the explicitly immutable migration set; new migrations remain authorable and must be formatted before their hashes become history.

### Establish one baseline per repository

Opportunistic cleanup would leave a long-lived partial signal. A controlled baseline pass in each of the seven repositories keeps ownership clear, makes review mechanical, and allows each nested source to land independently once the four-root relocation is available on its default branch.

### Keep one policy across platform and nested sources

Repository-specific style exceptions would recreate drift. All sources should consume the same Pint rules and differ only in their explicit immutable-file inventory.

## Public Contract

- Pint passes for every authorable PHP file in the platform, Domain, and Extension repositories.
- Existing migrations covered by recorded integrity hashes are never modified merely for formatting.
- A newly created migration is formatted before it is applied or recorded as immutable history.
- CI and contributor guidance invoke the same formatter scope.

## Phases

### Inventory and boundary

- [ ] Capture Pint findings per repository and classify each file as authorable or hash-immutable.
- [ ] Define the canonical authorable-file scope and prove it excludes only recorded historical migrations.
- [ ] Add a guard that rejects an unformatted new migration before it becomes recorded history.

### Baseline cleanup

- [ ] Format authorable platform files, review the mechanical diff, and run the affected plus full platform suites.
- [ ] Format authorable files in each Domain and Extension repository, reviewing and validating each repository separately.
- [ ] Confirm every historical migration remains byte-identical and every repository's authorable Pint scope passes.

### Enforcement

- [ ] Wire the canonical scope into CI for the platform and nested source repositories.
- [ ] Update contributor guidance with the local check and the immutable-migration rationale.
- [ ] Record final per-repository validation evidence and set this plan to Complete.
