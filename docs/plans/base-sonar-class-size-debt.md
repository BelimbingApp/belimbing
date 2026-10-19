# base-sonar-class-size-debt

**Status:** Proposed
**Last Updated:** 2026-10-19
**Sources:** SonarCloud `php:S1448` findings for `BelimbingApp_lara` on `main`; `D:\repo\belimbing\.agents\skills\sonarqube-fix\SKILL.md`
**Agents:** Codex/GPT-5

## Problem Essence

SonarCloud reports 26 legacy maintainability findings on `main`: 14 methods above the cognitive-complexity threshold and 12 platform classes with more methods than the current quality profile allows. The counts are useful entropy signals, but most flagged classes are boundary objects where a mechanical split or control-flow rewrite could make ownership less honest.

## Desired Outcome

Each flagged method and class either becomes a smaller orchestration surface with cohesive collaborators or is explicitly justified by framework shape. Refactors should reduce complexity by naming real decisions and reduce method count by moving real responsibilities, not by hiding related methods behind vague helpers.

## Top-Level Components

- `Base\Foundation` manifest and source-installation services own module metadata, repository validation, and install lifecycle orchestration.
- `Base\Schedule` Livewire and recorder classes own schedule UI actions, locking, run lifecycle, and history projection.
- `Base\Software` repository and deployment classes own git command boundaries and update workflow state.
- Core Livewire detail screens and the `User` model should separate UI actions, read models, and relationship/security concerns only where a named ownership split exists.
- The remaining complexity findings span module discovery, source installation, software updates, schedule execution, subject scans, action inventory, backup recovery, and schema-drift processing; each needs a local decision boundary rather than generic control-flow flattening.

## Design Decisions

Three approaches are available: suppress findings, mechanically split methods/classes, or extract cohesive collaborators at existing subsystem boundaries. Suppression leaves real maintenance cost unaddressed, while mechanical splitting creates shallow APIs and obscures workflows. Recommend the collaborator approach: it meets the quality profile while preserving deep modules and honest ownership.

For cognitive complexity, prefer guard clauses only when they reveal a precondition, private methods when a single class-local decision has a name, and a service/collaborator only when it owns a stable policy or I/O boundary. Do not rewrite failure handling or user-visible messages merely to lower a score.

Prefer cohesive collaborator extraction over trait scatter. A helper class wins when it owns a stable subsystem boundary, such as schedule-run reservation or module-manifest parsing. Traits only win for repeated framework glue across sibling Livewire components.

Do not split Eloquent models by moving relationships into arbitrary traits solely to satisfy a method count. Model concerns are acceptable only when the extracted relations or predicates form an existing product concept and are reused by sibling models.

Treat Livewire page classes as orchestration shells. Move persistence, projection, or policy-heavy work into services, but keep UI state transitions visible in the component so the workflow remains readable.

## Phases

### Base Foundation

- [ ] Reduce `DomainCatalog` discovery complexity by separating topology filtering from catalog assembly, with catalog/discovery tests covering the same enabled-module cases.
- [ ] Split `ModuleManifestReader` into manifest loading, dependency verification, and parse-normalization collaborators with fixture coverage.
- [ ] Split `SourceRepositoryInstaller` install orchestration from checkout validation and migration/reload recovery.
- [ ] Reduce `ModuleCheck` validation complexity by extracting named verification phases without changing reported check results.

### Base Schedule

- [ ] Extract scheduler run reservation/terminalization policy from `ScheduleRunRecorder` while preserving transaction and lock boundaries.
- [ ] Move schedule page projection and cron persistence helpers out of `Base\Schedule\Livewire\Index` only where the Livewire component remains the workflow owner.

### Base Runtime and Operations

- [ ] Reduce `SoftwareUpdateCommand` and `UpstreamSyncService` complexity by separating command selection, state transition, and recovery policies at their existing service boundaries.
- [x] Extract named validation and action-name comparison phases from `ActionInventory` while preserving its public contract and diagnostics.
- [ ] Extract named decision phases from `SubjectIdRequestScan`, `BackupService`, and `SchemaCallProcessor`; preserve their current public contracts and operator-facing diagnostics.

### Base Software

- [ ] Extract git command groups from `SoftwareSourceRepository` and keep auth/log masking at the repository boundary.
- [ ] Review `Base\Software\Livewire\Deployment\Index` for projection/action split before moving any UI state.

### Core Surfaces

- [ ] Review `Core\AI\Livewire\Chat` and `LifecycleControlService` for true service boundaries around control-plane lifecycle actions.
- [ ] Review company/user detail screens and `Core\User\Models\User` for reusable relation or action concepts; skip arbitrary model-trait splits.

Validation: targeted tests for each extracted collaborator, `vendor/bin/pint --dirty`, and the affected feature tests for every moved public workflow.
