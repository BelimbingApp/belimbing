# SonarQube Legacy Complexity Remediation

Status: In progress

Last Updated: 2026-10-20

Sources: SonarCloud project `BelimbingApp_lara`, branch `main`; current open findings retrieved 2026-10-20

Agents: `{root}/gpt-5`

## Problem Essence

SonarCloud reports 25 legacy code smells on `main`: 13 methods above the cognitive-complexity threshold and 12 classes above the method-count threshold. The quality gate is green and has no new-code duplication, but these findings make several framework services harder to change safely.

## Desired Outcome

Each remaining finding is resolved through a behavior-preserving extraction with a nameable responsibility, while public module contracts and scheduler, deployment, database, and AI lifecycle behavior remain unchanged. No artificial class split or blanket rule suppression is introduced.

## Top-Level Components

- `SubjectIdRequestScan` owns lexical request-to-query seam detection for Domain Livewire components.
- Base services own the operational workflows that need cohesive private helpers rather than long procedural methods.
- Large service and Livewire classes need concern or collaborator boundaries only where the resulting contract is independently meaningful.

## Design Decisions

The first option is arbitrary splitting to satisfy Sonar thresholds. It lowers metrics but hides related workflow state across more files, so it is rejected.

The second option is suppressing the rules or marking the findings false positive. These are ordinary production methods and classes, not framework-mandated model definitions, migrations, or lifecycle hooks, so it is rejected.

The recommended option is incremental local extraction: private methods for one-class responsibilities, then focused collaborators or concerns only when several methods share an obvious contract. This preserves deep modules and lowers entropy without changing observable behavior.

## Public Contract

Existing commands, scheduler events, Livewire actions, service interfaces, and scanner output formats remain stable. Refactors must preserve their current behavior and test coverage.

## Phases

### Phase 1 — Isolated scanner complexity

- [x] Extract literal-specific request-reader detection from `App\Base\Livewire\SubjectIdRequestScan` while preserving match order and evidence strings. `{root}/gpt-5`

Evidence: `tests/Unit/Base/Livewire/SubjectIdRequestScanTest.php` passes; Pint passes for the changed scanner.

### Phase 2 — Cognitive-complexity services

- [ ] Decompose the backup workflow in `App\Base\Database\Services\Backup\BackupService` into named validation and execution steps.
- [ ] Decompose `App\Base\Database\Services\SchemaDrift\SchemaCallProcessor` raw-SQL processing without weakening supported-statement classification.
- [ ] Decompose catalog discovery in `App\Base\Foundation\Services\DomainCatalog` without changing discovery order.
- [ ] Decompose the three flagged checks in `App\Base\Foundation\Services\ModuleCheck` while retaining complete diagnostic output.
- [ ] Decompose source installation and migration rollback paths in `App\Base\Foundation\Services\SourceRepositoryInstaller` without broadening installation authority.
- [ ] Decompose scheduler-index filtering in `App\Base\Schedule\Livewire\Index` while preserving pagination and operator actions.
- [ ] Decompose `App\Base\Schedule\Services\ScheduleRunRecorder` reservation flow without altering concurrency or ledger honesty guarantees.
- [ ] Decompose update orchestration in `App\Base\Software\Console\Commands\SoftwareUpdateCommand` and upstream synchronization in `App\Base\Software\Services\UpstreamSyncService` without changing deployment safety semantics.

### Phase 3 — Method-count boundaries

- [ ] Extract independently meaningful collaborators or concerns from the flagged `ModuleManifestReader`, `Schedule\Livewire\Index`, `ScheduleRunRecorder`, and `DatabaseSettingsService` classes.
- [ ] Extract independently meaningful collaborators or concerns from the flagged deployment `Index`, `SoftwareSourceRepository`, and `GitRepository` classes.
- [ ] Extract independently meaningful collaborators or concerns from the flagged AI `Chat` and `LifecycleControlService` classes.
- [ ] Extract independently meaningful collaborators or concerns from the flagged Company and User `Show` components and the User model.

### Phase 4 — Verification and Sonar reconciliation

- [ ] Run targeted regression tests after each extraction, then the full test suite and Pint before submission.
- [ ] Re-query SonarCloud after analysis completes; confirm the resolved finding count, zero safe-hotspot backlog, and that `new_duplicated_lines` remains zero.
