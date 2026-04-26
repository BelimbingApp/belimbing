# Module Layer1 Operation

**Agent:** GPT-5.5  
**Status:** Complete  
**Last Updated:** 2026-04-26  
**Sources:** `docs/architecture/file-structure.md`, `docs/architecture/database.md`, `app/Modules/Operation/IT/`, `app/Modules/Commerce/Inventory/`

## Problem Essence

The module directory convention uses `Layer0` and `Layer1` to express architectural placement, but the current `Business` Layer1 is too broad now that `Commerce` exists as a sibling Layer1. The IT ticket module currently lives under `app/Modules/Business/IT`, while its purpose is operational support rather than a generic business-domain category.

The database migration registry also lists the module as `IT/Ticket`, which mixes the module name (`IT`) with an entity or feature (`Ticket`).

## Desired Outcome

Operational modules should have a truthful Layer1 home: `app/Modules/Operation/{Module}`. The IT module should move to `app/Modules/Operation/IT`, while `Ticket` remains an entity or feature inside that module.

The architecture docs, migration registry, namespaces, and playbooks should all agree on the same convention:

- Layer0 stays as `Base` or `Modules`.
- Layer1 stays as a named module category such as `Core`, `Commerce`, or `Operation`.
- Module names stay at the module boundary, such as `IT` or `Inventory`.
- Entity names stay in table names, models, routes, and features, such as `Ticket` or `Part`.

## Top-Level Components

- `docs/architecture/file-structure.md` owns the directory layer convention and should describe `Operation` as a valid Layer1 category.
- `docs/architecture/database.md` owns the migration prefix registry and should list IT as `Modules/Operation | IT`, not `Modules/Business | IT/Ticket`.
- `app/Modules/Business/IT/` is the implemented module that should move to `app/Modules/Operation/IT/`.
- Agent playbooks and planning docs that use `app/Modules/Business/IT` as the canonical example should be updated after the module move.

## Design Decisions

### D1: Keep Layer0 and Layer1 terminology

The file-structure docs already use `Layer0` and `Layer1` to describe path boundaries. Keeping that vocabulary preserves the useful distinction between filesystem architecture and database entities.

### D2: Use Operation for internal operational modules

`Operation` is a better Layer1 category for IT tickets than `Business`. It describes internal operational workflows without colliding with more specific commercial categories such as `Commerce`.

### D3: Treat Ticket as an entity, not part of the module name

The module should be `IT`. Tickets belong inside that module as models, tables, routes, and UI features. Registry rows should not use `IT/Ticket` as the module name because it blurs module and entity boundaries.

### D4: Prefer direct refactor during initialization

BLB is still in initialization, so the move should be a direct rename/refactor instead of compatibility shims. If there is no production data to preserve, namespaces, paths, and docs should be corrected outright.

## Public Contract

After this change, new operational modules should be placed under:

- `app/Modules/Operation/{Module}`

The IT module should be referenced as:

- Layer: `Modules/Operation`
- Module: `IT`
- Entity or feature: `Ticket`

The migration registry row should read conceptually as:

- Prefix: `0300_01_01_*`
- Layer: `Modules/Operation`
- Module: `IT`
- Dependencies: `Company`, `User`

The `0300` range is retained for `Operation` (decided in Phase 1), so migration filenames stay unchanged.

## Phases

### Phase 1

Goal: document the intended convention before code movement.

- [x] Update `docs/architecture/database.md` so the IT registry row uses `Modules/Operation | IT`.
- [x] Update `docs/architecture/database.md` business category text so `Operation` is listed as an active or reserved Layer1 category.
- [x] Update `docs/architecture/file-structure.md` examples so IT is not presented as a `Business` module unless `Business` remains as an intentional catch-all Layer1.
- [x] Decide whether `0300` remains the Operation prefix or whether Operation gets a new reserved prefix range.

### Phase 2

Goal: move the implemented IT module to its target Layer1.

- [x] Move `app/Modules/Business/IT/` to `app/Modules/Operation/IT/`.
- [x] Rename PHP namespaces from `App\Modules\Business\IT` to `App\Modules\Operation\IT` inside the module (Models, Services, Livewire, ServiceProvider, Routes, Factories, Seeders, Migrations).
- [x] Update cross-module callers in `app/Modules/Core/AI/Tools/TicketUpdateTool.php` and `app/Modules/Core/AI/Services/AgentTaskPromptFactory.php`.
- [x] Update tests that import the old namespace: `tests/Feature/Workflow/WorkflowEngineTest.php` and `tests/Unit/Modules/Core/AI/Tools/TicketUpdateToolTest.php`.
- [x] Confirm provider auto-discovery (`ProviderRegistry::discoverModuleProviders`) picks up the new path; `bootstrap/providers.php` needed no manual change.
- [x] Migration filename kept under `0300_01_01_*` and renamed to `create_operation_it_tickets_table.php`; the table registry now records `module_path = app/Modules/Operation/IT`.
- [x] Rename the underlying table from `it_tickets` to `operation_it_tickets` so it matches the `{layer1}_{module}_{entity}` convention in `docs/architecture/database.md`. Required `migrate:fresh` once with the BLB-specific cleanup that removed the orphaned legacy registry row.
- [x] Remove the now-empty `app/Modules/Business/` directory so `Business` does not linger as a phantom Layer1.

### Phase 3

Goal: remove stale examples and verify the new convention.

- [x] Update stale `Business/IT` references in `docs/plans/ham/01-ebay-car-parts-operations.md`. (`docs/modules/workflow/design.md` only used hypothetical `Business/Leave` and `Business/Logistics` examples; updated to `Operation/Leave`/`Operation/Logistics` since `Business` Layer1 was retired.)
- [x] Update agent playbooks (`feat-new-business-module.md`, `feat-module-schema.md`, `feat-module-feature.md`, `feat-workflow-consumer.md`) to reference `Modules/Operation/IT` and `operation_it_tickets`.
- [x] Boy-scout: realign other docs that referenced the retired `Business` Layer1 — `docs/Base/Database/migration-registry.md`, `docs/development/agent-context.md`, `docs/installation/package-evaluation.md`, `docs/reference/package-evaluation.md`. Generic "Core vs Business" framing rewritten as "Core vs Application (Operation, Commerce, …)".
- [x] Update Livewire view docblocks under `resources/core/views/livewire/it/tickets/` to the new namespace.
- [x] Final search confirmed no residual `Modules/Business`, `App\Modules\Business`, or `Business/IT` references outside this plan doc.
- [x] `php artisan test` — 1582 passed (5177 assertions). `migrate:fresh --dev --seed` succeeded; registry reports `IT | app/Modules/Operation/IT | 0300_01_01_000000_create_operation_it_tickets_table.php`. Pint applied to touched files.
