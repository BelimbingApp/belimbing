# Module Domain Alignment

**Status:** Complete
**Last Updated:** 2026-05-07
**Sources:** `docs/plans/menu-system-cleanup.md`, `docs/plans/module-layer1-operation.md`, `docs/architecture/module-system.md`, `docs/architecture/database.md`, `docs/Base/Database/migration-registry.md`, `app/Modules/People/Config/menu.php`, `app/Modules/Core/Employee/`, `app/Modules/Operation/Quality/`, `app/Modules/Operation/IT/`, `extensions/sb-group/qac/`
**Agents:** codex/gpt-5.5-medium, claude-sonnet-4-6

## Problem Essence

The menu cleanup made the navigation taxonomy clearer: Employee screens are useful under `people`, and Quality belongs under `operations`. Code ownership should follow domain ownership, not every sidebar bucket. Quality is an operational workflow module, so it moved out of Core. Employee remains Core because `employees` represent employment relationships for any company, including customers, suppliers, partners, agencies, and the licensee.

## Desired Outcome

- Keep `app/Modules/Operation` as the canonical domain path and `App\Modules\Operation` as the namespace. Do not rename it to `Operations`.
- Keep Employee in `app/Modules/Core/Employee` with namespace `App\Modules\Core\Employee`.
- Treat `app/Modules/People/Config/menu.php` as a People domain anchor for licensee-facing views, not ownership of the canonical any-company Employee module.
- Move Quality to `app/Modules/Operation/Quality` with namespace `App\Modules\Operation\Quality`.
- Use `0300_01_03_*` for Quality migrations because Quality now belongs to the `0300` Operation band after IT's `0300_01_01_*`.
- Keep Employee migrations at `0200_01_09_*`; User and AI depend on Employee and remain later in Core.
- Preserve menu IDs and permission keys from the completed menu cleanup: `people.employee.*` and `operations.quality.*` remain the public navigation/authz contract.

## Design Decisions

### D1: Keep domain directory names singular and conceptual

`Operation` is an architectural domain, not the rendered sidebar label. The existing `module-layer1-operation` plan already retired `Business` in favor of singular `Operation`, updated namespaces, migration registry paths, and table naming around `operation_it_tickets`. Renaming `Operation` to `Operations` would add broad namespace and documentation churn while weakening the convention that domain names are conceptual.

The menu label stays plural because it is user-facing prose. The code path stays singular because it is an ownership domain.

### D2: Keep Employee in Core

Employee is not inherently a licensee-only people module. It is the system-of-record for employment relationships across companies, and those companies can be external customers, suppliers, partners, or agencies. People should expose licensee-scoped views over Employee records when that workflow is needed; it should not own the underlying any-company model.

`Company`, `User`, `Address`, `Geonames`, `Employee`, and `AI` therefore stay in Core. Company is legal entity master data, User is authentication identity, Address and Geonames are reference data, Employee is any-company employment data, and AI is platform capability.

### D3: Move Quality to Operation

Quality owns NCR/SCAR/CAPA operational workflows. The `operations.quality.*` menu IDs reflect real ownership, and the module now lives under `app/Modules/Operation/Quality` with `App\Modules\Operation\Quality`.

Quality table names stay `quality_*`, and workflow flow codes stay `quality_ncr` and `quality_scar`. Those names describe the bounded domain and do not need an additional `operation_` prefix.

### D4: Align migration bands with ownership

Core keeps the `0200` band. Employee remains `0200_01_09_*` because User (`0200_01_20_*`) and AI (`0200_02_01_*`) depend on it.

Operation uses the `0300` band. IT already owns `0300_01_01_*`, so Quality uses `0300_01_03_*`. This keeps Quality after IT inside Operation without falsely making it a Core module.

## Public Contract

New operational modules should continue to live under:

- `app/Modules/Operation/{Module}`
- `App\Modules\Operation\{Module}`

People remains a navigation/domain anchor for licensee-scoped people workflows. A future People leaf should be created only when it owns a licensee-scoped workflow or view, not merely because it displays Employee records.

Navigation and permission IDs remain menu-domain based:

- Employee: `people.employee.*`, `people.employee-type.*`
- Quality: `operations.quality.*`, `operations.quality.ncr.*`, `operations.quality.scar.*`

## Phases

### Phase 1 — Pre-flight and guardrails

- [x] Search and record all references to `App\Modules\Core\Employee`, `app/Modules/Core/Employee`, and Employee module docs. codex/gpt-5
- [x] Search and record all references to `App\Modules\Core\Quality`, `app/Modules/Core/Quality`, and Quality module docs. codex/gpt-5
- [x] Confirm provider auto-discovery still discovers one-level domain anchors and two-level leaf modules after the moves. codex/gpt-5
- [x] Confirm no code depends on `Operation` becoming plural; document that `Operation` remains the canonical domain in `docs/architecture/module-system.md`. codex/gpt-5

### Phase 2 — Keep Employee in Core

- [x] Revert the attempted Employee module move back to `app/Modules/Core/Employee/`. codex/gpt-5
- [x] Restore Employee namespaces to `App\Modules\Core\Employee`. codex/gpt-5
- [x] Keep Employee PHP classes in Core while treating People as the navigation/domain lens for employee-facing UI. codex/gpt-5; superseded for Blade placement by module-colocated People views under each owning `app/Modules/People/*/Views` tree. Amp/GPT-5
- [x] Update docs so People is described as a licensee-scoped lens rather than the owner of Employee. codex/gpt-5
- [x] Keep Employee migrations at `0200_01_09_*`. codex/gpt-5

### Phase 3 — Move Quality to Operation

- [x] Move `app/Modules/Core/Quality/` to `app/Modules/Operation/Quality/`. codex/gpt-5
- [x] Rename PHP namespaces from `App\Modules\Core\Quality` to `App\Modules\Operation\Quality`. codex/gpt-5
- [x] Update route imports, Livewire view docblocks, tests, extensions, and module docs. codex/gpt-5
- [x] Rename Quality migrations from `0200_01_25_*` to `0300_01_03_*`. codex/gpt-5
- [x] Keep `quality_*` table names and `quality_ncr` / `quality_scar` workflow flow codes. codex/gpt-5

### Phase 4 — Documentation cleanup

- [x] Update `docs/architecture/module-system.md` so Employee appears under Core and People appears only as a navigation/domain anchor. codex/gpt-5
- [x] Update database architecture and migration registry docs for Employee `0200_01_09_*` and Quality `0300_01_03_*`. codex/gpt-5
- [x] Update `docs/modules/quality.md` and SB Group QAC docs with the Operation Quality prefix. codex/gpt-5
- [x] Final search confirms no stale People-owned Employee references or `0200_01_25` Quality prefix remain in active docs/code. codex/gpt-5

### Phase 5 — Verification

- [x] `composer dump-autoload` codex/gpt-5
- [x] `vendor/bin/pint --dirty` codex/gpt-5
- [x] `php artisan cache:clear` codex/gpt-5
- [x] `php artisan route:list` codex/gpt-5
- [x] `php artisan migrate:fresh --dev --seed` codex/gpt-5
- [x] Focused Employee/Core dependency and Quality workflow tests. codex/gpt-5
- [x] Full test suite: `php artisan test`. codex/gpt-5
- [x] Menu Inspector discovery confirms Employee source paths are under `app/Modules/Core/Employee` and Quality source paths are under `app/Modules/Operation/Quality`; menu validation returned no errors. codex/gpt-5

## Risks and Notes

- **People view remains future work.** The current correction fixes module ownership. A true licensee-scoped People view can be added later without moving Employee out of Core.
- **Migration prefix churn.** During initialization, renumbering Quality from `0200_01_25_*` to `0300_01_03_*` is cleaner than carrying a misleading Core prefix.
- **Historical plan references.** Completed plans may mention old paths as historical state. Keep those only where the old path is part of the story; update forward-looking recommendations.
