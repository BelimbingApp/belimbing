# Module Layer1 Operation

**Agent:** GPT-5.5  
**Status:** Identified  
**Last Updated:** 2026-04-26  
**Sources:** `docs/architecture/file-structure.md`, `docs/architecture/database.md`, `app/Modules/Business/IT/`, `app/Modules/Commerce/Inventory/`

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

If a new prefix range is reserved for `Operation`, the registry should say so explicitly before any migration files are renamed.

## Phases

### Phase 1

Goal: document the intended convention before code movement.

- [x] Update `docs/architecture/database.md` so the IT registry row uses `Modules/Operation | IT`.
- [x] Update `docs/architecture/database.md` business category text so `Operation` is listed as an active or reserved Layer1 category.
- [x] Update `docs/architecture/file-structure.md` examples so IT is not presented as a `Business` module unless `Business` remains as an intentional catch-all Layer1.
- [x] Decide whether `0300` remains the Operation prefix or whether Operation gets a new reserved prefix range.

### Phase 2

Goal: move the implemented IT module to its target Layer1.

- [ ] Move `app/Modules/Business/IT/` to `app/Modules/Operation/IT/`.
- [ ] Rename PHP namespaces from `App\Modules\Business\IT` to `App\Modules\Operation\IT`.
- [ ] Update routes, service providers, config references, factories, seeders, and tests that reference the old namespace or path.
- [ ] Update migration registration metadata so the table registry reports the new module path.
- [ ] Rename migration files only if the prefix policy changes before production data exists.

### Phase 3

Goal: remove stale examples and verify the new convention.

- [ ] Update agent playbooks that use `app/Modules/Business/IT` as the canonical business-module example.
- [ ] Search for stale `Modules/Business/IT`, `App\Modules\Business\IT`, and `Business/IT` references.
- [ ] Run the database and IT module tests after the move.
- [ ] Mark this plan complete once docs, code, and tests agree.
